<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\SchoolService;
use Illuminate\Support\Facades\Auth;

class AuditLogController extends Controller
{
    public function auditLogs()
    {
        // The Developer sees the complete log; Headmaster / Administrator do not see
        // the Developer's own actions (the Developer has their own full view).
        $query = AuditLog::orderBy('id', 'desc');
        if (!Auth::user()?->isDeveloper()) {
            $query->schoolVisible();
        }
        $rows = $query->limit(300)->get();
        $logs = SchoolService::humanizeLogs($rows);

        return view('audit_logs', [
            'logs' => $logs,
            'retention_days' => SchoolService::auditLogRetentionDays(),
        ]);
    }
}
