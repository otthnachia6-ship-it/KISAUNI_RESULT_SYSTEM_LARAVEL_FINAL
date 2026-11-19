<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    private function mkUser(string $name, string $role = 'class_teacher', $classId = null, array $extra = []): User
    {
        return User::create(array_merge(['username' => $name, 'password' => Hash::make('Pass#123'), 'full_name' => ucfirst($name),
            'role' => $role, 'class_id' => $classId, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr()], $extra));
    }

    // ---------- AUTHENTICATION ----------

    public function test_lockout_after_five_failures_and_generic_message(): void
    {
        $u = $this->mkUser('locky');
        for ($i = 1; $i <= 4; $i++) {
            $this->post('/login', ['username' => 'locky', 'password' => 'bad'])->assertSessionHas('danger', 'Incorrect username or password.');
        }
        $this->post('/login', ['username' => 'locky', 'password' => 'bad'])
            ->assertSessionHas('danger', fn ($m) => str_contains($m, 'Too many failed attempts'));
        $this->assertTrue(DB::table('audit_log')->where('action', 'LOGIN_LOCKED')->exists());

        // correct password is refused while blocked on this device
        $this->post('/login', ['username' => 'locky', 'password' => 'Pass#123']);
        $this->assertGuest();

        // ...but the same teacher on ANOTHER device (e.g. a stranger cannot lock them out) still gets in
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->post('/login', ['username' => 'locky', 'password' => 'Pass#123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u);
        $this->post(route('logout'));
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

        // unknown user gets the same generic message (no username enumeration)
        $this->post('/login', ['username' => 'nobody', 'password' => 'x'])->assertSessionHas('danger', 'Incorrect username or password.');

        // once the block has expired, login works and counters reset
        $this->travel(config('kisauni.login_lockout_minutes') + 1)->minutes();
        $this->post('/login', ['username' => 'locky', 'password' => 'Pass#123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u);
        $this->assertEquals(0, $u->fresh()->failed_login_attempts);
    }

    public function test_unknown_usernames_are_blocked_the_same_way_and_one_device_cannot_guess_forever(): void
    {
        config(['kisauni.max_login_attempts_per_ip' => 8]);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'ghost', 'password' => 'bad']);
        }
        // a username that does not exist shows exactly the same "blocked" message
        $this->post('/login', ['username' => 'ghost', 'password' => 'bad'])
            ->assertSessionHas('danger', fn ($m) => str_contains($m, 'Too many failed attempts'));

        // spraying other usernames from the same device hits the per-device limit
        $this->mkUser('real1');
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['username' => "other{$i}", 'password' => 'bad']);
        }
        $this->post('/login', ['username' => 'real1', 'password' => 'Pass#123'])
            ->assertSessionHas('danger', fn ($m) => str_contains($m, 'Too many failed attempts'));
        $this->assertGuest();
    }

    public function test_deactivated_or_password_reset_accounts_are_signed_out_of_open_sessions(): void
    {
        $hm = $this->mkUser('boss', 'headmaster');
        $t = $this->mkUser('mwalimu');

        $this->post('/login', ['username' => 'mwalimu', 'password' => 'Pass#123'])->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertOk();

        // The Headmaster resets the password (another session): this old session is signed out.
        $t->update(['password' => Hash::make('NewPass#9')]);
        $this->app['auth']->forgetGuards(); // a new request loads the account again (as on a real server)
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        // Signed in again with the new password, then deactivated: signed out on the next click.
        $this->post('/login', ['username' => 'mwalimu', 'password' => 'NewPass#9'])->assertRedirect(route('dashboard'));
        $t->update(['active' => 0]);
        $this->app['auth']->forgetGuards();
        $this->get('/students')->assertRedirect(route('login'))->assertSessionHas('danger', fn ($m) => str_contains($m, 'deactivated'));
        $this->assertGuest();

        // Changing your OWN password keeps you signed in.
        $this->post('/login', ['username' => 'boss', 'password' => 'Pass#123']);
        $this->post('/change-password', ['current_password' => 'Pass#123', 'new_password' => 'Boss#2026', 'confirm_password' => 'Boss#2026', 'new_username' => 'boss'])
            ->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($hm->fresh());
    }

    public function test_logout_needs_post_and_get_is_not_allowed(): void
    {
        $u = $this->mkUser('poster');
        $this->actingAs($u)->get('/logout')->assertStatus(405);
        $this->assertAuthenticatedAs($u);
    }

    public function test_inactive_account_cannot_login_and_must_change_password_is_enforced(): void
    {
        $this->mkUser('off', 'class_teacher', null, ['active' => 0]);
        $this->post('/login', ['username' => 'off', 'password' => 'Pass#123'])->assertSessionHas('danger');
        $this->assertGuest();

        $u = $this->mkUser('fresh', 'class_teacher', null, ['must_change_password' => 1]);
        $this->post('/login', ['username' => 'fresh', 'password' => 'Pass#123'])->assertRedirect(route('change_password'));
        $this->get('/dashboard')->assertRedirect(route('change_password'));
        $this->get('/students')->assertRedirect(route('change_password'));
        $this->get('/change-password')->assertStatus(200);
    }

    public function test_logout_and_login_are_audited_and_idle_timeout_logs_out(): void
    {
        $u = $this->mkUser('idle');
        $this->post('/login', ['username' => 'idle', 'password' => 'Pass#123']);
        $this->assertTrue(DB::table('audit_log')->where('action', 'LOGIN')->exists());

        $this->withSession(['last_activity' => SchoolService::now()->timestamp - 3600])->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(DB::table('audit_log')->where('action', 'IDLE_LOGOUT')->exists());

        $this->actingAs($u)->post('/logout')->assertRedirect(route('login'));
        $this->assertTrue(DB::table('audit_log')->where('action', 'LOGOUT')->exists());
    }

    public function test_session_cookie_is_browser_session_only_and_debug_off_in_production_example(): void
    {
        $this->assertTrue(config('session.expire_on_close'));
        $prod = file_get_contents(base_path('.env.ostexs.example'));
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false/m', $prod);
        $this->assertMatchesRegularExpression('/^APP_ENV=production/m', $prod);
    }

    // ---------- RESULT WORKFLOW ----------

    public function test_full_status_machine_with_audit_details_and_edit_locking(): void
    {
        $class = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $exam = Examination::first();
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $t = $this->mkUser('teach', 'class_teacher', $class->id);
        $s = Student::create(['reg_no' => 'W1', 'full_name' => 'Wanjiru', 'gender' => 'Female', 'class_id' => $class->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $subjects = Subject::whereHas('classes', fn($q) => $q->where('classes.id', $class->id))->get();
        $full = fn($v) => $subjects->mapWithKeys(fn($sub) => ["score_{$s->id}_{$sub->id}" => $v])->all();
        $status = fn() => DB::table('exam_class_status')->where('exam_id', $exam->id)->where('class_id', $class->id)->value('status');
        $act = fn($a) => DB::table('audit_log')->where('action', $a)->where('details', "exam={$exam->id} class={$class->id}")->exists();
        $url = "/marks/{$exam->id}/{$class->id}";

        // validation: out-of-range and non-numeric are rejected (nothing saved), decimals kept exactly
        $first = $subjects[0]->id;
        $payload = $full(50) ;
        $payload["score_{$s->id}_{$first}"] = '120';
        $payload["score_{$s->id}_{$subjects[1]->id}"] = 'abc';
        $payload["score_{$s->id}_{$subjects[2]->id}"] = '20.95';
        $this->actingAs($t)->post($url, $payload)->assertSessionHas('warning');
        $this->assertNull(DB::table('marks')->where('student_id', $s->id)->where('subject_id', $first)->value('score'));
        $this->assertEquals(20.95, DB::table('marks')->where('student_id', $s->id)->where('subject_id', $subjects[2]->id)->value('score'));
        $this->assertSame('draft', $status());
        $this->assertTrue($act('ENTER_MARKS'));

        // incomplete marks cannot be submitted
        $this->post("/marks/submit/{$exam->id}/{$class->id}")->assertSessionHas('warning');
        $this->assertSame('draft', $status());

        // complete + submit -> submitted, and the sheet is locked for the teacher
        $this->post($url, $full(75))->assertSessionHas('success');
        $this->post("/marks/submit/{$exam->id}/{$class->id}")->assertSessionHas('success');
        $this->assertSame('submitted', $status());
        $this->assertTrue($act('SUBMIT_RESULTS'));
        $this->post($url, $full(10))->assertSessionHas('warning');
        $this->assertEquals(75, DB::table('marks')->where('student_id', $s->id)->where('subject_id', $first)->value('score'));

        // headmaster opens review -> under_review
        $this->actingAs($hm)->get("/results/review/{$exam->id}/{$class->id}")->assertStatus(200);
        $this->assertSame('under_review', $status());
        $this->assertTrue($act('START_REVIEW'));

        // return needs a reason; with reason -> returned and teacher may edit again
        $this->post("/results/review/{$exam->id}/{$class->id}", ['action' => 'return', 'remarks' => ''])->assertSessionHas('warning');
        $this->assertSame('under_review', $status());
        $this->post("/results/review/{$exam->id}/{$class->id}", ['action' => 'return', 'remarks' => 'Fix Math'])->assertRedirect(route('results'));
        $this->assertSame('returned', $status());
        $this->assertTrue(DB::table('audit_log')->where('action', 'RETURN_RESULTS')->exists());
        $this->actingAs($t)->post($url, $full(80))->assertSessionHas('success');
        $this->assertSame('draft', $status());

        // resubmit -> approve -> locked for everybody
        $this->post("/marks/submit/{$exam->id}/{$class->id}");
        $this->actingAs($hm)->get("/results/review/{$exam->id}/{$class->id}");
        $this->post("/results/review/{$exam->id}/{$class->id}", ['action' => 'approve'])->assertRedirect(route('results'));
        $this->assertSame('approved', $status());
        $this->assertTrue($act('APPROVE_RESULTS'));
        $this->actingAs($t)->post($url, $full(1))->assertSessionHas('warning');
        $this->assertEquals(80, DB::table('marks')->where('student_id', $s->id)->where('subject_id', $first)->value('score'));
    }
}
