<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\SchoolService;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\DB;

class DeveloperDashboardController extends Controller
{
    public function dashboard()
    {
        $academicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);

        $health = [];
        foreach (SystemHealthService::SECTIONS as $key => $label) {
            $health[$key] = ['label' => $label, 'status' => SystemHealthService::overall(SystemHealthService::section($key))];
        }

        return view('developer.dashboard', [
            'health' => $health,
            'backup' => SystemHealthService::backupHealth(),
            'stats' => [
                'students' => Student::where('active', 1)->count(),
                'classes' => SchoolClass::count(),
                'school_accounts' => User::where('role', '!=', SchoolService::ROLE_DEVELOPER)->count(),
                'pending' => DB::table('exam_class_status as ecs')
                    ->join('examinations as e', 'ecs.exam_id', '=', 'e.id')
                    ->whereIn('ecs.status', ['submitted', 'under_review'])
                    ->where('e.academic_year', $academicYear)->count(),
            ],
            'recent' => SchoolService::humanizeLogs(AuditLog::orderBy('id', 'desc')->limit(8)->get()),
            'academic_year' => $academicYear,
        ]);
    }

    public function systemInfo()
    {
        $roles = User::select('role', DB::raw('count(*) as c'))->groupBy('role')->pluck('c', 'role');

        $info = [
            'Application' => [
                'System' => 'Kisauni Result System',
                'Version' => (string) config('app.version', '-'),
                'Environment' => (string) config('app.env'),
                'Debug mode' => config('app.debug') ? 'ON' : 'OFF',
                'Application URL' => (string) config('app.url'),
                'Time zone (school clock)' => 'Africa/Dar_es_Salaam - ' . SchoolService::nowStr(),
            ],
            'School' => [
                'School name' => (string) Setting::get('school_name', SchoolService::DEFAULT_SCHOOL_NAME),
                'Academic year' => (string) Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR),
                'Headmaster / Administrator accounts' => (int) ($roles[SchoolService::ROLE_HEADMASTER] ?? 0),
                'Class Teacher accounts' => (int) ($roles[SchoolService::ROLE_CLASS_TEACHER] ?? 0),
                'Developer accounts' => (int) ($roles[SchoolService::ROLE_DEVELOPER] ?? 0),
            ],
            'Platform' => [
                'PHP' => PHP_VERSION,
                'Laravel' => app()->version(),
                'Database driver' => DB::connection()->getDriverName(),
                'Cache driver' => (string) config('cache.default'),
                'Session driver' => (string) config('session.driver'),
            ],
            'Policies' => [
                'Audit log retention' => SchoolService::auditLogRetentionDays() . ' days',
                'Idle sign-out' => (int) config('kisauni.idle_timeout_minutes') . ' minutes',
                'Wrong passwords before lockout' => (int) config('kisauni.max_login_attempts') . ' (lockout ' . (int) config('kisauni.login_lockout_minutes') . ' min)',
                'Minimum password length' => (int) config('kisauni.min_password_length'),
                'Backup retention' => (int) config('kisauni.backup.retention_days') . ' days (always keep newest ' . (int) config('kisauni.backup.min_keep') . ')',
            ],
        ];

        try {
            $info['Platform']['Backup folder'] = 'Private, outside public web folder (' . (DatabaseBackupService::directory() ? 'available' : '-') . ')';
        } catch (\Throwable) {
            $info['Platform']['Backup folder'] = 'Not available - set BACKUP_PATH';
        }

        return view('developer.system_info', ['info' => $info]);
    }
}
