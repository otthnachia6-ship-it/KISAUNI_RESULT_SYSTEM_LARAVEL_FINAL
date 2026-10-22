<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\SchoolService;
use App\Services\XlsReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Students > Import from ZEMS: register pupils from a ZEMS result file into one class.
 * Uses the synthetic STD7 fixture (364 pupils, boys and girls, some older ZL.../ numbers).
 */
class ZemsStudentImportTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $class7a;
    private SchoolClass $class7b;
    private array $rows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);

        $this->class7a = SchoolClass::where('standard', 7)->orderBy('id')->first();
        $this->class7b = SchoolClass::where('standard', 7)->orderBy('id')->skip(1)->first();
        $this->teacher = User::create([
            'username' => 't7a', 'password' => Hash::make('x'), 'full_name' => 'Teacher 7A',
            'role' => 'class_teacher', 'class_id' => $this->class7a->id, 'active' => 1,
            'must_change_password' => 0, 'created_at' => SchoolService::nowStr(),
        ]);

        // pupil rows of the fixture: [zems, name]
        $sheet = XlsReader::readFirstSheet(file_get_contents(base_path('tests/Fixtures/zems_big.xls')));
        $this->rows = [];
        foreach (array_slice($sheet, 7) as $r) {
            $this->rows[] = ['zems' => $r[0], 'name' => trim($r[1] . ' ' . $r[2] . ' ' . $r[3]), 'gender' => $r[4]];
        }
    }

    private function upload(?User $as = null, ?int $classId = null, string $fixture = 'zems_big.xls', string $name = 'big.xls')
    {
        $file = UploadedFile::fake()->createWithContent($name, file_get_contents(base_path("tests/Fixtures/$fixture")));
        $data = ['file' => $file];
        if ($classId) {
            $data['class_id'] = $classId;
        }

        return $this->actingAs($as ?? $this->teacher)->post(route('students_import_upload'), $data);
    }

    private function token(): string
    {
        return session('zems_import.token');
    }

    private function confirm(array $selected, ?User $as = null, ?string $token = null)
    {
        return $this->actingAs($as ?? $this->teacher)->post(route('students_import_confirm'), [
            'token' => $token ?? $this->token(),
            'selected' => $selected,
        ]);
    }

    // ---------------------------------------------------------------- pages

    public function test_students_page_has_the_import_button_and_the_form_opens(): void
    {
        $this->actingAs($this->teacher)->get(route('students'))->assertOk()->assertSee('Import from ZEMS');
        $this->actingAs($this->teacher)->get(route('students_import'))->assertOk()->assertSee('ZEMS Excel file');
        $this->actingAs($this->hm)->get(route('students_import'))->assertOk()->assertSee('-- Select Class --');
    }

    public function test_preview_lists_every_pupil_boys_first_and_registers_nothing(): void
    {
        $this->upload()->assertRedirect(route('students_import_preview'));
        $page = $this->actingAs($this->teacher)->get(route('students_import_preview'))->assertOk();

        $page->assertSee('STD7')->assertSee('364 pupil(s)')->assertSee('can be registered')
            ->assertSee('Select all shown')->assertSee('ZL0033/0000/21', false);

        // boys come before girls (same order as the Students page)
        $html = $page->getContent();
        $firstBoy = strpos($html, '>Male<');
        $firstGirl = strpos($html, '>Female<');
        $this->assertNotFalse($firstBoy);
        $this->assertLessThan($firstGirl, $firstBoy);

        $this->assertSame(0, Student::count());
    }

    // ---------------------------------------------------------------- registering

    public function test_ticked_pupils_are_registered_in_the_class_of_the_teacher(): void
    {
        $this->upload();
        $pick = [$this->rows[0], $this->rows[1], $this->rows[200]];

        $this->confirm(array_column($pick, 'zems'))
            ->assertRedirect(route('students'))
            ->assertSessionHas('success');

        $this->assertSame(3, Student::count());
        foreach ($pick as $p) {
            $s = Student::where('zems_no', $p['zems'])->first();
            $this->assertNotNull($s, $p['zems']);
            $this->assertSame($p['name'], $s->full_name);
            $this->assertSame($this->class7a->id, (int) $s->class_id);
            $this->assertSame($p['zems'], $s->reg_no, 'the ZEMS number is the Reg No until the school edits it');
            $this->assertSame($p['gender'] === 'M' ? 'Male' : 'Female', $s->gender);
            $this->assertEquals(1, $s->active);
            $this->assertEquals(1, $s->gender_confirmed);
        }
        $this->assertDatabaseHas('audit_log', ['action' => 'IMPORT_STUDENTS']);
    }

    public function test_registered_pupils_do_not_come_back_and_cannot_be_registered_twice(): void
    {
        $this->upload();
        $first = [$this->rows[0]['zems'], $this->rows[1]['zems']];
        $this->confirm($first);

        $this->upload();
        $this->actingAs($this->teacher)->get(route('students_import_preview'))->assertOk()
            ->assertSee('2 already registered in')
            ->assertSee('362</strong> pupil(s) can be registered', false);

        $this->confirm($first)->assertSessionHas('warning'); // nothing new
        $this->assertSame(2, Student::count());
    }

    public function test_numbers_that_are_not_in_the_file_are_ignored(): void
    {
        $this->upload();
        $this->confirm(['ZE9999999999', $this->rows[3]['zems']]);

        $this->assertSame(1, Student::count());
        $this->assertNull(Student::where('zems_no', 'ZE9999999999')->first());
    }

    public function test_class_teacher_can_only_import_into_their_own_class(): void
    {
        $this->upload($this->teacher, $this->class7b->id); // a forged class_id is ignored
        $this->confirm([$this->rows[0]['zems']]);

        $this->assertSame($this->class7a->id, (int) Student::first()->class_id);
    }

    public function test_headmaster_chooses_the_class(): void
    {
        $this->upload($this->hm, $this->class7b->id);
        $this->confirm([$this->rows[0]['zems'], $this->rows[1]['zems']], $this->hm)
            ->assertRedirect(route('students', ['class_id' => $this->class7b->id]));

        $this->assertSame(2, Student::where('class_id', $this->class7b->id)->count());

        $this->upload($this->hm)->assertSessionHas('danger'); // no class chosen
    }

    // ---------------------------------------------------------------- linking existing pupils

    public function test_existing_pupil_without_a_number_is_linked_not_duplicated(): void
    {
        $row = $this->rows[10];
        $existing = Student::create([
            'reg_no' => 'OLD-1', 'full_name' => $row['name'], 'gender' => $row['gender'] === 'M' ? 'Male' : 'Female',
            'class_id' => $this->class7a->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);

        $this->upload();
        $this->actingAs($this->teacher)->get(route('students_import_preview'))->assertOk()
            ->assertSee('linked to their ZEMS number by name')
            ->assertSee('363</strong> pupil(s) can be registered', false);

        $this->confirm([$this->rows[0]['zems']])->assertSessionHas('success');

        $this->assertSame($row['zems'], $existing->fresh()->zems_no);
        $this->assertSame('OLD-1', $existing->fresh()->reg_no, 'the school Reg No is not touched');
        $this->assertSame(2, Student::count(), 'one linked + one new, no duplicate');
    }

    public function test_a_file_of_a_smaller_standard_is_refused_for_a_standard_seven_teacher(): void
    {
        $this->upload($this->teacher, null, 'zems_std5_arabic.xls', 'small.xls')->assertSessionHas('danger'); // STD5 file, STD7 class
        $this->assertSame(0, Student::count());
    }

    // ---------------------------------------------------------------- safety

    public function test_file_of_another_standard_is_refused(): void
    {
        $class5 = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->upload($this->hm, $class5->id)->assertSessionHas('danger');

        $this->assertNull(session('zems_import'));
    }

    public function test_confirm_needs_the_session_and_the_right_token(): void
    {
        $this->actingAs($this->teacher)->post(route('students_import_confirm'), ['token' => 'x', 'selected' => ['ZE1']])
            ->assertRedirect(route('students_import'))->assertSessionHas('danger');

        $this->upload();
        $this->confirm([$this->rows[0]['zems']], null, 'wrong-token')->assertSessionHas('danger');
        $this->assertSame(0, Student::count());
    }

    public function test_an_older_number_used_as_reg_no_is_reported_not_overwritten(): void
    {
        $row = $this->rows[5];
        Student::create([
            'reg_no' => $row['zems'], 'full_name' => 'Someone Else Entirely', 'gender' => 'Male',
            'class_id' => $this->class7b->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);

        $this->upload();
        $this->confirm([$row['zems'], $this->rows[6]['zems']])->assertSessionHas('warning');

        $this->assertSame(2, Student::count());
        $this->assertSame('Someone Else Entirely', Student::where('reg_no', $row['zems'])->first()->full_name);
    }

    public function test_files_that_are_not_zems_exports_are_rejected_cleanly(): void
    {
        $file = UploadedFile::fake()->createWithContent('x.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 700));
        $this->actingAs($this->teacher)->post(route('students_import_upload'), ['file' => $file])->assertSessionHas('danger');

        $pdf = UploadedFile::fake()->createWithContent('x.pdf', 'hello');
        $this->actingAs($this->teacher)->post(route('students_import_upload'), ['file' => $pdf])->assertSessionHas('danger');

        $this->assertSame(0, Student::count());
    }
}
