<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Services\SchoolService;
use App\Services\SystemHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cache management, system diagnostics and maintenance mode.
 * Cache: only "clear" operations, which rebuild themselves and never touch school data.
 * Maintenance mode: school users see the existing maintenance page; the Developer keeps
 * working through Laravel's signed bypass cookie, and /developer/* stays reachable
 * (see PreventRequestsDuringMaintenance::$except) so the Developer can never be locked out.
 */
class MaintenanceController extends Controller
{
    /** action => [artisan command, label, explanation] */
    public const CACHE_ACTIONS = [
        'views' => ['view:clear', 'Compiled page templates', 'Safe at any time. Pages are recompiled on the next visit.'],
        'routes' => ['route:clear', 'Route cache', 'Safe. If the server uses route:cache, run it again after deployment for best speed.'],
        'config' => ['config:clear', 'Configuration cache', 'Safe. If the server uses config:cache, run it again after deployment for best speed.'],
        'application' => ['cache:clear', 'Application data cache', 'Also resets login-lockout counters and the once-a-day audit cleanup marker. School data is NOT affected.'],
    ];

    public function cache()
    {
        return view('developer.maintenance.cache', [
            'actions' => self::CACHE_ACTIONS,
            'state' => [
                'Configuration cache' => app()->configurationIsCached() ? 'Cached' : 'Not cached',
                'Route cache' => app()->routesAreCached() ? 'Cached' : 'Not cached',
                'Compiled views' => count(glob(storage_path('framework/views/*.php')) ?: []) . ' files',
                'Application cache driver' => (string) config('cache.default'),
            ],
        ]);
    }

    public function clearCache(Request $request)
    {
        $action = (string) $request->input('action');
        if (!isset(self::CACHE_ACTIONS[$action])) {
            return redirect()->route('developer.maintenance.cache')->with('danger', 'Unknown cache action.');
        }
        [$command, $label] = self::CACHE_ACTIONS[$action];

        try {
            $code = Artisan::call($command);
        } catch (Throwable $e) {
            $code = 1;
        }

        // Logged after the command, so the entry is kept even when the application cache was cleared.
        SchoolService::logAction($request->user(), 'CLEAR_CACHE', "{$label} ({$command}) " . ($code === 0 ? 'cleared' : 'FAILED'));

        return redirect()->route('developer.maintenance.cache')->with(
            $code === 0 ? 'success' : 'danger',
            $code === 0 ? "{$label} cleared." : "{$label} could not be cleared. See Error Monitoring."
        );
    }

    /** page key => [view rendered for school users, label] */
    public const MAINTENANCE_PAGES = [
        'updating' => ['maintenance.updating', 'Scheduled maintenance - system update (Matengenezo)'],
        'suspended' => ['maintenance.suspended', 'Service suspended - hosting & domain renewal pending (Malipo ya hosting/domain)'],
    ];

    public function mode()
    {
        $active = app()->isDownForMaintenance();
        $data = [];
        if ($active) {
            try {
                $data = app()->maintenanceMode()->data();
            } catch (Throwable) {
                $data = [];
            }
        }

        return view('developer.maintenance.mode', [
            'active' => $active,
            'pages' => self::MAINTENANCE_PAGES,
            'has_bypass' => $active && !empty($data['secret']) && request()->cookie('laravel_maintenance')
                && MaintenanceModeBypassCookie::isValid((string) request()->cookie('laravel_maintenance'), (string) $data['secret']),
        ]);
    }

    public function enable(Request $request)
    {
        $page = (string) $request->input('page', 'updating');
        if (!isset(self::MAINTENANCE_PAGES[$page])) {
            return redirect()->route('developer.maintenance.mode')->with('danger', 'Choose which page school users should see.');
        }
        if (app()->isDownForMaintenance()) {
            return redirect()->route('developer.maintenance.mode')->with('info', 'Maintenance mode is already ON.');
        }

        $secret = Str::random(48);
        try {
            $code = Artisan::call('down', ['--render' => self::MAINTENANCE_PAGES[$page][0], '--secret' => $secret, '--retry' => 60]);
        } catch (Throwable) {
            $code = 1;
        }
        if ($code !== 0 || !app()->isDownForMaintenance()) {
            SchoolService::logAction($request->user(), 'MAINTENANCE_ON_FAILED', 'Could not switch maintenance mode on');

            return redirect()->route('developer.maintenance.mode')->with('danger', 'Maintenance mode could not be switched on. See Error Monitoring.');
        }

        SchoolService::logAction($request->user(), 'MAINTENANCE_ON', 'Maintenance mode ON (page: ' . self::MAINTENANCE_PAGES[$page][1] . ')');

        // This browser keeps full access to the whole system (signed cookie, 12 hours).
        return redirect()->route('developer.maintenance.mode')
            ->withCookie(MaintenanceModeBypassCookie::create($secret))
            ->with('warning', 'Maintenance mode is ON. School users now see the maintenance page. You still have full access in this browser.');
    }

    public function disable(Request $request)
    {
        if (!app()->isDownForMaintenance()) {
            return redirect()->route('developer.maintenance.mode')->with('info', 'Maintenance mode is already OFF.');
        }
        try {
            $code = Artisan::call('up');
        } catch (Throwable) {
            $code = 1;
        }
        $ok = $code === 0 && !app()->isDownForMaintenance();
        SchoolService::logAction($request->user(), $ok ? 'MAINTENANCE_OFF' : 'MAINTENANCE_OFF_FAILED', $ok ? 'Maintenance mode OFF - system open to everyone' : 'Could not switch maintenance mode off');

        return redirect()->route('developer.maintenance.mode')->with(
            $ok ? 'success' : 'danger',
            $ok ? 'Maintenance mode is OFF. The system is open to everyone again.' : 'Maintenance mode could not be switched off. Run php artisan up on the server.'
        );
    }

    /** Give THIS browser access to the whole system again (e.g. after signing in from another browser). */
    public function bypass(Request $request)
    {
        if (!app()->isDownForMaintenance()) {
            return redirect()->route('developer.maintenance.mode');
        }
        try {
            $secret = (string) (app()->maintenanceMode()->data()['secret'] ?? '');
        } catch (Throwable) {
            $secret = '';
        }
        if ($secret === '') {
            return redirect()->route('developer.maintenance.mode')->with('danger', 'This maintenance mode was started without a secret (e.g. php artisan down on the server). Switch it off here or with php artisan up.');
        }

        return redirect()->route('developer.maintenance.mode')
            ->withCookie(MaintenanceModeBypassCookie::create($secret))
            ->with('success', 'This browser has full access again during maintenance.');
    }

    public function diagnostics()
    {
        $sections = [];
        foreach (SystemHealthService::SECTIONS as $key => $label) {
            $checks = SystemHealthService::section($key);
            $sections[$key] = [
                'label' => $label,
                'overall' => SystemHealthService::overall($checks),
                'problems' => array_values(array_filter($checks, fn ($c) => in_array($c['status'], [SystemHealthService::CRITICAL, SystemHealthService::WARNING], true))),
                'total' => count($checks),
            ];
        }

        $extra = [
            ['.env file present', is_file(base_path('.env')) ? 'Yes' : 'No (values come from the server environment)',
                is_file(base_path('.env')) || getenv('APP_KEY') ? SystemHealthService::HEALTHY : SystemHealthService::WARNING],
            ['Registered web routes', (string) count(Route::getRoutes()), SystemHealthService::HEALTHY],
            ['Audit log retention', SchoolService::auditLogRetentionDays() . ' days', SchoolService::auditLogRetentionDays() === 5 ? SystemHealthService::HEALTHY : SystemHealthService::WARNING],
            ['Public storage of backups', 'None (backups are outside the public folder)', SystemHealthService::HEALTHY],
        ];

        return view('developer.maintenance.diagnostics', [
            'sections' => $sections,
            'extra' => $extra,
            'ran_at' => SchoolService::nowStr(),
        ]);
    }
}
