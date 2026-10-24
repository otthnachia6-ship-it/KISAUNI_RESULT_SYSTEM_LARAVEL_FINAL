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
 * Reports for class teachers: student reports and the class result sheet open only
 * after EVERY class that took the exam is Approved. Administrator = full headmaster rights.
 */
class ReportReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $admin;
    private User $teacherA;
    private SchoolClass $classA;
    private SchoolClass $classB;
    private Examination $exam;
    private int $studentA;
    private int $studentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->admin = User::create([
            'username' => 'admin_one', 'password' => bcrypt('x'), 'full_name' => 'Admin One',
            'role' => 'headmaster', 'title' => 'Administrator', 'active' => 1, 'must_change_password' => 0,
        ]);
        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->classB = SchoolClass::where('standard', 6)->orderBy('id')->first();
        $this->exam = Examination::orderBy('id')->first();
        $maths = Subject::where('name', 'Mathematics')->first();
        $this->teacherA = User::create([
            'username' => 'teacher_a', 'password' => bcrypt('x'), 'full_name' => 'Teacher A',
            'role' => 'class_teacher', 'class_id' => $this->classA->id, 'active' => 1, 'must_change_password' => 0,
        ]);
        $this->classA->update(['teacher_id' => $this->teacherA->id]);

        foreach ([['A', $this->classA, 70], ['B', $this->classB, 50]] as [$k, $class, $score]) {
            $sid = DB::table('students')->insertGetId([
                'reg_no' => "RG{$k}1", 'full_name' => "Pupil {$k}", 'gender' => 'Male',
                'class_id' => $class->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
            ]);
            DB::table('marks')->insert([
                'student_id' => $sid, 'exam_id' => $this->exam->id, 'subject_id' => $maths->id,
                'class_id' => $class->id, 'score' => $score, 'updated_at' => SchoolService::nowStr(),
            ]);
            $k === 'A' ? $this->studentA = $sid : $this->studentB = $sid;
        }
    }

    private function setStatus(SchoolClass $c, string $status): void
    {
        DB::table('exam_class_status')->updateOrInsert(['exam_id' => $this->exam->id, 'class_id' => $c->id], ['status' => $status]);
    }

    public function test_teacher_cannot_generate_student_report_until_all_classes_are_approved(): void
    {
        $this->setStatus($this->classA, 'approved');
        $this->setStatus($this->classB, 'submitted');
        $url = "/reports/student/{$this->studentA}/{$this->exam->id}";

        $this->actingAs($this->teacherA)->get($url)->assertRedirect('/reports');
        $this->actingAs($this->teacherA)->get($url . '/download')->assertRedirect('/reports');
        $this->assertStringContainsString('1 of 2 classes approved', session('warning'));

        $this->setStatus($this->classB, 'approved');
        $this->actingAs($this->teacherA)->get($url)->assertOk();
        $pdf = $this->actingAs($this->teacherA)->get($url . '/download');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_students_list_for_reports_is_blocked_until_released(): void
    {
        $this->setStatus($this->classA, 'approved');
        $this->setStatus($this->classB, 'draft');
        $this->actingAs($this->teacherA)->getJson("/api/students-with-marks/{$this->classA->id}/{$this->exam->id}")
            ->assertStatus(403)->assertJsonPath('error', 'not_released');

        $this->setStatus($this->classB, 'approved');
        $this->actingAs($this->teacherA)->getJson("/api/students-with-marks/{$this->classA->id}/{$this->exam->id}")
            ->assertOk()->assertJsonFragment(['full_name' => 'Pupil A']);
    }

    public function test_teacher_class_sheet_follows_the_same_rule_and_only_for_own_class(): void
    {
        $sheet = fn($c) => "/reports/class-sheet/{$this->exam->id}/{$c->id}";
        $this->setStatus($this->classA, 'submitted');
        $this->setStatus($this->classB, 'submitted');
        $this->actingAs($this->teacherA)->get($sheet($this->classA))->assertRedirect('/reports');
        $this->assertStringContainsString('Class Result Sheet', session('warning'));

        $this->setStatus($this->classA, 'approved');
        $this->setStatus($this->classB, 'approved');
        $r = $this->actingAs($this->teacherA)->get($sheet($this->classA));
        $r->assertOk();
        $this->assertStringContainsString('application/pdf', $r->headers->get('Content-Type'));
        $this->assertTrue(DB::table('audit_log')->where('action', 'DOWNLOAD_CLASS_SHEET')->exists());

        // another class is never allowed, even when everything is approved
        $this->actingAs($this->teacherA)->get($sheet($this->classB))->assertRedirect('/reports');
    }

    public function test_reports_page_offers_the_class_result_sheet_to_teacher_and_headmaster(): void
    {
        $t = $this->actingAs($this->teacherA)->get('/reports')->assertOk();
        $t->assertSee('Class Result Sheet')->assertSee($this->classA->name);
        $this->assertStringNotContainsString($this->classB->name, $t->getContent());

        $this->actingAs($this->hm)->get('/reports')->assertOk()->assertSee('Class Result Sheet');
    }

    public function test_headmaster_and_administrator_are_not_limited_by_the_gate(): void
    {
        $this->setStatus($this->classA, 'submitted'); // nothing approved at all
        foreach ([$this->hm, $this->admin] as $u) {
            $this->actingAs($u)->get("/reports/student/{$this->studentA}/{$this->exam->id}")->assertOk();
            $this->actingAs($u)->get("/reports/class-sheet/{$this->exam->id}/{$this->classB->id}")->assertOk();
        }
    }

    public function test_administrator_has_every_headmaster_menu_and_page(): void
    {
        $home = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();
        foreach (['Review &amp; Approval', 'Headmaster Overview', 'Users / Accounts', 'Settings', 'Audit Log', 'Promote Students', 'Subjects', 'Classes', 'Examinations'] as $menu) {
            $this->assertStringContainsString($menu, $home, "Administrator menu missing: {$menu}");
        }
        foreach (['/users', '/classes', '/subjects', '/examinations', '/results', '/headmaster/overview', '/settings', '/audit-logs', '/promote'] as $path) {
            $this->actingAs($this->admin)->get($path)->assertOk();
        }
        // and the review / approve workflow
        $this->setStatus($this->classA, 'submitted');
        $this->actingAs($this->admin)->post("/results/review/{$this->exam->id}/{$this->classA->id}", ['action' => 'approve']);
        $this->assertSame('approved', DB::table('exam_class_status')->where('exam_id', $this->exam->id)->where('class_id', $this->classA->id)->value('status'));
    }

    public function test_review_page_has_no_pdf_download_any_more(): void
    {
        $this->setStatus($this->classA, 'submitted');
        $html = $this->actingAs($this->hm)->get("/results/review/{$this->exam->id}/{$this->classA->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('Download PDF', $html);
        $this->assertStringNotContainsString('/download', $html);
        // the old address is gone, the sheet lives in Reports only
        $this->actingAs($this->hm)->get("/results/review/{$this->exam->id}/{$this->classA->id}/download")->assertNotFound();
        $this->actingAs($this->hm)->get("/reports/class-sheet/{$this->exam->id}/{$this->classA->id}")->assertOk();
    }
}
