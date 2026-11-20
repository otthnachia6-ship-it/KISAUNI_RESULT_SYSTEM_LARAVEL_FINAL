<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Second maintenance check, after the session has started (web group).
 *
 * While maintenance mode is ON, only a signed-in, active Developer account may use the
 * school pages. Everyone else - including a Headmaster who signs in on the Developer's
 * browser (which still holds the bypass cookie) - gets the same maintenance page that
 * PreventRequestsDuringMaintenance shows. /developer/* and /logout stay open.
 */
class EnforceMaintenanceForSchoolUsers
{
    public function handle(Request $request, Closure $next)
    {
        if (!app()->isDownForMaintenance() || PreventRequestsDuringMaintenance::isAlwaysOpen($request)) {
            return $next($request);
        }

        $user = Auth::user();
        if ($user && $user->isDeveloper() && $user->active) {
            return $next($request);
        }

        try {
            $data = app()->maintenanceMode()->data();
        } catch (Throwable) {
            $data = [];
        }

        $headers = [];
        if (isset($data['retry'])) {
            $headers['Retry-After'] = $data['retry'];
        }
        if (isset($data['refresh'])) {
            $headers['Refresh'] = $data['refresh'];
        }

        if (isset($data['template'])) {
            return response($data['template'], $data['status'] ?? 503, $headers);
        }

        throw new HttpException($data['status'] ?? 503, 'Service Unavailable', null, $headers);
    }
}
