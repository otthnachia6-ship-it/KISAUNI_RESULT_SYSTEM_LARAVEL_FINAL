<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SchoolService;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only security views built from data the system really keeps:
 * the audit log (kept for the configured retention, 5 days), the users table
 * counters, and the session/cookie configuration. No session ids or secrets are shown.
 */
class SecurityController extends Controller
{
    public const LOGIN_ACTIONS = ['LOGIN', 'LOGOUT', 'IDLE_LOGOUT', 'LOGIN_LOCKED', 'PASSWORD_RESET_REQUESTED', 'CHANGE_PASSWORD', 'PASSWORD_HASH_UPGRADED'];

    private function since(): string
    {
        return SchoolService::now()->subDays(SchoolService::auditLogRetentionDays())->format('Y-m-d H:i:s');
    }

    public function overview()
    {
        $since = $this->since();
        $count = fn (string $action) => DB::table('audit_log')->where('action', $action)->where('created_at', '>=', $since)->count();
        $session = config('session');
        $https = request()->isSecure();
        $prod = config('app.env') === 'production';

        $checks = [
            ['HTTPS for this page', $https ? 'Yes' : 'No', $https ? SystemHealthService::HEALTHY : ($prod ? SystemHealthService::CRITICAL : SystemHealthService::WARNING)],
            ['Debug mode', config('app.debug') ? 'ON' : 'OFF', config('app.debug') ? ($prod ? SystemHealthService::CRITICAL : SystemHealthService::WARNING) : SystemHealthService::HEALTHY],
            ['Session cookie HTTP-only', !empty($session['http_only']) ? 'Yes' : 'No', !empty($session['http_only']) ? SystemHealthService::HEALTHY : SystemHealthService::WARNING],
            ['Session cookie secure (HTTPS only)', $session['secure'] === null ? 'Not set' : ($session['secure'] ? 'Yes' : 'No'),
                $session['secure'] ? SystemHealthService::HEALTHY : ($prod ? SystemHealthService::WARNING : SystemHealthService::NOT_CONFIGURED)],
            ['Session cookie SameSite', (string) ($session['same_site'] ?? 'none'), ($session['same_site'] ?? null) ? SystemHealthService::HEALTHY : SystemHealthService::WARNING],
            ['Session ends when browser closes', !empty($session['expire_on_close']) ? 'Yes' : 'No', SystemHealthService::HEALTHY],
            ['Idle sign-out', (int) config('kisauni.idle_timeout_minutes') . ' minutes', SystemHealthService::HEALTHY],
            ['Login lockout', (int) config('kisauni.max_login_attempts') . ' wrong passwords -> ' . (int) config('kisauni.login_lockout_minutes') . ' min', SystemHealthService::HEALTHY],
            ['CSRF protection on forms', 'Enabled (VerifyCsrfToken)', SystemHealthService::HEALTHY],
        ];

        return view('developer.security.overview', [
            'checks' => $checks,
            'retention_days' => SchoolService::auditLogRetentionDays(),
            'counts' => [
                'Successful sign-ins' => $count('LOGIN'),
                'Lockouts (too many wrong passwords)' => $count('LOGIN_LOCKED'),
                'Password reset requests' => $count('PASSWORD_RESET_REQUESTED'),
                'Idle sign-outs' => $count('IDLE_LOGOUT'),
            ],
            'accounts' => [
                'Active accounts' => User::where('active', 1)->count(),
                'Deactivated accounts' => User::where('active', 0)->count(),
                'Must change password' => User::where('must_change_password', 1)->count(),
                'Headmaster-level accounts' => User::where('role', SchoolService::ROLE_HEADMASTER)->count(),
                'Accounts with recent wrong passwords' => User::where('failed_login_attempts', '>', 0)->count(),
            ],
        ]);
    }

    public function attempts()
    {
        return view('developer.security.attempts', [
            'users' => User::where('failed_login_attempts', '>', 0)->orderByDesc('failed_login_attempts')->get(['id', 'username', 'full_name', 'role', 'title', 'active', 'failed_login_attempts']),
            'lockouts' => DB::table('audit_log')->whereIn('action', ['LOGIN_LOCKED', 'PASSWORD_RESET_REQUESTED'])->orderBy('id', 'desc')->limit(200)->get(),
            'retention_days' => SchoolService::auditLogRetentionDays(),
        ]);
    }

    public function sessions()
    {
        $driver = (string) config('session.driver');
        $lifetime = (int) config('session.lifetime');
        $active = null;
        $note = null;

        try {
            if ($driver === 'database' && Schema::hasTable((string) config('session.table', 'sessions'))) {
                $active = DB::table((string) config('session.table', 'sessions'))
                    ->where('last_activity', '>=', time() - $lifetime * 60)->count();
                $note = 'Browser sessions active within the session lifetime (includes signed-out visitors of the login page).';
            } elseif ($driver === 'file') {
                $dir = (string) config('session.files');
                $active = 0;
                foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                    if (is_file($f) && basename($f) !== '.gitignore' && filemtime($f) >= time() - $lifetime * 60) {
                        $active++;
                    }
                }
                $note = 'Session files changed within the session lifetime. This counts browsers, including visitors who only opened the login page; it is not an exact count of signed-in staff.';
            } else {
                $note = "The '{$driver}' session driver does not let the application count sessions.";
            }
        } catch (Throwable) {
            $active = null;
            $note = 'Sessions could not be counted on this host.';
        }

        $recentLogins = DB::table('audit_log')->whereIn('action', ['LOGIN', 'LOGOUT', 'IDLE_LOGOUT'])
            ->orderBy('id', 'desc')->limit(30)->get();

        return view('developer.security.sessions', [
            'driver' => $driver,
            'lifetime' => $lifetime,
            'idle' => (int) config('kisauni.idle_timeout_minutes'),
            'active' => $active,
            'note' => $note,
            'recent' => $recentLogins,
        ]);
    }

    public function loginActivity()
    {
        return view('developer.security.login_activity', [
            'logs' => DB::table('audit_log')->whereIn('action', self::LOGIN_ACTIONS)->orderBy('id', 'desc')->paginate(50),
            'retention_days' => SchoolService::auditLogRetentionDays(),
        ]);
    }
}
