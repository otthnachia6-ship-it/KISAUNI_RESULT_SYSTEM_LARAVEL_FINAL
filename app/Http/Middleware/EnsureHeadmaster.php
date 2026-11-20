<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Headmaster-level pages. The Headmaster/Administrator (role 'headmaster') and the
 * Developer (role 'developer', approved to hold Headmaster capabilities) pass.
 * Class Teachers are refused.
 */
class EnsureHeadmaster
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if (!$user || !$user->hasHeadmasterAccess()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'Forbidden.'], 403);
            }
            abort(403, 'Forbidden. Headmaster access required.');
        }

        return $next($request);
    }
}
