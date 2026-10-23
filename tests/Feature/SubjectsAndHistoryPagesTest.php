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

    public function test_subjects_page_assignments_match_the_class_subjects_table(): void
    {
        $assigned = DB::table('class_subjects')->count();
        $this->assertGreaterThan(0, $assigned);

        $html = $this->actingAs($this->hm)->get('/subjects')->assertStatus(200)->getContent();
        // Subject Assignments card shows the real number
        $this->assertMatchesRegularExpression('/Subject Assignments<\/div><div class="subj-stat-num">' . $assigned . '</', $html);
        // no more one column per class in the main table
        $this->assertStringNotContainsString('subject-toggle', $html);

        $row = DB::table('class_subjects')->first();
        DB::table('class_subjects')->where('class_id', $row->class_id)->where('subject_id', $row->subject_id)->delete();
        $html = $this->actingAs($this->hm)->get('/subjects')->getContent();
        $this->assertMatchesRegularExpression('/Subject Assignments<\/div><div class="subj-stat-num">' . ($assigned - 1) . '</', $html);
    }

    public function test_subjects_page_shows_zero_assignments_when_nothing_is_assigned(): void
    {
        DB::table('class_subjects')->delete();
        $html = $this->actingAs($this->hm)->get('/subjects')->assertStatus(200)->getContent();
        $this->assertMatchesRegularExpression('/Subject Assignments<\/div><div class="subj-stat-num">0</', $html);
        $this->assertStringContainsString('0 classes', $html);
    }

    public function test_subjects_page_for_a_class_teacher_is_read_only(): void
    {
        $cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $teacher = User::create([
            'username' => 'tchr5', 'password' => bcrypt('Secret#123'), 'full_name' => 'Teacher Five',
            'role' => 'class_teacher', 'class_id' => $cls->id, 'active' => 1, 'must_change_password' => 0,
        ]);
        $html = $this->actingAs($teacher)->get('/subjects')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Mathematics', $html);
        $this->assertStringNotContainsString('Add Subject', $html);
        $this->assertStringNotContainsString('manageClassesModal', $html);

        // and cannot change assignments
        $math = Subject::where('name', 'Mathematics')->first();
        $before = DB::table('class_subjects')->count();
        $this->actingAs($teacher)->post('/subjects', ['action' => 'set_class_assignments', 'subject_id' => $math->id, 'class_ids' => []]);
        $this->assertSame($before, DB::table('class_subjects')->count());
    }

    public function test_search_by_name_and_code_and_filters(): void
    {
        $this->actingAs($this->hm);
        $this->post('/subjects', ['action' => 'add_subject', 'name' => 'Computer', 'subject_code' => '122d', 'class_ids' => []]);
        $this->assertSame('122D', Subject::where('name', 'Computer')->value('subject_code')); // letters kept, not turned into a number

        $byName = $this->get('/subjects?q=comput')->getContent();
        $this->assertStringContainsString('Computer', $byName);
        $this->assertStringNotContainsString('Kiswahili', $byName);

        $byCode = $this->get('/subjects?q=122D')->getContent();
        $this->assertStringContainsString('Computer', $byCode);
        $this->assertStringNotContainsString('Kiswahili', $byCode);

        $this->assertStringContainsString('No subjects found', $this->get('/subjects?q=zzzzzz')->getContent());

        $unassigned = $this->get('/subjects?f=unassigned')->getContent();
        $this->assertStringContainsString('Computer', $unassigned);
        $this->assertStringNotContainsString('Kiswahili', $unassigned);

        $nocode = $this->get('/subjects?f=nocode')->getContent();
        $this->assertStringNotContainsString('Computer', $nocode);
    }

    public function test_manage_classes_adds_and_removes_assignments_without_duplicates(): void
    {
        $this->actingAs($this->hm);
        $this->post('/subjects', ['action' => 'add_subject', 'name' => 'Computer', 'subject_code' => '130', 'class_ids' => []]);
        $sub = Subject::where('name', 'Computer')->first();
        $c = SchoolClass::orderBy('sort_order')->take(3)->pluck('id')->all();

        $this->post('/subjects', ['action' => 'set_class_assignments', 'subject_id' => $sub->id, 'class_ids' => [$c[0], $c[1], $c[1]]]);
        $this->assertEqualsCanonicalizing([$c[0], $c[1]], DB::table('class_subjects')->where('subject_id', $sub->id)->pluck('class_id')->all());

        // swap one class: add the third, remove the first
        $this->post('/subjects', ['action' => 'set_class_assignments', 'subject_id' => $sub->id, 'class_ids' => [$c[1], $c[2]]]);
        $this->assertEqualsCanonicalizing([$c[1], $c[2]], DB::table('class_subjects')->where('subject_id', $sub->id)->pluck('class_id')->all());
        $this->assertTrue(DB::table('audit_log')->where('action', 'UPDATE_SUBJECT_CLASSES')->exists());

        // a made-up class id is ignored, the subject itself is untouched
        $this->post('/subjects', ['action' => 'set_class_assignments', 'subject_id' => $sub->id, 'class_ids' => [$c[1], 999999]]);
        $this->assertSame([$c[1]], DB::table('class_subjects')->where('subject_id', $sub->id)->pluck('class_id')->map(fn($i) => (int) $i)->all());
        $this->assertSame('130', $sub->fresh()->subject_code);
    }

    public function test_manage_classes_refuses_to_remove_a_class_that_has_marks_and_changes_nothing(): void
    {
        [$sid, $exam] = $this->studentWithMarks();
        $cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $subjectId = DB::table('marks')->where('student_id', $sid)->value('subject_id');
        $other = SchoolClass::where('standard', 6)->orderBy('id')->first();

        $before = DB::table('class_subjects')->where('subject_id', $subjectId)->pluck('class_id')->sort()->values()->all();
        $marksBefore = DB::table('marks')->count();

        // try to drop the class with marks AND add another class in the same save
        $this->actingAs($this->hm)->post('/subjects', [
            'action' => 'set_class_assignments', 'subject_id' => $subjectId,
            'class_ids' => [$other->id],
        ])->assertRedirect(route('subjects'));

        $this->assertSame($before, DB::table('class_subjects')->where('subject_id', $subjectId)->pluck('class_id')->sort()->values()->all());
        $this->assertSame($marksBefore, DB::table('marks')->count());
        $this->assertStringContainsString('Nothing was saved', session('danger'));
    }

    public function test_deleting_a_subject_with_marks_is_refused_and_results_stay(): void
    {
        [$sid, $exam] = $this->studentWithMarks();
        $subjectId = DB::table('marks')->where('student_id', $sid)->value('subject_id');
        $marks = DB::table('marks')->where('subject_id', $subjectId)->count();

        $this->actingAs($this->hm)->post("/subjects/delete/{$subjectId}");
        $this->assertNotNull(Subject::find($subjectId));
        $this->assertSame($marks, DB::table('marks')->where('subject_id', $subjectId)->count());
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
