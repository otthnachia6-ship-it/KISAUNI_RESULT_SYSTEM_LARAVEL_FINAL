<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The dashboard keeps every figure and shortcut it had, shows each role only what it may open,
 * and no longer uses the gradient banner, emoji or the long list of empty classes.
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $classA;
    private SchoolClass $emptyClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);

        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->emptyClass = SchoolClass::where('standard', 7)->orderBy('id')->first();

        $this->teacher = User::create(['username' => 'ta', 'password' => Hash::make('x'), 'full_name' => 'Teacher A',
            'role' => 'class_teacher', 'class_id' => $this->classA->id, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr()]);

        Student::create(['reg_no' => 'A1', 'full_name' => 'Pupil A1', 'gender' => 'Male',
            'class_id' => $this->classA->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
    }

    public function test_headmaster_dashboard_has_header_tiles_links_and_panels(): void
    {
        $page = $this->actingAs($this->hm)->get(route('dashboard'))->assertOk();

        $page->assertSee('Welcome back, ' . $this->hm->full_name);
        $page->assertSee('Quick actions');
        $page->assertSee('Recent activities');
        foreach (['students', 'users', 'classes', 'examinations', 'results', 'reports', 'add_student'] as $name) {
            $page->assertSee('href="' . route($name) . '"', false);
        }
        $page->assertSee('in 1 class');   // one class has students
        $page->assertDontSee('page-actions', false);   // no buttons in the page header; they live under Quick actions
    }

    public function test_class_teacher_sees_no_admin_links_or_audit_panel(): void
    {
        $page = $this->actingAs($this->teacher)->get(route('dashboard'))->assertOk();

        $page->assertSee('Add Student');
        $page->assertSee('Upload Exam Results');
        $page->assertDontSee('Add Class');
        $page->assertDontSee('Add User');
        $page->assertDontSee('Recent activities');
        $page->assertDontSee('href="' . route('users') . '"', false);
        $page->assertDontSee('href="' . route('classes') . '"', false);
        $page->assertDontSee('href="' . route('results') . '"', false);
    }

    public function test_classes_without_students_are_summarised_not_listed(): void
    {
        $page = $this->actingAs($this->hm)->get(route('dashboard'))->assertOk();

        $page->assertSee($this->classA->name);
        $page->assertDontSee($this->emptyClass->name);
        $page->assertSee('without students yet');
    }

    public function test_the_old_banner_and_emoji_are_gone(): void
    {
        $page = $this->actingAs($this->hm)->get(route('dashboard'))->assertOk();

        $page->assertDontSee('dash-hero', false);
        $page->assertDontSee('stat-card', false);
        $page->assertDontSee('👋');
        $page->assertDontSee('🎉');
    }
}
