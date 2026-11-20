<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\AuthController;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Independent sign-in for the Developer account at /developer/login.
 * Reuses AuthController::attemptLogin() so throttling, lockout, legacy-hash handling,
 * password-stamp sessions and audit logging are exactly the same as for school accounts.
 * School accounts cannot sign in here, and the Developer cannot sign in at /login.
 */
class DeveloperAuthController extends AuthController
{
    public function login(Request $request)
    {
        if ($request->isMethod('GET')) {
            $user = Auth::user();
            if ($user) {
                return redirect()->route($user->isDeveloper() ? 'developer.dashboard' : 'dashboard');
            }

            return view('developer.login');
        }

        $response = $this->attemptLogin($request, true);

        // Signed in during maintenance: give this browser the bypass cookie straight away,
        // so the Developer can use every page without an extra step.
        $user = Auth::user();
        if ($user && $user->isDeveloper() && app()->isDownForMaintenance() && method_exists($response, 'withCookie')) {
            try {
                $secret = (string) (app()->maintenanceMode()->data()['secret'] ?? '');
            } catch (\Throwable) {
                $secret = '';
            }
            if ($secret !== '') {
                $response->withCookie(MaintenanceModeBypassCookie::create($secret));
            }
        }

        return $response;
    }
}
