<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only health information for the Developer Control Center.
 *
 * Rules:
 *  - Only values that PHP / Laravel / the database actually report are shown.
 *    When the host does not expose a metric (disabled function, Windows, no permission)
 *    the check says "unavailable" instead of inventing a number.
 *  - Never returns secrets: no .env values, DB host/user/password, APP_KEY or session ids.
 *  - Never changes anything.
 *
 * Every check is ['label', 'value', 'status', 'note'] with status one of the constants below.
 */
class SystemHealthService
{
    public const HEALTHY = 'healthy';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';
    public const UNAVAILABLE = 'unavailable';
    public const NOT_CONFIGURED = 'not_configured';

    public const STATUS_LABELS = [
        self::HEALTHY => 'Healthy',
        self::WARNING => 'Warning',
        self::CRITICAL => 'Critical',
        self::UNAVAILABLE => 'Unavailable',
        self::NOT_CONFIGURED => 'Not configured',
    ];

    public const SECTIONS = [
        'health' => 'Application Health',
        'database' => 'Database Monitoring',
        'server' => 'Server Status',
        'storage' => 'Storage Monitoring',
        'errors' => 'Error Monitoring',
        'performance' => 'Performance',
    ];

    private static function check(string $label, $value, string $status, ?string $note = null): array
    {
        return ['label' => $label, 'value' => $value, 'status' => $status, 'note' => $note];
    }

    /** Worst status of a list of checks (for summary cards). */
    public static function overall(array $checks): string
    {
        $order = [self::CRITICAL, self::WARNING, self::HEALTHY];
        $statuses = array_column($checks, 'status');
        foreach ($order as $s) {
            if (in_array($s, $statuses, true)) {
                return $s;
            }
        }

        return self::UNAVAILABLE;
    }

    public static function section(string $section): array
    {
        return match ($section) {
            'health' => static::application(),
            'database' => static::database(),
            'server' => static::server(),
            'storage' => static::storage(),
            'errors' => static::errors(),
            'performance' => static::performance(),
            default => [],
        };
    }

    // ------------------------------------------------------------ application

    public static function application(): array
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $checks = [
            static::check('Laravel application', 'Running (this page was served)', self::HEALTHY),
            static::check('Environment', $env, $env === 'production' ? self::HEALTHY : self::WARNING,
                $env === 'production' ? null : 'APP_ENV is not "production".'),
            static::check('Debug mode', $debug ? 'ON' : 'OFF',
                $debug ? ($env === 'production' ? self::CRITICAL : self::WARNING) : self::HEALTHY,
                $debug ? 'Debug pages can reveal internal details. Set APP_DEBUG=false on the live server.' : null),
            static::check('Application key', config('app.key') ? 'Set' : 'Missing',
                config('app.key') ? self::HEALTHY : self::CRITICAL, config('app.key') ? null : 'Run php artisan key:generate.'),
            static::check('Maintenance mode', app()->isDownForMaintenance() ? 'ON' : 'OFF',
                app()->isDownForMaintenance() ? self::WARNING : self::HEALTHY),
        ];

        $db = static::databasePing();
        $checks[] = static::check('Database connection', $db['ok'] ? 'Connected' : 'Failed',
            $db['ok'] ? self::HEALTHY : self::CRITICAL, $db['ok'] ? null : $db['error']);

        foreach (['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache')] as $name => $path) {
            $w = is_dir($path) && is_writable($path);
            $checks[] = static::check("Folder {$name} writable", $w ? 'Yes' : 'No', $w ? self::HEALTHY : self::CRITICAL);
        }

        $checks[] = static::backupHealth();

        return $checks;
    }

    /** Backup health from the status file kept by DatabaseBackupService. */
    public static function backupHealth(): array
    {
        try {
            $s = DatabaseBackupService::status();
        } catch (Throwable $e) {
            return static::check('Database backups', 'Unknown', self::UNAVAILABLE, 'Backup status could not be read.');
        }
        $state = $s['last_status'] ?? 'unknown';
        if ($state === 'never_run') {
            return static::check('Database backups', 'No backup made yet', self::NOT_CONFIGURED, 'Create one under Database & Backup, and schedule php artisan db:backup in cron.');
        }
        if ($state === 'failed') {
            return static::check('Database backups', 'Last attempt FAILED', self::CRITICAL, $s['last_error'] ?? null);
        }
        if (!empty($s['last_success_at'])) {
            try {
                $hours = \Carbon\Carbon::parse($s['last_success_at'], 'Africa/Dar_es_Salaam')->diffInHours(SchoolService::now(), true);
            } catch (Throwable) {
                $hours = null;
            }
            if ($hours !== null) {
                return static::check('Database backups', 'Last success ' . $s['last_success_at'],
                    $hours > 36 ? self::WARNING : self::HEALTHY, $hours > 36 ? sprintf('Last backup is %.0f hours old.', $hours) : null);
            }
        }

        return static::check('Database backups', 'Unknown', self::UNAVAILABLE);
    }

    // --------------------------------------------------------------- database

    /** @return array{ok:bool,ms:?float,error:?string} */
    public static function databasePing(): array
    {
        try {
            $t = microtime(true);
            DB::select('select 1');

            return ['ok' => true, 'ms' => round((microtime(true) - $t) * 1000, 2), 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'ms' => null, 'error' => 'The database did not answer (details are in the error log).'];
        }
    }

    public static function database(): array
    {
        $ping = static::databasePing();
        $driver = (string) DB::connection()->getDriverName();
        $checks = [
            static::check('Connection', $ping['ok'] ? 'Connected' : 'Failed', $ping['ok'] ? self::HEALTHY : self::CRITICAL, $ping['error']),
            static::check('Driver', $driver, self::HEALTHY),
        ];
        if (!$ping['ok']) {
            return $checks;
        }

        $checks[] = static::check('Query response time', $ping['ms'] . ' ms',
            $ping['ms'] > 500 ? self::WARNING : self::HEALTHY);

        try {
            $version = $driver === 'sqlite'
                ? DB::selectOne('select sqlite_version() as v')->v
                : DB::selectOne('select version() as v')->v;
            $checks[] = static::check('Server version', $version, self::HEALTHY);
        } catch (Throwable) {
            $checks[] = static::check('Server version', '-', self::UNAVAILABLE);
        }

        $size = static::databaseSizeBytes();
        $checks[] = $size === null
            ? static::check('Database size', '-', self::UNAVAILABLE, 'The database server does not report its size to this account.')
            : static::check('Database size', static::bytes($size), self::HEALTHY);

        try {
            $ran = DB::table('migrations')->count();
            $files = count(glob(database_path('migrations/*.php')) ?: []);
            $checks[] = static::check('Migrations', "{$ran} run / {$files} files", $ran >= $files ? self::HEALTHY : self::WARNING,
                $ran >= $files ? null : 'Some migrations have not been run on this database.');
        } catch (Throwable) {
            $checks[] = static::check('Migrations', '-', self::UNAVAILABLE);
        }

        return $checks;
    }

    public static function databaseSizeBytes(): ?int
    {
        try {
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne('select sum(data_length + index_length) as s from information_schema.tables where table_schema = database()');

                return $row && $row->s !== null ? (int) $row->s : null;
            }
            if ($driver === 'sqlite') {
                $file = (string) DB::connection()->getDatabaseName();

                return is_file($file) ? (int) filesize($file) : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /** Application tables with their row counts (no data, no secrets). */
    public static function tableCounts(): array
    {
        $out = [];
        $tables = [
            'students' => 'Students', 'classes' => 'Classes', 'subjects' => 'Subjects', 'class_subjects' => 'Class subjects',
            'examinations' => 'Examinations', 'marks' => 'Marks', 'exam_class_status' => 'Result statuses',
            'users' => 'User accounts', 'settings' => 'Settings', 'audit_log' => 'Audit log entries',
        ];
        foreach ($tables as $table => $label) {
            try {
                $out[] = ['table' => $table, 'label' => $label, 'rows' => Schema::hasTable($table) ? DB::table($table)->count() : null];
            } catch (Throwable) {
                $out[] = ['table' => $table, 'label' => $label, 'rows' => null];
            }
        }

        return $out;
    }

    // ----------------------------------------------------------------- server

    public static function server(): array
    {
        $checks = [
            static::check('PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>=') ? self::HEALTHY : self::CRITICAL),
            static::check('Laravel version', app()->version(), self::HEALTHY),
            static::check('PHP interface (SAPI)', PHP_SAPI, self::HEALTHY),
            static::check('Operating system', PHP_OS_FAMILY, self::HEALTHY),
            static::check('Web server', static::serverSoftware() ?? '-', static::serverSoftware() ? self::HEALTHY : self::UNAVAILABLE),
            static::check('Time zone (app)', (string) config('app.timezone') . ' / school clock ' . SchoolService::nowStr(), self::HEALTHY),
            static::check('PHP memory limit', (string) ini_get('memory_limit'), self::HEALTHY),
            static::check('Max execution time', ini_get('max_execution_time') . ' s', self::HEALTHY),
            static::check('Upload max file size', (string) ini_get('upload_max_filesize'), self::HEALTHY),
            static::check('Post max size', (string) ini_get('post_max_size'), self::HEALTHY),
        ];

        $load = static::loadAverage();
        $checks[] = $load === null
            ? static::check('Server load average', '-', self::UNAVAILABLE, 'Not reported by this host (e.g. Windows or a disabled function).')
            : static::check('Server load average (1/5/15 min)', implode(' / ', array_map(fn ($v) => number_format($v, 2), $load)), self::HEALTHY);

        foreach (['pdo_mysql', 'pdo_sqlite', 'mbstring', 'openssl', 'zip', 'gd', 'fileinfo', 'zlib'] as $ext) {
            $loaded = extension_loaded($ext);
            $required = in_array($ext, ['mbstring', 'openssl', 'zip', 'fileinfo', 'zlib'], true);
            $checks[] = static::check("Extension {$ext}", $loaded ? 'Loaded' : 'Missing',
                $loaded ? self::HEALTHY : ($required ? self::CRITICAL : self::NOT_CONFIGURED));
        }

        return $checks;
    }

    private static function serverSoftware(): ?string
    {
        $s = request()->server('SERVER_SOFTWARE');

        return $s ? mb_substr((string) $s, 0, 80) : null;
    }

    private static function loadAverage(): ?array
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('sys_getloadavg')) {
            return null;
        }
        $l = @sys_getloadavg();

        return is_array($l) && count($l) === 3 ? $l : null;
    }

    // ---------------------------------------------------------------- storage

    public static function storage(): array
    {
        $checks = [];
        $free = function_exists('disk_free_space') ? @disk_free_space(base_path()) : false;
        $total = function_exists('disk_total_space') ? @disk_total_space(base_path()) : false;
        if ($free === false || $total === false || $total <= 0) {
            $checks[] = static::check('Disk space', '-', self::UNAVAILABLE, 'The hosting account does not report disk space to PHP.');
        } else {
            $pct = $free / $total * 100;
            $checks[] = static::check('Disk free', static::bytes((int) $free) . ' of ' . static::bytes((int) $total) . sprintf(' (%.1f%% free)', $pct),
                $pct < 10 ? self::CRITICAL : ($pct < 20 ? self::WARNING : self::HEALTHY));
        }

        $folders = [
            'Log files (storage/logs)' => storage_path('logs'),
            'Uploaded photos (storage/app/uploads)' => storage_path('app/uploads'),
            'Compiled views & cache (storage/framework)' => storage_path('framework'),
        ];
        try {
            $folders['Backups (private folder)'] = DatabaseBackupService::directory();
        } catch (Throwable) {
            $checks[] = static::check('Backups (private folder)', '-', self::NOT_CONFIGURED, 'No writable backup folder. Set BACKUP_PATH.');
        }
        foreach ($folders as $label => $path) {
            $size = static::folderSize($path);
            $checks[] = $size === null
                ? static::check($label, 'Folder not found', self::UNAVAILABLE)
                : static::check($label, static::bytes($size), self::HEALTHY);
        }

        return $checks;
    }

    public static function folderSize(string $path, int $maxFiles = 20000): ?int
    {
        if (!is_dir($path)) {
            return null;
        }
        $size = 0;
        $n = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $size += $f->getSize();
                }
                if (++$n >= $maxFiles) {
                    break;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return $size;
    }

    // ----------------------------------------------------------------- errors

    /** Newest Laravel log file (single or daily channel), or null. */
    public static function logFile(): ?string
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    public static function errors(): array
    {
        $file = static::logFile();
        if (!$file) {
            return [static::check('Error log', 'No log file yet', self::HEALTHY, 'Nothing has been logged.')];
        }
        $entries = static::recentLogEntries();
        $errors = array_filter($entries, fn ($e) => in_array($e['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true));
        $last24 = array_filter($errors, fn ($e) => strtotime($e['time']) >= time() - 86400);
        $size = (int) filesize($file);

        return [
            static::check('Log file', basename($file) . ' (' . static::bytes($size) . ')', $size > 50 * 1024 * 1024 ? self::WARNING : self::HEALTHY,
                $size > 50 * 1024 * 1024 ? 'The log file is large; consider archiving it.' : null),
            static::check('Last modified', date('Y-m-d H:i:s', (int) filemtime($file)), self::HEALTHY),
            static::check('Errors in last 24 hours (recent part of log)', (string) count($last24), count($last24) > 0 ? self::WARNING : self::HEALTHY),
        ];
    }

    /**
     * The last log entries (only the tail of the file is read). Each message is cut to
     * one line of 300 characters and known secrets are masked.
     *
     * @return array<int,array{time:string,level:string,message:string}> newest first
     */
    public static function recentLogEntries(int $limit = 50, int $tailBytes = 262144): array
    {
        $file = static::logFile();
        if (!$file || !is_readable($file)) {
            return [];
        }
        $size = (int) filesize($file);
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return [];
        }
        fseek($fh, max(0, $size - $tailBytes));
        $text = (string) stream_get_contents($fh);
        fclose($fh);

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+\w+\.(\w+):\s?(.*)$/m', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach (array_reverse($m) as $row) {
            $out[] = ['time' => $row[1], 'level' => strtoupper($row[2]), 'message' => static::mask(mb_substr($row[3], 0, 300))];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Hide values that must never be shown, even if they ended up in a log line. */
    public static function mask(string $text): string
    {
        $secrets = array_filter([
            (string) config('database.connections.' . config('database.default') . '.password'),
            (string) config('app.key'),
            (string) config('mail.mailers.smtp.password'),
        ], fn ($s) => strlen($s) >= 4);
        foreach ($secrets as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return $text;
    }

    // ------------------------------------------------------------ performance

    public static function performance(): array
    {
        $ping = static::databasePing();
        $start = defined('LARAVEL_START') ? LARAVEL_START : null;
        $checks = [
            static::check('This page: time so far', $start ? round((microtime(true) - $start) * 1000) . ' ms' : '-', $start ? self::HEALTHY : self::UNAVAILABLE),
            static::check('This page: peak memory', static::bytes(memory_get_peak_usage(true)), self::HEALTHY),
            static::check('Database round trip', $ping['ok'] ? $ping['ms'] . ' ms' : 'Failed', $ping['ok'] ? ($ping['ms'] > 500 ? self::WARNING : self::HEALTHY) : self::CRITICAL),
            static::check('Configuration cache', app()->configurationIsCached() ? 'Cached' : 'Not cached',
                app()->configurationIsCached() ? self::HEALTHY : self::WARNING, app()->configurationIsCached() ? null : 'php artisan config:cache speeds up the live site.'),
            static::check('Route cache', app()->routesAreCached() ? 'Cached' : 'Not cached', app()->routesAreCached() ? self::HEALTHY : self::WARNING),
            static::check('Cache driver', (string) config('cache.default'), self::HEALTHY),
            static::check('Session driver', (string) config('session.driver'), self::HEALTHY),
        ];

        $opcache = null;
        if (function_exists('opcache_get_status')) {
            $st = @opcache_get_status(false);
            $opcache = is_array($st) ? $st : null;
        }
        if ($opcache === null) {
            $checks[] = static::check('PHP OPcache', '-', self::UNAVAILABLE, 'OPcache is off or its status is not available on this host.');
        } else {
            $enabled = !empty($opcache['opcache_enabled']);
            $hit = $opcache['opcache_statistics']['opcache_hit_rate'] ?? null;
            $checks[] = static::check('PHP OPcache', $enabled ? 'Enabled' . ($hit !== null ? sprintf(' (hit rate %.1f%%)', $hit) : '') : 'Disabled',
                $enabled ? self::HEALTHY : self::WARNING);
        }

        return $checks;
    }

    // ---------------------------------------------------------------- helpers

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $bytes : number_format($bytes, 1)) . ' ' . $units[$i];
    }
}
