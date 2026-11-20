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
        // Class Teacher: own class. Developer (Headmaster + Class Teacher capabilities): chooses the class.
        $canChooseClass = $user->isDeveloper();
        if (!$user->isClassTeacher() && !$canChooseClass) {
            return redirect()->route('marks_select')
                ->with('info', 'Result files are uploaded by the Class Teacher of each class.');
        }

        $classes = $canChooseClass ? SchoolClass::orderBy('sort_order')->get() : collect();
        if ($canChooseClass) {
            $cls = $classes->firstWhere('id', (int) $request->query('class', 0));
        } else {
            $cls = $user->class_id ? SchoolClass::find($user->class_id) : null;
        }
        $currentAcademicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);
        $exams = Examination::where('academic_year', $currentAcademicYear)->orderBy('id', 'desc')->get();

        $subjects = $cls
            ? DB::table('subjects as sub')->join('class_subjects as cs', 'cs.subject_id', '=', 'sub.id')
                ->where('cs.class_id', $cls->id)->select('sub.id', 'sub.name', 'sub.subject_code')->orderBy('sub.name')->get()
            : collect();
        $selectedExam = (int) $request->query('exam', 0);
        $selectedSubject = (int) $request->query('subject', 0);
        if (!$exams->contains('id', $selectedExam)) {
            $selectedExam = 0;
        }
        if (!$subjects->contains('id', $selectedSubject)) {
            $selectedSubject = 0;
        }
        $status = null;
        if ($cls && $selectedExam) {
            $status = DB::table('exam_class_status')->where('exam_id', $selectedExam)->where('class_id', $cls->id)->value('status') ?: 'draft';
        }

        return view('marks_upload', [
            'cls' => $cls,
            'classes' => $classes,
            'can_choose_class' => $canChooseClass,
            'exams' => $exams,
            'subjects' => $subjects,
            'selected_exam' => $selectedExam,
            'selected_subject' => $selectedSubject,
            'status' => $status,
            'locked' => in_array($status, ['submitted', 'under_review', 'approved'], true),
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

            // Load every existing mark of these students for this exam in ONE query,
            // instead of one query per student per subject.
            $existingMarks = $students->isEmpty() ? collect() : DB::table('marks')
                ->where('exam_id', $examId)
                ->whereIn('student_id', $students->pluck('id'))
                ->get(['id', 'student_id', 'subject_id', 'score', 'class_id'])
                ->keyBy(fn ($m) => $m->student_id . '_' . $m->subject_id);

            $toInsert = [];
            $toUpdate = [];

            foreach ($students as $st) {
                foreach ($subjects as $sub) {
                    $field = "score_{$st->id}_{$sub->id}";
                    $existing = $existingMarks->get($st->id . '_' . $sub->id);

                    // A box that was not on the page at all (e.g. a pupil added after the page was
                    // opened, or a very large form cut short by the server) is left exactly as it is.
                    if (!$request->has($field)) {
                        continue;
                    }

                    $val = trim((string) $request->input($field, ''));
                    $score = null;
                    $invalid = false;

                    if ($val !== '') {
                        $num = str_replace(',', '.', $val); // "45,5" is accepted like "45.5"
                        if (!is_numeric($num)) {
                            $invalid = true;
                            $invalidEntries[] = "{$st->full_name} - {$sub->name}: \"{$val}\" is not a number";
                        } else {
                            $parsed = round((float) $num, 2);
                            if ($parsed < 0 || $parsed > 100) {
                                $invalid = true;
                                $invalidEntries[] = "{$st->full_name} - {$sub->name}: {$val} (must be between 0 and 100)";
                            } else {
                                $score = $parsed;
                            }
                        }
                    }

                    // A typing mistake is never saved and never wipes out a mark that was already saved.
                    if ($invalid) {
                        continue;
                    }

                    if ($existing) {
                        // Only write rows that really changed (score, or the class the mark belongs to).
                        $oldScore = $existing->score === null ? null : (float) $existing->score;
                        $sameScore = ($oldScore === null && $score === null)
                            || ($oldScore !== null && $score !== null && abs($oldScore - $score) < 1e-9);
                        if (!$sameScore || (int) $existing->class_id !== (int) $classId) {
                            $toUpdate[$existing->id] = $score;
                        }
                    } else {
                        $toInsert[] = [
                            'student_id' => $st->id,
                            'exam_id' => $examId,
                            'subject_id' => $sub->id,
                            'class_id' => $classId,
                            'score' => $score,
                            'entered_by' => $user->id,
                            'updated_at' => $nowStr,
                        ];
                    }
                }
            }

            // Save everything together: either all marks and the status are saved, or nothing is.
            DB::transaction(function () use ($toUpdate, $toInsert, $examId, $classId, $user, $nowStr) {
                foreach ($toUpdate as $markId => $score) {
                    DB::table('marks')->where('id', $markId)->update([
                        'score' => $score,
                        'entered_by' => $user->id,
                        'updated_at' => $nowStr,
                        'class_id' => $classId,
                    ]);
                }
                foreach (array_chunk($toInsert, 500) as $chunk) {
                    DB::table('marks')->insert($chunk);
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
            });

            SchoolService::logAction($user, 'ENTER_MARKS', "exam={$examId} class={$classId}");

            if (!empty($invalidEntries)) {
                $preview = implode('; ', array_slice($invalidEntries, 0, 5));
                if (count($invalidEntries) > 5) {
                    $preview .= '; and ' . (count($invalidEntries) - 5) . ' more';
                }
                $noun = count($invalidEntries) === 1 ? 'entry' : 'entries';
                return redirect()->route('marks', ['exam_id' => $examId, 'class_id' => $classId])
                    ->with('warning', "Marks saved, but " . count($invalidEntries) . " {$noun} could not be used (the previous value was kept) - please correct and re-save: {$preview}");
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

        if (!Examination::whereKey($examId)->exists() || !SchoolClass::whereKey($classId)->exists()) {
            abort(404);
        }

        // Results already with the Headmaster (or approved) cannot be sent again: that would
        // silently turn "Approved" back into "Submitted" and hold back everyone's reports.
        $currentStatus = ExamClassStatus::where('exam_id', $examId)->where('class_id', $classId)->value('status');
        if (in_array($currentStatus, ['submitted', 'under_review', 'approved'], true)) {
            return redirect()->route('marks', ['exam_id' => $examId, 'class_id' => $classId])
                ->with('info', 'These results have already been submitted' . ($currentStatus === 'approved' ? ' and approved' : '') . '. Nothing was changed.');
        }

        // Count only marks of pupils who are in the class NOW and of subjects the class takes NOW
        // (marks of removed pupils or dropped subjects must not make up for missing ones).
        $subjectIds = DB::table('class_subjects')->where('class_id', $classId)->pluck('subject_id');
        $studentIds = Student::where('class_id', $classId)->where('active', 1)->pluck('id');
        $enteredCount = ($subjectIds->isEmpty() || $studentIds->isEmpty()) ? 0 : Mark::where('exam_id', $examId)
            ->whereIn('student_id', $studentIds)
            ->whereIn('subject_id', $subjectIds)
            ->whereNotNull('score')
            ->count();

        $expectedCount = $subjectIds->count() * $studentIds->count();
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
