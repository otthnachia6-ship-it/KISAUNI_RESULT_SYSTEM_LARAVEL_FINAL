<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Performance Analytics must never answer 500 just because there is no data.
 * Every "expected empty" situation gets its own, accurate message, while real
 * programming errors are left alone (no blanket try/catch anywhere).
 */
class AnalyticsEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private SchoolClass $classA;
    private SchoolClass $classB;
    private Examination $exam;
    private Subject $maths;
    private Subject $english;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->classB = SchoolClass::where('standard', 6)->orderBy('id')->first();
        $this->exam = Examination::orderBy('id')->first();
        $this->maths = Subject::where('name', 'Mathematics')->first();
        $this->english = Subject::where('name', 'English')->first();
    }

    private function student(SchoolClass $class, string $reg, string $name = null): int
    {
        return DB::table('students')->insertGetId([
            'reg_no' => $reg, 'full_name' => $name ?? "Student {$reg}", 'gender' => 'Male',
            'class_id' => $class->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);
    }

    private function mark(int $studentId, SchoolClass $class, Subject $subject, ?float $score, ?Examination $exam = null): void
    {
        DB::table('marks')->insert([
            'student_id' => $studentId, 'exam_id' => ($exam ?? $this->exam)->id, 'subject_id' => $subject->id,
            'class_id' => $class->id, 'score' => $score, 'updated_at' => SchoolService::nowStr(),
        ]);
    }

    private function page(string $qs = '', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->hm)->get('/analytics' . ($qs ? "?{$qs}" : ''));
    }

    private function assertClean($response): void
    {
        $response->assertStatus(200);
        $html = $response->getContent();
        foreach (['Undefined variable', 'Division by zero', 'Trying to access array offset', 'Attempt to read property', 'Stack trace', 'SQLSTATE'] as $bad) {
            $this->assertStringNotContainsString($bad, $html, "leaked error text: {$bad}");
        }
    }

    // TEST A --------------------------------------------------------------
    public function test_a_school_wide_and_class_views_work_when_data_exists(): void
    {
        $s1 = $this->student($this->classA, 'A1');
        $s2 = $this->student($this->classA, 'A2');
        $this->mark($s1, $this->classA, $this->maths, 90);
        $this->mark($s2, $this->classA, $this->maths, 50);
        $this->mark($s1, $this->classA, $this->english, 70);

        $school = $this->page("exam_id={$this->exam->id}");
        $this->assertClean($school);
        $school->assertSee('PER CLASS BREAKDOWN');
        $school->assertSee($this->classA->name);

        $class = $this->page("exam_id={$this->exam->id}&class_id={$this->classA->id}");
        $this->assertClean($class);
        $class->assertSee('GRADE DISTRIBUTION BY SUBJECT');
        $class->assertDontSee('No marks have been entered');
    }

    // TEST B --------------------------------------------------------------
    public function test_b_examination_without_any_students(): void
    {
        DB::table('students')->delete();

        $school = $this->page("exam_id={$this->exam->id}");
        $this->assertClean($school);
        $school->assertSee('No students have been registered');

        $class = $this->page("exam_id={$this->exam->id}&class_id={$this->classA->id}");
        $this->assertClean($class);
        $class->assertSee('No students found in ' . $this->classA->name);
    }

    // TEST C --------------------------------------------------------------
    public function test_c_students_exist_but_no_marks_says_so_instead_of_no_students(): void
    {
        $this->student($this->classA, 'C1');
        $this->student($this->classA, 'C2');

        $class = $this->page("exam_id={$this->exam->id}&class_id={$this->classA->id}");
        $this->assertClean($class);
        $class->assertSee('No marks have been entered');
        $class->assertDontSee('No students found');

        $school = $this->page("exam_id={$this->exam->id}");
        $this->assertClean($school);
        $school->assertSee('No marks have been entered');
        $school->assertDontSee('No students have been registered');
    }

    public function test_class_without_students_while_other_class_has_results(): void
    {
        $s = $this->student($this->classA, 'D1');
        $this->mark($s, $this->classA, $this->maths, 65);

        $class = $this->page("exam_id={$this->exam->id}&class_id={$this->classB->id}");
        $this->assertClean($class);
        $class->assertSee('No students found in ' . $this->classB->name);

        $school = $this->page("exam_id={$this->exam->id}");
        $this->assertClean($school);
        $school->assertSee($this->classA->name);
    }

    public function test_incomplete_results_show_a_notice_but_still_render(): void
    {
        $s1 = $this->student($this->classA, 'E1');
        $this->student($this->classA, 'E2');
        $this->student($this->classA, 'E3');
        $this->mark($s1, $this->classA, $this->maths, 72);

        $r = $this->page("exam_id={$this->exam->id}&class_id={$this->classA->id}");
        $this->assertClean($r);
        $r->assertSee('Only 1 of 3 students');
        $r->assertSee('GRADE DISTRIBUTION BY SUBJECT');
    }

    public function test_subject_without_marks_is_named_in_a_notice(): void
    {
        $s1 = $this->student($this->classA, 'F1');
        $this->mark($s1, $this->classA, $this->maths, 55);

        $r = $this->page("exam_id={$this->exam->id}&class_id={$this->classA->id}");
        $this->assertClean($r);
        $r->assertSee('No marks entered yet for');
        $r->assertSee('English');
    }

    public function test_marks_with_null_scores_only_is_treated_as_no_marks(): void
    {
        $s = $this->student($this->classA, 'G1');
        $this->mark($s, $this->classA, $this->maths, null);

        $r = $this->page("exam_id={$this->exam->id}&class_id={$this->classA->id}");
        $this->assertClean($r);
        $r->assertSee('No marks have been entered');
    }

    public function test_no_examinations_at_all(): void
    {
        DB::table('exam_class_status')->delete();
        DB::table('marks')->delete();
        DB::table('examinations')->delete();

        $r = $this->page();
        $this->assertClean($r);
        $r->assertSee('No examinations have been created yet');
    }

    public function test_unknown_examination_or_class_ids(): void
    {
        $r = $this->page('exam_id=999999');
        $this->assertClean($r);
        $r->assertSee('selected examination could not be found');

        $r = $this->page("exam_id={$this->exam->id}&class_id=999999");
        $this->assertClean($r);
        $r->assertSee('selected class could not be found');

        $this->assertClean($this->page('exam_id=abc&class_id=xyz'));
    }

    public function test_class_teacher_with_empty_class_gets_a_message(): void
    {
        $teacher = User::create([
            'username' => 'teach_empty', 'password' => bcrypt('x'), 'full_name' => 'Empty Class',
            'role' => 'class_teacher', 'class_id' => $this->classA->id, 'active' => 1,
            'must_change_password' => 0,
        ]);

        $r = $this->page("exam_id={$this->exam->id}", $teacher);
        $this->assertClean($r);
        $r->assertSee('No students found in ' . $this->classA->name);
    }

    public function test_real_programming_errors_are_not_swallowed(): void
    {
        // The controller must not wrap analytics in a blanket try/catch.
        $src = file_get_contents(app_path('Http/Controllers/AnalyticsController.php'));
        $this->assertStringNotContainsString('catch (', $src);
        $this->assertStringNotContainsString('catch(', $src);
    }
}
