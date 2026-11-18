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

/**
 * Class tools > "Correct marks and approve again": the Headmaster fixes marks of an
 * examination even after approval; saving re-approves, and invalid entries save nothing.
 */
class ClassMarksCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $a;
    private SchoolClass $b;
    private Examination $exam;
    private Subject $maths;
    private Subject $english;
    private Student $s1;
    private Student $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->teacher = User::create(['username' => 'tt', 'password' => Hash::make('x'), 'full_name' => 'Amina Said',
            'role' => 'class_teacher', 'class_id' => null, 'active' => 1, 'must_change_password' => 0, 'created_at' => SchoolService::nowStr()]);

        [$this->a, $this->b] = SchoolClass::orderBy('id')->take(2)->get()->all();
        $this->exam = Examination::create(['exam_type' => 'Midterm', 'academic_year' => '2026', 'created_at' => SchoolService::nowStr()]);
        [$this->maths, $this->english] = Subject::orderBy('id')->take(2)->get()->all();
        DB::table('class_subjects')->whereIn('class_id', [$this->a->id, $this->b->id])->delete();
        foreach ([$this->a, $this->b] as $c) {
            foreach ([$this->maths, $this->english] as $sub) {
                DB::table('class_subjects')->insert(['class_id' => $c->id, 'subject_id' => $sub->id]);
            }
        }
        $this->s1 = $this->pupil('R1', $this->a);
        $this->s2 = $this->pupil('R2', $this->a);
        $this->mark($this->s1, $this->maths, 40);
        $this->mark($this->s1, $this->english, 60);
        $this->mark($this->s2, $this->maths, 70);
        DB::table('exam_class_status')->insert(['exam_id' => $this->exam->id, 'class_id' => $this->a->id, 'status' => 'approved',
            'approved_by' => null, 'approved_at' => '2026-01-01 08:00:00']);
    }

    private function pupil(string $reg, SchoolClass $c): Student
    {
        return Student::create(['reg_no' => $reg, 'full_name' => "Pupil $reg", 'gender' => 'Male', 'class_id' => $c->id,
            'gender_confirmed' => 1, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
    }

    private function mark(Student $s, Subject $sub, float $score, ?SchoolClass $c = null): void
    {
        DB::table('marks')->insert(['student_id' => $s->id, 'exam_id' => $this->exam->id, 'subject_id' => $sub->id,
            'class_id' => ($c ?? $this->a)->id, 'score' => $score]);
    }

    private function score(Student $s, Subject $sub): ?float
    {
        $v = DB::table('marks')->where('student_id', $s->id)->where('exam_id', $this->exam->id)->where('subject_id', $sub->id)->value('score');
        return $v === null ? null : (float) $v;
    }

    private function save(array $marks)
    {
        return $this->actingAs($this->hm)->post(route('class_tools_marks_save', [$this->a->id, $this->exam->id]), ['marks' => $marks]);
    }

    private function approvedAt(): ?string
    {
        return DB::table('exam_class_status')->where('class_id', $this->a->id)->where('exam_id', $this->exam->id)->value('approved_at');
    }

    public function test_tools_page_offers_the_choice_and_continue_opens_the_table(): void
    {
        $this->actingAs($this->hm)->get(route('class_tools', $this->a->id))->assertOk()->assertSee('Correct marks and approve again');

        $this->actingAs($this->hm)->post(route('class_tools_preview', $this->a->id), ['action' => 'edit_marks', 'edit_exam' => $this->exam->id])
            ->assertRedirect(route('class_tools_marks', [$this->a->id, $this->exam->id]));

        $page = $this->actingAs($this->hm)->get(route('class_tools_marks', [$this->a->id, $this->exam->id]))->assertOk();
        $page->assertSee('Pupil R1')->assertSee('Pupil R2')->assertSee('Save &amp; Approve', false);
        $page->assertSee('name="marks[' . $this->s1->id . '][' . $this->maths->id . ']"', false)->assertSee('value="40"', false);
    }

    public function test_unknown_exam_or_teacher_is_refused(): void
    {
        $this->actingAs($this->hm)->post(route('class_tools_preview', $this->a->id), ['action' => 'edit_marks', 'edit_exam' => 9999])
            ->assertRedirect(route('class_tools', $this->a->id));
        $this->actingAs($this->hm)->get(route('class_tools_marks', [$this->b->id, $this->exam->id]))->assertNotFound();
        $this->actingAs($this->teacher)->get(route('class_tools_marks', [$this->a->id, $this->exam->id]))->assertForbidden();
        $this->actingAs($this->teacher)->post(route('class_tools_marks_save', [$this->a->id, $this->exam->id]), ['marks' => [$this->s1->id => [$this->maths->id => 99]]])->assertForbidden();
        $this->assertSame(40.0, $this->score($this->s1, $this->maths));
    }

    public function test_correcting_approved_marks_saves_and_approves_again(): void
    {
        $this->save([
            $this->s1->id => [$this->maths->id => '45.5', $this->english->id => '60'],   // one changed, one same
            $this->s2->id => [$this->english->id => '88'],                                // new mark
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(45.5, $this->score($this->s1, $this->maths));
        $this->assertSame(60.0, $this->score($this->s1, $this->english));
        $this->assertSame(88.0, $this->score($this->s2, $this->english));
        $this->assertSame(70.0, $this->score($this->s2, $this->maths), 'cells not sent are untouched');
        $this->assertSame('approved', DB::table('exam_class_status')->where('class_id', $this->a->id)->value('status'));
        $this->assertNotSame('2026-01-01 08:00:00', $this->approvedAt());
        $this->assertSame($this->hm->id, (int) DB::table('exam_class_status')->where('class_id', $this->a->id)->value('approved_by'));

        $log = DB::table('audit_log')->where('action', 'CLASS_TOOL_EDIT_MARKS')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('2 change(s)', $log->details);
        $this->assertStringContainsString('40 -> 45.5', $log->details);
    }

    public function test_blank_clears_a_mark_and_other_classes_are_untouched(): void
    {
        $other = $this->pupil('R9', $this->b);
        DB::table('marks')->insert(['student_id' => $other->id, 'exam_id' => $this->exam->id, 'subject_id' => $this->maths->id, 'class_id' => $this->b->id, 'score' => 33]);

        $this->save([$this->s1->id => [$this->maths->id => '']])->assertSessionHas('success');

        $this->assertNull($this->score($this->s1, $this->maths));
        $this->assertSame(33.0, $this->score($other, $this->maths));
    }

    public function test_invalid_entries_save_nothing(): void
    {
        $before = $this->approvedAt();
        foreach (['101', '-1', 'abc'] as $bad) {
            $this->save([$this->s1->id => [$this->maths->id => '50', $this->english->id => $bad]])->assertRedirect()->assertSessionHas('danger');
        }
        $this->assertSame(40.0, $this->score($this->s1, $this->maths), 'the valid cell was not saved either');
        $this->assertSame(60.0, $this->score($this->s1, $this->english));
        $this->assertSame($before, $this->approvedAt());
    }

    public function test_saving_without_changes_leaves_approval_and_log_alone(): void
    {
        $before = $this->approvedAt();
        $this->save([$this->s1->id => [$this->maths->id => '40', $this->english->id => '60']])->assertSessionHas('info');
        $this->assertSame($before, $this->approvedAt());
        $this->assertFalse(DB::table('audit_log')->where('action', 'CLASS_TOOL_EDIT_MARKS')->exists());
    }

    public function test_a_promoted_students_kept_marks_can_be_corrected_too(): void
    {
        $promoted = $this->pupil('P1', $this->b);        // now in B, marks stay under A
        $this->mark($promoted, $this->maths, 20);

        $this->actingAs($this->hm)->get(route('class_tools_marks', [$this->a->id, $this->exam->id]))->assertSee('Pupil P1');
        $this->save([$promoted->id => [$this->maths->id => '25']])->assertSessionHas('success');

        $this->assertSame(25.0, $this->score($promoted, $this->maths));
        $this->assertSame($this->a->id, (int) DB::table('marks')->where('student_id', $promoted->id)->value('class_id'));
        $this->assertSame($this->b->id, (int) Student::find($promoted->id)->class_id);
    }
}
