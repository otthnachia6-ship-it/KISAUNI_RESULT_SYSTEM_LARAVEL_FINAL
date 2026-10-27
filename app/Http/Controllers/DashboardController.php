<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function dashboard()
    {
        $currentAcademicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);

        $stats = [
            'students' => Student::where('active', 1)->count(),
            'teachers' => User::where('role', SchoolService::ROLE_CLASS_TEACHER)->where('active', 1)->count(),
            'classes' => SchoolClass::count(),
            'subjects' => Subject::count(),
            'exams' => Examination::where('academic_year', $currentAcademicYear)->count(),
            'pending' => DB::table('exam_class_status as ecs')
                ->join('examinations as e', 'e.id', '=', 'ecs.exam_id')
                ->whereIn('ecs.status', ['submitted', 'under_review'])
                ->where('e.academic_year', $currentAcademicYear)
                ->count(),
            'approved' => DB::table('exam_class_status as ecs')
                ->join('examinations as e', 'e.id', '=', 'ecs.exam_id')
                ->where('ecs.status', 'approved')
                ->where('e.academic_year', $currentAcademicYear)
                ->count(),
        ];

        $classBreakdown = DB::table('classes as c')
            ->leftJoin('students as s', function ($join) {
                $join->on('s.class_id', '=', 'c.id')->where('s.active', '=', 1);
            })
            ->select('c.name', DB::raw('COUNT(s.id) as c'))
            ->groupBy('c.id', 'c.name', 'c.sort_order')
            ->orderBy('c.sort_order')
            ->get();

        $statusRows = DB::table('exam_class_status as ecs')
            ->join('examinations as e', 'e.id', '=', 'ecs.exam_id')
            ->where('e.academic_year', $currentAcademicYear)
            ->select('ecs.status', DB::raw('COUNT(*) as c'))
            ->groupBy('ecs.status')
            ->get();

        $statusBreakdown = ['draft' => 0, 'submitted' => 0, 'under_review' => 0, 'approved' => 0, 'returned' => 0];
        foreach ($statusRows as $row) {
            $statusBreakdown[$row->status] = $row->c;
        }

        $user = Auth::user();
        $myClass = null;
        $myClassStudents = 0;
        $returnedResults = [];
        $myClassLatestExam = null;
        $myClassPendingRelease = null;

        if ($user && $user->role === SchoolService::ROLE_CLASS_TEACHER && $user->class_id) {
            $myClass = SchoolClass::find($user->class_id);
            $myClassStudents = Student::where('class_id', $user->class_id)->where('active', 1)->count();

            $returnedResults = DB::table('exam_class_status as ecs')
                ->join('examinations as e', 'ecs.exam_id', '=', 'e.id')
                ->where('ecs.class_id', $user->class_id)
                ->where('ecs.status', 'returned')
                ->select('ecs.*', 'e.exam_type', 'e.academic_year', 'e.id as exam_id_val')
                ->orderBy('e.id', 'desc')
                ->get();

            // Only examinations whose results are released (every class Approved) are shown to a class teacher.
            $examsWithMarks = Examination::whereExists(function ($query) use ($user) {
                $query->select(DB::raw(1))
                    ->from('marks')
                    ->whereColumn('marks.exam_id', 'examinations.id')
                    ->where('marks.class_id', $user->class_id)
                    ->whereNotNull('marks.score');
            })->orderBy('id', 'desc')->get();

            $myClassLatestExam = null;
            foreach ($examsWithMarks as $candidate) {
                $release = SchoolService::examReleaseStatus($candidate->id);
                if ($release['released']) {
                    $myClassLatestExam = $candidate;
                    break;
                }
                if (!$myClassPendingRelease) {
                    $myClassPendingRelease = ['exam' => $candidate] + $release;
                }
            }
            if ($myClassLatestExam) {
                $myClassPendingRelease = null;
            }
        }

        // Routine sign-ins and sign-outs are left out of this short list (the Audit Log page still shows everything).
        $recentLogs = SchoolService::humanizeLogs(
            AuditLog::whereNotIn('action', ['LOGIN', 'LOGOUT', 'IDLE_LOGOUT', 'PASSWORD_HASH_UPGRADED'])
                ->orderBy('id', 'desc')->limit(8)->get()
        );

        // Headmaster only: the classes whose results are waiting for review, oldest first.
        $pendingReviews = collect();
        if ($user && $user->role === SchoolService::ROLE_HEADMASTER) {
            $pendingReviews = DB::table('exam_class_status as ecs')
                ->join('examinations as e', 'ecs.exam_id', '=', 'e.id')
                ->join('classes as c', 'ecs.class_id', '=', 'c.id')
                ->whereIn('ecs.status', ['submitted', 'under_review'])
                ->where('e.academic_year', $currentAcademicYear)
                ->select('ecs.exam_id', 'ecs.status', 'ecs.submitted_at', 'e.exam_type', 'e.academic_year', 'c.name as class_name', 'c.id as class_id_val')
                ->orderBy('ecs.submitted_at')
                ->orderBy('c.sort_order')
                ->limit(5)
                ->get();
        }


        return view('dashboard', [
            'stats' => $stats,
            'my_class' => $myClass,
            'my_class_students' => $myClassStudents,
            'recent_logs' => $recentLogs,
            'pending_reviews' => $pendingReviews,
            'returned_results' => $returnedResults,
            'class_breakdown' => $classBreakdown,
            'status_breakdown' => $statusBreakdown,
            'current_academic_year' => $currentAcademicYear,
            'my_class_latest_exam' => $myClassLatestExam,
            'my_class_pending_release' => $myClassPendingRelease,
            'chart_colors' => [
                '#2563eb', '#16a34a', '#f59e0b', '#dc2626',
                '#6f42c1', '#0891b2', '#db2777', '#64748b',
            ],
        ]);
    }
}
