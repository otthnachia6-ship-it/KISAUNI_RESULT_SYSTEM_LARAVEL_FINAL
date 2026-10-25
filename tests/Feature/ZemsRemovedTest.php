<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** ZEMS is gone: pupils are identified by the school's Reg No only. */
class ZemsRemovedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_zems_pages_and_routes_no_longer_exist(): void
    {
        $hm = User::where('role', 'headmaster')->first();
        $hm->update(['must_change_password' => 0]);
        $cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $teacher = User::create([
            'username' => 'tz', 'password' => bcrypt('x'), 'full_name' => 'Tz', 'role' => 'class_teacher',
            'class_id' => $cls->id, 'active' => 1, 'must_change_password' => 0,
        ]);

        $this->actingAs($hm)->get('/students/import')->assertNotFound();
        $this->actingAs($teacher)->post('/marks/upload-ozems')->assertNotFound();

        foreach ([$hm, $teacher] as $u) {
            foreach (['/students', '/students/add', '/students/add-bulk', '/subjects', '/marks/upload', '/dashboard'] as $path) {
                $html = $this->actingAs($u)->get($path)->getContent();
                $this->assertStringNotContainsStringIgnoringCase('zems', $html, "ZEMS still visible on {$path}");
            }
        }
    }

    public function test_students_table_has_no_zems_column_but_subject_codes_stay(): void
    {
        $this->assertFalse(Schema::hasColumn('students', 'zems_no'));
        $this->assertTrue(Schema::hasColumn('students', 'reg_no'));
        $this->assertTrue(Schema::hasColumn('subjects', 'subject_code'));
        $this->assertSame('121D', Subject::where('name', 'Mazingira')->value('subject_code'));
        $this->assertSame('121D', Subject::normalizeCode(' 121d '));
        $this->assertSame('108', Subject::normalizeCode('108.0'));
    }
}
