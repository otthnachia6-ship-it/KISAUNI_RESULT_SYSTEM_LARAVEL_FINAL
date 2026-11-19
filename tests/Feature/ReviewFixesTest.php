<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fixes from the security / data / usability review:
 *  - a class has only one teacher with access (users.class_id and classes.teacher_id agree);
 *  - the last Headmaster cannot be demoted, nor can a Headmaster demote themselves;
 *  - submitted / approved results cannot be "submitted" again (which used to un-approve them);
 *  - marks of removed pupils do not count towards a complete submission;
 *  - only submitted results can be approved or returned;
 *  - a promoted pupil keeps last year's results (history and report);
 *  - bulk-add errors are shown to the teacher;
 *  - new passwords follow the password rule;
 *  - the audit log keeps 5 days and is cleaned at most once a day.
 */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private SchoolClass $classA;
    private SchoolClass $classB;
    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->classB = SchoolClass::where('standard', 6)->orderBy('id')->first();
        $this->exam = Examination::first();
    }

    private function teacher(string $username, ?int $classId): User
    {
        $u = User::create(['username' => $username, 'password' => Hash::make('Pass#123'), 'full_name' => ucfirst($username),
            'role' => 'class_teacher', 'class_id' => $classId, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr()]);
        if ($classId) {
            SchoolClass::whereKey($classId)->update(['teacher_id' => $u->id]);
        }
        return $u;
    }

    private function pupil(string $reg, int $classId, int $active = 1): Student
    {
        return Student::create(['reg_no' => $reg, 'full_name' => "Pupil {$reg}", 'gender' => 'Male',
            'class_id' => $classId, 'active' => $active, 'created_at' => SchoolService::nowStr()]);
    }

    private function fillAllMarks(Student $s, int $classId, int $score = 60): void
    {
        foreach (DB::table('class_subjects')->where('class_id', $classId)->pluck('subject_id') as $sub) {
            DB::table('marks')->updateOrInsert(
                ['student_id' => $s->id, 'exam_id' => $this->exam->id, 'subject_id' => $sub],
                ['class_id' => $classId, 'score' => $score, 'updated_at' => SchoolService::nowStr()]
            );
        }
    }

    // ---- class teacher access --------------------------------------------

    public function test_giving_a_class_to_a_new_teacher_removes_the_old_teachers_access(): void
    {
        $old = $this->teacher('oldteacher', $this->classA->id);

        $this->actingAs($this->hm)->post('/users/add', [
            'username' => 'newteacher', 'full_name' => 'New Teacher', 'password' => 'Temp#2026',
            'role' => 'class_teacher', 'class_id' => $this->classA->id,
        ])->assertRedirect(route('users'));

        $new = User::findByUsername('newteacher');
        $this->assertNull($old->fresh()->class_id, 'old teacher must lose the class');
        $this->assertSame($new->id, (int) $this->classA->fresh()->teacher_id);
        $this->assertSame(1, User::where('class_id', $this->classA->id)->count());

        // the old teacher can no longer open the marks sheet of that class
        $this->actingAs($old->fresh())->get("/marks/{$this->exam->id}/{$this->classA->id}")->assertRedirect(route('marks_select'));

        // moving the new teacher to class B (Edit user) frees class A on both sides
        $this->actingAs($this->hm)->post("/users/edit/{$new->id}", [
            'username' => 'newteacher', 'full_name' => 'New Teacher', 'role' => 'class_teacher', 'class_id' => $this->classB->id,
        ])->assertRedirect(route('users'));
        $this->assertNull($this->classA->fresh()->teacher_id);
        $this->assertSame($new->id, (int) $this->classB->fresh()->teacher_id);
        $this->assertSame($this->classB->id, (int) $new->fresh()->class_id);
    }

    public function test_classes_page_only_assigns_active_class_teachers(): void
    {
        $this->actingAs($this->hm)->post('/classes', ['class_id' => $this->classA->id, 'teacher_id' => $this->hm->id])
            ->assertSessionHas('danger');
        $this->assertNull($this->classA->fresh()->teacher_id);
    }

    public function test_the_last_headmaster_cannot_lose_the_role(): void
    {
        $payload = ['username' => $this->hm->username, 'full_name' => 'Head', 'role' => 'class_teacher'];
        $this->actingAs($this->hm)->post("/users/edit/{$this->hm->id}", $payload)->assertSessionHas('danger');
        $this->assertSame('headmaster', $this->hm->fresh()->role);

        $other = User::create(['username' => 'admin2', 'password' => Hash::make('Pass#123'), 'full_name' => 'Admin Two',
            'role' => 'headmaster', 'title' => 'Administrator', 'active' => 1, 'must_change_password' => 0]);
        // still refused for your own account...
        $this->actingAs($this->hm)->post("/users/edit/{$this->hm->id}", $payload)->assertSessionHas('danger');
        // ...but another Headmaster may be changed when one remains
        $this->actingAs($this->hm)->post("/users/edit/{$other->id}", ['username' => 'admin2', 'full_name' => 'Admin Two', 'role' => 'class_teacher'])
            ->assertRedirect(route('users'));
        $this->assertSame('class_teacher', $other->fresh()->role);
    }

    // ---- submit / approve ------------------------------------------------

    public function test_approved_results_cannot_be_submitted_again(): void
    {
        $t = $this->teacher('mwl', $this->classA->id);
        ExamClassStatus::create(['exam_id' => $this->exam->id, 'class_id' => $this->classA->id, 'status' => 'approved']);

        $this->actingAs($t)->post("/marks/submit/{$this->exam->id}/{$this->classA->id}")->assertSessionHas('info');
        $this->assertSame('approved', ExamClassStatus::where('exam_id', $this->exam->id)->where('class_id', $this->classA->id)->value('status'));
    }

    public function test_marks_of_removed_pupils_do_not_complete_a_class(): void
    {
        $t = $this->teacher('mwl', $this->classA->id);
        $here = $this->pupil('H1', $this->classA->id);
        $gone = $this->pupil('G1', $this->classA->id, 0);
        $this->fillAllMarks($gone, $this->classA->id); // the removed pupil has every mark...
        // ...the pupil still in the class has none, so submitting must be refused
        $this->actingAs($t)->post("/marks/submit/{$this->exam->id}/{$this->classA->id}")->assertSessionHas('warning');
        $this->assertNull(ExamClassStatus::where('exam_id', $this->exam->id)->where('class_id', $this->classA->id)->value('status'));

        $this->fillAllMarks($here, $this->classA->id);
        $this->actingAs($t)->post("/marks/submit/{$this->exam->id}/{$this->classA->id}")->assertSessionHas('success');
        $this->assertSame('submitted', ExamClassStatus::where('exam_id', $this->exam->id)->where('class_id', $this->classA->id)->value('status'));
    }

    public function test_only_submitted_results_can_be_approved_or_returned(): void
    {
        $url = "/results/review/{$this->exam->id}/{$this->classA->id}";
        $this->actingAs($this->hm)->post($url, ['action' => 'approve'])->assertSessionHas('warning');
        $this->assertNull(ExamClassStatus::where('exam_id', $this->exam->id)->where('class_id', $this->classA->id)->value('status'));

        ExamClassStatus::create(['exam_id' => $this->exam->id, 'class_id' => $this->classA->id, 'status' => 'draft']);
        $this->actingAs($this->hm)->post($url, ['action' => 'return', 'remarks' => 'fix'])->assertSessionHas('warning');
        $this->assertSame('draft', ExamClassStatus::where('exam_id', $this->exam->id)->where('class_id', $this->classA->id)->value('status'));
    }

    // ---- history after promotion -------------------------------------------

    public function test_a_promoted_pupil_keeps_last_years_results(): void
    {
        $p = $this->pupil('P1', $this->classA->id);
        $this->fillAllMarks($p, $this->classA->id, 70);
        ExamClassStatus::create(['exam_id' => $this->exam->id, 'class_id' => $this->classA->id, 'status' => 'approved']);

        // promoted: now in class B, marks stay recorded under class A
        $p->update(['class_id' => $this->classB->id]);

        $this->actingAs($this->hm)->get("/students/{$p->id}/history")->assertOk()
            ->assertSee($this->classA->name)->assertDontSee('No marks have been recorded');

        $this->actingAs($this->hm)->get("/reports/student/{$p->id}/{$this->exam->id}")->assertOk()
            ->assertSee($this->classA->name);
    }

    // ---- bulk add ----------------------------------------------------------

    public function test_bulk_add_shows_the_problem_rows_to_the_teacher(): void
    {
        $this->pupil('TAKEN1', $this->classA->id);
        $html = $this->actingAs($this->hm)->post('/students/add-bulk', [
            'class_id' => $this->classA->id,
            'reg_no' => ['TAKEN1', 'NEW1'], 'full_name' => ['Some <b>One</b>', 'Juma New'], 'gender' => ['Male', 'Male'],
        ])->assertOk()->getContent();

        $this->assertStringContainsString("Row 1: Reg No &#039;TAKEN1&#039; is already registered", $html);
        $this->assertStringContainsString('1 student(s) saved successfully', $html);
        $this->assertStringContainsString('<br', $html);
        $this->assertTrue(Student::where('reg_no', 'NEW1')->exists());
    }

    // ---- passwords -----------------------------------------------------------

    public function test_new_passwords_follow_the_password_rule(): void
    {
        $t = $this->teacher('mwalimu', $this->classA->id);
        $change = fn ($new) => $this->actingAs($t)->post('/change-password', [
            'current_password' => 'Pass#123', 'new_password' => $new, 'confirm_password' => $new, 'new_username' => 'mwalimu',
        ]);

        $change('abc12')->assertSessionHas('danger', fn ($m) => str_contains($m, 'at least 6'));
        $change('mwalimu')->assertSessionHas('danger', fn ($m) => str_contains($m, 'same as the username'));
        $change('Pass#123')->assertSessionHas('danger', fn ($m) => str_contains($m, 'different from your current'));
        $change('Kisauni#1')->assertRedirect(route('dashboard'));
        $this->assertTrue(Hash::check('Kisauni#1', $t->fresh()->password));
    }

    // ---- audit log -------------------------------------------------------------

    public function test_audit_log_keeps_five_days_and_is_cleaned_once_a_day(): void
    {
        AuditLog::create(['username' => 'x', 'action' => 'OLD', 'details' => '', 'created_at' => SchoolService::now()->subDays(6)->format('Y-m-d H:i:s')]);
        AuditLog::create(['username' => 'x', 'action' => 'RECENT', 'details' => '', 'created_at' => SchoolService::now()->subDays(4)->format('Y-m-d H:i:s')]);

        SchoolService::logAction(null, 'FIRST_TODAY');
        $this->assertFalse(AuditLog::where('action', 'OLD')->exists(), 'older than 5 days is removed');
        $this->assertTrue(AuditLog::where('action', 'RECENT')->exists(), '4 days old is kept');

        // later the same day no clean-up runs again (no extra DELETE on every action)
        AuditLog::create(['username' => 'x', 'action' => 'OLD2', 'details' => '', 'created_at' => SchoolService::now()->subDays(9)->format('Y-m-d H:i:s')]);
        SchoolService::logAction(null, 'SECOND_TODAY');
        $this->assertTrue(AuditLog::where('action', 'OLD2')->exists());

        Cache::flush(); // next day
        SchoolService::logAction(null, 'NEXT_DAY');
        $this->assertFalse(AuditLog::where('action', 'OLD2')->exists());

        $this->actingAs($this->hm)->get('/audit-logs')->assertOk()->assertSee('records kept for 5 days');
    }
}
