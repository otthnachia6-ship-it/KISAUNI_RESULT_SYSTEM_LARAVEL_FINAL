<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnalyticsController extends Controller
{
    public function analytics(Request $request)
    {
        $user = Auth::user();
        $exams = Examination::orderBy('academic_year', 'desc')->orderBy('id', 'desc')->get();
        $examId = $request->input('exam_id') ? (int) $request->input('exam_id') : null;
        if (!$examId && $exams->isNotEmpty()) {
            $examId = $exams->first()->id;
        }

        $classes = null;
        $cls = null;
        $classId = null;
        $viewScope = 'school';

        if ($user->isClassTeacher()) {
            $viewScope = 'class';
            $classId = $user->class_id;
            if (!$classId) {
                return redirect()->route('dashboard')->with('warning', 'Your account is not assigned to a class yet. Contact the Headmaster.');
            }
        } else {
            $classes = SchoolClass::orderBy('sort_order')->get();
            $classParam = $request->input('class_id', 'all');
            if ($classParam !== 'all' && $classParam !== '' && $classParam !== null) {
                $classId = is_numeric($classParam) ? (int) $classParam : null;
            }
            $viewScope = $classId ? 'class' : 'school';
        }

        $analytics = null;
        $trend = [];

        if ($examId && $classId) {
            $cls = SchoolClass::find($classId);
            $analytics = SchoolService::computeClassAnalytics($classId, $examId);
            $trend = SchoolService::computeTrend($classId);
        } elseif ($examId && $viewScope === 'school') {
            $analytics = SchoolService::computeSchoolAnalytics($examId);
            $trend = SchoolService::computeTrend(null);
        }

        return view('analytics', [
            'exams' => $exams,
            'exam_id' => $examId,
            'classes' => $classes,
            'class_id' => $classId,
            'cls' => $cls,
            'analytics' => $analytics,
            'view_scope' => $viewScope,
            'trend' => $trend,
        ]);
    }
}
