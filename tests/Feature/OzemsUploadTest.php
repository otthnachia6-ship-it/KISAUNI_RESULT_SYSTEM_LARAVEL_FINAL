<?php

namespace Tests\Feature;

use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\OzemsImporter;
use App\Services\SchoolService;
use App\Services\XlsReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ZEMS (Ozems) result upload: Excel (.xls) and CSV files, subject codes, ZEMS numbers on pupils,
 * Subject code CRUD and the Class-Teacher-only upload workflow.
 * The .xls fixtures in tests/Fixtures are synthetic (no real pupils).
 */
class OzemsUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $classA;
    private SchoolClass $classB;
    private Examination $exam;
    private Student $s1;
    private Student $s2;
    private Student $s3;
    private Student $s4;
    private Student $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);

        $this->classA = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->classB = SchoolClass::where('standard', 6)->orderBy('id')->first();
        $this->teacher = User::create([
            'username' => 'ta', 'password' => Hash::make('x'), 'full_name' => 'Teacher A',
            'role' => 'class_teacher', 'class_id' => $this->classA->id, 'active' => 1,
            'must_change_password' => 0, 'created_at' => SchoolService::nowStr(),
        ]);

        $mk = fn($reg, $zems, $name, $gender, $cls) => Student::create([
            'reg_no' => $reg, 'zems_no' => $zems, 'full_name' => $name, 'gender' => $gender,
            'class_id' => $cls->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);
        $this->s1 = $mk('R1', 'ZE0000000001', 'Amina Juma Ali', 'Female', $this->classA);
        $this->s2 = $mk('R2', 'ZE0000000002', 'Bakari Haji Omar', 'Male', $this->classA);
        $this->s3 = $mk('R3', null, 'Fatma Said Mussa', 'Female', $this->classA);
        $this->s4 = $mk('R4', 'ZL0033/0214/21', 'Khamis Ali Hamad', 'Male', $this->classA);
        $this->other = $mk('R9', 'ZE0000000009', 'Zuhura Omar Ame', 'Female', $this->classB);

        $this->exam = Examination::first();
    }

    /**
     * Builds a CSV laid out like the real ZEMS sheet: SUBJECT CODE / NAME / YEAR on row 5,
     * class label and term in the two header rows, pupils from row 8.
     * Each pupil: [zems, score, name = 'Some Name', gender = ''].
     */
    private function csv(string $code, string $name, array $students, string $delim = ',', bool $bom = false, ?string $std = null, ?string $year = null): string
    {
        $rows = [
            [],
            ['ZANZIBAR EXAMINATIONS COUNCIL'],
            ['STUDENT CONTINUOUS ASSESSMENT FORM FOR PRIMARY SCHOOLS'],
            ['FORM02', 'CENTER No:', 'ZP0000', 'CENTER NAME:', 'TESTSCHOOL'],
            ['', 'SUBJECT CODE:', $code, 'SUBJECT NAME:', $name, $year ? "YEAR: $year" : ''],
            ['ZEMS NO', 'CANDIDATE FULL NAME', '', '', 'GENDER', $std ?? ''],
            ['', 'FIRST NAME', 'MIDDLE NAME', 'SURNAME', '', 'TERM ONE'],
        ];
        foreach ($students as $st) {
            $rows[] = [$st[0], $st[2] ?? 'Some Name', '', '', $st[3] ?? '', $st[1]];
        }

        $h = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($h, $r, $delim);
        }
        rewind($h);
        $out = stream_get_contents($h);
        fclose($h);

        return ($bom ? "\xEF\xBB\xBF" : '') . $out;
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/$name"));
    }

    private function upload(string $content, ?User $as = null, string $filename = 'math.csv', ?int $examId = null)
    {
        $file = UploadedFile::fake()->createWithContent($filename, $content);

        return $this->actingAs($as ?? $this->teacher)->post(route('marks.upload_ozems'), [
            'exam_id' => $examId ?? $this->exam->id,
            'file' => $file,
        ]);
    }

    private function subject(string $name): Subject
    {
        return Subject::where('name', $name)->firstOrFail();
    }

    private function score(Student $s, string $subjectName): ?float
    {
        $v = DB::table('marks')->where('student_id', $s->id)->where('exam_id', $this->exam->id)
            ->where('subject_id', $this->subject($subjectName)->id)->value('score');

        return $v === null ? null : (float) $v;
    }

    // ---------------------------------------------------------------- schema / codes

    public function test_new_columns_exist_and_seeded_subjects_have_zems_codes(): void
    {
        $this->assertTrue(Schema::hasColumn('subjects', 'subject_code'));
        $this->assertTrue(Schema::hasColumn('students', 'zems_no'));

        $this->assertSame('101', $this->subject('English')->subject_code);
        $this->assertSame('102', $this->subject('Kiswahili')->subject_code);
        $this->assertSame('104', $this->subject('Mathematics')->subject_code);
        $this->assertSame('107', $this->subject('Dini')->subject_code);
        $this->assertSame('108', $this->subject('Arabic')->subject_code);
        $this->assertSame('110', $this->subject('S.JAMII')->subject_code);
        $this->assertSame('114', $this->subject('Science and Technology')->subject_code);
        $this->assertSame('121D', $this->subject('Mazingira')->subject_code);
        $this->assertNull($this->subject('SUMI')->subject_code);
    }

    public function test_code_and_zems_number_normalisation(): void
    {
        $this->assertSame('108', OzemsImporter::normalizeCode('108.0'));
        $this->assertSame('108', OzemsImporter::normalizeCode(' 108.00 '));
        $this->assertSame('121D', OzemsImporter::normalizeCode('121d'));
        $this->assertSame('', OzemsImporter::normalizeCode(null));

        $this->assertSame('ZE0423951897', OzemsImporter::normalizeZems(' ze0423951897 '));
        $this->assertSame('ZL0033/0214/21', OzemsImporter::normalizeZems('zl0033/0214/21'));
        $this->assertNull(OzemsImporter::normalizeZems('  '));

        foreach (['ZE0423951897', 'ZE20260417996', 'ZE202606023290', 'ZL0033/0214/21'] as $ok) {
            $this->assertTrue(OzemsImporter::looksLikeZems($ok), $ok);
        }
        foreach (['ZEMS NO', 'hello', 'GENDER', '12', ''] as $bad) {
            $this->assertFalse(OzemsImporter::looksLikeZems($bad), $bad);
        }
        $this->assertSame(OzemsImporter::nameKey('Amina  juma-Ali'), OzemsImporter::nameKey('ALI AMINA JUMA'));
    }

    // ---------------------------------------------------------------- Excel (.xls) as exported from ZEMS

    public function test_excel_reader_reads_a_large_file_with_accents_and_arabic_letters(): void
    {
        $rows = XlsReader::readFirstSheet($this->fixture('zems_big.xls'));

        $this->assertCount(371, $rows);
        $this->assertSame('SUBJECT CODE:', $rows[4][1]);
        $this->assertSame('114', $rows[4][2]);                       // 114.0 becomes "114"
        $this->assertSame('ZL0033/0000/21', $rows[7][0]);            // older numbers with slashes
        $this->assertSame('0.5', $rows[7][5]);
        $this->assertStringStartsWith('عائشة', $rows[9][1]);         // Arabic letters
        $this->assertStringStartsWith('ÉLODIE', $rows[8][1]);        // accents
        $this->assertSame('7.5', $rows[14][5]);                      // decimals
        $this->assertSame('99', $rows[7 + 99][5]);
    }

    public function test_excel_file_exported_from_zems_is_read_and_saved(): void
    {
        $this->upload($this->fixture('zems_std5_arabic.xls'), null, 'ZP0000-108-2026.xls')
            ->assertRedirect(route('marks_upload', ['exam' => $this->exam->id]))
            ->assertSessionHas('warning')
            ->assertSessionHas('ozems_report.saved', 4)
            ->assertSessionHas('ozems_report.other_class', 1)
            ->assertSessionHas('ozems_report.not_found_total', 1)
            ->assertSessionHas('ozems_report.linked_total', 1)
            ->assertSessionHas('ozems_report.file_std', 5)
            ->assertSessionHas('ozems_report.file_term', 'TERM ONE');

        $this->assertSame(59.0, $this->score($this->s1, 'Arabic'));
        $this->assertSame(69.5, $this->score($this->s2, 'Arabic'));
        $this->assertSame(35.0, $this->score($this->s4, 'Arabic'), 'older ZL0033/0214/21 numbers must work');
        $this->assertSame(80.0, $this->score($this->s3, 'Arabic'));
        $this->assertNull($this->score($this->other, 'Arabic'), 'a pupil of another stream must never be touched');

        $this->assertSame('ZE0000000003', $this->s3->fresh()->zems_no, 'linked by exact name');
        $this->assertSame('ZE0000000009', $this->other->fresh()->zems_no);
        $this->assertSame('draft', ExamClassStatus::where('exam_id', $this->exam->id)
            ->where('class_id', $this->classA->id)->value('status'));
    }

    public function test_second_excel_upload_replaces_marks_and_links_nothing_new(): void
    {
        $this->upload($this->fixture('zems_std5_arabic.xls'), null, 'a.xls');
        $this->upload($this->fixture('zems_std5_arabic.xls'), null, 'a.xls')
            ->assertSessionHas('ozems_report.saved', 4)
            ->assertSessionHas('ozems_report.linked_total', 0);

        $this->assertSame(1, DB::table('marks')->where('student_id', $this->s3->id)->count());
    }

    public function test_excel_file_that_is_damaged_or_old_is_rejected_cleanly(): void
    {
        $broken = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 900);
        $this->upload($broken, null, 'broken.xls')->assertSessionHas('danger');

        $this->upload("PK\x03\x04" . str_repeat('x', 200), null, 'new.xlsx')->assertSessionHas('danger');
        $this->upload("PK\x03\x04" . str_repeat('x', 200), null, 'renamed.xls')->assertSessionHas('danger');

        $this->assertSame(0, DB::table('marks')->count());
    }

    // ---------------------------------------------------------------- upload rules (CSV)

    public function test_upload_saves_marks_sets_draft_and_reports_problems(): void
    {
        $csv = $this->csv('104.0', 'MATHEMATICS', [
            ['ZE0000000001', '59.0'],
            ['ZE0000000002', '72,5'],
            ['ZE0000000009', '85.0'],   // pupil of another class (stream)
            ['ZE0000000077', '40.0'],   // unknown number
        ]);

        $this->upload($csv)
            ->assertRedirect(route('marks_upload', ['exam' => $this->exam->id]))
            ->assertSessionHas('warning')
            ->assertSessionHas('ozems_report.saved', 2)
            ->assertSessionHas('ozems_report.other_class', 1)
            ->assertSessionHas('ozems_report.not_found_total', 1);

        $this->assertSame(59.0, $this->score($this->s1, 'Mathematics'));
        $this->assertSame(72.5, $this->score($this->s2, 'Mathematics'));
        $this->assertNull($this->score($this->other, 'Mathematics'), 'a pupil of another class must never be touched');

        $this->assertSame('draft', ExamClassStatus::where('exam_id', $this->exam->id)
            ->where('class_id', $this->classA->id)->value('status'));
        $this->assertDatabaseHas('audit_log', ['action' => 'UPLOAD_OZEMS', 'username' => 'ta']);

        $row = DB::table('marks')->where('student_id', $this->s1->id)->first();
        $this->assertEquals($this->classA->id, $row->class_id);
        $this->assertEquals($this->teacher->id, $row->entered_by);
    }

    public function test_clean_upload_shows_success(): void
    {
        $this->upload($this->csv('108.0', 'ARABIC', [['ZE0000000001', '41'], ['ZE0000000002', '90']]))
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');

        $this->assertSame(41.0, $this->score($this->s1, 'Arabic'));
        $this->assertSame(90.0, $this->score($this->s2, 'Arabic'));
    }

    public function test_uploading_again_replaces_marks_without_duplicates(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']]));
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '66']]));

        $this->assertSame(66.0, $this->score($this->s1, 'Mathematics'));
        $this->assertSame(1, DB::table('marks')->where('student_id', $this->s1->id)
            ->where('subject_id', $this->subject('Mathematics')->id)->count());
    }

    public function test_bad_scores_are_skipped_and_nothing_is_saved_for_them(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [
            ['ZE0000000001', 'ABS'],
            ['ZE0000000002', '150'],
        ]))->assertSessionHas('warning')->assertSessionHas('ozems_report.saved', 0);

        $this->assertNull($this->score($this->s1, 'Mathematics'));
        $this->assertNull($this->score($this->s2, 'Mathematics'));
        $this->assertNull(ExamClassStatus::where('exam_id', $this->exam->id)->where('class_id', $this->classA->id)->first(),
            'nothing saved, so the status must not change');
    }

    public function test_empty_scores_do_not_overwrite_existing_marks(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']]));
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '']]));

        $this->assertSame(50.0, $this->score($this->s1, 'Mathematics'));
    }

    public function test_repeated_zems_number_in_one_file_is_used_once(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [
            ['ZE0000000001', '10'],
            ['ZE0000000001', '99'],
        ]))->assertSessionHas('ozems_report.duplicates_total', 1);

        $this->assertSame(10.0, $this->score($this->s1, 'Mathematics'));
    }

    public function test_semicolon_files_with_a_bom_are_read(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '77']], ';', true))
            ->assertSessionHas('success');

        $this->assertSame(77.0, $this->score($this->s1, 'Mathematics'));
    }

    public function test_submitted_or_approved_results_cannot_be_changed_by_upload(): void
    {
        ExamClassStatus::create(['exam_id' => $this->exam->id, 'class_id' => $this->classA->id, 'status' => 'submitted']);

        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']]))
            ->assertSessionHas('danger');

        $this->assertNull($this->score($this->s1, 'Mathematics'));
    }

    public function test_returned_results_can_be_corrected_by_upload(): void
    {
        ExamClassStatus::create(['exam_id' => $this->exam->id, 'class_id' => $this->classA->id, 'status' => 'returned']);

        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']]))->assertSessionHas('success');

        $this->assertSame(50.0, $this->score($this->s1, 'Mathematics'));
        $this->assertSame('draft', ExamClassStatus::where('exam_id', $this->exam->id)
            ->where('class_id', $this->classA->id)->value('status'));
    }

    public function test_headmaster_cannot_upload(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']]), $this->hm)
            ->assertRedirect(route('marks_select'))
            ->assertSessionHas('danger');

        $this->assertNull($this->score($this->s1, 'Mathematics'));
    }

    public function test_subject_not_taught_in_the_class_is_rejected(): void
    {
        // Mazingira (121D) is only taught in Standard 1-3
        $this->upload($this->csv('121D', 'MAZINGIRA', [['ZE0000000001', '50']]))
            ->assertSessionHas('danger');

        $this->assertNull($this->score($this->s1, 'Mazingira'));
    }

    public function test_unknown_subject_code_is_rejected(): void
    {
        $this->upload($this->csv('999.0', 'MYSTERY', [['ZE0000000001', '50']]))->assertSessionHas('danger');

        $this->assertSame(0, DB::table('marks')->count());
    }

    public function test_file_without_subject_code_or_pupils_is_rejected(): void
    {
        $this->upload("hello,world\n1,2\n")->assertSessionHas('danger');
        $this->upload($this->csv('104.0', 'MATHEMATICS', []))->assertSessionHas('danger');

        $this->assertSame(0, DB::table('marks')->count());
    }

    public function test_only_excel_or_csv_files_are_accepted(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']]), null, 'math.pdf')
            ->assertSessionHas('danger');
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']]), null, 'math.xlsx')
            ->assertSessionHas('danger');

        $this->assertSame(0, DB::table('marks')->count());
    }

    public function test_missing_exam_or_file_is_reported(): void
    {
        $this->actingAs($this->teacher)->post(route('marks.upload_ozems'), [])
            ->assertRedirect(route('marks_upload'))
            ->assertSessionHas('danger');
    }

    // ---------------------------------------------------------------- wrong file for this class / year

    public function test_file_of_another_standard_or_year_is_rejected(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']], ',', false, 'STD7'))
            ->assertSessionHas('danger');
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']], ',', false, 'STD5', '2019'))
            ->assertSessionHas('danger');

        $this->assertSame(0, DB::table('marks')->count());

        // the right standard and year pass
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '50']], ',', false, 'STD5', '2026'))
            ->assertSessionHas('success');
        $this->assertSame(50.0, $this->score($this->s1, 'Mathematics'));
    }

    // ---------------------------------------------------------------- linking pupils by name

    public function test_pupil_is_linked_by_exact_name_and_gender(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000003', '61', 'MUSSA FATMA SAID', 'F']]))
            ->assertSessionHas('ozems_report.linked_total', 1);

        $this->assertSame('ZE0000000003', $this->s3->fresh()->zems_no);
        $this->assertSame(61.0, $this->score($this->s3, 'Mathematics'));
    }

    public function test_name_link_is_refused_when_gender_differs(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000003', '61', 'Fatma Said Mussa', 'M']]))
            ->assertSessionHas('ozems_report.not_found_total', 1);

        $this->assertNull($this->s3->fresh()->zems_no);
        $this->assertNull($this->score($this->s3, 'Mathematics'));
    }

    public function test_name_link_is_refused_when_two_pupils_of_the_class_share_the_name(): void
    {
        Student::create([
            'reg_no' => 'R5', 'full_name' => 'Mussa Fatma Said', 'gender' => 'Female',
            'class_id' => $this->classA->id, 'active' => 1, 'created_at' => SchoolService::nowStr(),
        ]);

        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000003', '61', 'Fatma Said Mussa', 'F']]))
            ->assertSessionHas('ozems_report.not_found_total', 1);

        $this->assertNull($this->s3->fresh()->zems_no);
    }

    public function test_name_link_is_refused_when_the_name_appears_twice_in_the_file(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [
            ['ZE0000000003', '61', 'Fatma Said Mussa', 'F'],
            ['ZE0000000004', '62', 'Fatma Said Mussa', 'F'],
        ]))->assertSessionHas('ozems_report.not_found_total', 2);

        $this->assertNull($this->s3->fresh()->zems_no);
    }

    public function test_name_link_never_steals_a_number_that_belongs_to_another_pupil(): void
    {
        // ZE0000000009 belongs to a pupil of another class; a same-named pupil here must not get it
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000009', '61', 'Fatma Said Mussa', 'F']]))
            ->assertSessionHas('ozems_report.other_class', 1);

        $this->assertNull($this->s3->fresh()->zems_no);
        $this->assertSame('ZE0000000009', $this->other->fresh()->zems_no);
    }

    // ---------------------------------------------------------------- pages / menu

    public function test_upload_page_is_for_class_teachers_and_menus_are_correct(): void
    {
        $this->actingAs($this->teacher)->get(route('marks_upload'))
            ->assertOk()->assertSee('Upload Exam Results')->assertSee('ZEMS result file');

        $dash = $this->actingAs($this->teacher)->get(route('dashboard'))->assertOk();
        $dash->assertSee('Upload Exam Results');
        $dash->assertDontSee('Marks Entry');

        $this->actingAs($this->hm)->get(route('marks_upload'))->assertRedirect(route('marks_select'));
        $hmDash = $this->actingAs($this->hm)->get(route('dashboard'))->assertOk();
        $hmDash->assertDontSee('Marks Entry');
        $hmDash->assertDontSee('Upload Exam Results');
        $hmDash->assertSee('Review');
    }

    public function test_upload_page_shows_the_report_after_an_upload(): void
    {
        $this->upload($this->fixture('zems_std5_arabic.xls'), null, 'a.xls');

        $this->actingAs($this->teacher)->get(route('marks_upload', ['exam' => $this->exam->id]))
            ->assertOk()
            ->assertSee('STD5')
            ->assertSee('TERM ONE')
            ->assertSee('UNKNOWN PERSON ONE (ZE0000000077)')
            ->assertSee('are not registered in your class')
            ->assertSee('linked to their ZEMS number by name')
            ->assertSee('Fatma Said Mussa -&gt; ZE0000000003', false)
            ->assertSee(route('marks', ['exam_id' => $this->exam->id, 'class_id' => $this->classA->id]), false);
    }

    public function test_marks_after_upload_feed_the_normal_results_engine(): void
    {
        $this->upload($this->csv('104.0', 'MATHEMATICS', [['ZE0000000001', '90'], ['ZE0000000002', '40']]));

        [$subjects, $results] = SchoolService::computeClassResults($this->classA->id, $this->exam->id, null, false);
        $byId = collect($results)->keyBy(fn($r) => $r['student']['id']);
        $this->assertGreaterThan(0, $byId[$this->s1->id]['subjects_entered']);
        $this->assertGreaterThan(0, $byId[$this->s2->id]['subjects_entered']);
    }

    // ---------------------------------------------------------------- subject CRUD

    public function test_headmaster_adds_subject_with_normalised_code(): void
    {
        $this->actingAs($this->hm)->post(route('subjects'), [
            'action' => 'add_subject', 'name' => 'Computer', 'subject_code' => '120.0', 'class_ids' => [$this->classA->id],
        ])->assertRedirect(route('subjects'))->assertSessionHas('success');

        $s = Subject::where('name', 'Computer')->first();
        $this->assertSame('120', $s->subject_code);
        $this->assertTrue(DB::table('class_subjects')->where('subject_id', $s->id)->where('class_id', $this->classA->id)->exists());
    }

    public function test_duplicate_code_or_name_is_rejected(): void
    {
        $before = Subject::count();

        $this->actingAs($this->hm)->post(route('subjects'), ['action' => 'add_subject', 'name' => 'Computer', 'subject_code' => '104'])
            ->assertSessionHas('danger');
        $this->actingAs($this->hm)->post(route('subjects'), ['action' => 'add_subject', 'name' => 'mathematics', 'subject_code' => ''])
            ->assertSessionHas('danger');
        $this->actingAs($this->hm)->post(route('subjects'), ['action' => 'add_subject', 'name' => 'Computer', 'subject_code' => 'a b!'])
            ->assertSessionHas('danger');

        $this->assertSame($before, Subject::count());
    }

    public function test_headmaster_edits_subject_name_and_code(): void
    {
        $sumi = $this->subject('SUMI');

        $this->actingAs($this->hm)->post(route('subjects'), [
            'action' => 'update_subject', 'subject_id' => $sumi->id, 'name' => 'SUMI JKT', 'subject_code' => '130',
        ])->assertSessionHas('success');

        $sumi->refresh();
        $this->assertSame('SUMI JKT', $sumi->name);
        $this->assertSame('130', $sumi->subject_code);
        $this->assertDatabaseHas('audit_log', ['action' => 'UPDATE_SUBJECT']);

        // clearing the code is allowed
        $this->actingAs($this->hm)->post(route('subjects'), [
            'action' => 'update_subject', 'subject_id' => $sumi->id, 'name' => 'SUMI JKT', 'subject_code' => '',
        ])->assertSessionHas('success');
        $this->assertNull($sumi->fresh()->subject_code);

        // keeping its own code is not a duplicate
        $math = $this->subject('Mathematics');
        $this->actingAs($this->hm)->post(route('subjects'), [
            'action' => 'update_subject', 'subject_id' => $math->id, 'name' => 'Mathematics', 'subject_code' => '104',
        ])->assertSessionHas('success');
    }

    public function test_editing_to_another_subjects_code_is_rejected(): void
    {
        $sumi = $this->subject('SUMI');

        $this->actingAs($this->hm)->post(route('subjects'), [
            'action' => 'update_subject', 'subject_id' => $sumi->id, 'name' => 'SUMI', 'subject_code' => '104',
        ])->assertSessionHas('danger');

        $this->assertNull($sumi->fresh()->subject_code);
    }

    public function test_class_teacher_cannot_change_subjects(): void
    {
        $sumi = $this->subject('SUMI');
        $before = Subject::count();

        $this->actingAs($this->teacher)->post(route('subjects'), ['action' => 'add_subject', 'name' => 'Hack', 'subject_code' => '999']);
        $this->actingAs($this->teacher)->post(route('subjects'), [
            'action' => 'update_subject', 'subject_id' => $sumi->id, 'name' => 'Hacked', 'subject_code' => '998',
        ]);

        $this->assertSame($before, Subject::count());
        $this->assertSame('SUMI', $sumi->fresh()->name);
    }

    public function test_subjects_page_shows_codes_and_edit_dialog_for_headmaster_only(): void
    {
        $this->actingAs($this->hm)->get(route('subjects'))->assertOk()
            ->assertSee('<code>104</code>', false)->assertSee('editSubjectModal', false);

        $this->actingAs($this->teacher)->get(route('subjects'))->assertOk()
            ->assertSee('<code>104</code>', false)->assertDontSee('editSubjectModal', false);
    }

    // ---------------------------------------------------------------- ZEMS numbers on students

    public function test_teacher_saves_a_zems_number_on_own_student(): void
    {
        $this->actingAs($this->teacher)->post(route('edit_student', ['student_id' => $this->s3->id]), [
            'reg_no' => 'R3', 'full_name' => 'Pupil R3', 'class_id' => $this->classA->id, 'gender' => 'Male', 'zems_no' => ' ze0000000003 ',
        ])->assertRedirect(route('students'));

        $this->assertSame('ZE0000000003', $this->s3->fresh()->zems_no);
    }

    public function test_bad_or_duplicate_zems_number_is_rejected(): void
    {
        $base = ['reg_no' => 'R3', 'full_name' => 'Pupil R3', 'class_id' => $this->classA->id, 'gender' => 'Male'];

        $this->actingAs($this->teacher)->post(route('edit_student', ['student_id' => $this->s3->id]), $base + ['zems_no' => 'hello'])
            ->assertSessionHas('danger');
        $this->actingAs($this->teacher)->post(route('edit_student', ['student_id' => $this->s3->id]), $base + ['zems_no' => 'ZE0000000001'])
            ->assertSessionHas('danger');

        $this->assertNull($this->s3->fresh()->zems_no);

        // older numbers with slashes are real ZEMS numbers too
        $this->actingAs($this->teacher)->post(route('edit_student', ['student_id' => $this->s3->id]), $base + ['zems_no' => 'zl0033/0300/21'])
            ->assertRedirect(route('students'));
        $this->assertSame('ZL0033/0300/21', $this->s3->fresh()->zems_no);
    }

    public function test_new_student_is_registered_with_zems_number_only(): void
    {
        $this->actingAs($this->teacher)->post(route('add_student'), [
            'full_name' => 'New One', 'class_id' => $this->classA->id, 'gender' => 'Female', 'zems_no' => 'ze0000000100',
        ])->assertRedirect(route('students'));
        $this->actingAs($this->teacher)->post(route('add_student'), [
            'full_name' => 'New Two', 'class_id' => $this->classA->id, 'gender' => 'Female', 'zems_no' => '',
        ]);

        $one = Student::where('full_name', 'New One')->first();
        $this->assertSame('ZE0000000100', $one->zems_no);
        $this->assertSame('ZE0000000100', $one->reg_no);
        $this->assertSame(0, Student::where('full_name', 'New Two')->count()); // ZEMS No is required
    }

    public function test_student_form_shows_the_zems_field(): void
    {
        $this->actingAs($this->teacher)->get(route('edit_student', ['student_id' => $this->s1->id]))
            ->assertOk()->assertSee('ZEMS No')->assertSee('ZE0000000001');
    }
}
