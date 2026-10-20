<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\SchoolService;

class AuditLogController extends Controller
{
    public function auditLogs()
    {
        $rows = AuditLog::orderBy('id', 'desc')->limit(300)->get();
        $logs = SchoolService::humanizeLogs($rows);

        return view('audit_logs', [
            'logs' => $logs,
            'retention_days' => SchoolService::AUDIT_LOG_RETENTION_DAYS,
        ]);
    }
}
