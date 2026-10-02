<?php

// Values read from .env here (not with env() inside app code) so they keep
// working after `php artisan config:cache`, which deployment scripts run.
return [
    'idle_timeout_minutes'  => (int) env('IDLE_TIMEOUT_MINUTES', 10),
    'max_login_attempts'    => (int) env('MAX_LOGIN_ATTEMPTS', 5),
    'login_lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 5),
    'backup_min_keep'       => (int) env('BACKUP_MIN_KEEP', 3),
    'backup_retention_days' => (int) env('BACKUP_RETENTION_DAYS', 3),
    'backup_min_interval_hours' => (int) env('BACKUP_MIN_INTERVAL_HOURS', 20),
];
