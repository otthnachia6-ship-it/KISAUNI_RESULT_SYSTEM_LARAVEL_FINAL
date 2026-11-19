<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Only signed-in, still-active accounts get through.
 *
 * Checked on EVERY request (not only at login):
 *  - a deactivated account is signed out at once, even with a session that is already open;
 *  - when the password was changed somewhere else (e.g. reset by the Headmaster), older
 *    sessions are signed out, so a reset really locks the previous holder out.
 */
class EnsureAuthenticated
{
    public const PASSWORD_STAMP = 'password_stamp';

    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user) {
            return $this->deny($request, null);
        }

        if (!$user->active) {
            return $this->signOut($request, 'This account has been deactivated. Please contact the Headmaster.');
        }

        $session = $request->session();
        $saved = $session->get(self::PASSWORD_STAMP);
        if (!is_array($saved) || (int) ($saved['user'] ?? 0) !== (int) $user->id) {
            // Session opened before this check existed (or by another sign-in path): start tracking now.
            self::remember($request, $user);
        } elseif (!hash_equals((string) ($saved['stamp'] ?? ''), $user->passwordStamp())) {
            return $this->signOut($request, 'Your password was changed. Please log in again with the new password.');
        }

        return $next($request);
    }

    /** Records the current password fingerprint for this session (call after login or own password change). */
    public static function remember(Request $request, User $user): void
    {
        $request->session()->put(self::PASSWORD_STAMP, ['user' => (int) $user->id, 'stamp' => $user->passwordStamp()]);
    }

    private function signOut(Request $request, string $message)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->deny($request, $message);
    }

    private function deny(Request $request, ?string $message)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['error' => $message ?? 'Unauthenticated.'], 401);
        }

        $redirect = redirect()->route('login');

        return $message ? $redirect->with('danger', $message) : $redirect;
    }
}
