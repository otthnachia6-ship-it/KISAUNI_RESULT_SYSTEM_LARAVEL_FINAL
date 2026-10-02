<?php

namespace App\Http\Controllers;

use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
