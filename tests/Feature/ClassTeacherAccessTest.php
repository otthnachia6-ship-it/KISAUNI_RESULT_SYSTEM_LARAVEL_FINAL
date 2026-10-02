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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A class teacher must only reach data of the class they are assigned to
 * (IDOR checks), and headmaster-only pages must be closed to them.
 */
class ClassTeacherAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $teacherA; protected $classA; protected $classB; protected $studentA; protected $studentB; protected $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->classB = SchoolClass::where('standard', 6)->orderBy('id')->first();
        $this->teacherA = User::create(['username' => 'ta', 'password' => Hash::make('x'), 'full_name' => 'Teacher A',
            'role' => 'class_teacher', 'class_id' => $this->classA->id, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr()]);
        $mk = fn($reg, $cls) => Student::create(['reg_no' => $reg, 'full_name' => "Pupil $reg", 'gender' => 'Male',
            'class_id' => $cls->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $this->studentA = $mk('A1', $this->classA);
        $this->studentB = $mk('B1', $this->classB);
        $this->exam = Examination::first();
        $subj = Subject::where('name', 'Mathematics')->first();
        DB::table('marks')->insert(['student_id' => $this->studentB->id, 'exam_id' => $this->exam->id,
            'subject_id' => $subj->id, 'class_id' => $this->classB->id, 'score' => 55, 'updated_at' => SchoolService::nowStr()]);
    }

    private function ok(string $method, string $uri, array $data = []): int
    {
        $r = $this->actingAs($this->teacherA)->call($method, $uri, $data);
        return $r->getStatusCode();
    }

    public function test_cannot_read_other_class_data(): void
    {
        $e = $this->exam->id; $b = $this->classB->id; $sb = $this->studentB->id;
        foreach ([
            "/marks/$e/$b", "/api/students-by-class/$b", "/api/students-with-marks/$b/$e",
            "/students/$sb/history", "/reports/student/$sb/$e", "/reports/student/$sb/$e/download",
            "/students/edit/$sb",
        ] as $uri) {
            $r = $this->actingAs($this->teacherA)->get($uri);
            $body = $r->getStatusCode() === 200 ? $r->getContent() : '';
            $this->assertStringNotContainsString('Pupil B1', $body, "GET $uri leaked other class student");
            $this->assertNotSame(200, $r->getStatusCode(), "GET $uri returned 200 for a class the teacher does not own");
        }
    }

    public function test_cannot_modify_other_class_data(): void
    {
        $e = $this->exam->id; $b = $this->classB->id; $sb = $this->studentB->id;
        $subj = Subject::where('name', 'Mathematics')->first();

        $this->actingAs($this->teacherA)->post("/marks/$e/$b", ["score_{$sb}_{$subj->id}" => 99]);
        $this->assertEquals(55, DB::table('marks')->where('student_id', $sb)->value('score'), 'marks of other class were changed');

        $this->actingAs($this->teacherA)->post("/marks/submit/$e/$b");
        $this->assertNull(DB::table('exam_class_status')->where('exam_id', $e)->where('class_id', $b)->where('status', 'submitted')->first(),
            'teacher submitted another class');

        $this->actingAs($this->teacherA)->post("/students/delete/$sb");
        $this->assertEquals(1, Student::find($sb)->active, 'teacher deleted a student from another class');

        $this->actingAs($this->teacherA)->post("/students/edit/$sb", ['full_name' => 'Hacked', 'reg_no' => 'B1', 'gender' => 'Male', 'class_id' => $this->classA->id]);
        $this->assertNotSame('Hacked', Student::find($sb)->full_name);
        $this->assertEquals($this->classB->id, Student::find($sb)->class_id);
    }

    public function test_students_list_only_shows_own_class(): void
    {
        $html = $this->actingAs($this->teacherA)->get('/students')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Pupil A1', $html);
        $this->assertStringNotContainsString('Pupil B1', $html);
    }

    public function test_headmaster_only_pages_are_closed_to_teacher(): void
    {
        $e = $this->exam->id; $c = $this->classA->id;
        foreach (['/audit-logs', '/settings', '/classes', '/results', '/headmaster/overview', "/results/review/$e/$c", '/users'] as $uri) {
            $this->assertNotSame(200, $this->ok('GET', $uri), "teacher could open $uri");
        }
        foreach (["/students/purge/{$this->studentA->id}", "/students/restore/{$this->studentA->id}",
                  "/results/review/$e/$c"] as $uri) {
            $this->assertNotSame(200, $this->ok('POST', $uri, ['action' => 'approve']), "teacher POST $uri");
        }
        $this->assertNotNull(Student::find($this->studentA->id));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/dashboard', '/users', '/students', '/settings', '/reports'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
        $this->getJson('/api/user')->assertStatus(401);
    }
}
