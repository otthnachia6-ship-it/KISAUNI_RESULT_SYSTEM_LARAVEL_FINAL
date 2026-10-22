<?php

namespace App\Http\Controllers;

use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Services\OzemsImporter;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class MarksController extends Controller
{
    public function marksSelect()
    {
        $user = Auth::user();
        $currentAcademicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);

        $exams = Examination::where('academic_year', $currentAcademicYear)
            ->orderBy('id', 'desc')
            ->get();

        if ($user->isClassTeacher()) {
            $classes = SchoolClass::where('id', $user->class_id)->get();
        } else {
            $classes = SchoolClass::orderBy('sort_order')->get();
        }

        return view('marks_select', [
            'exams' => $exams,
            'classes' => $classes,
            'current_academic_year' => $currentAcademicYear,
        ]);
    }

    public function uploadPage(Request $request)
    {
        $user = Auth::user();
        if (!$user->isClassTeacher()) {
            return redirect()->route('marks_select')
                ->with('info', 'Result files are uploaded by the Class Teacher of each class.');
        }

        $cls = $user->class_id ? SchoolClass::find($user->class_id) : null;
        $currentAcademicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);
        $exams = Examination::where('academic_year', $currentAcademicYear)->orderBy('id', 'desc')->get();

        return view('marks_upload', [
            'cls' => $cls,
            'exams' => $exams,
            'selected_exam' => (int) $request->query('exam', 0),
            'current_academic_year' => $currentAcademicYear,
            'report' => session('ozems_report'),
        ]);
    }

    public function uploadOzemsExcel(Request $request)
    {
        $user = Auth::user();
        if (!$user->isClassTeacher()) {
            return redirect()->route('marks_select')
                ->with('danger', 'Only the Class Teacher of a class can upload result files.');
        }
        if (!$user->class_id || !SchoolClass::find($user->class_id)) {
            return redirect()->route('marks_upload')
                ->with('danger', 'You are not assigned to a class yet. Contact the Headmaster.');
        }

        $validator = Validator::make($request->all(), [
            'exam_id' => 'required|integer|exists:examinations,id',
            'file' => 'required|file|max:4096',
        ], [
            'exam_id.required' => 'Please choose the examination.',
            'exam_id.exists' => 'That examination does not exist.',
            'file.required' => 'Please choose the Excel file exported from ZEMS.',
            'file.max' => 'The file is too large (maximum 4 MB).',
            'file.file' => 'The file could not be uploaded. Please try again.',
        ]);
        if ($validator->fails()) {
            return redirect()->route('marks_upload')->with('danger', $validator->errors()->first());
        }

        $examId = (int) $request->input('exam_id');
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xls', 'csv'], true)) {
            return redirect()->route('marks_upload', ['exam' => $examId])
                ->with('danger', 'Please upload the Excel file (.xls) exactly as exported from ZEMS. A .csv file also works.');
        }

        try {
            $summary = OzemsImporter::import($user, $examId, (int) $user->class_id, $file->getRealPath());
        } catch (InvalidArgumentException $e) {
            return redirect()->route('marks_upload', ['exam' => $examId])->with('danger', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('marks_upload', ['exam' => $examId])
                ->with('danger', 'The upload failed and nothing was saved. Please try again, or tell the Headmaster if it keeps happening.');
        }

        $report = $summary;
        foreach (['invalid', 'not_found', 'duplicates', 'missing_students', 'linked'] as $key) {
            $report[$key . '_total'] = count($summary[$key]);
            $report[$key] = array_slice($summary[$key], 0, 30);
        }

        $problems = count($summary['invalid']) + count($summary['not_found']) + count($summary['duplicates']);
        $text = "{$summary['saved']} mark(s) saved for {$summary['subject_name']} (code {$summary['subject_code']}).";
        if (count($summary['linked']) > 0) {
            $text .= ' ' . count($summary['linked']) . ' pupil(s) were linked to their ZEMS number by name - please check them below.';
        }

        $redirect = redirect()->route('marks_upload', ['exam' => $examId])->with('ozems_report', $report);
        if ($summary['saved'] === 0) {
            return $redirect->with('warning', 'No marks were saved from this file. See the details below.');
        }
        if ($problems > 0) {
            return $redirect->with('warning', $text . " {$problems} row(s) were skipped - see the details below.");
        }

        return $redirect->with('success', $text);
    }

    public function marks(Request $request, $examId, $classId)
    {
        $user = Auth::user();
        if ($user->isClassTeacher() && (int)$classId !== (int)$user->class_id) {
            return redirect()->route('marks_select')->with('danger', 'You can only enter marks for your own assigned class.');
        }

        $exam = Examination::find($examId);
        $cls = SchoolClass::find($classId);
        if (!$exam || !$cls) {
            abort(404);
        }

        $statusRow = ExamClassStatus::where('exam_id', $examId)->where('class_id', $classId)->first();
        $status = $statusRow ? $statusRow->status : 'draft';
        $locked = in_array($status, ['submitted', 'under_review', 'approved'], true);

        if ($request->isMethod('post')) {
            if ($locked) {
                return redirect()->route('marks', ['exam_id' => $examId, 'class_id' => $classId])
                    ->with('warning', 'Marks for this class have already been submitted/approved and cannot be edited.');
            }

            $subjects = DB::table('subjects as sub')
                ->join('class_subjects as cs', 'cs.subject_id', '=', 'sub.id')
                ->where('cs.class_id', $classId)
                ->select('sub.*')
                ->orderBy('sub.name')
                ->get();

            $students = Student::where('class_id', $classId)->where('active', 1)->get();
            $invalidEntries = [];
            $nowStr = SchoolService::nowStr();

            foreach ($students as $st) {
                foreach ($subjects as $sub) {
                    $field = "score_{$st->id}_{$sub->id}";
                    $val = trim((string) $request->input($field, ''));
                    $score = null;

                    if ($val !== '') {
                        if (!is_numeric($val)) {
                            $invalidEntries[] = "{$st->full_name} - {$sub->name}: \"{$val}\" is not a number";
                        } else {
                            $parsed = (float) $val;
                            if ($parsed < 0 || $parsed > 100) {
                                $invalidEntries[] = "{$st->full_name} - {$sub->name}: {$val} (must be between 0 and 100)";
                            } else {
                                $score = $parsed;
                            }
                        }
                    }

                    $existing = Mark::where('student_id', $st->id)
                        ->where('exam_id', $examId)
                        ->where('subject_id', $sub->id)
                        ->first();

                    if ($existing) {
                        $existing->update([
                            'score' => $score,
                            'entered_by' => $user->id,
                            'updated_at' => $nowStr,
                            'class_id' => $classId,
                        ]);
                    } else {
                        Mark::create([
                            'student_id' => $st->id,
                            'exam_id' => $examId,
                            'subject_id' => $sub->id,
                            'class_id' => $classId,
                            'score' => $score,
                            'entered_by' => $user->id,
                            'updated_at' => $nowStr,
                        ]);
                    }
                }
            }

            // Upsert status to 'draft'
            $statusRecord = ExamClassStatus::where('exam_id', $examId)->where('class_id', $classId)->first();
            if ($statusRecord) {
                $statusRecord->update(['status' => 'draft']);
            } else {
                ExamClassStatus::create([
                    'exam_id' => $examId,
                    'class_id' => $classId,
                    'status' => 'draft',
                ]);
            }

            SchoolService::logAction($user, 'ENTER_MARKS', "exam={$examId} class={$classId}");

            if (!empty($invalidEntries)) {
                $preview = implode('; ', array_slice($invalidEntries, 0, 5));
                if (count($invalidEntries) > 5) {
                    $preview .= '; and ' . (count($invalidEntries) - 5) . ' more';
                }
                $noun = count($invalidEntries) === 1 ? 'entry' : 'entries';
                return redirect()->route('marks', ['exam_id' => $examId, 'class_id' => $classId])
                    ->with('warning', "Marks saved, but " . count($invalidEntries) . " {$noun} could not be used (left blank) - please correct and re-save: {$preview}");
            }

            return redirect()->route('marks', ['exam_id' => $examId, 'class_id' => $classId])
                ->with('success', 'Marks saved successfully.');
        }

        [$subjectsList, $results] = SchoolService::computeClassResults($classId, $examId, null, $locked);
        $history = SchoolService::getStatusHistory($examId, $classId);

        return view('marks', [
            'exam' => $exam,
            'cls' => $cls,
            'subjects' => $subjectsList,
            'results' => $results,
            'status' => $status,
            'locked' => $locked,
            'history' => $history,
        ]);
    }

    public function submit($examId, $classId)
    {
        $user = Auth::user();
        if ($user->isClassTeacher() && (int)$classId !== (int)$user->class_id) {
            return redirect()->route('marks_select')->with('danger', 'You do not have permission to do that.');
        }

        $subjectCount = DB::table('class_subjects')->where('class_id', $classId)->count();
        $studentCount = Student::where('class_id', $classId)->where('active', 1)->count();
        $enteredCount = Mark::where('exam_id', $examId)
            ->where('class_id', $classId)
            ->whereNotNull('score')
            ->count();

        $expectedCount = $subjectCount * $studentCount;
        if ($expectedCount > 0 && $enteredCount < $expectedCount) {
            $missing = $expectedCount - $enteredCount;
            return redirect()->route('marks', ['exam_id' => $examId, 'class_id' => $classId])->with(
                'warning',
                "Cannot submit yet - {$missing} mark(s) are still missing for this class/exam. Please fill in every subject for every student before submitting."
            );
        }

        $nowStr = SchoolService::nowStr();
        $statusRecord = ExamClassStatus::where('exam_id', $examId)->where('class_id', $classId)->first();
        if ($statusRecord) {
            $statusRecord->update([
                'status' => 'submitted',
                'submitted_by' => $user->id,
                'submitted_at' => $nowStr,
            ]);
        } else {
            ExamClassStatus::create([
                'exam_id' => $examId,
                'class_id' => $classId,
                'status' => 'submitted',
                'submitted_by' => $user->id,
                'submitted_at' => $nowStr,
            ]);
        }

        SchoolService::logAction($user, 'SUBMIT_RESULTS', "exam={$examId} class={$classId}");

        return redirect()->route('marks', ['exam_id' => $examId, 'class_id' => $classId])
            ->with('success', 'Results submitted to the Headmaster for review.');
    }
}
