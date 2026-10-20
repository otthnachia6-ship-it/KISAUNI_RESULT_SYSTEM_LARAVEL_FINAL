<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for two pages that returned wrong output with real data:
 *  - Subjects: every tick-box looked empty because the view looked up a flat "class-subject"
 *    key while the controller builds a nested [class][subject] map.
 *  - Student history: HTTP 500 because the view read ->exam_type on an array.
 */
class SubjectsAndHistoryPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
    }

    public function test_subjects_page_ticks_match_the_class_subjects_table(): void
    {
        $assigned = DB::table('class_subjects')->count();
        $this->assertGreaterThan(0, $assigned);

        $html = $this->actingAs($this->hm)->get('/subjects')->assertStatus(200)->getContent();
        $this->assertSame($assigned, substr_count($html, 'subject-toggle checked'));

        // removing one assignment removes exactly one tick
        $row = DB::table('class_subjects')->first();
        DB::table('class_subjects')->where('class_id', $row->class_id)->where('subject_id', $row->subject_id)->delete();
        $html = $this->actingAs($this->hm)->get('/subjects')->getContent();
        $this->assertSame($assigned - 1, substr_count($html, 'subject-toggle checked'));
    }

    public function test_subjects_page_shows_no_ticks_when_nothing_is_assigned(): void
    {
        DB::table('class_subjects')->delete();
        $html = $this->actingAs($this->hm)->get('/subjects')->assertStatus(200)->getContent();
        $this->assertSame(0, substr_count($html, 'subject-toggle checked'));
    }

    public function test_subjects_page_for_a_class_teacher_shows_check_icons(): void
    {
        $cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $teacher = User::create([
            'username' => 'tchr5', 'password' => bcrypt('Secret#123'), 'full_name' => 'Teacher Five',
            'role' => 'class_teacher', 'class_id' => $cls->id, 'active' => 1, 'must_change_password' => 0,
        ]);
        $assigned = DB::table('class_subjects')->count();
        $html = $this->actingAs($teacher)->get('/subjects')->assertStatus(200)->getContent();
        $this->assertSame($assigned, substr_count($html, 'bi-check-lg text-success'));
    }

    private function studentWithMarks(): array
    {
        $cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $exam = Examination::orderBy('id')->first();
        $sid = DB::table('students')->insertGetId([
            'reg_no' => 'H1', 'full_name' => 'History Pupil', 'gender' => 'Female',
            'class_id' => $cls->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);
        $subjectIds = DB::table('class_subjects')->where('class_id', $cls->id)->pluck('subject_id');
        foreach ($subjectIds as $subId) {
            DB::table('marks')->insert([
                'student_id' => $sid, 'exam_id' => $exam->id, 'subject_id' => $subId,
                'class_id' => $cls->id, 'score' => 70, 'updated_at' => SchoolService::nowStr(),
            ]);
        }
        return [$sid, $exam];
    }

    public function test_student_history_renders_with_exam_results(): void
    {
        [$sid, $exam] = $this->studentWithMarks();
        $response = $this->actingAs($this->hm)->get("/students/{$sid}/history");
        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertStringContainsString('Academic Year ' . $exam->academic_year, $html);
        $this->assertStringContainsString(ucwords(strtolower($exam->exam_type)), $html);
        $this->assertStringContainsString("/reports/student/{$sid}/{$exam->id}", $html);
        $this->assertStringNotContainsString('Attempt to read property', $html);
    }

    public function test_student_history_without_marks_still_renders(): void
    {
        $cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $sid = DB::table('students')->insertGetId([
            'reg_no' => 'H2', 'full_name' => 'No Marks Pupil', 'gender' => 'Male',
            'class_id' => $cls->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);
        $this->actingAs($this->hm)->get("/students/{$sid}/history")->assertStatus(200);
    }

    public function test_student_history_for_unknown_student_is_404(): void
    {
        $this->actingAs($this->hm)->get('/students/999999/history')->assertStatus(404);
    }
}
