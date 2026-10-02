<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BackupService
{
    public const BACKUP_FILENAME_PREFIX = 'kisauni_backup_';

    public static function getBackupDir(): string
    {
        $dir = storage_path('backups');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true, true);
        }
        return $dir;
    }

    public static function listBackups(): array
    {
        $dir = static::getBackupDir();
        $files = File::glob($dir . DIRECTORY_SEPARATOR . static::BACKUP_FILENAME_PREFIX . '*');

        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        $out = [];
        foreach ($files as $f) {
            $mtime = filemtime($f);
            $size = filesize($f);
            $dt = Carbon::createFromTimestamp($mtime, 'Africa/Dar_es_Salaam');
            $out[] = [
                'filename' => basename($f),
                'size_kb' => round($size / 1024, 1),
                'created_at' => $dt->format('Y-m-d H:i:s'),
                'mtime' => $mtime,
            ];
        }
        return $out;
    }

    public static function createBackup(string $reason = 'manual'): ?string
    {
        $dir = static::getBackupDir();
        $safeReason = preg_replace('/[^a-zA-Z0-9_\-]/', '_', substr($reason, 0, 40)) ?: 'manual';
        $ts = SchoolService::now()->format('Ymd_His');

        $driver = config('database.default');

        if ($driver === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            if (!File::exists($dbPath)) {
                return null;
            }
            $filename = static::BACKUP_FILENAME_PREFIX . "{$ts}_{$safeReason}.db";
            $dest = $dir . DIRECTORY_SEPARATOR . $filename;
            File::copy($dbPath, $dest);
            static::pruneOldBackups();
            return $filename;
        }

        // MySQL database export
        $filename = static::BACKUP_FILENAME_PREFIX . "{$ts}_{$safeReason}.sql";
        $dest = $dir . DIRECTORY_SEPARATOR . $filename;

        $host = config('database.connections.mysql.host', '127.0.0.1');
        $port = config('database.connections.mysql.port', '3306');
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        // Check if mysqldump is available
        $mysqldump = trim(shell_exec('which mysqldump 2>/dev/null') ?: '');
        if ($mysqldump && $database) {
            $cmd = sprintf(
                'timeout 120 %s --host=%s --port=%s --user=%s %s --single-transaction --quick --skip-lock-tables --skip-add-locks %s > %s 2>/dev/null',
                escapeshellcmd($mysqldump),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                $password !== '' ? '--password=' . escapeshellarg($password) : '',
                escapeshellarg($database),
                escapeshellarg($dest)
            );
            exec($cmd, $output, $returnVar);
            if ($returnVar === 0 && File::exists($dest) && filesize($dest) > 0) {
                static::pruneOldBackups();
                return $filename;
            }
        }

        // Native PHP SQL dump fallback for MySQL
        try {
            $tables = DB::select('SHOW TABLES');
            $dbKey = "Tables_in_{$database}";
            $handle = fopen($dest, 'w');
            fwrite($handle, "-- Kisauni School Result System SQL Dump\n");
            fwrite($handle, "-- Generated: " . SchoolService::nowStr() . "\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $tbl) {
                $tableName = $tbl->$dbKey ?? array_values((array)$tbl)[0];
                $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`");
                $createSql = $createTable[0]->{'Create Table'} ?? null;
                if ($createSql) {
                    fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n");
                    fwrite($handle, $createSql . ";\n\n");

                    $rows = DB::table($tableName)->get();
                    foreach ($rows as $row) {
                        $rowArr = (array)$row;
                        $cols = array_map(fn($c) => "`{$c}`", array_keys($rowArr));
                        $vals = array_map(function ($v) {
                            if ($v === null) return 'NULL';
                            return "'" . addslashes((string)$v) . "'";
                        }, array_values($rowArr));
                        fwrite($handle, "INSERT INTO `{$tableName}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n");
                    }
                    fwrite($handle, "\n");
                }
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);

            static::pruneOldBackups();
            return $filename;
        } catch (\Throwable $e) {
            if (File::exists($dest)) {
                File::delete($dest);
            }
            return null;
        }
    }

    public static function pruneOldBackups(?int $keep = null, ?int $retentionDays = null): void
    {
        $keep = $keep ?? config('kisauni.backup_min_keep');
        $retentionDays = $retentionDays ?? config('kisauni.backup_retention_days');

        $files = static::listBackups();
        if (count($files) <= $keep) {
            return;
        }

        $candidates = array_slice($files, $keep);
        $cutoff = SchoolService::now()->timestamp - ($retentionDays * 86400);

        $dir = static::getBackupDir();
        foreach ($candidates as $c) {
            if ($c['mtime'] < $cutoff) {
                $path = $dir . DIRECTORY_SEPARATOR . $c['filename'];
                if (File::exists($path)) {
                    File::delete($path);
                }
            }
        }
    }

    public static function shouldRunPeriodicBackup(): bool
    {
        $files = static::listBackups();
        if (empty($files)) {
            return true;
        }
        $newest = $files[0]['mtime'];
        $ageHours = (SchoolService::now()->timestamp - $newest) / 3600;
        return $ageHours >= config('kisauni.backup_min_interval_hours');
    }

    public static function restoreBackup(string $filename): bool
    {
        $safeName = basename($filename);
        if (!str_starts_with($safeName, static::BACKUP_FILENAME_PREFIX)) {
            return false;
        }
        $dir = static::getBackupDir();
        $src = $dir . DIRECTORY_SEPARATOR . $safeName;
        if (!File::exists($src)) {
            return false;
        }

        // Take a safety backup first
        static::createBackup('before_restore');

        $driver = config('database.default');
        if ($driver === 'sqlite') {
            $dest = config('database.connections.sqlite.database');
            File::copy($src, $dest);
            return true;
        }

        // MySQL restore
        $host = config('database.connections.mysql.host', '127.0.0.1');
        $port = config('database.connections.mysql.port', '3306');
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        $mysql = trim(shell_exec('which mysql 2>/dev/null') ?: '');
        if ($mysql && $database) {
            $cmd = sprintf(
                '%s --host=%s --port=%s --user=%s %s %s < %s 2>/dev/null',
                escapeshellcmd($mysql),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                $password !== '' ? '--password=' . escapeshellarg($password) : '',
                escapeshellarg($database),
                escapeshellarg($src)
            );
            exec($cmd, $output, $returnVar);
            if ($returnVar === 0) {
                return true;
            }
        }

        // Fallback: run SQL commands
        $sql = File::get($src);
        DB::unprepared($sql);
        return true;
    }

    public static function restoreBackupFromUpload($uploadedFile): array
    {
        $dir = static::getBackupDir();
        $ext = strtolower($uploadedFile->getClientOriginalExtension());
        if (!in_array($ext, ['db', 'sqlite', 'sql'], true)) {
            return [false, 'That doesn’t look like a valid database backup file (expected .sql or .db file).'];
        }

        $tmpName = '_uploaded_tmp_' . time() . '.' . $ext;
        $tmpPath = $dir . DIRECTORY_SEPARATOR . $tmpName;
        $uploadedFile->move($dir, $tmpName);

        // Validation
        if ($ext === 'sql') {
            $content = File::get($tmpPath, false);
            if (!str_contains($content, 'students') || !str_contains($content, 'marks')) {
                File::delete($tmpPath);
                return [false, "That file doesn't look like a Kisauni Result System backup (missing expected tables)."];
            }
        }

        static::createBackup('before_restore_upload');

        $ts = SchoolService::now()->format('Ymd_His');
        $keptName = static::BACKUP_FILENAME_PREFIX . "{$ts}_uploaded.{$ext}";
        $keptPath = $dir . DIRECTORY_SEPARATOR . $keptName;
        File::move($tmpPath, $keptPath);

        $ok = static::restoreBackup($keptName);
        if ($ok) {
            static::pruneOldBackups();
            return [true, null];
        }

        return [false, 'Failed to restore database from uploaded file.'];
    }

    public static function deleteBackup(string $filename): bool
    {
        $safeName = basename($filename);
        if (!str_starts_with($safeName, static::BACKUP_FILENAME_PREFIX)) {
            return false;
        }
        $path = static::getBackupDir() . DIRECTORY_SEPARATOR . $safeName;
        if (File::exists($path)) {
            return File::delete($path);
        }
        return false;
    }

    public static function daysSinceLastOffsiteDownload(): ?int
    {
        $last = \App\Models\AuditLog::where('action', 'DOWNLOAD_BACKUP')->orderBy('id', 'desc')->first();
        if (!$last || !$last->created_at) {
            return null;
        }
        try {
            $dt = Carbon::parse($last->created_at, 'Africa/Dar_es_Salaam');
            // Carbon 3 changed diffIn*() to be signed (negative when the
            // argument is in the past) and to return floats by default.
            // absolute:true restores the old "always positive" behaviour,
            // and the (int) cast restores the declared ?int return type.
            return (int) round(SchoolService::now()->diffInDays($dt, true));
        } catch (\Throwable) {
            return null;
        }
    }
}
