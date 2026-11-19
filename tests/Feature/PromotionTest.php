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

        // Seeded classes were set up for the current year, so promoting means starting the next year.
        $r = $this->actingAs($hm)->post('/promote', [
            'new_academic_year' => '2027',
            'target_' . $class1->id => $class2->id,
        ]);
        $r->assertStatus(302);

        $student->refresh();
        $this->assertEquals($class2->id, $student->class_id, 'student should have moved to the target class');
        $this->assertSame('2027', (string) \App\Models\Setting::get('academic_year'));
    }

    public function test_a_class_is_promoted_only_once_per_academic_year(): void
    {
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $class1 = SchoolClass::where('standard', 1)->orderBy('id')->first();
        $class2 = SchoolClass::where('standard', 2)->orderBy('id')->first();
        $class3 = SchoolClass::where('standard', 3)->orderBy('id')->first();
        $student = Student::create([
            'reg_no' => 'PROMO-003', 'full_name' => 'Asha Once', 'gender' => 'Female',
            'class_id' => $class1->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);

        // Without a new year, a class already set up for this year is refused and nothing moves.
        $this->actingAs($hm)->post('/promote', ['new_academic_year' => '', 'target_' . $class1->id => $class2->id])
            ->assertSessionHas('danger');
        $this->assertEquals($class1->id, $student->fresh()->class_id);

        // Into 2027: moves once...
        $this->actingAs($hm)->post('/promote', ['new_academic_year' => '2027', 'target_' . $class1->id => $class2->id]);
        $this->assertEquals($class2->id, $student->fresh()->class_id);

        // ...and the same class cannot be promoted again in 2027 (no double promotion by mistake).
        $again = Student::create([
            'reg_no' => 'PROMO-004', 'full_name' => 'Juma Late', 'gender' => 'Male',
            'class_id' => $class1->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);
        $this->actingAs($hm)->post('/promote', ['new_academic_year' => '', 'target_' . $class1->id => $class3->id])
            ->assertSessionHas('danger');
        $this->assertEquals($class1->id, $again->fresh()->class_id);
        $this->assertEquals($class2->id, $student->fresh()->class_id, 'pupils moved this year are not moved twice');
    }

    public function test_class_teacher_promotes_once_and_never_mixes_pupils_with_an_unpromoted_class(): void
    {
        \App\Models\Setting::set('academic_year', '2027'); // the Headmaster has started the new year
        $class1 = SchoolClass::where('standard', 1)->orderBy('id')->first();
        $class2 = SchoolClass::where('standard', 2)->orderBy('id')->first();
        $teacher = User::create([
            'username' => 'mwl_promo', 'password' => bcrypt('x'), 'full_name' => 'Mwalimu Promo',
            'role' => 'class_teacher', 'class_id' => $class1->id, 'active' => 1, 'must_change_password' => 0,
        ]);
        $mine = Student::create(['reg_no' => 'P-1', 'full_name' => 'Mine One', 'gender' => 'Male',
            'class_id' => $class1->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $theirs = Student::create(['reg_no' => 'P-2', 'full_name' => 'Theirs Two', 'gender' => 'Male',
            'class_id' => $class2->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);

        // Standard Two still has its own pupils, not yet promoted for 2027: refused.
        $this->actingAs($teacher)->post('/promote', ['target_class_id' => $class2->id])->assertSessionHas('danger');
        $this->assertEquals($class1->id, $mine->fresh()->class_id);

        // A non-final class cannot "graduate" its pupils.
        $this->actingAs($teacher)->post('/promote', ['target_class_id' => 'graduate'])->assertSessionHas('danger');
        $this->assertSame(1, (int) $mine->fresh()->active);

        // Once Standard Two has moved up, Standard One can follow.
        $class2->update(['last_promoted_year' => '2027']);
        $theirs->update(['class_id' => SchoolClass::where('standard', 3)->orderBy('id')->value('id')]);
        $this->actingAs($teacher)->post('/promote', ['target_class_id' => $class2->id])->assertRedirect(route('students'));
        $this->assertEquals($class2->id, $mine->fresh()->class_id);

        // Second time in the same year: refused.
        $late = Student::create(['reg_no' => 'P-3', 'full_name' => 'Late Three', 'gender' => 'Male',
            'class_id' => $class1->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $this->actingAs($teacher)->post('/promote', ['target_class_id' => $class2->id])->assertSessionHas('danger');
        $this->assertEquals($class1->id, $late->fresh()->class_id);
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
            'new_academic_year' => '2027', 'move_teachers' => 'on',
            'target_' . $class1->id => $class2->id,
        ])->assertStatus(302);

        $this->assertEquals($class2->id, $student->fresh()->class_id);
        $this->assertEquals($class1->id, $teacher->fresh()->class_id);
        $this->assertEquals($teacher->id, $class1->fresh()->teacher_id);
        $this->assertNull($class2->fresh()->teacher_id);
    }
}
