<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\SchoolService;
use App\Services\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Register students from an Excel (.xlsx) template made by the system.
 * Download template -> fill (Reg No, Full Name, Gender) -> upload -> check preview -> confirm.
 * All-or-nothing: if any row has a problem nothing is saved.
 */
class StudentExcelController extends Controller
{
    private const SESSION_KEY = 'students_excel';
    private const MAX_ROWS = 500;

    public function template()
    {
        $inputRows = [];
        for ($i = 0; $i < 300; $i++) {
            $inputRows[] = [['v' => null, 's' => Xlsx::S_INPUT_TEXT], ['v' => null, 's' => Xlsx::S_INPUT_TEXT], ['v' => null, 's' => Xlsx::S_INPUT_TEXT]];
        }

        $bytes = Xlsx::build([
            [
                'name' => 'Students',
                'freeze' => 1,
                'cols' => [['width' => 22, 'style' => Xlsx::S_TEXT], ['width' => 38, 'style' => Xlsx::S_TEXT], ['width' => 14, 'style' => Xlsx::S_TEXT]],
                'rows' => array_merge(
                    [[['v' => 'Reg No', 's' => Xlsx::S_HEADER], ['v' => 'Full Name', 's' => Xlsx::S_HEADER], ['v' => 'Gender', 's' => Xlsx::S_HEADER]]],
                    $inputRows
                ),
                'validations' => [['sqref' => 'C2:C301', 'list' => '"Male,Female"', 'error' => 'Choose Male or Female.']],
            ],
            [
                'name' => 'Instructions',
                'cols' => [['width' => 110]],
                'rows' => [
                    [['v' => 'How to fill this template', 's' => Xlsx::S_TITLE]],
                    ['1. Use the sheet "Students". Write one pupil per row, starting on row 2.'],
                    ['2. Reg No: the school registration number of the pupil. It must be different for every pupil.'],
                    ['3. Full Name: the full name of the pupil.'],
                    ['4. Gender: choose Male or Female from the list. If you leave it empty, the system tries to guess it from the first name.'],
                    ['5. Do not change the headings in row 1 and do not add other columns.'],
                    ['6. Save the file as Excel Workbook (.xlsx), then upload it in Students > Upload Excel.'],
                    ['7. The system shows a preview first. Nothing is saved until you confirm, and nothing is saved at all if a row has a problem.'],
                ],
            ],
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Students_Template.xlsx"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function form()
    {
        $user = Auth::user();
        $classes = $user->isClassTeacher()
            ? SchoolClass::where('id', $user->class_id)->get()
            : SchoolClass::orderBy('sort_order')->get();

        return view('students_upload', ['classes' => $classes]);
    }

    public function upload(Request $request)
    {
        $user = Auth::user();
        $cls = $user->isClassTeacher() ? SchoolClass::find($user->class_id) : SchoolClass::find($request->input('class_id'));
        if (!$cls) {
            return back()->with('danger', $user->isClassTeacher() ? 'You are not currently assigned to a class.' : 'Please choose the class.');
        }

        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return back()->with('danger', 'Please choose the Excel file to upload.');
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return back()->with('danger', 'Only Excel files saved as .xlsx are accepted. Use the template downloaded from the system.');
        }

        try {
            $sheets = Xlsx::read($file->getRealPath());
        } catch (InvalidArgumentException $e) {
            return back()->with('danger', $e->getMessage());
        }

        $rows = $sheets['Students'] ?? (count($sheets) ? reset($sheets) : null);
        if (!$rows) {
            return back()->with('danger', 'The file has no data. Use the template downloaded from the system.');
        }

        // header check (row 1)
        $head = array_map(fn($h) => preg_replace('/[^a-z]/', '', strtolower((string) $h)), $rows[1] ?? []);
        if (($head[0] ?? '') !== 'regno' || ($head[1] ?? '') !== 'fullname' || ($head[2] ?? '') !== 'gender') {
            return back()->with('danger', 'The headings in row 1 must be: Reg No, Full Name, Gender. Please use the template downloaded from the system.');
        }

        $items = [];
        $seen = [];
        $dbRegs = Student::pluck('reg_no')->flip();
        $total = 0;
        foreach ($rows as $rowNum => $cells) {
            if ($rowNum === 1) {
                continue;
            }
            $reg = trim((string) ($cells[0] ?? ''));
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($cells[1] ?? '')));
            $genderRaw = trim((string) ($cells[2] ?? ''));
            if ($reg === '' && $name === '' && $genderRaw === '') {
                continue;
            }
            if (++$total > self::MAX_ROWS) {
                return back()->with('danger', 'Too many pupils in one file (maximum ' . self::MAX_ROWS . '). Split the list into two files.');
            }

            $errors = [];
            if ($reg === '') {
                $errors[] = 'Reg No is empty';
            } elseif (mb_strlen($reg) > 50) {
                $errors[] = 'Reg No is longer than 50 characters';
            } elseif (preg_match('/[\x00-\x1F\x7F<>]/u', $reg)) {
                $errors[] = 'Reg No has characters that are not allowed';
            } elseif (isset($seen[$reg])) {
                $errors[] = "Reg No is repeated (also on row {$seen[$reg]})";
            } elseif (isset($dbRegs[$reg])) {
                $errors[] = 'Reg No is already registered in the system';
            }
            if ($reg !== '' && !isset($seen[$reg])) {
                $seen[$reg] = $rowNum;
            }

            if ($name === '') {
                $errors[] = 'Full Name is empty';
            } elseif (mb_strlen($name) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                $errors[] = 'Full Name is not valid';
            }

            [$gender, $confirmed] = $this->gender($genderRaw, $name);
            if (!$gender) {
                $errors[] = $genderRaw === '' ? 'Gender is empty and could not be guessed from the name' : "Gender '{$genderRaw}' is not Male or Female";
            }

            $items[] = ['row' => $rowNum, 'reg_no' => $reg, 'full_name' => $name, 'gender' => $gender, 'confirmed' => $confirmed ? 1 : 0, 'errors' => $errors];
        }

        if (!$items) {
            return back()->with('danger', 'No pupils were found in the file. Write the pupils from row 2 downwards.');
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'class_id' => $cls->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 80),
            'items' => $items,
        ]);

        return redirect()->route('students_upload_preview');
    }

    public function preview(Request $request)
    {
        $data = $this->pending($request);
        if (!$data) {
            return redirect()->route('students_upload')->with('warning', 'Nothing to check. Please upload the file again.');
        }
        $errorCount = count(array_filter($data['items'], fn($i) => $i['errors']));

        return view('students_upload_preview', [
            'cls' => SchoolClass::find($data['class_id']),
            'items' => $data['items'],
            'file_name' => $data['file_name'],
            'error_count' => $errorCount,
        ]);
    }

    public function confirm(Request $request)
    {
        $user = Auth::user();
        $data = $this->pending($request);
        if (!$data) {
            return redirect()->route('students_upload')->with('warning', 'Nothing to save. Please upload the file again.');
        }
        if (array_filter($data['items'], fn($i) => $i['errors'])) {
            return redirect()->route('students_upload_preview')->with('danger', 'Some rows have problems. Correct the Excel file and upload it again.');
        }
        $cls = SchoolClass::find($data['class_id']);
        if (!$cls || ($user->isClassTeacher() && (int) $user->class_id !== (int) $cls->id)) {
            return redirect()->route('students')->with('danger', 'You do not have permission to do that.');
        }

        // someone may have registered the same Reg No since the preview
        $regs = array_column($data['items'], 'reg_no');
        if (Student::whereIn('reg_no', $regs)->exists()) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('students_upload')->with('danger', 'A Reg No in the file was registered in the meantime. Please upload the file again.');
        }

        $now = SchoolService::nowStr();
        DB::transaction(function () use ($data, $cls, $now) {
            foreach ($data['items'] as $i) {
                Student::create([
                    'reg_no' => $i['reg_no'],
                    'full_name' => $i['full_name'],
                    'gender' => $i['gender'],
                    'gender_confirmed' => $i['confirmed'],
                    'class_id' => $cls->id,
                    'active' => 1,
                    'created_at' => $now,
                ]);
            }
        });

        $request->session()->forget(self::SESSION_KEY);
        SchoolService::logAction($user, 'ADD_STUDENTS_EXCEL', count($data['items']) . " students to {$cls->name}");

        return redirect()->route('students', ['class_id' => $cls->id])
            ->with('success', count($data['items']) . " students registered in {$cls->name}.");
    }

    private function pending(Request $request): ?array
    {
        $data = $request->session()->get(self::SESSION_KEY);
        if (!$data || (int) ($data['user_id'] ?? 0) !== (int) Auth::id()) {
            return null;
        }

        return $data;
    }

    /** @return array{0:?string,1:bool} gender and whether the teacher stated it (true) or the system guessed it (false) */
    private function gender(string $raw, string $name): array
    {
        $k = strtolower(preg_replace('/[^a-z]/i', '', $raw));
        if (in_array($k, ['male', 'm', 'me', 'mwanaume', 'boy'], true)) {
            return ['Male', true];
        }
        if (in_array($k, ['female', 'f', 'ke', 'mwanamke', 'girl'], true)) {
            return ['Female', true];
        }
        if ($raw === '') {
            $g = SchoolService::detectGender($name);

            return [$g === 'Male' || $g === 'Female' ? $g : null, false];
        }

        return [null, false];
    }
}
