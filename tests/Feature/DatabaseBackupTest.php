<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Automatic system-level backup: php artisan db:backup (run from cron).
 */
class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->dir = sys_get_temp_dir() . '/kis_bk_' . uniqid();
        config([
            'kisauni.backup.path' => $this->dir,
            'kisauni.backup.retention_days' => 90,
            'kisauni.backup.min_keep' => 7,
            'kisauni.backup.force_php_dump' => true, // deterministic on any machine
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            is_dir("$d/$e") ? $this->rrmdir("$d/$e") : @unlink("$d/$e");
        }
        @rmdir($d);
    }

    private function backupFiles(): array
    {
        return array_values(array_filter(scandir($this->dir), fn ($f) => str_ends_with($f, '.sql.gz')));
    }

    private function fakeBackup(string $name, int $ageDays): string
    {
        $p = $this->dir . '/' . $name;
        file_put_contents($p, 'x');
        touch($p, time() - $ageDays * 86400);
        return $p;
    }

    public function test_command_creates_a_verified_compressed_backup(): void
    {
        $this->artisan('db:backup')->assertExitCode(0);

        $files = $this->backupFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^kisauni_db_\d{8}_\d{6}\.sql\.gz$/', $files[0]);

        $sql = gzdecode(file_get_contents($this->dir . '/' . $files[0]));
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertMatchesRegularExpression('/CREATE TABLE [`"]?users[`"]?/i', $sql);
        $this->assertStringContainsString('-- Dump completed', $sql);
        $this->assertStringContainsString('headmaster', $sql, 'real rows must be in the dump');
    }

    public function test_backup_is_private_and_has_no_secrets_or_destructive_sql(): void
    {
        $this->artisan('db:backup')->assertExitCode(0);
        $file = $this->dir . '/' . $this->backupFiles()[0];

        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));

        $sql = gzdecode(file_get_contents($file));
        $this->assertStringNotContainsString(config('app.key') ?: 'no-key-set', $sql);
        $this->assertStringNotContainsStringIgnoringCase('APP_KEY', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bDROP\s+(TABLE|DATABASE)\b/i', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bTRUNCATE\b|\bDELETE\s+FROM\b/i', $sql);
        // volatile tables are not backed up
        $this->assertDoesNotMatchRegularExpression('/CREATE TABLE [`"]?sessions[`"]?/i', $sql);
    }

    public function test_backup_directory_must_be_outside_the_public_web_root(): void
    {
        $inside = public_path('kis_bk_test_' . uniqid());
        config(['kisauni.backup.path' => $inside]);
        try {
            $this->artisan('db:backup')->assertExitCode(1);
            $this->assertSame([], glob($inside . '/*.sql.gz') ?: []);
        } finally {
            $this->rrmdir($inside);
        }
    }

    public function test_running_a_backup_never_changes_application_data(): void
    {
        $before = ['u' => DB::table('users')->count(), 'c' => DB::table('classes')->count(), 's' => DB::table('subjects')->count()];
        $this->artisan('db:backup')->assertExitCode(0);
        $this->artisan('db:backup')->assertExitCode(0);
        $after = ['u' => DB::table('users')->count(), 'c' => DB::table('classes')->count(), 's' => DB::table('subjects')->count()];
        $this->assertSame($before, $after);
    }

    public function test_labels_are_sanitised_so_they_cannot_escape_the_directory(): void
    {
        $this->artisan('db:backup', ['--label' => '../../etc/passwd'])->assertExitCode(0);
        $files = $this->backupFiles();
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('/', $files[0]);
        $this->assertStringNotContainsString('..', $files[0]);
        $this->assertFileDoesNotExist(dirname($this->dir) . '/etc');
    }

    // ------------------------------------------------------------- retention

    public function test_retention_removes_only_old_backups_and_keeps_the_minimum(): void
    {
        DatabaseBackupService::directory();
        // 10 old backups (100..109 days) + 2 recent
        for ($i = 0; $i < 10; $i++) {
            $this->fakeBackup(sprintf('kisauni_db_2026010%d_010101.sql.gz', $i), 100 + $i);
        }
        $this->fakeBackup('kisauni_db_20260920_010101.sql.gz', 9);
        $this->fakeBackup('kisauni_db_20260925_010101.sql.gz', 4);
        // unrelated files must never be touched, however old
        $other = $this->fakeBackup('notes.txt', 400);
        $other2 = $this->fakeBackup('kisauni_db_20200101_000000.sql', 400); // wrong extension: not ours

        $deleted = DatabaseBackupService::prune($this->dir, 7, 90);

        // 12 backups, keep newest 7 => 5 candidates, all older than 90 days => 5 deleted
        $this->assertSame(5, $deleted);
        $this->assertCount(7, $this->backupFiles());
        $this->assertFileExists($this->dir . '/kisauni_db_20260920_010101.sql.gz');
        $this->assertFileExists($this->dir . '/kisauni_db_20260925_010101.sql.gz');
        $this->assertFileExists($other);
        $this->assertFileExists($other2);
    }

    public function test_retention_keeps_recent_backups_even_beyond_the_minimum_count(): void
    {
        DatabaseBackupService::directory();
        for ($i = 1; $i <= 12; $i++) {
            $this->fakeBackup(sprintf('kisauni_db_202609%02d_010101.sql.gz', $i), $i); // all < 90 days
        }
        $this->assertSame(0, DatabaseBackupService::prune($this->dir, 7, 90));
        $this->assertCount(12, $this->backupFiles());
    }

    public function test_retention_days_are_configurable_from_config(): void
    {
        DatabaseBackupService::directory();
        for ($i = 1; $i <= 10; $i++) {
            $this->fakeBackup(sprintf('kisauni_db_202608%02d_010101.sql.gz', $i), 40 + $i);
        }
        config(['kisauni.backup.retention_days' => 30, 'kisauni.backup.min_keep' => 3]);
        $this->assertSame(7, DatabaseBackupService::prune($this->dir));
        $this->assertCount(3, $this->backupFiles());
    }

    public function test_a_normal_run_applies_retention_after_the_new_backup_is_verified(): void
    {
        DatabaseBackupService::directory();
        for ($i = 0; $i < 9; $i++) {
            $this->fakeBackup(sprintf('kisauni_db_2026010%d_010101.sql.gz', $i), 120 + $i);
        }
        $this->artisan('db:backup')->assertExitCode(0);
        // 9 old + 1 new = 10; keep newest 7 => the 3 oldest (all > 90 days) are removed
        $this->assertCount(7, $this->backupFiles());
    }

    public function test_no_prune_option_leaves_old_backups_alone(): void
    {
        DatabaseBackupService::directory();
        for ($i = 0; $i < 9; $i++) {
            $this->fakeBackup(sprintf('kisauni_db_2026010%d_010101.sql.gz', $i), 120 + $i);
        }
        $this->artisan('db:backup', ['--no-prune' => true])->assertExitCode(0);
        $this->assertCount(10, $this->backupFiles());
    }

    // --------------------------------------------------------------- failure

    public function test_failed_backup_returns_non_zero_logs_and_keeps_old_backups(): void
    {
        DatabaseBackupService::directory();
        $old = $this->fakeBackup('kisauni_db_20260101_010101.sql.gz', 200);

        // Make the dump unusable: without the users table verification must fail.
        Schema::disableForeignKeyConstraints();
        Schema::drop('users');

        \Log::spy();
        $this->artisan('db:backup')->assertExitCode(1);

        \Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'Database backup FAILED'))->once();

        $this->assertFileExists($old, 'a failed run must not prune or touch existing backups');
        $this->assertSame([basename($old)], $this->backupFiles(), 'no half-written backup may be left as a valid file');
        $this->assertSame([], glob($this->dir . '/*.partial') ?: []);

        $status = DatabaseBackupService::status();
        $this->assertSame('failed', $status['last_status']);
        $this->assertNotEmpty($status['last_error']);
    }

    public function test_verify_rejects_truncated_or_invalid_files(): void
    {
        DatabaseBackupService::directory();

        $notGzip = $this->dir . '/bad.gz';
        file_put_contents($notGzip, str_repeat('not a gzip file ', 20));
        try {
            DatabaseBackupService::verifyDump($notGzip);
            $this->fail('invalid gzip must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('verification failed', $e->getMessage());
        }

        $truncated = $this->dir . '/cut.gz';
        $gz = gzopen($truncated, 'wb');
        gzwrite($gz, "CREATE TABLE `users` (id int);\nINSERT INTO `users` VALUES (1);\n-- but no completion marker, e.g. the server died half way through the dump\n");
        gzclose($gz);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incomplete');
        DatabaseBackupService::verifyDump($truncated);
    }

    public function test_status_command_reports_health_and_exit_codes(): void
    {
        $this->artisan('db:backup --status')->assertExitCode(1); // never run

        $this->artisan('db:backup')->assertExitCode(0);
        $this->artisan('db:backup --status')->assertExitCode(0);

        // pretend the last success was three days ago
        $file = $this->dir . '/' . DatabaseBackupService::STATUS_FILE;
        $s = json_decode(file_get_contents($file), true);
        $s['last_success_at'] = SchoolService::now()->subDays(3)->format('Y-m-d H:i:s');
        file_put_contents($file, json_encode($s));
        $this->artisan('db:backup --status')->assertExitCode(1);
        $this->artisan('db:backup', ['--status' => true, '--max-age-hours' => 100])->assertExitCode(0);
    }

    public function test_second_simultaneous_run_is_refused_not_corrupting(): void
    {
        DatabaseBackupService::directory();
        $lock = fopen($this->dir . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('db:backup')->assertExitCode(1);
            $this->assertSame([], $this->backupFiles());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
