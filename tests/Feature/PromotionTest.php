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
}
