<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassDataService;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Headmaster "Class tools" (Classes menu only): five actions, each scoped to one class.
 */
class ClassToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $a;
    private SchoolClass $b;
    private SchoolClass $c;
    private Examination $e1;
    private Examination $e2;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->teacher = User::create(['username' => 'tt', 'password' => Hash::make('x'), 'full_name' => 'Amina Said',
            'role' => 'class_teacher', 'class_id' => null, 'active' => 1, 'must_change_password' => 0, 'created_at' => SchoolService::nowStr()]);

        [$this->a, $this->b, $this->c] = SchoolClass::orderBy('id')->take(3)->get()->all();
        $this->subject = Subject::first();
        $this->e1 = Examination::create(['exam_type' => 'Midterm', 'academic_year' => '2026', 'created_at' => SchoolService::nowStr()]);
        $this->e2 = Examination::create(['exam_type' => 'Terminal', 'academic_year' => '2026', 'created_at' => SchoolService::nowStr()]);
    }

    private function makeStudent(string $reg, SchoolClass $cls, int $active = 1): Student
    {
        return Student::create(['reg_no' => $reg, 'full_name' => "Pupil $reg", 'gender' => 'Male', 'class_id' => $cls->id,
            'gender_confirmed' => 1, 'active' => $active, 'created_at' => SchoolService::nowStr()]);
    }

    private function makeMark(Student $s, Examination $e, SchoolClass $cls, float $score = 50): void
    {
        DB::table('marks')->insert(['student_id' => $s->id, 'exam_id' => $e->id, 'subject_id' => $this->subject->id,
            'class_id' => $cls->id, 'score' => $score]);
    }

    private function makeResultStatus(Examination $e, SchoolClass $cls, string $status = 'pending'): void
    {
        DB::table('exam_class_status')->insert(['exam_id' => $e->id, 'class_id' => $cls->id, 'status' => $status]);
    }

    private function runTool(SchoolClass $cls, array $data)
    {
        $this->actingAs($this->hm)->post(route('class_tools_preview', $cls->id), $data)->assertOk();
        return $this->actingAs($this->hm)->post(route('class_tools_run', $cls->id), $data);
    }

    private function marks(SchoolClass $cls, ?Examination $e = null): int
    {
        $q = DB::table('marks')->where('class_id', $cls->id);
        return $e ? $q->where('exam_id', $e->id)->count() : $q->count();
    }

    // ---------------------------------------------------------------- access / page

    public function test_only_the_headmaster_can_open_class_tools(): void
    {
        $this->actingAs($this->teacher)->get(route('class_tools', $this->a->id))->assertForbidden();
        $this->actingAs($this->teacher)->post(route('class_tools_run', $this->a->id), ['action' => 'class_all'])->assertForbidden();
        auth()->logout();
        $this->get(route('class_tools', $this->a->id))->assertRedirect();
    }

    public function test_tools_page_shows_five_choices_and_the_classes_menu_links_to_it(): void
    {
        $page = $this->actingAs($this->hm)->get(route('class_tools', $this->a->id))->assertOk();
        foreach (ClassDataService::actions() as $label) {
            $page->assertSee($label, false);
        }
        $this->actingAs($this->hm)->get(route('classes'))->assertSee(route('class_tools', $this->a->id), false);

        // no deletion tools anywhere else
        foreach (['results', 'examinations'] as $name) {
            $this->actingAs($this->hm)->get(route($name))->assertDontSee('class_tools', false);
        }
    }

    public function test_unknown_choice_or_empty_selection_changes_nothing(): void
    {
        $s = $this->makeStudent('R1', $this->a);
        $this->makeMark($s, $this->e1, $this->a);

        $this->actingAs($this->hm)->post(route('class_tools_preview', $this->a->id), ['action' => 'nonsense'])->assertRedirect(route('class_tools', $this->a->id));
        $this->actingAs($this->hm)->post(route('class_tools_run', $this->a->id), ['action' => 'results'])->assertRedirect(route('class_tools', $this->a->id));
        $this->assertSame(1, $this->marks($this->a));
    }

    // ---------------------------------------------------------------- 1. delete results

    public function test_delete_results_removes_only_selected_exams_of_this_class(): void
    {
        $s = $this->makeStudent('R1', $this->a);
        $o = $this->makeStudent('R2', $this->b);
        $this->makeMark($s, $this->e1, $this->a);
        $this->makeMark($s, $this->e2, $this->a);
        $this->makeMark($o, $this->e1, $this->b);
        $this->makeResultStatus($this->e1, $this->a);
        $this->makeResultStatus($this->e2, $this->a);
        $this->makeResultStatus($this->e1, $this->b);

        $this->runTool($this->a, ['action' => 'results', 'exam_ids' => [$this->e1->id]])->assertRedirect(route('class_tools', $this->a->id));

        $this->assertSame(0, $this->marks($this->a, $this->e1));
        $this->assertSame(1, $this->marks($this->a, $this->e2));
        $this->assertSame(1, $this->marks($this->b, $this->e1));
        $this->assertFalse(DB::table('exam_class_status')->where('class_id', $this->a->id)->where('exam_id', $this->e1->id)->exists());
        $this->assertTrue(DB::table('exam_class_status')->where('class_id', $this->a->id)->where('exam_id', $this->e2->id)->exists());
        $this->assertTrue(DB::table('exam_class_status')->where('class_id', $this->b->id)->where('exam_id', $this->e1->id)->exists());
        $this->assertSame(1, Student::where('class_id', $this->a->id)->count());
        $this->assertTrue(DB::table('audit_log')->where('action', 'CLASS_TOOL_RESULTS')->exists());
    }

    // ---------------------------------------------------------------- 2. move to the correct exam

    public function test_move_results_to_another_exam_for_this_class_only(): void
    {
        $s = $this->makeStudent('R1', $this->a);
        $o = $this->makeStudent('R2', $this->b);
        $this->makeMark($s, $this->e1, $this->a, 70);
        $this->makeMark($o, $this->e1, $this->b, 30);
        $this->makeResultStatus($this->e1, $this->a, 'approved');

        $this->runTool($this->a, ['action' => 'move_exam', 'from_exam' => $this->e1->id, 'to_exam' => $this->e2->id])->assertRedirect();

        $this->assertSame(1, $this->marks($this->a, $this->e2));
        $this->assertSame(0, $this->marks($this->a, $this->e1));
        $this->assertSame(70.0, (float) DB::table('marks')->where('student_id', $s->id)->value('score'));
        $this->assertSame(1, $this->marks($this->b, $this->e1), 'other class untouched');
        $this->assertSame('approved', DB::table('exam_class_status')->where('class_id', $this->a->id)->where('exam_id', $this->e2->id)->value('status'));
    }

    public function test_move_is_refused_when_target_exam_already_has_results_for_the_class(): void
    {
        $s = $this->makeStudent('R1', $this->a);
        $this->makeMark($s, $this->e1, $this->a);
        $this->makeMark($s, $this->e2, $this->a);

        $this->actingAs($this->hm)->post(route('class_tools_preview', $this->a->id),
            ['action' => 'move_exam', 'from_exam' => $this->e1->id, 'to_exam' => $this->e2->id])->assertRedirect();
        $this->assertSame(2, $this->marks($this->a));
        $this->assertSame(1, $this->marks($this->a, $this->e1));
    }

    // ---------------------------------------------------------------- 3. delete students only

    public function test_delete_students_keeps_the_class_and_vanishes_from_review_when_empty(): void
    {
        $s = $this->makeStudent('R1', $this->a);
        $this->makeStudent('R3', $this->a, 0);
        $o = $this->makeStudent('R2', $this->b);
        $this->makeMark($s, $this->e1, $this->a);
        $this->makeMark($o, $this->e1, $this->b);
        $this->makeResultStatus($this->e1, $this->a);
        $this->makeResultStatus($this->e1, $this->b);

        $this->runTool($this->a, ['action' => 'students'])->assertRedirect(route('class_tools', $this->a->id));

        $this->assertTrue(SchoolClass::whereKey($this->a->id)->exists());
        $this->assertSame(0, Student::where('class_id', $this->a->id)->count());
        $this->assertSame(0, $this->marks($this->a));
        $this->assertFalse(DB::table('exam_class_status')->where('class_id', $this->a->id)->exists());
        $this->assertSame(1, Student::where('class_id', $this->b->id)->count());
        $this->assertSame(1, $this->marks($this->b));
        $this->assertTrue(DB::table('exam_class_status')->where('class_id', $this->b->id)->exists());
    }

    public function test_deleting_students_does_not_touch_history_of_promoted_students(): void
    {
        $promoted = $this->makeStudent('P1', $this->b);          // now in B, results entered while in A
        $this->makeMark($promoted, $this->e1, $this->a);
        $stay = $this->makeStudent('S1', $this->a);
        $this->makeMark($stay, $this->e2, $this->a);
        $this->makeResultStatus($this->e1, $this->a);
        $this->makeResultStatus($this->e2, $this->a);

        $this->runTool($this->a, ['action' => 'students']);

        $this->assertSame(1, $this->marks($this->a, $this->e1), 'promoted student history kept');
        $this->assertTrue(Student::whereKey($promoted->id)->exists());
        $this->assertTrue(DB::table('exam_class_status')->where('class_id', $this->a->id)->where('exam_id', $this->e1->id)->exists());
        $this->assertFalse(DB::table('exam_class_status')->where('class_id', $this->a->id)->where('exam_id', $this->e2->id)->exists());
    }

    // ---------------------------------------------------------------- 4. delete class, keep students

    public function test_delete_class_keep_students_moves_students_and_results(): void
    {
        $s = $this->makeStudent('R1', $this->a);
        $this->makeMark($s, $this->e1, $this->a, 66);
        $this->makeResultStatus($this->e1, $this->a, 'pending');
        $this->teacher->update(['class_id' => $this->a->id]);

        $this->runTool($this->a, ['action' => 'class_keep', 'target_class' => $this->b->id])->assertRedirect(route('classes'));

        $this->assertFalse(SchoolClass::whereKey($this->a->id)->exists());
        $this->assertSame($this->b->id, (int) Student::find($s->id)->class_id);
        $this->assertSame($this->b->id, (int) DB::table('marks')->where('student_id', $s->id)->value('class_id'));
        $this->assertSame(66.0, (float) DB::table('marks')->where('student_id', $s->id)->value('score'));
        $this->assertTrue(DB::table('exam_class_status')->where('class_id', $this->b->id)->where('exam_id', $this->e1->id)->exists());
        $this->assertFalse(DB::table('exam_class_status')->where('class_id', $this->a->id)->exists());
        $this->assertNull($this->teacher->fresh()->class_id);
        $this->assertTrue(User::whereKey($this->teacher->id)->exists(), 'teacher account stays');
    }

    public function test_keep_students_is_refused_for_same_class_or_clashing_status(): void
    {
        $s = $this->makeStudent('R1', $this->a);
        $this->makeMark($s, $this->e1, $this->a);
        $this->makeResultStatus($this->e1, $this->a);
        $this->makeResultStatus($this->e1, $this->b);

        $this->actingAs($this->hm)->post(route('class_tools_run', $this->a->id), ['action' => 'class_keep', 'target_class' => $this->a->id]);
        $this->actingAs($this->hm)->post(route('class_tools_run', $this->a->id), ['action' => 'class_keep', 'target_class' => $this->b->id]);

        $this->assertTrue(SchoolClass::whereKey($this->a->id)->exists());
        $this->assertSame($this->a->id, (int) Student::find($s->id)->class_id);
    }

    // ---------------------------------------------------------------- 5. delete class with students

    public function test_delete_class_with_students_removes_it_everywhere(): void
    {
        $s = $this->makeStudent('R1', $this->a);
        $o = $this->makeStudent('R2', $this->b);
        $this->makeMark($s, $this->e1, $this->a);
        $this->makeMark($o, $this->e1, $this->b);
        $this->makeResultStatus($this->e1, $this->a);
        $this->makeResultStatus($this->e1, $this->b);

        $this->runTool($this->a, ['action' => 'class_all'])->assertRedirect(route('classes'));

        $this->assertFalse(SchoolClass::whereKey($this->a->id)->exists());
        $this->assertFalse(Student::whereKey($s->id)->exists());
        $this->assertSame(0, DB::table('marks')->where('student_id', $s->id)->count());
        $this->assertFalse(DB::table('exam_class_status')->where('class_id', $this->a->id)->exists());
        $this->assertTrue(Student::whereKey($o->id)->exists());
        $this->assertSame(1, $this->marks($this->b));
        $this->assertTrue(DB::table('exam_class_status')->where('class_id', $this->b->id)->exists());

        // the class is gone from the Review & Approval page and the Classes list
        $this->actingAs($this->hm)->get(route('results'))->assertOk()->assertDontSee($this->a->name . '</', false);
        $this->actingAs($this->hm)->get(route('classes'))->assertDontSee(route('class_tools', $this->a->id), false);
    }

    public function test_deleting_a_class_is_refused_while_it_holds_history_of_promoted_students(): void
    {
        $promoted = $this->makeStudent('P1', $this->c);          // promoted to C, results belong to A
        $this->makeMark($promoted, $this->e1, $this->a);
        $this->makeStudent('S1', $this->a);

        foreach (['class_all', 'class_keep'] as $action) {
            $this->actingAs($this->hm)->post(route('class_tools_run', $this->a->id), ['action' => $action, 'target_class' => $this->b->id]);
        }

        $this->assertTrue(SchoolClass::whereKey($this->a->id)->exists());
        $this->assertSame(1, $this->marks($this->a));
        $this->assertSame(1, Student::where('class_id', $this->a->id)->count());
    }
}
