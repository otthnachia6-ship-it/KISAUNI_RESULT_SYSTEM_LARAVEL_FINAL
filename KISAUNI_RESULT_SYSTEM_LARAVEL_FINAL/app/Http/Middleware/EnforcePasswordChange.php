<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnforcePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if ($user && $user->must_change_password) {
            $exemptRoutes = ['change_password', 'logout', 'api_keep_alive'];
            $currentRoute = $request->route() ? $request->route()->getName() : null;

            if (!in_array($currentRoute, $exemptRoutes, true)) {
                return redirect()->route('change_password')
                    ->with('info', 'Welcome! For your security, please set a new password before continuing.');
            }
        }

        return $next($request);
    }
}
