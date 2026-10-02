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
        $emptyState = null;
        $notices = [];

        // Expected "nothing to show" situations are handled explicitly and
        // never with a blanket try/catch, so genuine bugs still surface
        // (and get logged) as real errors.
        $exam = $examId ? $exams->firstWhere('id', $examId) : null;

        if ($exams->isEmpty()) {
            $emptyState = [
                'title' => 'No examinations',
                'message' => 'No examinations have been created yet. Please ask the Headmaster to create an examination first.',
            ];
        } elseif (!$exam) {
            $emptyState = [
                'title' => 'Examination not found',
                'message' => 'The selected examination could not be found. Please choose another examination from the list.',
            ];
        } elseif ($viewScope === 'class') {
            $cls = SchoolClass::find($classId);
            if (!$cls) {
                $emptyState = [
                    'title' => 'Class not found',
                    'message' => 'The selected class could not be found. Please choose another class.',
                ];
                $classId = null;
            } else {
                $analytics = SchoolService::computeClassAnalytics($classId, $examId);
                $trend = SchoolService::computeTrend($classId);
            }
        } else {
            $analytics = SchoolService::computeSchoolAnalytics($examId);
            $trend = SchoolService::computeTrend(null);
        }

        if ($exam && !$emptyState) {
            $state = SchoolService::analyticsEmptyState($exam, $cls, $viewScope, $analytics);
            $emptyState = $state['empty'];
            $notices = $state['notices'];
        }

        if ($emptyState) {
            $trend = [];
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
            'empty_state' => $emptyState,
            'notices' => $notices,
        ]);
    }
}
