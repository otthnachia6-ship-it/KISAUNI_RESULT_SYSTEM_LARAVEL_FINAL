<?php

namespace Tests\Feature;

use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 1. A class teacher cannot move a student out of their own class when editing.
 * 4. Settings refuse an empty school name or an invalid academic year.
 * 5. Saving the marks sheet stores, changes and clears marks correctly in one go.
 */
class StudentEditSettingsAndMarksSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $classA;
    private SchoolClass $classB;
    private Student $pupil;
    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->classB = SchoolClass::where('standard', 6)->orderBy('id')->first();
        $this->teacher = User::create(['username' => 'ta', 'password' => Hash::make('x'), 'full_name' => 'Teacher A',
            'role' => 'class_teacher', 'class_id' => $this->classA->id, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr()]);
        $this->pupil = Student::create(['reg_no' => 'A1', 'full_name' => 'Ali Juma', 'gender' => 'Male',
            'class_id' => $this->classA->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $this->exam = Examination::first();
    }

    private function editAs(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->post("/students/edit/{$this->pupil->id}", array_merge([
            'reg_no' => 'A1', 'full_name' => 'Ali Juma', 'gender' => 'Male', 'class_id' => $this->classA->id,
        ], $overrides));
    }

    // ---- 1. Student edit -------------------------------------------------

    public function test_class_teacher_cannot_move_student_to_another_class(): void
    {
        $this->editAs($this->teacher, ['class_id' => $this->classB->id])->assertSessionHas('danger');
        $this->assertSame($this->classA->id, (int) $this->pupil->fresh()->class_id);
    }

    public function test_class_teacher_can_still_edit_own_student(): void
    {
        $this->editAs($this->teacher, ['full_name' => 'Ali Juma Hassan'])->assertSessionHas('success');
        $this->assertSame('Ali Juma Hassan', $this->pupil->fresh()->full_name);
        $this->assertSame($this->classA->id, (int) $this->pupil->fresh()->class_id);
    }

    public function test_headmaster_can_move_student_to_another_class(): void
    {
        $this->editAs($this->hm, ['class_id' => $this->classB->id])->assertSessionHas('success');
        $this->assertSame($this->classB->id, (int) $this->pupil->fresh()->class_id);
    }

    public function test_empty_name_or_unknown_class_is_rejected(): void
    {
        $this->editAs($this->hm, ['full_name' => '   '])->assertSessionHas('danger');
        $this->editAs($this->hm, ['class_id' => 999999])->assertSessionHas('danger');
        $fresh = $this->pupil->fresh();
        $this->assertSame('Ali Juma', $fresh->full_name);
        $this->assertSame($this->classA->id, (int) $fresh->class_id);
    }

    // ---- 4. Settings -----------------------------------------------------

    public function test_settings_reject_empty_or_invalid_academic_year(): void
    {
        Setting::set('academic_year', '2026');
        foreach (['', '   ', 'abc', '26', '2026/27'] as $bad) {
            $this->actingAs($this->hm)
                ->post('/settings', ['school_name' => 'KISAUNI PRIMARY SCHOOL', 'academic_year' => $bad])
                ->assertSessionHas('danger');
            $this->assertSame('2026', Setting::get('academic_year'), "'{$bad}' must not be saved");
        }
    }

    public function test_settings_reject_empty_school_name(): void
    {
        Setting::set('school_name', 'KISAUNI PRIMARY SCHOOL');
        $this->actingAs($this->hm)
            ->post('/settings', ['school_name' => '', 'academic_year' => '2027'])
            ->assertSessionHas('danger');
        $this->assertSame('KISAUNI PRIMARY SCHOOL', Setting::get('school_name'));
        $this->assertNotSame('2027', Setting::get('academic_year'), 'nothing is saved when one field is invalid');
    }

    public function test_settings_accept_valid_years(): void
    {
        foreach (['2027', '2027/2028', '2027-2028'] as $good) {
            $this->actingAs($this->hm)
                ->post('/settings', ['school_name' => 'KISAUNI PRIMARY SCHOOL', 'academic_year' => $good])
                ->assertSessionHas('success');
            $this->assertSame($good, Setting::get('academic_year'));
        }
    }

    // ---- 5. Marks save ---------------------------------------------------

    public function test_marks_save_inserts_updates_and_clears(): void
    {
        $subjects = DB::table('class_subjects')->where('class_id', $this->classA->id)->pluck('subject_id')->all();
        $this->assertGreaterThanOrEqual(2, count($subjects));
        [$s1, $s2] = $subjects;
        $other = Student::create(['reg_no' => 'A2', 'full_name' => 'Asha Ali', 'gender' => 'Female',
            'class_id' => $this->classA->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $url = "/marks/{$this->exam->id}/{$this->classA->id}";
        $p = $this->pupil->id;
        $o = $other->id;

        // First save: new marks are inserted (decimals kept).
        $this->actingAs($this->teacher)->post($url, [
            "score_{$p}_{$s1}" => '20.95', "score_{$p}_{$s2}" => '70', "score_{$o}_{$s1}" => '55',
        ])->assertSessionHas('success');

        $score = fn ($st, $sub) => DB::table('marks')->where('student_id', $st)->where('exam_id', $this->exam->id)
            ->where('subject_id', $sub)->value('score');
        $this->assertEquals(20.95, $score($p, $s1));
        $this->assertEquals(70, $score($p, $s2));
        $this->assertEquals(55, $score($o, $s1));
        $this->assertNull($score($o, $s2));
        $rowsAfterFirst = DB::table('marks')->where('exam_id', $this->exam->id)->count();

        // Second save: one mark changed, one cleared, a bad value is refused with a warning
        // (and a bad value typed over a saved mark keeps the saved mark).
        $this->actingAs($this->teacher)->post($url, [
            "score_{$p}_{$s1}" => '81', "score_{$p}_{$s2}" => '', "score_{$o}_{$s1}" => '5o', "score_{$o}_{$s2}" => '150',
        ])->assertSessionHas('warning', fn ($m) => str_contains($m, 'previous value was kept'));

        $this->assertEquals(81, $score($p, $s1));
        $this->assertNull($score($p, $s2));
        $this->assertEquals(55, $score($o, $s1), 'a typing mistake must not wipe the saved mark');
        $this->assertNull($score($o, $s2));

        // A box that is not in the form at all (e.g. form cut short) is left untouched.
        $this->actingAs($this->teacher)->post($url, ["score_{$p}_{$s2}" => '64'])->assertSessionHas('success');
        $this->assertEquals(81, $score($p, $s1));
        $this->assertEquals(55, $score($o, $s1));
        $this->assertEquals(64, $score($p, $s2));
        $this->actingAs($this->teacher)->post($url, ["score_{$p}_{$s2}" => '']);
        $this->assertSame($rowsAfterFirst, DB::table('marks')->where('exam_id', $this->exam->id)->count(), 'no duplicate rows');

        $status = ExamClassStatus::where('exam_id', $this->exam->id)->where('class_id', $this->classA->id)->first();
        $this->assertSame('draft', $status->status);
    }

    public function test_marks_save_is_refused_when_locked(): void
    {
        $sub = DB::table('class_subjects')->where('class_id', $this->classA->id)->value('subject_id');
        ExamClassStatus::create(['exam_id' => $this->exam->id, 'class_id' => $this->classA->id, 'status' => 'approved']);

        $this->actingAs($this->teacher)
            ->post("/marks/{$this->exam->id}/{$this->classA->id}", ["score_{$this->pupil->id}_{$sub}" => '50'])
            ->assertSessionHas('warning');
        $this->assertSame(0, DB::table('marks')->where('exam_id', $this->exam->id)->count());
    }
}
