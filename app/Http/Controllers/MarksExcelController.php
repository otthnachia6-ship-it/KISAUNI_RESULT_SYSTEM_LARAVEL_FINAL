<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\SchoolService;
use App\Services\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Marks of one subject for one class and one examination, by Excel (.xlsx).
 * The SYSTEM makes the template (pupils already listed), the teacher only fills the Marks column.
 * Upload -> preview -> confirm. All-or-nothing. Locked (submitted/approved) classes are refused.
 * The Class Teacher still presses Submit afterwards, so review/approval is unchanged.
 */
class MarksExcelController extends Controller
{
    private const SESSION_KEY = 'marks_excel';

    private function teacherClass(): ?SchoolClass
    {
        $user = Auth::user();

        return $user && $user->isClassTeacher() && $user->class_id ? SchoolClass::find($user->class_id) : null;
    }

    private function subjectOfClass(int $subjectId, int $classId): ?object
    {
        return DB::table('subjects as sub')->join('class_subjects as cs', 'cs.subject_id', '=', 'sub.id')
            ->where('cs.class_id', $classId)->where('sub.id', $subjectId)->select('sub.*')->first();
    }

    private function isLocked(int $examId, int $classId): bool
    {
        $status = DB::table('exam_class_status')->where('exam_id', $examId)->where('class_id', $classId)->value('status');

        return in_array($status, ['submitted', 'under_review', 'approved'], true);
    }

    public function template($examId, $subjectId)
    {
        $cls = $this->teacherClass();
        $exam = Examination::find($examId);
        if (!$cls || !$exam) {
            return redirect()->route('marks_upload')->with('danger', 'Choose the examination and the subject first.');
        }
        $subject = $this->subjectOfClass((int) $subjectId, $cls->id);
        if (!$subject) {
            return redirect()->route('marks_upload')->with('danger', 'That subject is not taught in your class.');
        }

        $students = DB::table('students')->where('class_id', $cls->id)->where('active', 1)->orderBy('full_name')->get();
        $existing = DB::table('marks')->where('exam_id', $exam->id)->where('subject_id', $subject->id)
            ->whereIn('student_id', $students->pluck('id'))->pluck('score', 'student_id');

        $code = $subject->subject_code ?: '';
        $rows = [
            [['v' => 'KISAUNI PRIMARY SCHOOL - Marks Sheet', 's' => Xlsx::S_TITLE]],
            [['v' => 'Class', 's' => Xlsx::S_LABEL], $cls->name],
            [['v' => 'Examination', 's' => Xlsx::S_LABEL], "{$exam->exam_type} - {$exam->academic_year}"],
            [['v' => 'Subject', 's' => Xlsx::S_LABEL], $subject->name],
            [['v' => 'Subject Code', 's' => Xlsx::S_LABEL], $code !== '' ? ['v' => $code, 's' => Xlsx::S_TEXT] : '-'],
            [['v' => 'Write the marks (0 to 100) in the yellow column. Write ABS for a pupil who was absent (counts as 0). Leave empty to skip. Do not change Reg No, names or their order.', 's' => Xlsx::S_LABEL]],
            [],
            [['v' => 'No', 's' => Xlsx::S_HEADER], ['v' => 'Reg No', 's' => Xlsx::S_HEADER], ['v' => 'Full Name', 's' => Xlsx::S_HEADER], ['v' => 'Marks', 's' => Xlsx::S_HEADER]],
        ];
        foreach ($students as $i => $st) {
            $score = $existing[$st->id] ?? null;
            $rows[] = [
                $i + 1,
                ['v' => $st->reg_no, 's' => Xlsx::S_TEXT],
                ['v' => $st->full_name, 's' => Xlsx::S_TEXT],
                ['v' => $score !== null ? (float) $score : null, 's' => Xlsx::S_INPUT],
            ];
        }

        $bytes = Xlsx::build([
            ['name' => 'Marks', 'freeze' => 8, 'cols' => [['width' => 8], ['width' => 22], ['width' => 40], ['width' => 14]], 'rows' => $rows],
            ['name' => 'Meta', 'hidden' => true, 'rows' => [
                ['class_id', $cls->id], ['exam_id', $exam->id], ['subject_id', $subject->id], ['subject_code', $code], ['version', 1],
            ]],
        ]);

        $safe = preg_replace('/[^A-Za-z0-9]+/', '_', "{$cls->name}_{$subject->name}_{$exam->exam_type}");
        SchoolService::logAction(Auth::user(), 'DOWNLOAD_MARKS_TEMPLATE', "exam={$exam->id} class={$cls->id} subject={$subject->id}");

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Marks_' . trim($safe, '_') . '.xlsx"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function upload(Request $request)
    {
        $user = Auth::user();
        $cls = $this->teacherClass();
        if (!$cls) {
            return redirect()->route('marks_upload')->with('info', 'Result files are uploaded by the Class Teacher of each class.');
        }
        $back = ['exam' => (int) $request->input('exam_id'), 'subject' => (int) $request->input('subject_id')];

        $exam = Examination::find($request->input('exam_id'));
        $subject = $this->subjectOfClass((int) $request->input('subject_id'), $cls->id);
        if (!$exam || !$subject) {
            return redirect()->route('marks_upload')->with('danger', 'Choose the examination and the subject first.');
        }
        if ($this->isLocked($exam->id, $cls->id)) {
            return redirect()->route('marks_upload', $back)->with('warning', 'Marks for this class have already been submitted/approved and cannot be changed.');
        }

        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return redirect()->route('marks_upload', $back)->with('danger', 'Please choose the Excel file to upload.');
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return redirect()->route('marks_upload', $back)->with('danger', 'Only Excel files saved as .xlsx are accepted. Use the template downloaded from the system.');
        }

        try {
            $sheets = Xlsx::read($file->getRealPath());
        } catch (InvalidArgumentException $e) {
            return redirect()->route('marks_upload', $back)->with('danger', $e->getMessage());
        }

        // the file must be the template made for THIS class, examination and subject
        $meta = [];
        foreach ($sheets['Meta'] ?? [] as $cells) {
            if (isset($cells[0])) {
                $meta[strtolower($cells[0])] = $cells[1] ?? '';
            }
        }
        if (!$meta || !isset($sheets['Marks'])) {
            return redirect()->route('marks_upload', $back)->with('danger', 'This is not a marks template from the system. Download the template, fill the Marks column and upload that file.');
        }
        if ((int) ($meta['class_id'] ?? 0) !== (int) $cls->id) {
            return redirect()->route('marks_upload', $back)->with('danger', 'This file was made for another class. Download the template for your own class.');
        }
        if ((int) ($meta['exam_id'] ?? 0) !== (int) $exam->id) {
            return redirect()->route('marks_upload', $back)->with('danger', 'This file was made for another examination. Choose that examination, or download a new template.');
        }
        if ((int) ($meta['subject_id'] ?? 0) !== (int) $subject->id) {
            return redirect()->route('marks_upload', $back)->with('danger', 'This file was made for another subject. Choose that subject, or download a new template.');
        }

        // find the table by its headings (Reg No / Full Name / Marks)
        $rows = $sheets['Marks'];
        $headRow = null;
        $col = [];
        foreach ($rows as $rn => $cells) {
            if ($rn > 30) {
                break;
            }
            $map = [];
            foreach ($cells as $ci => $text) {
                $map[preg_replace('/[^a-z]/', '', strtolower($text))] = $ci;
            }
            if (isset($map['regno'], $map['marks'])) {
                $headRow = $rn;
                $col = $map;
                break;
            }
        }
        if ($headRow === null) {
            return redirect()->route('marks_upload', $back)->with('danger', 'The headings Reg No and Marks were not found. Please use the template downloaded from the system.');
        }

        $students = DB::table('students')->where('class_id', $cls->id)->where('active', 1)->get()->keyBy('reg_no');
        $items = [];
        $seen = [];
        foreach ($rows as $rn => $cells) {
            if ($rn <= $headRow) {
                continue;
            }
            $reg = trim((string) ($cells[$col['regno']] ?? ''));
            $name = trim((string) ($cells[$col['fullname'] ?? -1] ?? ''));
            $raw = trim((string) ($cells[$col['marks']] ?? ''));
            if ($reg === '' && $name === '' && $raw === '') {
                continue;
            }

            $errors = [];
            $warn = null;
            $st = $students[$reg] ?? null;
            if ($reg === '') {
                $errors[] = 'Reg No is empty';
            } elseif (!$st) {
                $errors[] = 'Reg No is not a pupil of your class';
            } elseif (isset($seen[$reg])) {
                $errors[] = "Reg No is repeated (also on row {$seen[$reg]})";
            } elseif ($name !== '' && $this->nameKey($name) !== $this->nameKey($st->full_name)) {
                $warn = "Name in the file differs from the system ({$st->full_name})";
            }
            if ($reg !== '') {
                $seen[$reg] ??= $rn;
            }

            $score = null;
            $kind = 'blank';
            if ($raw !== '') {
                $up = strtoupper($raw);
                if (in_array($up, ['ABS', 'ABSENT'], true)) {
                    $score = 0.0;
                    $kind = 'absent';
                } else {
                    $num = str_replace(',', '.', $raw);
                    if (!is_numeric($num)) {
                        $errors[] = "Marks '{$raw}' is not a number (use ABS for absent)";
                    } elseif ((float) $num < 0 || (float) $num > 100) {
                        $errors[] = "Marks {$raw} must be between 0 and 100";
                    } else {
                        $score = round((float) $num, 2);
                        $kind = 'marks';
                    }
                }
            }

            $items[] = [
                'row' => $rn, 'student_id' => $st->id ?? null, 'reg_no' => $reg, 'full_name' => $st->full_name ?? $name,
                'score' => $score, 'kind' => $kind, 'raw' => $raw, 'errors' => $errors, 'warning' => $warn,
            ];
        }

        if (!$items) {
            return redirect()->route('marks_upload', $back)->with('danger', 'No pupils were found in the file.');
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id, 'class_id' => $cls->id, 'exam_id' => $exam->id, 'subject_id' => $subject->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 80), 'items' => $items,
            'not_in_file' => max(0, $students->count() - count(array_filter($seen, fn($v, $k) => isset($students[$k]), ARRAY_FILTER_USE_BOTH))),
        ]);

        return redirect()->route('marks_upload_preview');
    }

    public function preview(Request $request)
    {
        $data = $this->pending($request);
        if (!$data) {
            return redirect()->route('marks_upload')->with('warning', 'Nothing to check. Please upload the file again.');
        }

        return view('marks_upload_preview', [
            'cls' => SchoolClass::find($data['class_id']),
            'exam' => Examination::find($data['exam_id']),
            'subject' => Subject::find($data['subject_id']),
            'items' => $data['items'],
            'file_name' => $data['file_name'],
            'not_in_file' => $data['not_in_file'],
            'error_count' => count(array_filter($data['items'], fn($i) => $i['errors'])),
            'count_marks' => count(array_filter($data['items'], fn($i) => $i['kind'] === 'marks')),
            'count_absent' => count(array_filter($data['items'], fn($i) => $i['kind'] === 'absent')),
            'count_blank' => count(array_filter($data['items'], fn($i) => $i['kind'] === 'blank')),
        ]);
    }

    public function confirm(Request $request)
    {
        $user = Auth::user();
        $data = $this->pending($request);
        $cls = $this->teacherClass();
        if (!$data || !$cls || (int) $data['class_id'] !== (int) $cls->id) {
            return redirect()->route('marks_upload')->with('warning', 'Nothing to save. Please upload the file again.');
        }
        $back = ['exam' => $data['exam_id'], 'subject' => $data['subject_id']];
        if (array_filter($data['items'], fn($i) => $i['errors'])) {
            return redirect()->route('marks_upload_preview')->with('danger', 'Some rows have problems. Correct the Excel file and upload it again.');
        }
        if ($this->isLocked((int) $data['exam_id'], $cls->id)) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('marks_upload', $back)->with('warning', 'Marks for this class have already been submitted/approved and cannot be changed.');
        }
        if (!$this->subjectOfClass((int) $data['subject_id'], $cls->id)) {
            return redirect()->route('marks_upload')->with('danger', 'That subject is not taught in your class.');
        }

        $now = SchoolService::nowStr();
        $saved = 0;
        DB::transaction(function () use ($data, $cls, $user, $now, &$saved) {
            foreach ($data['items'] as $i) {
                if ($i['score'] === null || !$i['student_id']) {
                    continue;
                }
                DB::table('marks')->updateOrInsert(
                    ['student_id' => $i['student_id'], 'exam_id' => $data['exam_id'], 'subject_id' => $data['subject_id']],
                    ['class_id' => $cls->id, 'score' => $i['score'], 'entered_by' => $user->id, 'updated_at' => $now]
                );
                $saved++;
            }
            // same as saving the marks sheet by hand: the class stays in draft until the teacher submits
            DB::table('exam_class_status')->updateOrInsert(
                ['exam_id' => $data['exam_id'], 'class_id' => $cls->id],
                ['status' => 'draft']
            );
        });

        $request->session()->forget(self::SESSION_KEY);
        SchoolService::logAction($user, 'UPLOAD_MARKS_EXCEL', "exam={$data['exam_id']} class={$cls->id} subject={$data['subject_id']} saved={$saved}");

        return redirect()->route('marks', ['exam_id' => $data['exam_id'], 'class_id' => $cls->id])
            ->with('success', "{$saved} marks saved from Excel. Check the marks sheet, then press Submit when every subject is complete.");
    }

    private function pending(Request $request): ?array
    {
        $data = $request->session()->get(self::SESSION_KEY);
        if (!$data || (int) ($data['user_id'] ?? 0) !== (int) Auth::id()) {
            return null;
        }

        return $data;
    }

    private function nameKey(string $name): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));
    }
}
