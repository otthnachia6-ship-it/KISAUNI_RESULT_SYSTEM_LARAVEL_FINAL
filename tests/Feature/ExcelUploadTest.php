<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolService;
use App\Services\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Excel templates: register students and upload marks. */
class ExcelUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private User $teacher;
    private SchoolClass $cls;
    private SchoolClass $other;
    private Examination $exam;
    private Subject $maths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->cls = SchoolClass::where('standard', 5)->orderBy('id')->first();
        $this->other = SchoolClass::where('standard', 6)->orderBy('id')->first();
        $this->exam = Examination::orderBy('id')->first();
        $this->maths = Subject::where('name', 'Mathematics')->first();
        $this->teacher = User::create([
            'username' => 'tch', 'password' => bcrypt('x'), 'full_name' => 'Tch',
            'role' => 'class_teacher', 'class_id' => $this->cls->id, 'active' => 1, 'must_change_password' => 0,
        ]);
    }

    /** Files written by a real spreadsheet program (shared strings, numeric cells, blank rows), kept inside this test. */
    private const STUDENTS_MADE_BY_EXCEL =
        'UEsDBBQAAAAIAEoUWV1Gx01IlQAAAM0AAAAQAAAAZG9jUHJvcHMvYXBwLnhtbE3PTQvCMAwG4L9SdreZih6kDkQ9ip68zy51hbYp'.
        'bYT67+0EP255ecgboi6JIia2mEXxLuRtMzLHDUDWI/o+y8qhiqHke64x3YGMsRoPpB8eA8OibdeAhTEMOMzit7Dp1C5GZ3XPlkJ3'.
        'sjpRJsPiWDQ6sScfq9wcChDneiU+ixNLOZcrBf+LU8sVU57mym/8ZAW/B7oXUEsDBBQAAAAIAEoUWV3E9HdZ7wAAACsCAAARAAAA'.
        'ZG9jUHJvcHMvY29yZS54bWzNks9OwzAMh18F5d46TWGHqOtlEyeQkJgE4hYl3hat+aPEqN3b05atE4IH4Bj7l8+fJTc6Sh0SvqQQ'.
        'MZHFfDe4zmep45odiaIEyPqITuVyTPixuQ/JKRqf6QBR6ZM6IAjOV+CQlFGkYAIWcSGytjFa6oSKQrrgjV7w8TN1M8xowA4despQ'.
        'lRWwdpoYz0PXwA0wwQiTy98FNAtxrv6JnTvALskh2yXV933Z13Nu3KGC9+en13ndwvpMymscf2Ur6Rxxza6T3+rNdvfIWsHFqqh4'.
        'IR52XMj6Xgr+Mbn+8LsJu2Ds3v5j46tg28Cvu2i/AFBLAwQUAAAACABKFFldmVycIxAGAACcJwAAEwAAAHhsL3RoZW1lL3RoZW1l'.
        'MS54bWztWltz2jgUfu+v0Hhn9m0LxjaBtrQTc2l227SZhO1OH4URWI1seWSRhH+/RzYQy5YN7ZJNups8BCzp+85FR+foOHnz7i5i'.
        '6IaIlPJ4YNkv29a7ty/e4FcyJBFBMBmnr/DACqVMXrVaaQDDOH3JExLD3IKLCEt4FMvWXOBbGi8j1uq0291WhGlsoRhHZGB9Xixo'.
        'QNBUUVpvXyC05R8z+BXLVI1lowETV0EmuYi08vlsxfza3j5lz+k6HTKBbjAbWCB/zm+n5E5aiOFUwsTAamc/VmvH0dJIgILJfZQF'.
        'ukn2o9MVCDINOzqdWM52fPbE7Z+Mytp0NG0a4OPxeDi2y9KLcBwE4FG7nsKd9Gy/pEEJtKNp0GTY9tqukaaqjVNP0/d93+ubaJwK'.
        'jVtP02t33dOOicat0HgNvvFPh8Ouicar0HTraSYn/a5rpOkWaEJG4+t6EhW15UDTIABYcHbWzNIDll4p+nWUGtkdu91BXPBY7jmJ'.
        'Ef7GxQTWadIZljRGcp2QBQ4AN8TRTFB8r0G2iuDCktJckNbPKbVQGgiayIH1R4Ihxdyv/fWXu8mkM3qdfTrOa5R/aasBp+27m8+T'.
        '/HPo5J+nk9dNQs5wvCwJ8fsjW2GHJ247E3I6HGdCfM/29pGlJTLP7/kK6048Zx9WlrBdz8/knoxyI7vd9lh99k9HbiPXqcCzIteU'.
        'RiRFn8gtuuQROLVJDTITPwidhphqUBwCpAkxlqGG+LTGrBHgE323vgjI342I96tvmj1XoVhJ2oT4EEYa4pxz5nPRbPsHpUbR9lW8'.
        '3KOXWBUBlxjfNKo1LMXWeJXA8a2cPB0TEs2UCwZBhpckJhKpOX5NSBP+K6Xa/pzTQPCULyT6SpGPabMjp3QmzegzGsFGrxt1h2jS'.
        'PHr+BfmcNQockRsdAmcbs0YhhGm78B6vJI6arcIRK0I+Yhk2GnK1FoG2camEYFoSxtF4TtK0EfxZrDWTPmDI7M2Rdc7WkQ4Rkl43'.
        'Qj5izouQEb8ehjhKmu2icVgE/Z5ew0nB6ILLZv24fobVM2wsjvdH1BdK5A8mpz/pMjQHo5pZCb2EVmqfqoc0PqgeMgoF8bkePuV6'.
        'eAo3lsa8UK6CewH/0do3wqv4gsA5fy59z6XvufQ9odK3NyN9Z8HTi1veRm5bxPuuMdrXNC4oY1dyzcjHVK+TKdg5n8Ds/Wg+nvHt'.
        '+tkkhK+aWS0jFpBLgbNBJLj8i8rwKsQJ6GRbJQnLVNNlN4oSnkIbbulT9UqV1+WvuSi4PFvk6a+hdD4sz/k8X+e0zQszQ7dyS+q2'.
        'lL61JjhK9LHMcE4eyww7ZzySHbZ3oB01+/ZdduQjpTBTl0O4GkK+A226ndw6OJ6YkbkK01KQb8P56cV4GuI52QS5fZhXbefY0dH7'.
        '58FRsKPvPJYdx4jyoiHuoYaYz8NDh3l7X5hnlcZQNBRtbKwkLEa3YLjX8SwU4GRgLaAHg69RAvJSVWAxW8YDK5CifEyMRehw55dc'.
        'X+PRkuPbpmW1bq8pdxltIlI5wmmYE2eryt5lscFVHc9VW/Kwvmo9tBVOz/5ZrcifDBFOFgsSSGOUF6ZKovMZU77nK0nEVTi/RTO2'.
        'EpcYvOPmx3FOU7gSdrYPAjK5uzmpemUxZ6by3y0MCSxbiFkS4k1d7dXnm5yueiJ2+pd3wWDy/XDJRw/lO+df9F1Drn723eP6bpM7'.
        'SEycecURAXRFAiOVHAYWFzLkUO6SkAYTAc2UyUTwAoJkphyAmPoLvfIMuSkVzq0+OX9FLIOGTl7SJRIUirAMBSEXcuPv75Nqd4zX'.
        '+iyBbYRUMmTVF8pDicE9M3JD2FQl867aJguF2+JUzbsaviZgS8N6bp0tJ//bXtQ9tBc9RvOjmeAes4dzm3q4wkWs/1jWHvky3zlw'.
        '2zreA17mEyxDpH7BfYqKgBGrYr66r0/5JZw7tHvxgSCb/NbbpPbd4Ax81KtapWQrET9LB3wfkgZjjFv0NF+PFGKtprGtxtoxDHmA'.
        'WPMMoWY434dFmhoz1YusOY0Kb0HVQOU/29QNaPYNNByRBV4xmbY2o+ROCjzc/u8NsMLEjuHti78BUEsDBBQAAAAIAEoUWV1TRvnR'.
        '1wEAAOgEAAAYAAAAeGwvd29ya3NoZWV0cy9zaGVldDEueG1shZRhb5swEIb/iuUfUBOS0KkiSGmmrNvULkrUVeq3SzjAq42ZbcL2'.
        '72fTBGUTzj7hO9/73nOAnXZKv5kK0ZJfUtRmQStrmzvGzKFCCeZGNVi7nUJpCdaFumSm0Qh5L5KCxVGUMAm8plna5zY6S1VrBa9x'.
        'o4lppQT9+x6F6hZ0Qs+JLS8r6xMsSxsocYf2udloF7HBJecSa8NVTTQWC7qc3K0SX98XfOfYmYs18ZPslXrzwed8QSMPhAIP1juA'.
        'exxxhUJ4I4fx8+RJh5ZeeLk+u6/72d0sezC4UuKF57Za0A+U5FhAK+xWdQ94mmc+AH4EC1mqVUe0nzNLD37he7s6Xvv3s7Pa5blr'.
        'ZLMtluRJpcw6BJ9hh5PiPqRYt0KQJ5A4IlqFRJ+wzlH/rWAOciCNB9K4t/Df9ZhNomiSsuMlVhzosDQVkKXgY1Qhzdr9awKvUE0H'.
        'qmnAIopux15eqPxLK4GQb+5XHOMMqf5DORsoZwGDr5sdixM2HUMNaV6hg5yTHfD8Su85/SeRDDDJdZjZGExIswVT8Zw8wI/RDxyS'.
        'PY6Rs4tz4u+AR9Alrw0RWDiT6OZ2Tol+P1fvgVVNf4fslbVK9svKXUWofYHbL5Sy58Af6+Fyy/4AUEsDBBQAAAAIAEoUWV1886Pc'.
        'UQIAAPYJAAANAAAAeGwvc3R5bGVzLnhtbN1W24rbMBD9FeEPqJOYNXFJ8lBDYKEtC7sPfVViORHo4srykvTrOyM5drOrWSh9q03w'.
        'zByduRtn0/urEs9nITy7aGX6bXb2vvuc5/3xLDTvP9lOGEBa6zT3oLpT3ndO8KZHklb5arEoc82lyXYbM+i99j072sH4bbbI8t2m'.
        'tWa2LLNogKNcC/bK1TaruZIHJ8NZrqW6RvMKDUerrGMeUhFIBkv/K8LLqGGWox8tjXVozGOE8OjBqVRqSmCVRcNu03HvhTN7UAIn'.
        'GN9BbJRfrh1kcHL8ulw9ZDMhPCDIwbpGuLs6o2m3UaL1QHDydMant12OoPdWg9BIfrKGhxxujFEAt0eh1DOO6Ed75/vSstjrxwbb'.
        'zLDUmwgJjWJ0ExX0/6e36Puf3bJOvlr/ZYBqTNB/DtaLJydaeQn6pb2PP4UOidxFn6wMl2ObfcedU7MLdhik8tKM2lk2jTDvagP3'.
        'nh9gqe/8w/lGtHxQ/mUCt9ksfxONHHQ1nXrCssZTs/wVZ7gsp82EWNI04iKaelTd6RBEBgJEHS8kvEX24UojFCdiaQQxKg6VAcWJ'.
        'LCrO/1TPmqwnYlRu6ySyJjlrkhNZKaQONxUnzangSldaVUVRllRH6zqZQU31rSzxl/ZG5YYMKg5G+rte09OmN+TjPaBm+tGGUJXS'.
        'm0hVSvcakXTfkFFV6WlTcZBBTYHaHYyfjoM7leYUBU6Vyo16g2mkqigEdzG9o2VJdKfEOz0f6i0piqpKI4ilMygKCsG3kUaoDDAH'.
        'CimK8B188z3Kb9+pfP6nt/sNUEsDBBQAAAAIAEoUWV2XirscwAAAABMCAAALAAAAX3JlbHMvLnJlbHOdkrluwzAMQH/F0J4wB9Ah'.
        'iDNl8RYE+QFWog/YEgWKRZ2/r9qlcZALGXk9PBLcHmlA7TiktoupGP0QUmla1bgBSLYlj2nOkUKu1CweNYfSQETbY0OwWiw+QC4Z'.
        'Zre9ZBanc6RXiFzXnaU92y9PQW+ArzpMcUJpSEszDvDN0n8y9/MMNUXlSiOVWxp40+X+duBJ0aEiWBaaRcnToh2lfx3H9pDT6a9j'.
        'IrR6W+j5cWhUCo7cYyWMcWK0/jWCyQ/sfgBQSwMEFAAAAAgAShRZXe/UVRs1AQAAJQIAAA8AAAB4bC93b3JrYm9vay54bWyNUdFO'.
        'wzAM/JUqH0A7BJOY1r0wAZMQTAztPW3d1VoSV467wb4et1XFJF54Su5sXe4uyzPxsSA6Jl/ehZibRqRdpGksG/A23lALQSc1sbei'.
        'kA9pbBlsFRsA8S69zbJ56i0Gs1pOWltOrwEJlIIUlOyJPcI5/s57mJwwYoEO5Ts3w92BSTwG9HiBKjeZSWJD5xdivFAQ63Ylk3O5'.
        'mY2DPbBg+Yfe9SY/bREHRmzxYdVIbuaZCtbIUYaNQd+qxxPo8og6oSd0Ary2As9MXYvh0MtoivQqxtDDdI4lLvg/NVJdYwlrKjsP'.
        'QcYeGVxvMMQG22iSYD3kZiddpRuxj6RvbKoxnqivq7J4gTrgTTU6nGxVUGOA6k2VovJaUbnlpD8Gndu7+9mDVtE596jce3glW00p'.
        'px9a/QBQSwMEFAAAAAgAShRZXSQem6KtAAAA+AEAABoAAAB4bC9fcmVscy93b3JrYm9vay54bWwucmVsc7WRPQ6DMAyFrxLlADVQ'.
        'qUMFTF1YKy4QBfMjEhLFrgq3L4UBkDp0YbKeLX/vyU6faBR3bqC28yRGawbKZMvs7wCkW7SKLs7jME9qF6ziWYYGvNK9ahCSKLpB'.
        '2DNknu6Zopw8/kN0dd1pfDj9sjjwDzC8XeipRWQpShUa5EzCaLY2wVLiy0yWoqgyGYoqlnBaIOLJIG1pVn2wT06053kXN/dFrs3j'.
        'Ca7fDHB4dP4BUEsDBBQAAAAIAEoUWV1lkHmSGQEAAM8DAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbK2TTU7DMBCFrxJlWyUuLFig'.
        'phtgC11wAWNPGqv+k2da0tszTtpKoBIVhU2seN68z56XrN6PEbDonfXYlB1RfBQCVQdOYh0ieK60ITlJ/Jq2Ikq1k1sQ98vlg1DB'.
        'E3iqKHuU69UztHJvqXjpeRtN8E2ZwGJZPI3CzGpKGaM1ShLXxcHrH5TqRKi5c9BgZyIuWFCKq4Rc+R1w6ns7QEpGQ7GRiV6lY5Xo'.
        'rUA6WsB62uLKGUPbGgU6qL3jlhpjAqmxAyBn69F0MU0mnjCMz7vZ/MFmCsjKTQoRObEEf8edI8ndVWQjSGSmr3ghsvXs+0FOW4O+'.
        'kc3j/QxpN+SBYljmz/h7xhf/G87xEcLuvz+xvNZOGn/mi+E/Xn8BUEsBAhQDFAAAAAgAShRZXUbHTUiVAAAAzQAAABAAAAAAAAAA'.
        'AAAAAIABAAAAAGRvY1Byb3BzL2FwcC54bWxQSwECFAMUAAAACABKFFldxPR3We8AAAArAgAAEQAAAAAAAAAAAAAAgAHDAAAAZG9j'.
        'UHJvcHMvY29yZS54bWxQSwECFAMUAAAACABKFFldmVycIxAGAACcJwAAEwAAAAAAAAAAAAAAgAHhAQAAeGwvdGhlbWUvdGhlbWUx'.
        'LnhtbFBLAQIUAxQAAAAIAEoUWV1TRvnR1wEAAOgEAAAYAAAAAAAAAAAAAACAgSIIAAB4bC93b3Jrc2hlZXRzL3NoZWV0MS54bWxQ'.
        'SwECFAMUAAAACABKFFldfPOj3FECAAD2CQAADQAAAAAAAAAAAAAAgAEvCgAAeGwvc3R5bGVzLnhtbFBLAQIUAxQAAAAIAEoUWV2X'.
        'irscwAAAABMCAAALAAAAAAAAAAAAAACAAasMAABfcmVscy8ucmVsc1BLAQIUAxQAAAAIAEoUWV3v1FUbNQEAACUCAAAPAAAAAAAA'.
        'AAAAAACAAZQNAAB4bC93b3JrYm9vay54bWxQSwECFAMUAAAACABKFFldJB6boq0AAAD4AQAAGgAAAAAAAAAAAAAAgAH2DgAAeGwv'.
        'X3JlbHMvd29ya2Jvb2sueG1sLnJlbHNQSwECFAMUAAAACABKFFldZZB5khkBAADPAwAAEwAAAAAAAAAAAAAAgAHbDwAAW0NvbnRl'.
        'bnRfVHlwZXNdLnhtbFBLBQYAAAAACQAJAD4CAAAlEQAAAAA=';

    private function fixture(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fx') . '.xlsx';
        file_put_contents($path, base64_decode(self::STUDENTS_MADE_BY_EXCEL));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function tmpXlsx(array $sheets, string $name = 'file.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tx') . '.xlsx';
        file_put_contents($path, Xlsx::build($sheets));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function studentSheet(array $rows): array
    {
        return [['name' => 'Students', 'rows' => array_merge([['Reg No', 'Full Name', 'Gender']], $rows)]];
    }

    // ---------------------------------------------------------------- reader / writer

    public function test_xlsx_round_trip_and_files_made_by_excel_are_read(): void
    {
        $bytes = Xlsx::build([['name' => 'S', 'rows' => [['007', 'A & <B>', 72.5]]]]);
        $this->assertStringStartsWith('PK', $bytes);
        $path = tempnam(sys_get_temp_dir(), 'rt');
        file_put_contents($path, $bytes);
        $read = Xlsx::read($path);
        $this->assertSame(['007', 'A & <B>', '72.5'], $read['S'][1]);

        // a file written by a real spreadsheet program (shared strings, numeric cells, blank rows)
        $madePath = tempnam(sys_get_temp_dir(), 'mx');
        file_put_contents($madePath, base64_decode(self::STUDENTS_MADE_BY_EXCEL));
        $made = Xlsx::read($madePath);
        $this->assertSame('1001', $made['Students'][2][0]);   // numeric cell, no ".0"
        $this->assertSame('007', $made['Students'][3][0]);    // text keeps its zeros
        $this->assertArrayNotHasKey(5, $made['Students']);    // empty row skipped
    }

    public function test_reader_rejects_bad_files_without_crashing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, 'this is not a zip file');
        $this->expectException(\InvalidArgumentException::class);
        Xlsx::read($path);
    }

    // ---------------------------------------------------------------- student registration

    public function test_student_template_downloads_as_xlsx(): void
    {
        $r = $this->actingAs($this->teacher)->get('/students/template');
        $r->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $r->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'tp');
        file_put_contents($path, $r->getContent());
        $sheets = Xlsx::read($path);
        $this->assertSame(['Reg No', 'Full Name', 'Gender'], array_values($sheets['Students'][1]));
        $this->assertArrayHasKey('Instructions', $sheets);
    }

    public function test_teacher_registers_students_from_excel_after_preview(): void
    {
        $this->actingAs($this->teacher)->post('/students/upload', ['file' => $this->fixture('students_excel_made.xlsx')])
            ->assertRedirect('/students/upload/preview');
        $this->assertSame(0, Student::count()); // nothing saved by upload alone

        $page = $this->get('/students/upload/preview')->assertOk();
        $page->assertSee('1001')->assertSee('007')->assertSee('KPS/26/3')->assertSee('Juma Omar')->assertSee('(guessed)');

        $this->post('/students/upload/confirm')->assertRedirect();
        $this->assertSame(4, Student::count());
        $s = Student::where('reg_no', '007')->first();
        $this->assertSame('Juma Omar', $s->full_name);            // extra space cleaned
        $this->assertSame('Male', $s->gender);                    // "male" accepted
        $this->assertEquals($this->cls->id, $s->class_id);        // class comes from the teacher's account
        $this->assertSame('Female', Student::where('reg_no', 'KPS/26/3')->value('gender')); // guessed from the name
        $this->assertTrue(DB::table('audit_log')->where('action', 'ADD_STUDENTS_EXCEL')->exists());

        // a second confirm does nothing (preview data is gone)
        $this->post('/students/upload/confirm')->assertRedirect(route('students_upload'));
        $this->assertSame(4, Student::count());
    }

    public function test_any_bad_row_blocks_everything(): void
    {
        Student::create(['reg_no' => 'EXIST-1', 'full_name' => 'Old Pupil', 'gender' => 'Male', 'class_id' => $this->other->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);
        $file = $this->tmpXlsx($this->studentSheet([
            ['R-1', 'Good Pupil', 'Male'],
            ['R-1', 'Repeated Reg', 'Male'],
            ['EXIST-1', 'Already There', 'Female'],
            ['R-3', 'No Gender Known', 'Other'],
            ['', 'No Reg No', 'Male'],
        ]));
        $this->actingAs($this->teacher)->post('/students/upload', ['file' => $file])->assertRedirect('/students/upload/preview');
        $page = $this->get('/students/upload/preview')->assertOk();
        $page->assertSee('Reg No is repeated')->assertSee('already registered')->assertSee('is not Male or Female')->assertSee('Reg No is empty');
        $page->assertDontSee('Save 5 students');

        $this->post('/students/upload/confirm')->assertRedirect(route('students_upload_preview'));
        $this->assertSame(1, Student::count()); // only the pre-existing pupil
    }

    public function test_wrong_headings_wrong_extension_and_other_class_are_refused(): void
    {
        $bad = $this->tmpXlsx([['name' => 'Students', 'rows' => [['Number', 'Name', 'Sex'], ['R1', 'X', 'Male']]]]);
        $this->actingAs($this->teacher)->post('/students/upload', ['file' => $bad])->assertSessionHas('danger');

        $csv = UploadedFile::fake()->createWithContent('list.csv', "Reg No,Full Name,Gender\nR1,X,Male\n");
        $this->actingAs($this->teacher)->post('/students/upload', ['file' => $csv])->assertSessionHas('danger');
        $this->assertSame(0, Student::count());

        // a teacher cannot choose another class: the class always comes from the account
        $file = $this->tmpXlsx($this->studentSheet([['Z-1', 'Hawa Ali', 'Female']]));
        $this->actingAs($this->teacher)->post('/students/upload', ['file' => $file, 'class_id' => $this->other->id]);
        $this->post('/students/upload/confirm');
        $this->assertEquals($this->cls->id, Student::where('reg_no', 'Z-1')->value('class_id'));
    }

    public function test_headmaster_chooses_the_class_for_the_upload(): void
    {
        $file = $this->tmpXlsx($this->studentSheet([['H-1', 'Rashid Haji', 'Male']]));
        $this->actingAs($this->hm)->post('/students/upload', ['file' => $file])->assertSessionHas('danger'); // no class chosen
        $this->post('/students/upload', ['file' => $this->tmpXlsx($this->studentSheet([['H-1', 'Rashid Haji', 'Male']])), 'class_id' => $this->other->id]);
        $this->post('/students/upload/confirm');
        $this->assertEquals($this->other->id, Student::where('reg_no', 'H-1')->value('class_id'));
    }

    // ---------------------------------------------------------------- marks

    private function pupils(): array
    {
        $ids = [];
        foreach ([['A1', 'Pupil One'], ['A2', 'Pupil Two'], ['A3', 'Pupil Three']] as [$reg, $name]) {
            $ids[$reg] = Student::create(['reg_no' => $reg, 'full_name' => $name, 'gender' => 'Male', 'class_id' => $this->cls->id, 'active' => 1, 'created_at' => SchoolService::nowStr()])->id;
        }

        return $ids;
    }

    private function marksFile(array $rows, ?array $meta = null, bool $withMeta = true): UploadedFile
    {
        $meta ??= ['class_id' => $this->cls->id, 'exam_id' => $this->exam->id, 'subject_id' => $this->maths->id];
        $head = [['No', 'Reg No', 'Full Name', 'Marks']];
        $sheets = [['name' => 'Marks', 'rows' => array_merge([['title'], [], [], [], [], [], []], $head, $rows)]];
        if ($withMeta) {
            $sheets[] = ['name' => 'Meta', 'hidden' => true, 'rows' => array_map(fn($k, $v) => [$k, $v], array_keys($meta), $meta)];
        }

        return $this->tmpXlsx($sheets);
    }

    private function upload(UploadedFile $file)
    {
        return $this->actingAs($this->teacher)->post('/marks/upload', ['exam_id' => $this->exam->id, 'subject_id' => $this->maths->id, 'file' => $file]);
    }

    public function test_marks_template_lists_the_pupils_of_the_teachers_class(): void
    {
        $this->pupils();
        Student::create(['reg_no' => 'B1', 'full_name' => 'Other Class', 'gender' => 'Male', 'class_id' => $this->other->id, 'active' => 1, 'created_at' => SchoolService::nowStr()]);

        $r = $this->actingAs($this->teacher)->get("/marks/template/{$this->exam->id}/{$this->maths->id}");
        $r->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'mt');
        file_put_contents($path, $r->getContent());
        $sheets = Xlsx::read($path);
        $flat = json_encode($sheets['Marks']);
        $this->assertStringContainsString('Pupil One', $flat);
        $this->assertStringContainsString('104', $flat);                 // subject code
        $this->assertStringNotContainsString('Other Class', $flat);     // never another class
        $this->assertSame((string) $this->cls->id, $sheets['Meta'][1][1]);

        // a subject that is not taught in the class has no template
        $other = Subject::create(['name' => 'Chess', 'subject_code' => '999']);
        $this->actingAs($this->teacher)->get("/marks/template/{$this->exam->id}/{$other->id}")->assertRedirect(route('marks_upload'));
    }

    public function test_marks_upload_saves_after_preview_and_teacher_still_submits(): void
    {
        $ids = $this->pupils();
        $file = $this->marksFile([[1, 'A1', 'Pupil One', 75.5], [2, 'A2', 'Pupil Two', 'ABS'], [3, 'A3', 'Pupil Three', null]]);
        $this->upload($file)->assertRedirect('/marks/upload/preview');
        $this->assertSame(0, DB::table('marks')->count()); // preview only

        $this->get('/marks/upload/preview')->assertOk()->assertSee('75.5')->assertSee('ABS (0)')->assertSee('Save marks');
        $this->post('/marks/upload/confirm')->assertRedirect();

        $this->assertEquals(75.5, DB::table('marks')->where('student_id', $ids['A1'])->value('score'));
        $this->assertEquals(0, DB::table('marks')->where('student_id', $ids['A2'])->value('score'));
        $this->assertNull(DB::table('marks')->where('student_id', $ids['A3'])->first()); // empty cell = left alone
        $this->assertSame('draft', DB::table('exam_class_status')->where('exam_id', $this->exam->id)->where('class_id', $this->cls->id)->value('status'));
        $this->assertTrue(DB::table('audit_log')->where('action', 'UPLOAD_MARKS_EXCEL')->exists());

        // a made-by-Excel style file works too, and an upload overwrites the old mark
        DB::table('marks')->where('student_id', $ids['A1'])->update(['score' => 10]);
        $this->upload($this->marksFile([[1, 'A1', 'Pupil One', '88,5']]))->assertRedirect('/marks/upload/preview');
        $this->post('/marks/upload/confirm');
        $this->assertEquals(88.5, DB::table('marks')->where('student_id', $ids['A1'])->value('score'));
        $this->assertSame(1, DB::table('marks')->where('student_id', $ids['A1'])->count()); // no duplicate row
    }

    public function test_marks_upload_blocks_bad_rows_and_changes_nothing(): void
    {
        $this->pupils();
        $bad = $this->marksFile([
            [1, 'A1', 'Pupil One', 101],
            [2, 'A2', 'Pupil Two', 'abc'],
            [3, 'ZZ', 'Stranger', 50],
            [4, 'A1', 'Pupil One', 40],
        ]);
        $this->upload($bad)->assertRedirect('/marks/upload/preview');
        $page = $this->get('/marks/upload/preview')->assertOk();
        $page->assertSee('must be between 0 and 100')->assertSee('is not a number')->assertSee('not a pupil of your class')->assertSee('Reg No is repeated');
        $page->assertDontSee('Save marks');
        $this->post('/marks/upload/confirm')->assertRedirect(route('marks_upload_preview'));
        $this->assertSame(0, DB::table('marks')->count());
    }

    public function test_marks_file_must_match_class_exam_subject_and_come_from_the_system(): void
    {
        $this->pupils();
        $rows = [[1, 'A1', 'Pupil One', 50]];

        $this->upload($this->marksFile($rows, ['class_id' => $this->other->id, 'exam_id' => $this->exam->id, 'subject_id' => $this->maths->id]))
            ->assertSessionHas('danger', 'This file was made for another class. Download the template for your own class.');
        $eng = Subject::where('name', 'English')->first();
        $this->upload($this->marksFile($rows, ['class_id' => $this->cls->id, 'exam_id' => $this->exam->id, 'subject_id' => $eng->id]))
            ->assertSessionHas('danger');
        $this->assertStringContainsString('another subject', session('danger'));
        $this->upload($this->marksFile($rows, null, false))->assertSessionHas('danger'); // no Meta sheet
        $this->assertStringContainsString('not a marks template', session('danger'));
        $this->assertSame(0, DB::table('marks')->count());
    }

    public function test_locked_class_cannot_get_marks_by_upload(): void
    {
        $this->pupils();
        DB::table('exam_class_status')->insert(['exam_id' => $this->exam->id, 'class_id' => $this->cls->id, 'status' => 'submitted']);
        $this->upload($this->marksFile([[1, 'A1', 'Pupil One', 50]]))->assertRedirect();
        $this->assertStringContainsString('already been submitted', session('warning'));
        $this->assertSame(0, DB::table('marks')->count());

        $this->actingAs($this->teacher)->get("/marks/upload?exam={$this->exam->id}&subject={$this->maths->id}")
            ->assertOk()->assertSee('cannot be changed by upload')->assertDontSee('Download template');
    }

    public function test_upload_page_shows_template_and_upload_boxes_for_teacher_only(): void
    {
        $this->actingAs($this->teacher)->get("/marks/upload?exam={$this->exam->id}&subject={$this->maths->id}")
            ->assertOk()->assertSee('Download template')->assertSee('Upload and check');
        $this->actingAs($this->hm)->get('/marks/upload')->assertRedirect(route('marks_select'));
        $this->actingAs($this->hm)->post('/marks/upload', ['exam_id' => $this->exam->id, 'subject_id' => $this->maths->id])->assertRedirect(route('marks_upload'));
    }
}
