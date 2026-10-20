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
        $this->post('/login', ['username' => 'locky', 'password' => 'bad']);
        $this->assertNotNull($u->fresh()->locked_until);
        $this->assertTrue(DB::table('audit_log')->where('action', 'LOGIN_LOCKED')->exists());

        // correct password is refused while locked
        $this->post('/login', ['username' => 'locky', 'password' => 'Pass#123']);
        $this->assertGuest();

        // unknown user gets the same generic message (no username enumeration)
        $this->post('/login', ['username' => 'nobody', 'password' => 'x'])->assertSessionHas('danger', 'Incorrect username or password.');

        // once the lock has expired, login works and counters reset
        $u->update(['locked_until' => now('Africa/Dar_es_Salaam')->subMinute()->format('Y-m-d H:i:s')]);
        $this->post('/login', ['username' => 'locky', 'password' => 'Pass#123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u);
        $this->assertEquals(0, $u->fresh()->failed_login_attempts);
        $this->assertNull($u->fresh()->locked_until);
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

        $this->actingAs($u)->get('/logout')->assertRedirect(route('login'));
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

        // validation: out-of-range and non-numeric are rejected (left blank), decimals kept exactly
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
