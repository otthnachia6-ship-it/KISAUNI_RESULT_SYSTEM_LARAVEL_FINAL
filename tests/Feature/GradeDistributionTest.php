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
 * Grade boundaries (E 0-20.9, D 21-40.9, C 41-60.9, B 61-80.9, A 81-100, with
 * no rounding of individual marks) and the circular per-subject grade counts
 * on the Performance Analytics page.
 */
class GradeDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_grade_boundaries_including_decimals(): void
    {
        $cases = [
            0 => 'E', 20.9 => 'E', 20.95 => 'E', 21 => 'D', 40.9 => 'D', 40.95 => 'D', 41 => 'C',
            60.9 => 'C', 60.95 => 'C', 61 => 'B', 80.9 => 'B', 80.95 => 'B', 81 => 'A', 100 => 'A',
        ];
        foreach ($cases as $score => $expected) {
            [$g] = SchoolService::gradeForScore($score);
            $this->assertSame($expected, $g, "score {$score}");
        }
    }

    public function test_analytics_shows_correct_circular_counts_per_grade(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $class = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $exam = Examination::first();
        $subject = Subject::where('name', 'Mathematics')->first();
        $now = SchoolService::nowStr();

        // one student per score; expected counts: A=2, B=2, C=2, D=3, E=3
        $scores = [0, 20.9, 20.95, 21, 40.9, 40.95, 60.9, 60.95, 80.9, 80.95, 81, 100];
        foreach ($scores as $i => $sc) {
            $sid = DB::table('students')->insertGetId([
                'reg_no' => "G{$i}", 'full_name' => "Student {$i}", 'gender' => 'Male',
                'class_id' => $class->id, 'active' => 1, 'created_at' => $now,
            ]);
            DB::table('marks')->insert([
                'student_id' => $sid, 'exam_id' => $exam->id, 'subject_id' => $subject->id,
                'class_id' => $class->id, 'score' => $sc, 'updated_at' => $now,
            ]);
        }

        $html = $this->actingAs($hm)
            ->get("/analytics?exam_id={$exam->id}&class_id={$class->id}")
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('grade-count-circle', $html);
        $this->assertSame(0, substr_count($html, 'Chart(') , 'no chart library calls expected for the grade table');

        $this->assertSame(1, preg_match('/Mathematics<\/td>(.*?)<\/tr>/s', $html, $row), 'Mathematics row missing');
        preg_match_all('/grade-dot-([A-E])( is-zero)?"[^>]*>\s*(\d+)\s*<\/span>/s', $row[1], $m, PREG_SET_ORDER);
        $got = [];
        foreach ($m as $x) { $got[$x[1]] = (int) $x[3]; }
        $this->assertSame(['A' => 2, 'B' => 2, 'C' => 2, 'D' => 3, 'E' => 3], $got);
    }

    public function test_grade_with_no_students_still_renders_a_dimmed_circle(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $class = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $exam = Examination::first();
        $subject = Subject::where('name', 'Mathematics')->first();
        $sid = DB::table('students')->insertGetId(['reg_no' => 'Z1', 'full_name' => 'Solo', 'gender' => 'Female',
            'class_id' => $class->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        DB::table('marks')->insert(['student_id' => $sid, 'exam_id' => $exam->id, 'subject_id' => $subject->id,
            'class_id' => $class->id, 'score' => 75, 'updated_at' => SchoolService::nowStr()]);

        $html = $this->actingAs($hm)->get("/analytics?exam_id={$exam->id}&class_id={$class->id}")->getContent();
        preg_match('/Mathematics<\/td>(.*?)<\/tr>/s', $html, $row);
        $this->assertSame(4, substr_count($row[1], 'is-zero'), 'A, C, D, E have no students -> dimmed');
        $this->assertSame(1, preg_match('/grade-dot-B"[^>]*>\s*1\s*</s', $row[1]));
    }
}
