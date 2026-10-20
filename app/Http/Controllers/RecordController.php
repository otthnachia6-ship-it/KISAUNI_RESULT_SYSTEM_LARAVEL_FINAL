<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecordController extends Controller
{
    public function records(Request $request)
    {
        $user = Auth::user();
        $years = Examination::select('academic_year')
            ->distinct()
            ->orderBy('academic_year', 'desc')
            ->pluck('academic_year')
            ->toArray();

        $currentAcademicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);
        $year = $request->input('year', '');
        if (!$year) {
            $year = in_array($currentAcademicYear, $years, true) ? $currentAcademicYear : ($years[0] ?? '');
        }

        $examType = $request->input('exam_type', '');
        $search = trim($request->input('q', ''));

        if ($user->isClassTeacher()) {
            $classes = SchoolClass::where('id', $user->class_id)->get();
            $classFilterId = (string) ($user->class_id ?? '');
        } else {
            $classes = SchoolClass::orderBy('sort_order')->get();
            $classFilterId = $request->input('class_id', '');
        }

        if ($year) {
            $examTypesForYear = Examination::where('academic_year', $year)
                ->select('exam_type')
                ->distinct()
                ->orderBy('exam_type')
                ->pluck('exam_type')
                ->toArray();
        } else {
            $examTypesForYear = Examination::select('exam_type')
                ->distinct()
                ->orderBy('exam_type')
                ->pluck('exam_type')
                ->toArray();
        }

        $exams = [];
        if ($year) {
            $q = Examination::where('academic_year', $year);
            if ($examType) {
                $q->where('exam_type', $examType);
            }
            $exams = $q->orderBy('id')->get();
        }

        $rows = [];
        foreach ($exams as $exam) {
            $query = DB::table('marks as m')
                ->join('students as s', 'm.student_id', '=', 's.id')
                ->join('classes as cur', 's.class_id', '=', 'cur.id')
                ->leftJoin('classes as hc', 'm.class_id', '=', 'hc.id')
                ->where('m.exam_id', $exam->id)
                ->whereNotNull('m.score')
                ->select(
                    's.id',
                    's.reg_no',
                    's.full_name',
                    's.active',
                    's.leave_reason',
                    DB::raw('COALESCE(hc.name, cur.name) as class_name'),
                    DB::raw('COALESCE(hc.id, cur.id) as class_id')
                )
                ->distinct();

            if ($classFilterId) {
                $query->where('s.class_id', $classFilterId);
            }

            if ($search !== '') {
                $query->where(function ($sub) use ($search) {
                    $sub->where('s.full_name', 'like', "%{$search}%")
                        ->orWhere('s.reg_no', 'like', "%{$search}%");
                });
            }

            $query->orderBy('cur.sort_order')
                  ->orderByRaw('LOWER(s.full_name) ASC');

            $students = $query->get();
            if ($students->isNotEmpty()) {
                $rows[] = [
                    'exam' => $exam,
                    'students' => $students->map(fn($s) => (array)$s)->toArray(),
                ];
            }
        }

        return view('records', [
            'years' => $years,
            'year' => $year,
            'exam_type' => $examType,
            'exam_types' => $examTypesForYear,
            'classes' => $classes,
            'class_filter_id' => $classFilterId,
            'search' => $search,
            'rows' => $rows,
        ]);
    }
}
