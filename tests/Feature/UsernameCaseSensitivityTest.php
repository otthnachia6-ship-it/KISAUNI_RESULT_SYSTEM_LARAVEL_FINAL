<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "damtu" and "DAMTU" are two different teachers, and "Arif" / "عارف" are two
 * separate accounts. Usernames are matched exactly, and every per-account
 * counter (failed attempts, lockout) and session stays independent.
 */
class UsernameCaseSensitivityTest extends TestCase
{
    use RefreshDatabase;

    private User $damtu;
    private User $damtuUpper;
    private User $arif;
    private User $arifAr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $five = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $seven = SchoolClass::where('standard', 7)->orderBy('id')->first();

        $this->damtu = $this->mk('damtu', 'Pass-one#1', 'class_teacher', $five->id);
        $this->damtuUpper = $this->mk('DAMTU', 'Pass-two#2', 'class_teacher', $seven->id);
        $this->arif = $this->mk('Arif', 'Arif-hm#1', 'headmaster', null);
        $this->arifAr = $this->mk('عارف', 'Arif-ar#2', 'class_teacher', $seven->id);
    }

    private function mk(string $username, string $password, string $role, ?int $classId): User
    {
        return User::create([
            'username' => $username, 'password' => Hash::make($password), 'full_name' => "Person {$username}",
            'role' => $role, 'class_id' => $classId, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr(),
        ]);
    }

    public function test_both_accounts_exist_as_separate_rows(): void
    {
        $this->assertNotEquals($this->damtu->id, $this->damtuUpper->id);
        $this->assertSame(1, User::whereRaw('username = ?', ['damtu'])->get()->filter(fn ($u) => $u->username === 'damtu')->count());
        $this->assertSame('damtu', User::findByUsername('damtu')->username);
        $this->assertSame('DAMTU', User::findByUsername('DAMTU')->username);
        $this->assertNull(User::findByUsername('Damtu'), 'a third case variant must not match either account');
        $this->assertNull(User::findByUsername(''));
        $this->assertNull(User::findByUsername(null));
    }

    public function test_each_account_logs_in_with_its_own_password_and_gets_its_own_session(): void
    {
        $this->post('/login', ['username' => 'damtu', 'password' => 'Pass-one#1'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->damtu);
        $this->get('/logout');
        $this->assertGuest();

        $this->post('/login', ['username' => 'DAMTU', 'password' => 'Pass-two#2'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->damtuUpper);
        $this->get('/logout');
        $this->assertGuest();
    }

    public function test_password_of_one_account_never_opens_the_other(): void
    {
        $this->post('/login', ['username' => 'DAMTU', 'password' => 'Pass-one#1']);
        $this->assertGuest();
        $this->post('/login', ['username' => 'damtu', 'password' => 'Pass-two#2']);
        $this->assertGuest();
        $this->post('/login', ['username' => 'Damtu', 'password' => 'Pass-one#1']);
        $this->assertGuest();
    }

    public function test_failed_attempts_and_lockout_are_independent_per_account(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'damtu', 'password' => 'wrong']);
        }
        $this->assertNotNull($this->damtu->fresh()->locked_until, 'damtu should be locked');
        $this->assertSame(0, (int) $this->damtuUpper->fresh()->failed_login_attempts);
        $this->assertNull($this->damtuUpper->fresh()->locked_until, 'DAMTU must not be locked by damtu\'s failures');

        // DAMTU can still log in; damtu cannot (even with the right password).
        $this->post('/login', ['username' => 'DAMTU', 'password' => 'Pass-two#2'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->damtuUpper);
        $this->get('/logout');
        $this->post('/login', ['username' => 'damtu', 'password' => 'Pass-one#1']);
        $this->assertGuest();
    }

    public function test_arif_latin_and_arabic_accounts_are_independent(): void
    {
        $this->assertSame('headmaster', User::findByUsername('Arif')->role);
        $this->assertSame('class_teacher', User::findByUsername('عارف')->role);

        $this->post('/login', ['username' => 'عارف', 'password' => 'Arif-ar#2'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->arifAr);
        $this->get('/logout');

        $this->post('/login', ['username' => 'Arif', 'password' => 'Arif-ar#2']);
        $this->assertGuest();
        $this->post('/login', ['username' => 'Arif', 'password' => 'Arif-hm#1'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->arif);
    }

    public function test_headmaster_can_add_case_variant_but_not_an_exact_duplicate(): void
    {
        $hm = User::where('role', 'headmaster')->where('username', '!=', 'Arif')->first();
        $hm->update(['must_change_password' => 0]);

        $this->actingAs($hm)->post('/users/add', [
            'username' => 'Damtu', 'full_name' => 'Third', 'password' => 'Temp#123', 'role' => 'class_teacher',
        ])->assertRedirect(route('users'));
        $this->assertNotNull(User::findByUsername('Damtu'));

        $r = $this->actingAs($hm)->from('/users/add')->post('/users/add', [
            'username' => 'DAMTU', 'full_name' => 'Dup', 'password' => 'Temp#123', 'role' => 'class_teacher',
        ]);
        $r->assertRedirect('/users/add')->assertSessionHas('danger');
        $this->assertSame(1, User::all()->where('username', 'DAMTU')->count());
    }

    public function test_edit_user_allows_own_username_and_rejects_taking_an_exact_existing_one(): void
    {
        $hm = User::where('username', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);

        // Saving damtu without changing its username must not trip the duplicate check.
        $this->actingAs($hm)->post("/users/edit/{$this->damtu->id}", [
            'username' => 'damtu', 'full_name' => 'Person damtu', 'role' => 'class_teacher',
            'class_id' => $this->damtu->class_id, 'password' => '',
        ])->assertRedirect(route('users'));

        // Renaming damtu to exactly "DAMTU" (already used) is refused.
        $this->actingAs($hm)->from("/users/edit/{$this->damtu->id}")->post("/users/edit/{$this->damtu->id}", [
            'username' => 'DAMTU', 'full_name' => 'x', 'role' => 'class_teacher', 'class_id' => $this->damtu->class_id,
        ])->assertRedirect("/users/edit/{$this->damtu->id}")->assertSessionHas('danger');
        $this->assertSame('damtu', $this->damtu->fresh()->username);
    }

    public function test_forgot_password_request_is_logged_against_the_exact_account_only(): void
    {
        $this->post('/forgot-password', ['username' => 'DAMTU']);
        $log = DB::table('audit_log')->where('action', 'PASSWORD_RESET_REQUESTED')->get();
        $this->assertCount(1, $log);
        $this->assertSame($this->damtuUpper->id, (int) $log[0]->user_id);
    }

    public function test_mysql_column_is_binary_collated(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Collation check only applies on MySQL/MariaDB (SQLite is already case-sensitive).');
        }
        $collation = DB::selectOne(
            "SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'username'"
        )->c;
        $this->assertSame('utf8mb4_bin', $collation);
    }
}
