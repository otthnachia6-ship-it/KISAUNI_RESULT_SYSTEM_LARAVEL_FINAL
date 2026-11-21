<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Developer Control Center: run pending migrations from the web, always after a verified backup. */
class DeveloperMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const PENDING = '2026_11_19_000001_add_performance_indexes';

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        config(['kisauni.backup.force_php_dump' => true]);
        $this->dev = User::create(['username' => 'devuser', 'password' => Hash::make('Dev-pass-1234'), 'full_name' => 'Dev',
            'role' => 'developer', 'active' => 1, 'must_change_password' => 0]);
    }

    /** Make the live-server situation: one migration file not recorded as run (its index is safe to re-add). */
    private function makePending(): void
    {
        DB::table('migrations')->where('migration', self::PENDING)->delete();
    }

    public function test_pending_migrations_are_listed_and_run_after_a_verified_backup(): void
    {
        $this->makePending();
        $this->actingAs($this->dev)->get('/developer/database')->assertOk()
            ->assertSee('Pending migrations (1)')->assertSee(self::PENDING);

        $this->actingAs($this->dev)->post('/developer/database/migrate')
            ->assertRedirect(route('developer.backups.database'))->assertSessionHas('success');

        $this->assertTrue(DB::table('migrations')->where('migration', self::PENDING)->exists());
        $backups = DatabaseBackupService::listBackupFiles();
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('before-migrate', $backups[0]['name']);
        $this->assertTrue(AuditLog::where('action', 'RUN_MIGRATIONS')->where('details', 'like', '%' . self::PENDING . '%')->exists());

        $this->actingAs($this->dev)->get('/developer/database')->assertOk()->assertSee('All database migrations have been applied');
    }

    public function test_nothing_pending_changes_nothing(): void
    {
        $this->actingAs($this->dev)->post('/developer/database/migrate')->assertSessionHas('info');
        $this->assertCount(0, DatabaseBackupService::listBackupFiles());
    }

    public function test_school_accounts_cannot_run_migrations(): void
    {
        $this->makePending();
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $this->actingAs($hm)->post('/developer/database/migrate')->assertNotFound();
        $this->assertFalse(DB::table('migrations')->where('migration', self::PENDING)->exists());
    }

    public function test_cache_checks_are_informational_not_warnings(): void
    {
        $checks = collect(SystemHealthService::performance())->keyBy('label');
        $this->assertNotSame(SystemHealthService::WARNING, $checks['Configuration cache']['status']);
        $this->assertNotSame(SystemHealthService::WARNING, $checks['Route cache']['status']);
    }
}
