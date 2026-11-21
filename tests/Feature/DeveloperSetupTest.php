<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** One-time web creation of the first Developer account by a signed-in Headmaster. */
class DeveloperSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0, 'password' => Hash::make('Head-pass-123')]);
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'username' => 'devuser', 'full_name' => 'System Developer',
            'password' => 'Dev-Secret-99', 'password_confirmation' => 'Dev-Secret-99',
            'headmaster_password' => 'Head-pass-123',
        ], $overrides);
    }

    public function test_headmaster_creates_the_first_developer_once_and_the_page_closes(): void
    {
        $this->actingAs($this->hm)->get('/developer-setup')->assertOk()->assertSee('Create Developer Account');

        $this->actingAs($this->hm)->post('/developer-setup', $this->form())
            ->assertRedirect(route('developer.login'))->assertSessionHas('success');
        $this->assertGuest(); // the Headmaster is signed out, so the Developer sign-in opens directly

        $dev = User::findByUsername('devuser');
        $this->assertTrue($dev->isDeveloper());
        $this->assertTrue(Hash::check('Dev-Secret-99', $dev->password));
        $this->assertSame(0, $dev->must_change_password);
        $this->assertTrue(AuditLog::where('action', 'CREATE_DEVELOPER')->where('username', $this->hm->username)->exists());

        // closed for good, for everyone
        $this->actingAs($this->hm)->get('/developer-setup')->assertNotFound();
        $this->actingAs($this->hm)->post('/developer-setup', $this->form(['username' => 'second']))->assertNotFound();
        $this->assertSame(1, User::where('role', 'developer')->count());
        $this->actingAs($dev)->get('/developer-setup')->assertNotFound();

        // the new account signs in only at the Developer login
        auth()->logout();
        $this->post('/developer/login', ['username' => 'devuser', 'password' => 'Dev-Secret-99'])->assertRedirect(route('developer.dashboard'));
    }

    public function test_only_a_signed_in_headmaster_may_use_it(): void
    {
        $this->get('/developer-setup')->assertRedirect(route('login'));

        $cls = SchoolClass::orderBy('id')->first();
        $teacher = User::create(['username' => 'tch', 'password' => Hash::make('Teach-123'), 'full_name' => 'Teacher',
            'role' => 'class_teacher', 'class_id' => $cls->id, 'active' => 1, 'must_change_password' => 0]);
        $this->actingAs($teacher)->get('/developer-setup')->assertNotFound();
        $this->actingAs($teacher)->post('/developer-setup', $this->form(['headmaster_password' => 'Teach-123']))->assertNotFound();
        $this->assertFalse(User::where('role', 'developer')->exists());
    }

    public function test_wrong_headmaster_password_weak_or_mismatched_passwords_are_refused(): void
    {
        $this->actingAs($this->hm);
        $this->post('/developer-setup', $this->form(['headmaster_password' => 'wrong']))->assertSessionHas('danger', fn ($m) => str_contains($m, 'Headmaster'));
        $this->post('/developer-setup', $this->form(['password' => 'ab', 'password_confirmation' => 'ab']))->assertSessionHas('danger', fn ($m) => str_contains($m, 'at least'));
        $this->post('/developer-setup', $this->form(['password_confirmation' => 'Other-99']))->assertSessionHas('danger', fn ($m) => str_contains($m, 'do not match'));
        $this->post('/developer-setup', $this->form(['username' => 'headmaster']))->assertSessionHas('danger', fn ($m) => str_contains($m, 'already used'));
        $this->assertFalse(User::where('role', 'developer')->exists());
    }

    public function test_developer_can_sign_in_on_a_browser_where_the_headmaster_is_signed_in(): void
    {
        $dev = User::create(['username' => 'devkisauni', 'password' => Hash::make('Dev-Secret-99'), 'full_name' => 'System Developer',
            'role' => 'developer', 'active' => 1, 'must_change_password' => 0]);

        // a signed-in school account is told to log out first (opening a link never signs anyone out)
        $this->actingAs($this->hm)->get('/developer/login')
            ->assertRedirect(route('dashboard'))->assertSessionHas('info', fn ($m) => str_contains($m, 'press Logout first'));
        $this->assertAuthenticatedAs($this->hm);

        // after logging out, the Developer signs in normally
        $this->actingAs($this->hm)->post('/logout');
        $this->assertGuest();
        $this->get('/developer/login')->assertOk()->assertSee('Developer sign in');
        $this->post('/developer/login', ['username' => 'devkisauni', 'password' => 'Dev-Secret-99'])
            ->assertRedirect(route('developer.dashboard'));
        $this->assertAuthenticatedAs($dev);

        // profile shows the right role
        $this->actingAs($dev)->get('/profile')->assertOk()->assertSee('Developer')->assertDontSee('Class Teacher');
    }

    public function test_page_is_closed_when_a_developer_already_exists(): void
    {
        User::create(['username' => 'existingdev', 'password' => Hash::make('x-12345'), 'full_name' => 'Dev',
            'role' => 'developer', 'active' => 1, 'must_change_password' => 0]);
        $this->actingAs($this->hm)->get('/developer-setup')->assertNotFound();
        $this->actingAs($this->hm)->post('/developer-setup', $this->form())->assertNotFound();
        $this->assertSame(1, User::where('role', 'developer')->count());
    }
}
