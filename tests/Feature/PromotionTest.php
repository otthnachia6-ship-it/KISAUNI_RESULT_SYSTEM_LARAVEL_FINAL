<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers two flows the main FullWorkflowTest does not exercise:
 * year-end promotion (moving real students between classes).
 */
class PromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_promotion_moves_students_to_target_class(): void
    {
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $class1 = SchoolClass::where('standard', 1)->orderBy('id')->first();
        $class2 = SchoolClass::where('standard', 2)->orderBy('id')->first();

        $student = Student::create([
            'reg_no' => 'PROMO-001', 'full_name' => 'Amina Promo', 'gender' => 'Female',
            'class_id' => $class1->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);

        $r = $this->actingAs($hm)->post('/promote', [
            'new_academic_year' => '',
            'target_' . $class1->id => $class2->id,
        ]);
        $r->assertStatus(302);

        $student->refresh();
        $this->assertEquals($class2->id, $student->class_id, 'student should have moved to the target class');
    }

    public function test_promotion_moves_students_only_and_never_the_class_teacher(): void
    {
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $class1 = SchoolClass::where('standard', 1)->orderBy('id')->first();
        $class2 = SchoolClass::where('standard', 2)->orderBy('id')->first();
        $teacher = User::create([
            'username' => 'mwl_one', 'password' => bcrypt('x'), 'full_name' => 'Mwalimu One',
            'role' => 'class_teacher', 'class_id' => $class1->id, 'active' => 1, 'must_change_password' => 0,
        ]);
        $class1->update(['teacher_id' => $teacher->id]);
        $student = Student::create([
            'reg_no' => 'PROMO-002', 'full_name' => 'Zawadi Promo', 'gender' => 'Female',
            'class_id' => $class1->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);

        // The "move teachers" option is gone from the page...
        $this->actingAs($hm)->get('/promote')->assertOk()
            ->assertDontSee('Also move each Class Teacher')
            ->assertDontSee('move_teachers');

        // ...and even an old form that still sends it cannot move the teacher.
        $this->actingAs($hm)->post('/promote', [
            'new_academic_year' => '', 'move_teachers' => 'on',
            'target_' . $class1->id => $class2->id,
        ])->assertStatus(302);

        $this->assertEquals($class2->id, $student->fresh()->class_id);
        $this->assertEquals($class1->id, $teacher->fresh()->class_id);
        $this->assertEquals($teacher->id, $class1->fresh()->teacher_id);
        $this->assertNull($class2->fresh()->teacher_id);
    }
}
