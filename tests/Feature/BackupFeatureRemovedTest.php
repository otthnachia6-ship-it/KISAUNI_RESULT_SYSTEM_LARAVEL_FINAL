<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The in-app Backup / Download / Restore feature must be gone for every user.
 * (Automatic server-side backup is a cron command and is tested separately.)
 */
class BackupFeatureRemovedTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
    }

    public function test_no_registered_route_mentions_backup(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase('backup', $route->uri(), 'route ' . $route->uri());
            $this->assertStringNotContainsStringIgnoringCase('backup', (string) $route->getName());
            $this->assertStringNotContainsStringIgnoringCase('backup', $route->getActionName());
        }
        foreach (['create_backup_route', 'download_backup', 'restore_backup_route', 'upload_restore_backup_route', 'delete_backup_route'] as $name) {
            $this->assertFalse(Route::has($name), "$name still exists");
        }
    }

    public function test_backup_urls_are_gone_even_for_the_headmaster(): void
    {
        $this->actingAs($this->hm);
        $this->get('/settings/backup/download/kisauni_backup_x.sql')->assertNotFound();
        $this->get('/settings/backup/create')->assertStatus(404);
        foreach (['/settings/backup/create', '/settings/backup/restore/x.sql', '/settings/backup/upload-restore', '/settings/backup/delete/x.sql'] as $uri) {
            $this->assertContains($this->post($uri)->getStatusCode(), [404, 405], "POST $uri");
        }
    }

    public function test_backup_urls_stay_unreachable_for_guests(): void
    {
        $r = $this->get('/settings/backup/download/kisauni_backup_x.sql');
        $this->assertNotSame(200, $r->getStatusCode());
        $this->assertNotSame(200, $this->post('/settings/backup/create')->getStatusCode());
    }

    public function test_settings_and_dashboard_show_no_backup_controls(): void
    {
        $this->actingAs($this->hm);
        foreach (['/settings', '/dashboard'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/backup|Download one now|Restore/i', $html, $uri);
        }
        // the settings page still works for what it is for
        $this->get('/settings')->assertOk()->assertSee('School Name');
    }

    public function test_settings_can_still_be_saved(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->actingAs($this->hm)
            ->post('/settings', ['school_name' => 'KISAUNI PRIMARY SCHOOL', 'academic_year' => '2026'])
            ->assertRedirect(route('settings_page'));
    }

    public function test_old_backup_service_and_its_config_are_removed(): void
    {
        $this->assertFileDoesNotExist(app_path('Services/BackupService.php'));
        $this->assertFalse(class_exists(\App\Services\BackupService::class, false));
        $this->assertNull(config('kisauni.backup_min_interval_hours'));
        $this->assertNull(config('kisauni.backup_retention_days'));
    }

    public function test_cron_command_is_registered_but_not_a_web_feature(): void
    {
        $this->assertArrayHasKey('db:backup', \Illuminate\Support\Facades\Artisan::all());
    }
}
