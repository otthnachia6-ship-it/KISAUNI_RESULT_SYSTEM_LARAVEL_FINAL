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
use App\Services\BackupService;
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
        $myClassAnalytics = null;
        $myClassLatestExam = null;
        $classRanking = [];
        $leadingClass = null;
        $myClassRank = null;

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

            $myClassLatestExam = Examination::whereExists(function ($query) use ($user) {
                $query->select(DB::raw(1))
                    ->from('marks')
                    ->whereColumn('marks.exam_id', 'examinations.id')
                    ->where('marks.class_id', $user->class_id)
                    ->whereNotNull('marks.score');
            })->orderBy('id', 'desc')->first();

            if ($myClassLatestExam) {
                $myClassAnalytics = SchoolService::computeClassAnalytics($user->class_id, $myClassLatestExam->id);
                $classRanking = SchoolService::computeClassRanking($myClassLatestExam->id);
                $leadingClass = !empty($classRanking) ? $classRanking[0] : null;
                foreach ($classRanking as $cr) {
                    if ($cr['cls']['id'] == $user->class_id) {
                        $myClassRank = $cr;
                        break;
                    }
                }
            }
        }

        $recentLogs = SchoolService::humanizeLogs(
            AuditLog::orderBy('id', 'desc')->limit(8)->get()
        );

        $offsiteDays = ($user && $user->role === SchoolService::ROLE_HEADMASTER)
            ? BackupService::daysSinceLastOffsiteDownload()
            : null;

        return view('dashboard', [
            'stats' => $stats,
            'my_class' => $myClass,
            'my_class_students' => $myClassStudents,
            'recent_logs' => $recentLogs,
            'returned_results' => $returnedResults,
            'offsite_days' => $offsiteDays,
            'class_breakdown' => $classBreakdown,
            'status_breakdown' => $statusBreakdown,
            'current_academic_year' => $currentAcademicYear,
            'my_class_analytics' => $myClassAnalytics,
            'my_class_latest_exam' => $myClassLatestExam,
            'leading_class' => $leadingClass,
            'my_class_rank' => $myClassRank,
            'class_ranking' => $classRanking,
            'class_ranking_total' => count($classRanking),
            'chart_colors' => [
                '#2563eb', '#16a34a', '#f59e0b', '#dc2626',
                '#6f42c1', '#0891b2', '#db2777', '#64748b',
            ],
        ]);
    }
}
