<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ApiController extends Controller
{
    public function detectGender(Request $request)
    {
        $name = $request->input('name', '');
        $gender = SchoolService::detectGender($name);
        return response()->json(['gender' => $gender]);
    }

    public function keepAlive()
    {
        session(['last_activity' => SchoolService::now()->timestamp]);
        return response()->json(['ok' => true]);
    }

    public function studentsByClass($classId)
    {
        $user = Auth::user();
        if ($user->isClassTeacher() && (int) $classId !== (int) $user->class_id) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $rows = Student::where('class_id', $classId)
            ->where('active', 1)
            ->orderByRaw("CASE gender WHEN 'Male' THEN 0 WHEN 'Female' THEN 1 ELSE 2 END ASC")
            ->orderByRaw('LOWER(full_name) ASC')
            ->select('id', 'reg_no', 'full_name')
            ->get();

        return response()->json($rows);
    }

    public function studentsWithMarks($classId, $examId)
    {
        $user = Auth::user();
        if ($user->isClassTeacher() && (int) $classId !== (int) $user->class_id) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $rows = DB::table('students as s')
            ->join('marks as m', 'm.student_id', '=', 's.id')
            ->where('s.class_id', $classId)
            ->where('m.exam_id', $examId)
            ->where('s.active', 1)
            ->whereNotNull('m.score')
            ->select('s.id', 's.reg_no', 's.full_name')
            ->distinct()
            ->orderByRaw("CASE s.gender WHEN 'Male' THEN 0 WHEN 'Female' THEN 1 ELSE 2 END ASC")
            ->orderByRaw('LOWER(s.full_name) ASC')
            ->get();

        return response()->json($rows);
    }
}
