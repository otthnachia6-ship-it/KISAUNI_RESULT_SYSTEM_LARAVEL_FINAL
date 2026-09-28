<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FullWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_full_workflow(): void
    {
        $hm = User::where('role', 'headmaster')->first();
        $this->assertNotNull($hm, 'headmaster seeded');
        $hm->update(['must_change_password' => 0]);

        // Dashboard
        $r = $this->actingAs($hm)->get('/dashboard');
        $r->assertStatus(200);
        echo "\n[OK] dashboard\n";

        // Classes
        $r = $this->get('/classes');
        $r->assertStatus(200);
        echo "[OK] classes index\n";

        $class = SchoolClass::where('standard', 1)->first();
        $this->assertNotNull($class);

        // Create a class teacher for this class
        $teacher = User::create([
            'username' => 'teacher1',
            'password' => Hash::make('teacher123'),
            'full_name' => 'Teacher One',
            'role' => 'class_teacher',
            'class_id' => $class->id,
            'active' => 1,
            'must_change_password' => 0,
            'created_at' => SchoolService::nowStr(),
        ]);
        echo "[OK] created class teacher\n";

        // Add a student as headmaster
        $r = $this->post('/students/add', [
            'reg_no' => 'RN-TEST-001',
            'full_name' => 'Juma Testi',
            'gender' => 'Male',
            'class_id' => $class->id,
        ]);
        $r->assertStatus(302);
        $student = Student::where('reg_no', 'RN-TEST-001')->first();
        $this->assertNotNull($student, 'student was created');
        echo "[OK] add student -> " . ($r->headers->get('Location')) . "\n";

        // Subjects for this class's standard
        $subjects = Subject::whereHas('classes', fn($q) => $q->where('classes.id', $class->id))->get();
        echo "[INFO] subjects for class: " . $subjects->count() . "\n";
        $this->assertGreaterThan(0, $subjects->count(), 'class has subjects assigned');

        $exam = Examination::first();
        $this->assertNotNull($exam, 'examination seeded');

        // Marks select page
        $r = $this->actingAs($teacher)->get('/marks');
        $r->assertStatus(200);
        echo "[OK] marks select (class teacher)\n";

        // Marks entry page for this exam/class
        $r = $this->get("/marks/{$exam->id}/{$class->id}");
        if ($r->getStatusCode() >= 400) { echo $r->getContent(); }
        $r->assertStatus(200);
        echo "[OK] marks entry page loaded\n";

        // Submit marks for the one student, all subjects (real field naming: score_{studentId}_{subjectId})
        $flatMarks = [];
        foreach ($subjects as $subj) {
            $flatMarks["score_{$student->id}_{$subj->id}"] = 75;
        }
        $r = $this->post("/marks/{$exam->id}/{$class->id}", $flatMarks);
        echo "[INFO] marks save status: {$r->getStatusCode()}\n";
        if ($r->getStatusCode() >= 400) {
            echo $r->getContent();
        }
        $r->assertStatus(302);
        echo "[OK] marks saved -> " . $r->headers->get('Location') . "\n";

        // Submit for review
        $r = $this->post("/marks/submit/{$exam->id}/{$class->id}");
        echo "[INFO] submit status: {$r->getStatusCode()}\n";
        $r->assertStatus(302);
        echo "[OK] submitted for review -> " . $r->headers->get('Location') . "\n";

        // Headmaster: results list
        $r = $this->actingAs($hm)->get('/results');
        $r->assertStatus(200);
        echo "[OK] results list (headmaster)\n";

        // Review + approve
        $r = $this->get("/results/review/{$exam->id}/{$class->id}");
        $r->assertStatus(200);
        echo "[OK] review page loaded\n";

        $r = $this->post("/results/review/{$exam->id}/{$class->id}", ['action' => 'approve']);
        echo "[INFO] approve status: {$r->getStatusCode()}\n";
        if ($r->getStatusCode() >= 400) { echo $r->getContent(); }
        $r->assertStatus(302);
        echo "[OK] approved -> " . $r->headers->get('Location') . "\n";

        // Review download (PDF)
        $r = $this->get("/results/review/{$exam->id}/{$class->id}/download");
        echo "[INFO] class pdf status: {$r->getStatusCode()}, content-type: " . $r->headers->get('Content-Type') . ", bytes: " . strlen($r->getContent()) . "\n";
        $r->assertStatus(200);
        echo "[OK] class result PDF generated\n";

        // Analytics
        $r = $this->get('/analytics');
        $r->assertStatus(200);
        echo "[OK] analytics (headmaster)\n";

        // Reports index + student report + download
        $r = $this->get('/reports');
        $r->assertStatus(200);
        echo "[OK] reports index\n";

        $r = $this->get("/reports/student/{$student->id}/{$exam->id}");
        $r->assertStatus(200);
        echo "[OK] student report view\n";

        $r = $this->get("/reports/student/{$student->id}/{$exam->id}/download");
        echo "[INFO] student pdf status: {$r->getStatusCode()}, bytes: " . strlen($r->getContent()) . "\n";
        $r->assertStatus(200);
        echo "[OK] student report PDF generated\n";

        // Records, headmaster overview, audit logs, settings
        foreach (['/records', '/headmaster/overview', '/audit-logs', '/settings', '/users', '/subjects', '/examinations'] as $path) {
            $r = $this->get($path);
            echo "[INFO] GET {$path} -> {$r->getStatusCode()}\n";
            if ($r->getStatusCode() >= 400) { echo $r->getContent(); }
        }

        // Student delete/restore/purge
        $r = $this->post("/students/delete/{$student->id}");
        echo "[INFO] delete student: {$r->getStatusCode()} -> " . $r->headers->get('Location') . "\n";
        $r = $this->post("/students/restore/{$student->id}");
        echo "[INFO] restore student: {$r->getStatusCode()} -> " . $r->headers->get('Location') . "\n";

        // Promotion page
        $r = $this->get('/promote');
        echo "[INFO] promote page: {$r->getStatusCode()}\n";
        if ($r->getStatusCode() >= 400) { echo $r->getContent(); }

        echo "\n=== ALL STEPS COMPLETED ===\n";
    }
}
