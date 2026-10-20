<?php

namespace App\Http\Controllers;

use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\User;
use App\Services\PdfReportService;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ResultController extends Controller
{
    public function results()
    {
        $currentAcademicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);

        $rows = DB::table('exam_class_status as ecs')
            ->join('examinations as e', 'ecs.exam_id', '=', 'e.id')
            ->join('classes as c', 'ecs.class_id', '=', 'c.id')
            ->whereIn('ecs.status', ['submitted', 'under_review', 'approved', 'returned'])
            ->where('e.academic_year', $currentAcademicYear)
            ->select('ecs.*', 'e.exam_type', 'e.academic_year', 'c.name as class_name', 'c.id as class_id_val')
            ->orderByRaw("CASE WHEN ecs.status IN ('submitted', 'under_review') THEN 1 ELSE 0 END DESC")
            ->orderBy('e.academic_year', 'desc')
            ->orderBy('c.sort_order')
            ->get();

        return view('results', [
            'rows' => $rows,
            'current_academic_year' => $currentAcademicYear,
        ]);
    }

    public function review(Request $request, $examId, $classId)
    {
        $user = Auth::user();
        $exam = Examination::find($examId);
        $cls = SchoolClass::find($classId);
        if (!$exam || !$cls) {
            abort(404);
        }

        $statusRow = ExamClassStatus::where('exam_id', $examId)->where('class_id', $classId)->first();

        // Move submitted -> under_review on GET
        if ($request->isMethod('get') && $statusRow && $statusRow->status === 'submitted') {
            $statusRow->update(['status' => 'under_review']);
            SchoolService::logAction($user, 'START_REVIEW', "exam={$examId} class={$classId}");
            $statusRow = ExamClassStatus::where('exam_id', $examId)->where('class_id', $classId)->first();
        }

        if ($request->isMethod('post')) {
            $action = $request->input('action');
            $remarks = trim((string) $request->input('remarks', ''));
            $nowStr = SchoolService::nowStr();

            if ($action === 'approve') {
                if ($statusRow) {
                    $statusRow->update([
                        'status' => 'approved',
                        'approved_by' => $user->id,
                        'approved_at' => $nowStr,
                        'remarks' => $remarks,
                    ]);
                } else {
                    ExamClassStatus::create([
                        'exam_id' => $examId,
                        'class_id' => $classId,
                        'status' => 'approved',
                        'approved_by' => $user->id,
                        'approved_at' => $nowStr,
                        'remarks' => $remarks,
                    ]);
                }

                SchoolService::logAction($user, 'APPROVE_RESULTS', "exam={$examId} class={$classId}");
                return redirect()->route('results')->with('success', 'Results approved and locked successfully.');
            } elseif ($action === 'return') {
                if (!$remarks) {
                    return redirect()->route('review_results', ['exam_id' => $examId, 'class_id' => $classId])
                        ->with('warning', 'Please provide a reason/feedback when returning results for correction.');
                }

                if ($statusRow) {
                    $statusRow->update([
                        'status' => 'returned',
                        'remarks' => $remarks,
                    ]);
                } else {
                    ExamClassStatus::create([
                        'exam_id' => $examId,
                        'class_id' => $classId,
                        'status' => 'returned',
                        'remarks' => $remarks,
                    ]);
                }

                SchoolService::logAction($user, 'RETURN_RESULTS', "exam={$examId} class={$classId}: {$remarks}");
                return redirect()->route('results')->with('info', 'Results returned to the Class Teacher for correction.');
            }

            return redirect()->route('results');
        }

        [$subjects, $results] = SchoolService::computeClassResults($classId, $examId);
        $history = SchoolService::getStatusHistory($examId, $classId);

        $teacher = $cls->teacher_id ? User::find($cls->teacher_id) : null;

        $studentAverages = array_filter(array_column($results, 'average'), fn($a) => $a !== null);
        $classAverage = !empty($studentAverages) ? round(array_sum($studentAverages) / count($studentAverages), 1) : null;
        [$classGrade, ] = $classAverage !== null ? SchoolService::gradeForScore($classAverage) : ['-', null];

        $classRanking = SchoolService::computeClassRanking($examId);
        $classPosition = null;
        foreach ($classRanking as $cr) {
            if ($cr['cls']['id'] == $classId) {
                $classPosition = $cr['position'];
                break;
            }
        }

        return view('review_results', [
            'exam' => $exam,
            'cls' => $cls,
            'subjects' => $subjects,
            'results' => $results,
            'status_row' => $statusRow,
            'history' => $history,
            'teacher' => $teacher,
            'class_average' => $classAverage,
            'class_grade' => $classGrade,
            'class_position' => $classPosition,
            'report_date' => SchoolService::now()->format('d/m/Y'),
        ]);
    }

    public function reviewDownload($examId, $classId)
    {
        $exam = Examination::find($examId);
        $cls = SchoolClass::find($classId);
        if (!$exam || !$cls) {
            abort(404);
        }

        [$subjects, $results] = SchoolService::computeClassResults($classId, $examId);
        $teacher = $cls->teacher_id ? User::find($cls->teacher_id) : null;

        $studentAverages = array_filter(array_column($results, 'average'), fn($a) => $a !== null);
        $classAverage = !empty($studentAverages) ? round(array_sum($studentAverages) / count($studentAverages), 1) : null;
        [$classGrade, ] = $classAverage !== null ? SchoolService::gradeForScore($classAverage) : ['-', null];

        $classRanking = SchoolService::computeClassRanking($examId);
        $classPosition = null;
        foreach ($classRanking as $cr) {
            if ($cr['cls']['id'] == $classId) {
                $classPosition = $cr['position'];
                break;
            }
        }

        $schoolName = Setting::get('school_name', SchoolService::DEFAULT_SCHOOL_NAME);
        $logoPath = Setting::get('logo_path', 'images/logo.png');

        $logoAbsPath = null;
        if ($logoPath) {
            if (str_starts_with($logoPath, 'uploads/')) {
                $logoAbsPath = storage_path('app/uploads/' . substr($logoPath, 8));
            } else {
                $logoAbsPath = public_path($logoPath);
            }
        }

        $pdfRows = [];
        foreach ($results as $r) {
            $scoresList = [];
            foreach ($subjects as $s) {
                // $s is a stdClass row from SchoolService::computeClassResults()
                // (DB::table query, not an Eloquent model) - object access, not array access.
                $scoresList[] = $r['scores'][$s->id] ?? null;
            }

            $pdfRows[] = [
                'name' => $r['student']['full_name'],
                'reg_no' => $r['student']['reg_no'] ?? '-',
                'scores' => $scoresList,
                'total' => $r['subjects_entered'] ? $r['total'] : null,
                'average' => $r['average'],
                'grade' => $r['grade'],
                'remark' => $r['remark'],
                'position' => $r['position'],
            ];
        }

        $pdfData = [
            'school_name' => $schoolName,
            'academic_year' => $exam->academic_year,
            'exam_type' => ucwords(strtolower($exam->exam_type)),
            'class_name' => $cls->name,
            'teacher_name' => $teacher ? $teacher->full_name : null,
            'class_average' => $classAverage !== null ? $classAverage : '-',
            'class_grade' => $classGrade ?: '-',
            'class_position' => $classPosition !== null ? $classPosition : '-',
            'total_students' => count($results),
            'report_date' => SchoolService::now()->format('d/m/Y'),
            'subjects' => array_column($subjects, 'name'),
            'rows' => $pdfRows,
        ];

        $pdfBytes = PdfReportService::buildClassResultPdf($pdfData, $logoAbsPath);

        $safeClass = preg_replace('/[^a-zA-Z0-9]/', '_', $cls->name);
        $safeExam = preg_replace('/[^a-zA-Z0-9]/', '_', $exam->exam_type);
        $filename = "{$safeClass}_{$safeExam}_{$exam->academic_year}_ResultSheet.pdf";

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function headmasterOverview(Request $request)
    {
        $exams = Examination::orderBy('academic_year', 'desc')->orderBy('id', 'desc')->get();
        $examId = $request->input('exam_id');
        if (!$examId && $exams->isNotEmpty()) {
            $examId = $exams->first()->id;
        }

        $overview = [];
        if ($examId) {
            $classes = SchoolClass::orderBy('sort_order')->get();
            foreach ($classes as $cls) {
                [$subjects, $res] = SchoolService::computeClassResults($cls->id, $examId, null, true, false);
                $averages = array_filter(array_column($res, 'average'), fn($a) => $a !== null);
                $classAvg = !empty($averages) ? round(array_sum($averages) / count($averages), 1) : null;
                $statusRow = ExamClassStatus::where('exam_id', $examId)->where('class_id', $cls->id)->first();

                $topStudent = null;
                foreach ($res as $r) {
                    if (($r['position'] ?? null) === 1) {
                        $topStudent = $r['student']['full_name'];
                        break;
                    }
                }

                $overview[] = [
                    'cls' => $cls,
                    'student_count' => count($res),
                    'class_average' => $classAvg,
                    'status' => $statusRow ? $statusRow->status : 'draft',
                    'top_student' => $topStudent,
                ];
            }
        }

        return view('headmaster_overview', [
            'exams' => $exams,
            'exam_id' => $examId ? (int)$examId : null,
            'overview' => $overview,
        ]);
    }
}
