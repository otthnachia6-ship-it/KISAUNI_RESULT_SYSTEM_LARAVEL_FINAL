<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Developer Control Center: create, verify, list and download database backups. */
class DeveloperBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $dev;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->dir = sys_get_temp_dir() . '/kis_devbk_' . uniqid();
        config(['kisauni.backup.path' => $this->dir, 'kisauni.backup.force_php_dump' => true]);
        $this->dev = User::create(['username' => 'devuser', 'password' => Hash::make('Dev-pass-1234'), 'full_name' => 'System Developer',
            'role' => 'developer', 'active' => 1, 'must_change_password' => 0]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function createBackup(): string
    {
        $this->actingAs($this->dev)->post('/developer/backups/create')->assertRedirect(route('developer.backups.downloads'))->assertSessionHas('success');
        $files = DatabaseBackupService::listBackupFiles();
        $this->assertCount(1, $files);

        return $files[0]['name'];
    }

    public function test_developer_creates_a_verified_backup_and_it_is_audited(): void
    {
        $name = $this->createBackup();
        $this->assertMatchesRegularExpression('/^kisauni_db_\d{8}_\d{6}_developer\.sql\.gz$/', $name);
        DatabaseBackupService::verifyDump($this->dir . '/' . $name); // throws if not valid
        $this->assertTrue(AuditLog::where('action', 'CREATE_BACKUP')->where('username', 'devuser')->exists());
        $this->assertSame('success', DatabaseBackupService::status()['last_status']);
    }

    public function test_download_sends_the_verified_file_and_changes_no_data(): void
    {
        Student::create(['reg_no' => 'Z1', 'full_name' => 'Kept Pupil', 'gender' => 'Male', 'class_id' => 1, 'active' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $name = $this->createBackup();
        $before = [Student::count(), User::count()];

        $r = $this->actingAs($this->dev)->get("/developer/backups/download/{$name}");
        $r->assertOk();
        $this->assertStringContainsString($name, (string) $r->headers->get('content-disposition'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('cache-control'));
        $this->assertSame($before, [Student::count(), User::count()]);
        $this->assertTrue(AuditLog::where('action', 'DOWNLOAD_BACKUP')->exists());
    }

    public function test_history_status_and_list_pages_show_the_backup(): void
    {
        $name = $this->createBackup();
        foreach (['/developer/backups/download', '/developer/backups/history', '/developer/backups/create'] as $uri) {
            $this->actingAs($this->dev)->get($uri)->assertOk()->assertSee($name);
        }
        $this->actingAs($this->dev)->get('/developer/backups/status')->assertOk()->assertSee('success');
    }

    public function test_download_refuses_unknown_names_and_path_traversal(): void
    {
        $this->createBackup();
        file_put_contents($this->dir . '/secret.txt', 'secret');
        $this->actingAs($this->dev);
        $this->get('/developer/backups/download/kisauni_db_20000101_000000.sql.gz')->assertNotFound();
        $this->get('/developer/backups/download/..%2F..%2F.env')->assertNotFound();
        $this->get('/developer/backups/download/status.json')->assertNotFound();
        $this->get('/developer/backups/download/secret.txt')->assertNotFound();
        $this->assertNull(DatabaseBackupService::findBackup('../kisauni_db_20000101_000000.sql.gz'));
        $this->assertNull(DatabaseBackupService::findBackup('status.json'));
    }

    public function test_a_corrupted_backup_is_not_downloaded(): void
    {
        $name = $this->createBackup();
        file_put_contents($this->dir . '/' . $name, gzencode("CREATE TABLE users (id int);\n-- cut short"));
        $this->actingAs($this->dev)->get("/developer/backups/download/{$name}")
            ->assertRedirect(route('developer.backups.downloads'))->assertSessionHas('danger');
        $this->assertTrue(AuditLog::where('action', 'DOWNLOAD_BACKUP_REFUSED')->exists());
    }

    public function test_school_accounts_cannot_create_or_download_backups(): void
    {
        $name = $this->createBackup();
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $this->actingAs($hm)->get("/developer/backups/download/{$name}")->assertNotFound();
        $this->actingAs($hm)->post('/developer/backups/create')->assertNotFound();
        $this->assertCount(1, DatabaseBackupService::listBackupFiles());
        auth()->logout();
        $this->get("/developer/backups/download/{$name}")->assertRedirect(route('login'));
    }
}
