<?php

// Values read from .env here (not with env() inside app code) so they keep
// working after `php artisan config:cache`, which deployment scripts run.
return [
    'idle_timeout_minutes'  => (int) env('IDLE_TIMEOUT_MINUTES', 10),
    'max_login_attempts'    => (int) env('MAX_LOGIN_ATTEMPTS', 5),
    'login_lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 5),
    // Automatic database backup (php artisan db:backup, run from cron).
    // Not a user-facing feature: no routes, menus or downloads.
    'backup' => [
        // Private folder OUTSIDE public_html. Empty = ../kisauni_backups next to the project.
        'path'            => env('BACKUP_PATH'),
        'retention_days'  => (int) env('BACKUP_RETENTION_DAYS', 90),
        'min_keep'        => (int) env('BACKUP_MIN_KEEP', 7),
        'mysqldump_path'  => env('BACKUP_MYSQLDUMP_PATH'),
        'timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 600),
        'force_php_dump'  => (bool) env('BACKUP_FORCE_PHP_DUMP', false),
    ],

    // Verification of the old Flask (Werkzeug scrypt) password hashes at login.
    // OFF by default: turn on only after `php artisan auth:scrypt-benchmark`
    // shows acceptable time/memory on the real hosting.
    'legacy_scrypt' => [
        'enabled'            => (bool) env('LEGACY_SCRYPT_ENABLED', false),
        'max_n'              => (int) env('LEGACY_SCRYPT_MAX_N', 65536),
        'max_memory_mb'      => (int) env('LEGACY_SCRYPT_MAX_MEMORY_MB', 128),
        'wait_seconds'       => (int) env('LEGACY_SCRYPT_WAIT_SECONDS', 30),
        'time_limit_seconds' => (int) env('LEGACY_SCRYPT_TIME_LIMIT', 60),
    ],
];
