<?php

// Values read from .env here (not with env() inside app code) so they keep
// working after `php artisan config:cache`, which deployment scripts run.
return [
    'idle_timeout_minutes'  => (int) env('IDLE_TIMEOUT_MINUTES', 10),
    'max_login_attempts'    => (int) env('MAX_LOGIN_ATTEMPTS', 5),
    'login_lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 5),
    // Wrong passwords are counted per username AND device (IP address), so a stranger
    // cannot lock a teacher out of the school's shared Wi-Fi. This second, larger limit
    // stops one device from guessing across many usernames.
    'max_login_attempts_per_ip' => (int) env('MAX_LOGIN_ATTEMPTS_PER_IP', 50),
    // Shortest password accepted when a password is set or changed (existing passwords keep working).
    'min_password_length'   => (int) env('MIN_PASSWORD_LENGTH', 4),
    // How long audit-log entries are kept. Old entries are removed at most once a day.
    // Optional contact (phone / e-mail) shown on the maintenance and suspended pages. Empty = not shown.
    'support_contact' => env('SUPPORT_CONTACT'),
    // One-time migration helper: copy uploaded files (logo, profile photos) that are referenced in
    // the database but missing on this server, by fetching them from the old server over HTTPS.
    // 'host' is the old server's IP; 'domain' is the Host header it answers on. Empty host = disabled.
    'uploads_restore_host'   => env('UPLOADS_RESTORE_HOST', '151.242.3.80'),
    'uploads_restore_domain' => env('UPLOADS_RESTORE_DOMAIN', 'kisaunips.ostexs.com'),
    'audit_log_retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 5),
    // Automatic database backup (php artisan db:backup, run from cron).
    // Also available to the Developer account only (Developer Control Center:
    // create + download). School accounts see no backup pages.
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
