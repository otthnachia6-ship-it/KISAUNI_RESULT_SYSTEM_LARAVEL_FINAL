<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;

class MonitoringController extends Controller
{
    public function show(string $section)
    {
        abort_unless(array_key_exists($section, SystemHealthService::SECTIONS), 404);

        $checks = SystemHealthService::section($section);

        return view('developer.monitoring', [
            'section' => $section,
            'title' => SystemHealthService::SECTIONS[$section],
            'checks' => $checks,
            'overall' => SystemHealthService::overall($checks),
            'log_entries' => $section === 'errors' ? SystemHealthService::recentLogEntries() : [],
            'tables' => $section === 'database' ? SystemHealthService::tableCounts() : [],
        ]);
    }
}
