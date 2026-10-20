<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * System-level automatic database backup (run from cron via `php artisan db:backup`).
 *
 * There is intentionally NO web route, controller, menu or download for this:
 * backups are written to a private directory outside the public web root and
 * are collected from the server (File Manager / SFTP) by an administrator.
 *
 * Safety properties:
 *  - Read-only against the live database (mysqldump --single-transaction / SELECT).
 *  - Never deletes or alters application data.
 *  - Dumps contain no DROP statements, so importing one into a database that
 *    already has the tables fails loudly instead of overwriting live data.
 *  - Nothing from .env is stored in a backup; session/cache/token tables are
 *    excluded. (User rows, including their password HASHES, are kept because
 *    they are needed to recover accounts - protect the backup directory.)
 *  - Retention only ever removes files this class created, and only after a
 *    NEW backup has just been verified.
 */
class DatabaseBackupService
{
    public const STATUS_FILE = 'status.json';

    /** kisauni_db_20260929_231500.sql.gz  or  kisauni_db_20260929_231500_before-promote.sql.gz */
    private const FILE_PATTERN = '/^kisauni_db_\d{8}_\d{6}(?:_[A-Za-z0-9\-]{1,40})?\.sql\.gz$/';
    private const PARTIAL_PATTERN = '/^kisauni_db_.*\.partial$/';

    /** Volatile / sensitive-by-nature tables that are pointless to back up. */
    public const EXCLUDED_TABLES = [
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_resets', 'password_reset_tokens', 'personal_access_tokens',
    ];

    // ------------------------------------------------------------------ paths

    /**
     * Resolve (and create) the private backup directory.
     * Order: BACKUP_PATH, then a sibling of the project folder (outside the
     * web root), then storage/app/db_backups as a last resort.
     */
    public static function directory(): string
    {
        $candidates = array_values(array_filter([
            config('kisauni.backup.path'),
            dirname(base_path()) . DIRECTORY_SEPARATOR . 'kisauni_backups',
            storage_path('app' . DIRECTORY_SEPARATOR . 'db_backups'),
        ]));

        foreach ($candidates as $i => $dir) {
            if (!static::prepareDirectory($dir)) {
                continue;
            }
            static::assertOutsideWebRoot($dir);
            if ($i > 0 && !empty(config('kisauni.backup.path'))) {
                Log::warning('Backup: BACKUP_PATH is not usable, using fallback directory.', ['dir' => $dir]);
            }
            return $dir;
        }

        throw new RuntimeException('No writable backup directory is available. Set BACKUP_PATH in .env to a private, writable folder outside public_html.');
    }

    private static function prepareDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        @chmod($dir, 0700);
        return true;
    }

    private static function assertOutsideWebRoot(string $dir): void
    {
        $real = realpath($dir);
        $public = realpath(public_path());
        if ($real && $public && ($real === $public || str_starts_with($real, $public . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Refusing to store backups inside the public web directory.');
        }
    }

    // -------------------------------------------------------------------- run

    /**
     * Create one verified, gzip-compressed backup and (optionally) apply retention.
     *
     * @return array{file:string,path:string,size:int,method:string,seconds:float,pruned:int}
     * @throws RuntimeException when the backup could not be created or verified
     */
    public static function run(string $label = '', bool $prune = true): array
    {
        $started = microtime(true);
        $dir = null;
        $lock = null;

        try {
            @set_time_limit(0);
            $dir = static::directory();

            $lock = fopen($dir . DIRECTORY_SEPARATOR . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another backup is already running.');
            }

            $safeLabel = static::sanitizeLabel($label);
            $name = 'kisauni_db_' . SchoolService::now()->format('Ymd_His') . ($safeLabel !== '' ? "_{$safeLabel}" : '') . '.sql.gz';
            $final = $dir . DIRECTORY_SEPARATOR . $name;
            $partial = $dir . DIRECTORY_SEPARATOR . $name . '.partial';
            // Same-second collision (e.g. two safety backups): make it unique.
            $n = 1;
            while (file_exists($final)) {
                $name = 'kisauni_db_' . SchoolService::now()->format('Ymd_His') . ($safeLabel !== '' ? "_{$safeLabel}" : '') . "-{$n}.sql.gz";
                $final = $dir . DIRECTORY_SEPARATOR . $name;
                $partial = $final . '.partial';
                $n++;
            }
            $name = basename($final);

            $method = static::writeDump($partial);

            static::verifyDump($partial);

            if (!@rename($partial, $final)) {
                throw new RuntimeException('Could not finalise the backup file.');
            }
            @chmod($final, 0600);

            $size = (int) filesize($final);
            $pruned = $prune ? static::prune($dir) : 0;

            $result = [
                'file' => $name,
                'path' => $final,
                'size' => $size,
                'method' => $method,
                'seconds' => round(microtime(true) - $started, 2),
                'pruned' => $pruned,
            ];

            static::writeStatus($dir, [
                'last_status' => 'success',
                'last_attempt_at' => SchoolService::nowStr(),
                'last_success_at' => SchoolService::nowStr(),
                'last_file' => $name,
                'last_size_bytes' => $size,
                'last_method' => $method,
                'last_error' => null,
            ]);
            Log::info('Database backup completed.', ['file' => $name, 'size' => $size, 'method' => $method, 'pruned' => $pruned]);

            return $result;
        } catch (Throwable $e) {
            $message = static::scrub($e->getMessage());
            if ($dir) {
                if (isset($partial) && is_file($partial)) {
                    @unlink($partial);
                }
                static::writeStatus($dir, [
                    'last_status' => 'failed',
                    'last_attempt_at' => SchoolService::nowStr(),
                    'last_error' => $message,
                ]);
            }
            Log::error('Database backup FAILED: ' . $message);
            throw new RuntimeException($message, 0, $e);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Backup used as a safety net right before a bulk operation (promotion).
     * Never throws: a failure is logged and the operation proceeds as before.
     */
    public static function safetyBackup(string $label): ?string
    {
        try {
            return static::run($label, true)['file'];
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------ dump

    /** @return string method used: "mysqldump" or "php" */
    private static function writeDump(string $partialPath): string
    {
        $conn = DB::connection();
        $driver = $conn->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && !config('kisauni.backup.force_php_dump')) {
            $binary = static::findMysqldump();
            if ($binary) {
                try {
                    static::dumpWithMysqldump($binary, $conn->getConfig(), $partialPath);
                    return 'mysqldump';
                } catch (Throwable $e) {
                    Log::warning('Backup: mysqldump failed, falling back to the PHP dumper: ' . static::scrub($e->getMessage()));
                    @unlink($partialPath);
                }
            }
        }

        if (!in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Unsupported database driver for backup: {$driver}");
        }

        static::dumpWithPdo($partialPath, $driver);
        return 'php';
    }

    private static function findMysqldump(): ?string
    {
        $configured = config('kisauni.backup.mysqldump_path');
        if ($configured) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }
        $finder = new ExecutableFinder();
        return $finder->find('mysqldump') ?: $finder->find('mariadb-dump');
    }

    private static function dumpWithMysqldump(string $binary, array $cfg, string $partialPath): void
    {
        $database = $cfg['database'] ?? null;
        if (!$database) {
            throw new RuntimeException('Database name is not configured.');
        }

        // Credentials go through a private (0600) option file, never on the
        // command line where other server users could read them with `ps`.
        $cnf = tempnam(dirname($partialPath), '.cnf_');
        if ($cnf === false) {
            throw new RuntimeException('Could not create a temporary option file.');
        }

        $gz = null;
        try {
            @chmod($cnf, 0600);
            $lines = ['[client]'];
            $lines[] = 'user="' . static::cnfEscape((string) ($cfg['username'] ?? '')) . '"';
            $lines[] = 'password="' . static::cnfEscape((string) ($cfg['password'] ?? '')) . '"';
            if (!empty($cfg['unix_socket'])) {
                $lines[] = 'socket="' . static::cnfEscape((string) $cfg['unix_socket']) . '"';
            } else {
                $lines[] = 'host="' . static::cnfEscape((string) ($cfg['host'] ?? '127.0.0.1')) . '"';
                $lines[] = 'port=' . (int) ($cfg['port'] ?? 3306);
            }
            file_put_contents($cnf, implode("\n", $lines) . "\n");

            $args = [
                $binary,
                "--defaults-extra-file={$cnf}",
                '--single-transaction', '--quick', '--skip-lock-tables', '--skip-add-locks',
                '--skip-add-drop-table', '--no-tablespaces', '--default-character-set=utf8mb4',
            ];
            foreach (static::EXCLUDED_TABLES as $table) {
                $args[] = "--ignore-table={$database}.{$table}";
            }
            $args[] = $database;

            $gz = gzopen($partialPath, 'wb6');
            if (!$gz) {
                throw new RuntimeException('Could not open the backup file for writing.');
            }
            @chmod($partialPath, 0600);

            $stderr = '';
            $process = new Process($args);
            $process->setTimeout((float) config('kisauni.backup.timeout_seconds', 600));
            $process->run(function (string $type, string $buffer) use ($gz, &$stderr, &$process) {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                    $process->clearOutput();
                } else {
                    $stderr .= $buffer;
                }
            });
            gzclose($gz);
            $gz = null;

            if (!$process->isSuccessful()) {
                throw new RuntimeException('mysqldump exited with code ' . $process->getExitCode() . ': ' . trim(substr($stderr, 0, 500)));
            }
        } finally {
            if (is_resource($gz) || $gz) {
                @gzclose($gz);
            }
            @unlink($cnf);
        }
    }

    private static function cnfEscape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /** Pure-PHP dump (streamed, chunked). Used when mysqldump is missing/failing, and for SQLite. */
    private static function dumpWithPdo(string $partialPath, string $driver): void
    {
        $pdo = DB::connection()->getPdo();
        $gz = gzopen($partialPath, 'wb6');
        if (!$gz) {
            throw new RuntimeException('Could not open the backup file for writing.');
        }
        @chmod($partialPath, 0600);

        try {
            $isMysql = $driver !== 'sqlite';
            gzwrite($gz, "-- Kisauni Result System database dump (PHP dumper, {$driver})\n-- Generated: " . SchoolService::nowStr() . "\n\n");
            if ($isMysql) {
                gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");
            }

            $tables = $isMysql
                ? $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM)
                : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_NUM);

            foreach ($tables as $row) {
                $table = $row[0];
                if (in_array($table, static::EXCLUDED_TABLES, true)) {
                    continue;
                }
                $q = '`' . str_replace('`', '``', $table) . '`';

                if ($isMysql) {
                    $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(\PDO::FETCH_NUM)[1] ?? null;
                } else {
                    $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
                    $stmt->execute([$table]);
                    $create = $stmt->fetchColumn() ?: null;
                }
                if (!$create) {
                    throw new RuntimeException("Could not read the structure of table {$table}.");
                }
                gzwrite($gz, "--\n-- Table structure for {$q}\n--\n\n{$create};\n\n");

                $offset = 0;
                $chunk = 500;
                $wroteHeader = false;
                while (true) {
                    $rows = $pdo->query("SELECT * FROM {$q} ORDER BY 1 LIMIT {$chunk} OFFSET {$offset}")->fetchAll(\PDO::FETCH_ASSOC);
                    if (!$rows) {
                        break;
                    }
                    if (!$wroteHeader) {
                        gzwrite($gz, "--\n-- Data for {$q}\n--\n\n");
                        $wroteHeader = true;
                    }
                    $cols = '`' . implode('`,`', array_map(fn ($c) => str_replace('`', '``', $c), array_keys($rows[0]))) . '`';
                    $values = [];
                    foreach ($rows as $r) {
                        $values[] = '(' . implode(',', array_map(function ($v) use ($pdo) {
                            if ($v === null) {
                                return 'NULL';
                            }
                            if (is_int($v) || is_float($v)) {
                                return (string) $v;
                            }
                            return $pdo->quote((string) $v);
                        }, array_values($r))) . ')';
                    }
                    gzwrite($gz, "INSERT INTO {$q} ({$cols}) VALUES\n" . implode(",\n", $values) . ";\n");
                    $offset += $chunk;
                    if (count($rows) < $chunk) {
                        break;
                    }
                }
                if ($wroteHeader) {
                    gzwrite($gz, "\n");
                }
            }

            if ($isMysql) {
                gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n\n");
            }
            gzwrite($gz, '-- Dump completed on ' . SchoolService::nowStr() . "\n");
        } finally {
            gzclose($gz);
        }
    }

    // ---------------------------------------------------------------- verify

    /**
     * A backup is only accepted if it is a readable gzip, contains the users
     * table structure, and ends with the "dump completed" marker (i.e. it was
     * not cut short).
     */
    public static function verifyDump(string $path): void
    {
        if (!is_file($path) || filesize($path) < 50) {
            throw new RuntimeException('Backup verification failed: file is missing or empty.');
        }
        $gz = @gzopen($path, 'rb');
        if (!$gz) {
            throw new RuntimeException('Backup verification failed: file is not a valid gzip archive.');
        }

        $hasUsers = false;
        $last = '';
        try {
            while (!gzeof($gz)) {
                $line = gzgets($gz, 1048576);
                if ($line === false) {
                    break;
                }
                $trim = trim($line);
                if ($trim === '') {
                    continue;
                }
                $last = $trim;
                if (!$hasUsers && preg_match('/^CREATE TABLE (IF NOT EXISTS )?[`"]?users[`"]?\s*\(/i', $trim)) {
                    $hasUsers = true;
                }
            }
        } finally {
            gzclose($gz);
        }

        if (!$hasUsers) {
            throw new RuntimeException('Backup verification failed: the users table was not found in the dump.');
        }
        if (!str_starts_with($last, '-- Dump completed')) {
            throw new RuntimeException('Backup verification failed: the dump looks incomplete (no completion marker).');
        }
    }

    // ------------------------------------------------------------- retention

    /**
     * Delete backups older than the retention period, always keeping the newest
     * "min_keep". Only files matching this class's own naming pattern are touched.
     *
     * @return int number of files deleted
     */
    public static function prune(?string $dir = null, ?int $keep = null, ?int $retentionDays = null): int
    {
        $dir = $dir ?? static::directory();
        $keep = max(1, $keep ?? (int) config('kisauni.backup.min_keep', 7));
        $days = max(1, $retentionDays ?? (int) config('kisauni.backup.retention_days', 90));
        $cutoff = SchoolService::now()->timestamp - ($days * 86400);

        $files = static::listBackupFiles($dir);
        $deleted = 0;
        foreach (array_slice($files, $keep) as $file) {
            if ($file['mtime'] < $cutoff && @unlink($file['path'])) {
                $deleted++;
            }
        }

        // Leftovers from an interrupted run (older than a day).
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match(static::PARTIAL_PATTERN, $entry)) {
                $p = $dir . DIRECTORY_SEPARATOR . $entry;
                if (is_file($p) && filemtime($p) < time() - 86400) {
                    @unlink($p);
                }
            }
        }

        return $deleted;
    }

    /** @return array<int,array{name:string,path:string,mtime:int,size:int}> newest first */
    public static function listBackupFiles(?string $dir = null): array
    {
        $dir = $dir ?? static::directory();
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match(static::FILE_PATTERN, $entry)) {
                $p = $dir . DIRECTORY_SEPARATOR . $entry;
                if (is_file($p)) {
                    $out[] = ['name' => $entry, 'path' => $p, 'mtime' => (int) filemtime($p), 'size' => (int) filesize($p)];
                }
            }
        }
        usort($out, fn ($a, $b) => [$b['mtime'], $b['name']] <=> [$a['mtime'], $a['name']]);
        return $out;
    }

    // ---------------------------------------------------------------- status

    public static function status(): array
    {
        try {
            $file = static::directory() . DIRECTORY_SEPARATOR . static::STATUS_FILE;
        } catch (Throwable $e) {
            return ['last_status' => 'unknown', 'last_error' => $e->getMessage()];
        }
        if (!is_file($file)) {
            return ['last_status' => 'never_run'];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : ['last_status' => 'unknown'];
    }

    private static function writeStatus(string $dir, array $update): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . static::STATUS_FILE;
        $current = [];
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $current = is_array($decoded) ? $decoded : [];
        }
        $merged = array_merge($current, $update);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
    }

    // --------------------------------------------------------------- helpers

    private static function sanitizeLabel(string $label): string
    {
        $label = preg_replace('/[^A-Za-z0-9\-]+/', '-', $label) ?? '';
        return trim(substr($label, 0, 40), '-');
    }

    /** Remove the DB password (if any) from text before it is logged or stored. */
    private static function scrub(string $text): string
    {
        $password = (string) (config('database.connections.' . config('database.default') . '.password') ?? '');
        if ($password !== '') {
            $text = str_replace($password, '***', $text);
        }
        return $text;
    }
}
