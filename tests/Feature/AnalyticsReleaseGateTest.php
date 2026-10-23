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
 * A class teacher sees class analytics only after EVERY class with students
 * is Approved by the Headmaster for that examination.
 */
class AnalyticsReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $classA;
    private SchoolClass $classB;
    private Examination $exam;
    private Subject $maths;

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
        $this->teacher = User::create([
            'username' => 'teacher_a', 'password' => bcrypt('x'), 'full_name' => 'Teacher A',
            'role' => 'class_teacher', 'class_id' => $this->classA->id, 'active' => 1, 'must_change_password' => 0,
        ]);

        foreach ([[$this->classA, 'GA1', 70], [$this->classB, 'GB1', 50]] as [$class, $reg, $score]) {
            $sid = DB::table('students')->insertGetId([
                'reg_no' => $reg, 'full_name' => "Pupil {$reg}", 'gender' => 'Male',
                'class_id' => $class->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
            ]);
            DB::table('marks')->insert([
                'student_id' => $sid, 'exam_id' => $this->exam->id, 'subject_id' => $this->maths->id,
                'class_id' => $class->id, 'score' => $score, 'updated_at' => SchoolService::nowStr(),
            ]);
        }
    }

    private function setStatus(SchoolClass $class, string $status): void
    {
        DB::table('exam_class_status')->updateOrInsert(
            ['exam_id' => $this->exam->id, 'class_id' => $class->id],
            ['status' => $status]
        );
    }

    public function test_teacher_sees_nothing_while_other_classes_are_not_approved(): void
    {
        // Own class approved, the other class still only submitted
        $this->setStatus($this->classA, 'approved');
        $this->setStatus($this->classB, 'submitted');

        $st = SchoolService::examReleaseStatus($this->exam->id);
        $this->assertFalse($st['released']);
        $this->assertSame(1, $st['approved']);
        $this->assertSame(2, $st['total']);

        $r = $this->actingAs($this->teacher)->get('/analytics?exam_id=' . $this->exam->id);
        $r->assertOk()->assertSee('Results not released yet')->assertSee('1 of 2 classes approved');
        $r->assertDontSee('Pupil GA1');

        $d = $this->actingAs($this->teacher)->get('/dashboard');
        $d->assertOk()->assertSee('Results not released yet')->assertDontSee('View Full Analytics');
    }

    public function test_teacher_sees_analytics_once_every_class_is_approved(): void
    {
        $this->setStatus($this->classA, 'approved');
        $this->setStatus($this->classB, 'approved');
        $this->assertTrue(SchoolService::examReleaseStatus($this->exam->id)['released']);

        $r = $this->actingAs($this->teacher)->get('/analytics?exam_id=' . $this->exam->id);
        $r->assertOk()->assertDontSee('Results not released yet')->assertSee('Mathematics');

        $d = $this->actingAs($this->teacher)->get('/dashboard');
        $d->assertOk()->assertDontSee('Results not released yet')->assertSee('View Full Analytics');
    }

    public function test_class_without_this_exam_does_not_block_release(): void
    {
        // Standard Seven has pupils but took no part in this examination (no marks, no status)
        $classC = SchoolClass::where('standard', 7)->orderBy('id')->first();
        DB::table('students')->insert([
            'reg_no' => 'GC1', 'full_name' => 'Pupil GC1', 'gender' => 'Male',
            'class_id' => $classC->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);
        $this->setStatus($this->classA, 'approved');
        $this->setStatus($this->classB, 'approved');

        $st = SchoolService::examReleaseStatus($this->exam->id);
        $this->assertTrue($st['released']);
        $this->assertSame(2, $st['total']);

        $this->actingAs($this->teacher)->get('/analytics?exam_id=' . $this->exam->id)
            ->assertOk()->assertDontSee('Results not released yet');

        // ...but once that class starts (a draft status), it must be approved too
        $this->setStatus($classC, 'draft');
        $this->assertFalse(SchoolService::examReleaseStatus($this->exam->id)['released']);
    }

    public function test_headmaster_is_not_blocked_by_the_gate(): void
    {
        $this->setStatus($this->classA, 'submitted');
        $r = $this->actingAs($this->hm)->get('/analytics?exam_id=' . $this->exam->id);
        $r->assertOk()->assertDontSee('Results not released yet')->assertSee('Mathematics');
    }

    public function test_trend_for_teacher_leaves_out_unreleased_exams(): void
    {
        $this->setStatus($this->classA, 'approved');
        $this->setStatus($this->classB, 'submitted');
        $this->assertCount(1, SchoolService::computeTrend($this->classA->id));
        $this->assertCount(0, SchoolService::computeTrend($this->classA->id, 6, true));
    }
}
