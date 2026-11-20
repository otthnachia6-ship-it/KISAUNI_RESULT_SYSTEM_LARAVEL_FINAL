<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Developer Control Center pages: only the Developer account (role 'developer').
 * Headmaster, Administrator and Class Teacher get 404 so the area is not advertised.
 */
class EnsureDeveloper
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if (!$user || !$user->isDeveloper()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Not found.'], 404);
            }
            abort(404);
        }

        return $next($request);
    }
}
