<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureHeadmaster
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'headmaster') {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'Forbidden.'], 403);
            }
            abort(403, 'Forbidden. Headmaster access required.');
        }

        return $next($request);
    }
}
