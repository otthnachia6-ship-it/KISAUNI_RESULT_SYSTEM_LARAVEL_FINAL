<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * - every main page opens with the same page header (no more blue banner)
 * - result statuses read "Under review", not UNDER_REVIEW, in one chip style
 * - the dashboard's activity list leaves out routine sign-ins
 * - the Headmaster sees the results waiting for review, with a Review button
 */
class PageHeadersStatusAndAttentionTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private SchoolClass $classA;
    private int $examId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();

        $year = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);
        $this->examId = DB::table('examinations')->insertGetId([
            'exam_type' => 'Midterm', 'academic_year' => $year, 'created_at' => SchoolService::nowStr(),
        ]);
    }

    private function setStatus(string $status): void
    {
        DB::table('exam_class_status')->updateOrInsert(
            ['exam_id' => $this->examId, 'class_id' => $this->classA->id],
            ['status' => $status, 'submitted_at' => '2026-10-12 09:30:00']
        );
    }

    public function test_every_main_page_opens_with_the_shared_page_header(): void
    {
        $names = ['students', 'classes', 'subjects', 'examinations', 'users', 'results', 'marks_select',
            'headmaster_overview', 'promote_students', 'reports', 'settings_page', 'change_password', 'profile',
            'performance_analytics', 'audit_logs', 'records', 'add_student', 'add_user', 'add_students_bulk'];

        foreach ($names as $name) {
            $this->assertTrue(Route::has($name), "missing route $name");
            $page = $this->actingAs($this->hm)->get(route($name));
            $page->assertOk();
            $page->assertSee('<h1 class="page-title">', false);
            $page->assertDontSee('page-header-icon', false);
        }
    }

    public function test_results_page_shows_readable_status_chips(): void
    {
        $this->setStatus('under_review');
        $page = $this->actingAs($this->hm)->get(route('results'))->assertOk();

        $page->assertSee('Under review');
        $page->assertDontSee('UNDER_REVIEW');
        $page->assertSee('status-badge-under_review', false);
        $page->assertSee('Review & Approval');
    }

    public function test_review_page_shows_the_status_chip_in_its_header(): void
    {
        $this->setStatus('submitted');
        $page = $this->actingAs($this->hm)
            ->get(route('review_results', ['exam_id' => $this->examId, 'class_id' => $this->classA->id]))
            ->assertOk();

        // Opening a submitted result for review moves it to "Under review", so either chip is correct here.
        $this->assertMatchesRegularExpression('/status-badge-(submitted|under_review)/', $page->getContent());
        $page->assertSee('<h1 class="page-title">', false);
        $page->assertSee($this->classA->name);
        $page->assertDontSee('UNDER_REVIEW');
    }

    public function test_recent_activities_leave_out_routine_sign_ins(): void
    {
        SchoolService::logAction($this->hm, 'LOGIN', 'headmaster logged in');
        SchoolService::logAction($this->hm, 'LOGOUT', 'headmaster logged out');
        SchoolService::logAction($this->hm, 'ADD_STUDENT', 'Added student Pupil Zed');

        $page = $this->actingAs($this->hm)->get(route('dashboard'))->assertOk();

        $page->assertSee('Pupil Zed');
        $page->assertDontSee('headmaster logged in');
        $page->assertDontSee('headmaster logged out');
    }

    public function test_headmaster_sees_results_waiting_with_a_review_button(): void
    {
        $this->setStatus('submitted');
        $page = $this->actingAs($this->hm)->get(route('dashboard'))->assertOk();

        $page->assertSee('Results waiting for your review');
        $page->assertSee($this->classA->name);
        $page->assertSee('href="' . route('review_results', ['exam_id' => $this->examId, 'class_id' => $this->classA->id]) . '"', false);
    }

    public function test_attention_card_is_hidden_when_nothing_is_waiting_and_for_teachers(): void
    {
        $this->actingAs($this->hm)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Results waiting for your review');

        $this->setStatus('approved');
        $this->actingAs($this->hm)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Results waiting for your review');

        $teacher = User::create(['username' => 'tb', 'password' => bcrypt('x'), 'full_name' => 'Teacher B',
            'role' => 'class_teacher', 'class_id' => $this->classA->id, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr()]);
        $this->setStatus('submitted');
        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Results waiting for your review');
    }
}
