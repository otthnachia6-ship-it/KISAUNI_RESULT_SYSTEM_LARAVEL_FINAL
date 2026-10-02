<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use App\Services\SchoolService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Automatic, system-level database backup. Meant for cPanel Cron - there is no
 * web page, route or download for it.
 *
 *   php artisan db:backup                 create a verified backup + apply retention
 *   php artisan db:backup --no-prune      create a backup, skip retention
 *   php artisan db:backup --label=manual  add a short label to the file name
 *   php artisan db:backup --status        show the last run; exit 1 if failed/stale
 *
 * Exit code is non-zero on any failure so cron can e-mail the error.
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
                            {--label= : Short label added to the file name (letters, digits, dashes)}
                            {--no-prune : Do not delete old backups after this run}
                            {--status : Only report the last backup status (exit 1 if failed or too old)}
                            {--max-age-hours=36 : With --status, how old the last success may be}';

    protected $description = 'Create an automatic, verified database backup in a private folder (for cron)';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->reportStatus();
        }

        try {
            $result = DatabaseBackupService::run((string) $this->option('label'), !$this->option('no-prune'));
        } catch (Throwable $e) {
            $this->error('Backup FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }

        $kb = number_format($result['size'] / 1024, 1);
        $this->info("Backup OK: {$result['file']} ({$kb} KB, method: {$result['method']}, {$result['seconds']}s, old backups removed: {$result['pruned']})");
        return self::SUCCESS;
    }

    private function reportStatus(): int
    {
        $s = DatabaseBackupService::status();
        $state = $s['last_status'] ?? 'unknown';

        $this->line('Last status:   ' . $state);
        $this->line('Last attempt:  ' . ($s['last_attempt_at'] ?? '-'));
        $this->line('Last success:  ' . ($s['last_success_at'] ?? '-'));
        $this->line('Last file:     ' . ($s['last_file'] ?? '-'));
        if (!empty($s['last_error'])) {
            $this->line('Last error:    ' . $s['last_error']);
        }

        if ($state === 'never_run' || empty($s['last_success_at'])) {
            $this->error('No successful backup recorded.');
            return self::FAILURE;
        }
        if ($state === 'failed') {
            $this->error('The most recent backup attempt FAILED.');
            return self::FAILURE;
        }

        $maxHours = max(1, (int) $this->option('max-age-hours'));
        $ageHours = Carbon::parse($s['last_success_at'], 'Africa/Dar_es_Salaam')->diffInHours(SchoolService::now(), true);
        if ($ageHours > $maxHours) {
            $this->error(sprintf('Last successful backup is %.1f hours old (limit %d).', $ageHours, $maxHours));
            return self::FAILURE;
        }

        $this->info(sprintf('Backups are healthy (last success %.1f hours ago).', $ageHours));
        return self::SUCCESS;
    }
}
