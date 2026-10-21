<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Clean tables with quiet icon actions, name in the top bar only on the Dashboard,
 * soft coloured icons on the Dashboard cards, a centred empty message on phones,
 * and the Classes page with Add / View / Edit / Delete.
 */
class TablesTopbarAndClassesTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $classA;
    private SchoolClass $classB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);

        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->classB = SchoolClass::where('standard', 6)->orderBy('id')->first();

        $this->teacher = User::create(['username' => 'tt', 'password' => Hash::make('x'), 'full_name' => 'Amina Said',
            'role' => 'class_teacher', 'class_id' => null, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr()]);
    }

    private function student(string $name, string $gender, SchoolClass $cls, string $reg): Student
    {
        return Student::create(['reg_no' => $reg, 'full_name' => $name, 'gender' => $gender, 'class_id' => $cls->id,
            'gender_confirmed' => 1, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
    }

    // ---------------------------------------------------------------- top bar

    public function test_name_and_role_show_in_the_top_bar_only_on_the_dashboard(): void
    {
        $dash = $this->actingAs($this->hm)->get(route('dashboard'))->assertOk();
        $dash->assertSee('role-pill', false);

        foreach (['students', 'classes', 'users', 'results', 'examinations'] as $name) {
            $page = $this->actingAs($this->hm)->get(route($name))->assertOk();
            $page->assertDontSee('role-pill', false);
            // the round avatar stays, and carries the name as a tooltip
            $page->assertSee('class="user-avatar" title="' . $this->hm->full_name . '"', false);
        }
    }

    public function test_the_same_rule_applies_to_class_teachers(): void
    {
        $this->teacher->update(['class_id' => $this->classA->id]);
        $this->actingAs($this->teacher)->get(route('dashboard'))->assertOk()->assertSee('role-pill', false);
        $this->actingAs($this->teacher)->get(route('students'))->assertOk()->assertDontSee('role-pill', false);
    }

    // ---------------------------------------------------------------- dashboard icons

    public function test_dashboard_cards_have_soft_coloured_icon_squares(): void
    {
        $page = $this->actingAs($this->hm)->get(route('dashboard'))->assertOk();
        foreach (['blue', 'purple', 'navy', 'teal', 'amber', 'green'] as $colour) {
            $page->assertSee('stat-tile-ico stat-tile-ico--' . $colour, false);
        }
    }

    // ---------------------------------------------------------------- phone fixes live in the stylesheet

    public function test_stylesheet_centres_one_cell_rows_and_shrinks_header_buttons_on_phones(): void
    {
        $css = file_get_contents(public_path('css/style.css'));
        $this->assertStringContainsString('.table-stack td[colspan] { justify-content: center; text-align: center !important; }', $css);
        $this->assertStringContainsString('main .page-actions .btn', $css);
    }

    public function test_the_sidebar_keeps_its_width_when_the_page_content_is_wide(): void
    {
        $css = file_get_contents(public_path('css/style.css'));
        $this->assertMatchesRegularExpression('/\n\.sidebar \{[^}]*flex-shrink:\s*0/', $css);
    }

    public function test_review_page_empty_message_is_a_single_wide_cell(): void
    {
        $page = $this->actingAs($this->hm)->get(route('results'))->assertOk();
        $page->assertSee('No results submitted yet.');
        $page->assertSee('colspan="5"', false);
        $page->assertSee('tbl-clean', false);
    }

    // ---------------------------------------------------------------- students table

    public function test_students_table_shows_initials_name_gender_and_quiet_actions(): void
    {
        $this->student('Hamad Khamis', 'Male', $this->classA, 'T001');
        $this->student('Asha Juma', 'Female', $this->classA, 'T002');

        $page = $this->actingAs($this->hm)->get(route('students'))->assertOk();
        $page->assertSee('<span class="person-av" aria-hidden="true">HK</span>', false);
        $page->assertSee('<span class="person-av" aria-hidden="true">AJ</span>', false);
        $page->assertSee('class="ibtn" title="Edit" aria-label="Edit"', false);
        $page->assertSee('class="ibtn ibtn-danger" title="Remove" aria-label="Remove"', false);
        $page->assertDontSee('badge-gender-', false);
        // the boys / girls chips keep their blue and pink
        $page->assertSee('Boys', false);
        $page->assertSee('Girls', false);
        // header buttons now live in the shared header
        $page->assertSee('class="page-actions"', false);
        $page->assertSee(route('add_student'), false);
    }

    public function test_removed_students_view_keeps_restore_and_permanent_delete(): void
    {
        $s = $this->student('Old Pupil', 'Male', $this->classA, 'T009');
        $s->update(['active' => 0]);

        $page = $this->actingAs($this->hm)->get(route('students', ['status' => 'removed']))->assertOk();
        $page->assertSee('title="Restore"', false);
        $page->assertSee('title="Permanently delete"', false);
    }

    // ---------------------------------------------------------------- users table

    public function test_users_table_uses_role_tags_status_chips_and_labelled_actions(): void
    {
        $this->teacher->update(['class_id' => $this->classA->id, 'must_change_password' => 1]);
        $page = $this->actingAs($this->hm)->get(route('users'))->assertOk();

        $page->assertSee('role-tag role-tag--teacher', false);
        $page->assertSee('chip chip--warn', false);
        $page->assertSee('Temp password');
        $page->assertSee('title="Deactivate"', false);
        $page->assertSee('title="Edit"', false);
        $page->assertDontSee('<th>Username</th>', false);
        $page->assertSee('tt');   // the username now sits under the name
    }

    // ---------------------------------------------------------------- examinations

    public function test_examination_delete_is_a_quiet_icon_with_its_confirmation(): void
    {
        \Illuminate\Support\Facades\DB::table('examinations')->insert([
            'exam_type' => 'Terminal', 'academic_year' => '2026', 'created_at' => SchoolService::nowStr(),
        ]);
        $page = $this->actingAs($this->hm)->get(route('examinations'))->assertOk();
        $page->assertSee('title="Remove examination"', false);
        $page->assertSee('appConfirm', false);
    }

    // ---------------------------------------------------------------- classes

    public function test_classes_page_lists_class_teacher_students_status_and_actions(): void
    {
        $this->classA->update(['teacher_id' => $this->teacher->id]);
        $this->teacher->update(['class_id' => $this->classA->id]);
        $this->student('Pupil One', 'Male', $this->classA, 'C001');

        $page = $this->actingAs($this->hm)->get(route('classes'))->assertOk();

        foreach (['Class', 'Class Teacher', 'Students', 'Status', 'Actions'] as $th) {
            $page->assertSee('<th' . ($th === 'Actions' ? ' class="text-end"' : '') . '>' . $th . '</th>', false);
        }
        $page->assertSee($this->classA->name);
        $page->assertSee('Amina Said');
        $page->assertSee('Not assigned');                       // classes without a teacher
        $page->assertSee('chip chip--ok', false);               // Active
        $page->assertSee(route('students', ['class_id' => $this->classA->id]), false);   // View
        $page->assertSee('data-bs-target="#editClassModal"', false);                       // Edit
        $page->assertSee('Search class or teacher...', false);
    }

    public function test_class_with_students_cannot_be_removed_and_empty_one_can(): void
    {
        $this->student('Pupil Two', 'Female', $this->classA, 'C002');
        $page = $this->actingAs($this->hm)->get(route('classes'))->assertOk();

        $page->assertSee('class="ibtn is-disabled"', false);
        $page->assertDontSee(route('delete_class', ['class_id' => $this->classA->id]), false);
        $page->assertSee(route('delete_class', ['class_id' => $this->classB->id]), false);
    }

    public function test_add_class_form_shows_year_and_status_as_read_only(): void
    {
        $year = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);
        $page = $this->actingAs($this->hm)->get(route('classes'))->assertOk();

        $page->assertSee('Academic Year');
        $page->assertSee('value="' . $year . '" readonly', false);
        $page->assertSee('value="Active" readonly', false);
        $page->assertSee('Choose teacher');
    }

    public function test_add_class_with_a_teacher_assigns_the_teacher(): void
    {
        $this->actingAs($this->hm)->post(route('add_class'), [
            'standard' => 5, 'stream' => 'z', 'teacher_id' => $this->teacher->id,
        ])->assertRedirect(route('classes'))->assertSessionHas('success');

        $cls = SchoolClass::where('name', 'Standard Five Z')->first();
        $this->assertNotNull($cls);
        $this->assertSame($this->teacher->id, (int) $cls->teacher_id);
        $this->assertSame($cls->id, (int) $this->teacher->fresh()->class_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'ADD_CLASS']);
        $this->assertDatabaseHas('audit_log', ['action' => 'ASSIGN_CLASS_TEACHER']);
    }

    public function test_add_class_without_a_teacher_works_as_before(): void
    {
        $this->actingAs($this->hm)->post(route('add_class'), ['standard' => 5, 'stream' => 'Y'])
            ->assertRedirect(route('classes'))->assertSessionHas('success');

        $cls = SchoolClass::where('name', 'Standard Five Y')->first();
        $this->assertNotNull($cls);
        $this->assertNull($cls->teacher_id);
    }

    public function test_add_class_ignores_an_inactive_or_wrong_role_teacher(): void
    {
        $this->teacher->update(['active' => 0]);
        $this->actingAs($this->hm)->post(route('add_class'), [
            'standard' => 5, 'stream' => 'X', 'teacher_id' => $this->teacher->id,
        ])->assertRedirect(route('classes'));

        $cls = SchoolClass::where('name', 'Standard Five X')->first();
        $this->assertNotNull($cls);
        $this->assertNull($cls->teacher_id);
        $this->assertNull($this->teacher->fresh()->class_id);

        $this->actingAs($this->hm)->post(route('add_class'), [
            'standard' => 5, 'stream' => 'W', 'teacher_id' => $this->hm->id,    // a headmaster is not a class teacher
        ])->assertRedirect(route('classes'));
        $this->assertNull(SchoolClass::where('name', 'Standard Five W')->first()->teacher_id);
    }

    public function test_edit_dialog_posts_to_the_existing_assign_route(): void
    {
        $this->actingAs($this->hm)->post(route('classes'), [
            'class_id' => $this->classB->id, 'teacher_id' => $this->teacher->id,
        ])->assertRedirect(route('classes'));
        $this->assertSame($this->teacher->id, (int) $this->classB->fresh()->teacher_id);

        $this->actingAs($this->hm)->post(route('classes'), ['class_id' => $this->classB->id, 'teacher_id' => ''])
            ->assertRedirect(route('classes'));
        $this->assertNull($this->classB->fresh()->teacher_id);
        $this->assertNull($this->teacher->fresh()->class_id);
    }

    public function test_class_teachers_still_cannot_use_the_classes_page(): void
    {
        $this->teacher->update(['class_id' => $this->classA->id]);
        $this->actingAs($this->teacher)->post(route('add_class'), ['standard' => 5, 'stream' => 'V'])->assertForbidden();
        $this->actingAs($this->teacher)->post(route('classes'), ['class_id' => $this->classA->id, 'teacher_id' => ''])->assertForbidden();
    }
}
