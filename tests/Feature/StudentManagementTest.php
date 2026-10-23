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
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $hm; protected $c1; protected $c2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->c1 = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->c2 = SchoolClass::where('standard', 6)->orderBy('id')->first();
    }

    public function test_bulk_add_uses_zems_no_validates_rows_and_rejects_duplicates(): void
    {
        $this->actingAs($this->hm)->post('/students/add-bulk', [
            'class_id' => $this->c1->id,
            'zems_no' => ['ZE0000000001', 'ze0000000002', 'ZE0000000002', 'ZE0000000003', 'BAD', ''],
            'full_name' => ['Asha Ali', 'Juma Omar', 'Dup Row', 'No Gender', 'Bad Number', ''],
            'gender' => ['Female', 'Male', 'Male', '', 'Male', ''],
        ]);
        $this->assertSame(2, Student::whereIn('zems_no', ['ZE0000000001', 'ZE0000000002'])->count());
        // the ZEMS number is also kept as the Reg No (same as "Import from ZEMS")
        $this->assertSame('ZE0000000001', Student::where('full_name', 'Asha Ali')->value('reg_no'));
        $this->assertSame(0, Student::where('zems_no', 'ZE0000000003')->count());
        $this->assertSame(0, Student::where('full_name', 'Bad Number')->count());
        $this->assertTrue(DB::table('audit_log')->where('action', 'ADD_STUDENTS_BULK')->exists());

        // an already-registered ZEMS No is refused on a second save
        $this->post('/students/add-bulk', ['class_id' => $this->c1->id, 'zems_no' => ['ZE0000000001'], 'full_name' => ['Again'], 'gender' => ['Male']]);
        $this->assertSame(1, Student::where('zems_no', 'ZE0000000001')->count());
    }

    public function test_single_add_needs_zems_no_and_list_shows_it(): void
    {
        $this->actingAs($this->hm);
        $this->post('/students/add', ['zems_no' => '', 'full_name' => 'Nobody', 'class_id' => $this->c1->id, 'gender' => 'Male']);
        $this->assertSame(0, Student::where('full_name', 'Nobody')->count());

        $this->post('/students/add', ['zems_no' => 'ze0000000099', 'full_name' => 'Hawa Said', 'class_id' => $this->c1->id, 'gender' => 'Female']);
        $st = Student::where('full_name', 'Hawa Said')->first();
        $this->assertSame('ZE0000000099', $st->zems_no);
        $this->assertSame('ZE0000000099', $st->reg_no);

        $html = $this->get('/students')->getContent();
        $this->assertStringContainsString('ZE0000000099', $html);
        $this->assertStringContainsString('ZEMS No', $html);
        $this->assertStringContainsString('ZE0000000099', $this->get('/students?q=000099')->getContent());
        $this->assertStringNotContainsString('name="reg_no"', $this->get('/students/add')->getContent());
    }

    public function test_search_filter_soft_delete_restore_and_purge(): void
    {
        $mk = fn($reg, $name, $cls) => Student::create(['reg_no' => $reg, 'full_name' => $name, 'gender' => 'Male',
            'class_id' => $cls->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $a = $mk('S1', 'Zainab Hassan', $this->c1);
        $b = $mk('S2', 'Omar Khamis', $this->c2);
        $this->actingAs($this->hm);

        $html = $this->get('/students?q=Zainab')->getContent();
        $this->assertStringContainsString('Zainab Hassan', $html);
        $this->assertStringNotContainsString('Omar Khamis', $html);
        $html = $this->get('/students?q=S2')->getContent();
        $this->assertStringContainsString('Omar Khamis', $html);
        $html = $this->get("/students?class_id={$this->c2->id}")->getContent();
        $this->assertStringContainsString('Omar Khamis', $html);
        $this->assertStringNotContainsString('Zainab Hassan', $html);

        // purge is refused while active
        $this->post("/students/purge/{$a->id}")->assertSessionHas('danger');
        $this->assertNotNull(Student::find($a->id));

        // soft delete -> hidden from active list, visible under status=removed
        $this->post("/students/delete/{$a->id}");
        $this->assertEquals(0, $a->fresh()->active);
        $this->get('/students'); // consume the one-time flash message, which itself names the student
        $this->assertStringNotContainsString('Zainab Hassan', $this->get('/students')->getContent());
        $this->assertStringContainsString('Zainab Hassan', $this->get('/students?status=removed')->getContent());

        // restore
        $this->post("/students/restore/{$a->id}");
        $this->assertEquals(1, $a->fresh()->active);

        // purge removes the student and their marks permanently
        $subj = Subject::where('name', 'Mathematics')->first();
        DB::table('marks')->insert(['student_id' => $a->id, 'exam_id' => Examination::first()->id, 'subject_id' => $subj->id,
            'class_id' => $this->c1->id, 'score' => 40, 'updated_at' => SchoolService::nowStr()]);
        $this->post("/students/delete/{$a->id}");
        $this->post("/students/purge/{$a->id}")->assertRedirect();
        $this->assertNull(Student::find($a->id));
        $this->assertSame(0, DB::table('marks')->where('student_id', $a->id)->count());
        foreach (['DELETE_STUDENT', 'RESTORE_STUDENT', 'PURGE_STUDENT'] as $act) {
            $this->assertTrue(DB::table('audit_log')->where('action', $act)->exists(), $act);
        }
    }

    public function test_gender_detection_endpoint(): void
    {
        $r = $this->actingAs($this->hm)->getJson('/api/detect-gender?name=' . urlencode('Fatuma Said'))->assertStatus(200);
        $this->assertContains($r->json('gender'), ['Female', 'Male', null, '']);
    }
}
