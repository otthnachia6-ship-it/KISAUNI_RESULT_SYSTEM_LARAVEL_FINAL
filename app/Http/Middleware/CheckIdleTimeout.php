<?php

namespace App\Http\Middleware;

use App\Services\SchoolService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckIdleTimeout
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $timeoutMinutes = config('kisauni.idle_timeout_minutes');
            $lastActivity = session('last_activity');
            $now = SchoolService::now()->timestamp;

            if ($lastActivity && ($now - $lastActivity) > ($timeoutMinutes * 60)) {
                $user = Auth::user();
                if ($user) {
                    SchoolService::logAction($user, 'IDLE_LOGOUT', "{$user->username} logged out due to inactivity");
                }
                Auth::logout();
                session()->invalidate();
                session()->regenerateToken();

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['error' => 'Session expired.'], 401);
                }

                return redirect()->route('login')
                    ->with('info', 'Your session expired due to inactivity. Please log in again.');
            }

            session(['last_activity' => $now]);
        }

        return $next($request);
    }
}
