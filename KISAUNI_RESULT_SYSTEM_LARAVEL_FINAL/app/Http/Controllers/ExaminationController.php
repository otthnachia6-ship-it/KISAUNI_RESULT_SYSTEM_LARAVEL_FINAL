<?php

namespace App\Http\Controllers;

use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\Mark;
use App\Models\Setting;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExaminationController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $currentAcademicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);

        if ($request->isMethod('post') && $user->isHeadmaster()) {
            $examType = $request->input('exam_type');
            $academicYear = $currentAcademicYear;

            if (in_array($examType, SchoolService::EXAM_TYPES, true) && $academicYear) {
                $exists = Examination::where('exam_type', $examType)
                    ->where('academic_year', $academicYear)
                    ->exists();

                if ($exists) {
                    return redirect()->route('examinations')->with(
                        'warning',
                        'This examination already exists for that academic year.'
                    );
                }

                Examination::create([
                    'exam_type' => $examType,
                    'academic_year' => $academicYear,
                    'created_at' => SchoolService::nowStr(),
                ]);

                SchoolService::logAction($user, 'ADD_EXAM', "{$examType} {$academicYear}");

                return redirect()->route('examinations')->with('success', 'Examination added successfully.');
            }

            return redirect()->route('examinations');
        }

        $exams = Examination::where('academic_year', $currentAcademicYear)
            ->orderBy('id', 'desc')
            ->get();

        return view('examinations', [
            'exams' => $exams,
            'exam_types' => SchoolService::EXAM_TYPES,
            'current_academic_year' => $currentAcademicYear,
        ]);
    }

    public function delete($id)
    {
        $user = Auth::user();
        if (!$user->isHeadmaster()) {
            abort(403);
        }

        $exam = Examination::find($id);
        if (!$exam) {
            abort(404);
        }

        $hasMarks = Mark::where('exam_id', $id)->exists();
        if ($hasMarks) {
            return redirect()->route('examinations')->with(
                'danger',
                "Cannot delete '{$exam->exam_type} ({$exam->academic_year})' - marks already exist for this examination. Deleting it would destroy that data."
            );
        }

        ExamClassStatus::where('exam_id', $id)->delete();
        $exam->delete();

        SchoolService::logAction($user, 'DELETE_EXAM', "{$exam->exam_type} ({$exam->academic_year})");

        return redirect()->route('examinations')->with('info', 'Examination deleted successfully.');
    }
}
