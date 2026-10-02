<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Services\PdfReportService;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function reports()
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

        return view('reports', [
            'exams' => $exams,
            'classes' => $classes,
            'current_academic_year' => $currentAcademicYear,
        ]);
    }

    public function studentReport($studentId, $examId)
    {
        $user = Auth::user();
        [$ctx, $error] = SchoolService::loadStudentReportContext($studentId, $examId, $user);

        if ($error === 'forbidden') {
            return redirect()->route('reports')->with('danger', 'You do not have permission to do that.');
        }
        if (!$ctx) {
            abort(404);
        }

        return view('student_report', $ctx);
    }

    public function studentReportDownload($studentId, $examId)
    {
        $user = Auth::user();
        [$ctx, $error] = SchoolService::loadStudentReportContext($studentId, $examId, $user);

        if ($error === 'forbidden') {
            return redirect()->route('reports')->with('danger', 'You do not have permission to do that.');
        }
        if (!$ctx) {
            abort(404);
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

        $scoresMap = $ctx['result'] ? $ctx['result']['scores'] : [];
        $subjRows = [];
        foreach ($ctx['subjects'] as $sub) {
            // $sub is a stdClass row from SchoolService::computeClassResults()
            // (DB::table query, not an Eloquent model) - object access, not array access.
            $score = $scoresMap[$sub->id] ?? null;
            [$grade, ] = $score !== null ? SchoolService::gradeForScore($score) : ['-', null];
            $subjRows[] = [
                'name' => $sub->name,
                'mark' => $score,
                'grade' => $grade,
            ];
        }

        $pdfData = [
            'school_name' => $schoolName,
            'academic_year' => $ctx['exam']->academic_year,
            'reg_no' => $ctx['student']->reg_no,
            'full_name' => $ctx['student']->full_name,
            'class_name' => $ctx['cls']->name,
            'gender' => $ctx['student']->gender,
            'exam_type' => ucwords(strtolower($ctx['exam']->exam_type)),
            'subjects' => $subjRows,
            'total' => ($ctx['result'] && $ctx['result']['subjects_entered']) ? $ctx['result']['total'] : '-',
            'average' => ($ctx['result'] && $ctx['result']['average'] !== null) ? $ctx['result']['average'] : '-',
            'overall_grade' => $ctx['result'] ? $ctx['result']['grade'] : '-',
            'remark' => $ctx['result'] ? $ctx['result']['remark'] : '-',
            'position' => $ctx['result'] ? $ctx['result']['position'] : '-',
            'total_students' => $ctx['total_students'],
            'teacher_name' => $ctx['teacher'] ? $ctx['teacher']->full_name : null,
            'report_date' => $ctx['report_date'],
        ];

        $pdfBytes = PdfReportService::buildStudentReportPdf($pdfData, $logoAbsPath);

        $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $ctx['student']->full_name);
        $safeExam = preg_replace('/[^a-zA-Z0-9]/', '_', $ctx['exam']->exam_type);
        $filename = "{$safeName}_{$safeExam}_Report.pdf";

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
