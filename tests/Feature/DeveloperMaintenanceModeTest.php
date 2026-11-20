<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Developer Control Center: maintenance mode with a Developer bypass. */
class DeveloperMaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private User $dev;
    private User $hm;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolated storage folder: these tests must never read or change the maintenance
        // state (storage/framework/down) of a real running copy of the application.
        $this->storage = sys_get_temp_dir() . '/kis_maint_' . uniqid();
        @mkdir($this->storage . '/framework', 0777, true);
        $this->app->useStoragePath($this->storage);
        $this->assertFalse($this->app->isDownForMaintenance());
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0, 'password' => Hash::make('Head-pass-123')]);
        $this->dev = User::create(['username' => 'devuser', 'password' => Hash::make('Dev-pass-1234'), 'full_name' => 'System Developer',
            'role' => 'developer', 'active' => 1, 'must_change_password' => 0]);
    }

    protected function tearDown(): void
    {
        @unlink($this->storage . '/framework/down');
        @unlink($this->storage . '/framework/maintenance.php');
        @rmdir($this->storage . '/framework');
        @rmdir($this->storage);
        parent::tearDown();
    }

    public function test_developer_switches_maintenance_on_keeps_access_and_switches_it_off(): void
    {
        $r = $this->actingAs($this->dev)->post('/developer/maintenance/mode/on', ['page' => 'updating']);
        $r->assertRedirect(route('developer.maintenance.mode'))->assertSessionHas('warning');
        $this->assertTrue($this->app->isDownForMaintenance());
        $this->assertTrue(AuditLog::where('action', 'MAINTENANCE_ON')->exists());

        $cookie = $r->getCookie('laravel_maintenance', false);
        $this->assertNotNull($cookie, 'the Developer browser must receive the bypass cookie');

        // school users are stopped and see the maintenance page
        $this->actingAs($this->hm)->get('/dashboard')->assertStatus(503)->assertSee('under maintenance');
        $this->get('/login')->assertStatus(503);

        // the Developer with the bypass cookie uses the whole system
        $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())->actingAs($this->dev)->get('/students')->assertOk();

        // and the Developer area stays reachable even without the cookie
        $this->withUnencryptedCookies([])->actingAs($this->dev)->get('/developer/maintenance/mode')->assertOk()->assertSee('Switch OFF');

        // a school account still cannot switch it off
        $this->actingAs($this->hm)->post('/developer/maintenance/mode/off')->assertNotFound();
        $this->assertTrue($this->app->isDownForMaintenance());

        $this->actingAs($this->dev)->post('/developer/maintenance/mode/off')->assertSessionHas('success');
        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertTrue(AuditLog::where('action', 'MAINTENANCE_OFF')->exists());
        $this->actingAs($this->hm)->get('/dashboard')->assertOk();
    }

    public function test_developer_can_sign_in_during_maintenance_and_regain_access(): void
    {
        $this->actingAs($this->dev)->post('/developer/maintenance/mode/on', ['page' => 'suspended']);
        auth()->logout();

        $this->get('/developer/login')->assertOk();
        $login = $this->post('/developer/login', ['username' => 'devuser', 'password' => 'Dev-pass-1234']);
        $login->assertRedirect(route('developer.dashboard'));
        // signing in during maintenance gives the Developer's browser full access at once
        $this->assertNotNull($login->getCookie('laravel_maintenance', false));
        // a school account signing in at /developer/login gets no bypass cookie
        auth()->logout();
        $refused = $this->post('/developer/login', ['username' => 'headmaster', 'password' => 'Head-pass-123']);
        $this->assertNull($refused->getCookie('laravel_maintenance', false));

        $r = $this->actingAs($this->dev)->post('/developer/maintenance/mode/bypass');
        $r->assertSessionHas('success');
        $this->assertNotNull($r->getCookie('laravel_maintenance', false));
    }

    /**
     * Regression: the Developer switches maintenance ON, signs out, and the Headmaster signs in
     * on the SAME browser (which still holds the bypass cookie). The Headmaster must be stopped.
     */
    public function test_headmaster_on_the_developers_browser_is_still_stopped(): void
    {
        $r = $this->actingAs($this->dev)->post('/developer/maintenance/mode/on', ['page' => 'suspended']);
        $cookie = $r->getCookie('laravel_maintenance', false)->getValue();

        // signing out removes the cookie from the browser
        $out = $this->actingAs($this->dev)->post('/logout');
        $forgotten = $out->getCookie('laravel_maintenance', false);
        $this->assertNotNull($forgotten);
        $this->assertTrue($forgotten->getExpiresTime() < time());

        // even if the old cookie is still sent, school users are stopped
        auth()->logout();
        $browser = $this->withUnencryptedCookie('laravel_maintenance', $cookie);
        $browser->get('/login')->assertStatus(503)->assertSee('temporarily suspended');
        $browser->post('/login', ['username' => 'headmaster', 'password' => 'Head-pass-123'])->assertStatus(503);
        $this->assertGuest();
        $browser->actingAs($this->hm)->get('/dashboard')->assertStatus(503)->assertSee('temporarily suspended');
        $browser->actingAs($this->hm)->get('/students')->assertStatus(503);

        // the Developer on that browser still works everywhere
        $this->withUnencryptedCookie('laravel_maintenance', $cookie)->actingAs($this->dev)->get('/students')->assertOk();
    }

    public function test_invalid_page_choice_and_school_accounts_are_refused(): void
    {
        $this->actingAs($this->dev)->post('/developer/maintenance/mode/on', ['page' => '../../.env'])->assertSessionHas('danger');
        $this->assertFalse($this->app->isDownForMaintenance());

        $this->actingAs($this->hm)->post('/developer/maintenance/mode/on', ['page' => 'updating'])->assertNotFound();
        $this->actingAs($this->hm)->get('/developer/maintenance/mode')->assertNotFound();
        $this->assertFalse($this->app->isDownForMaintenance());
    }
}
