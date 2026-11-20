<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\LegacyPasswordService;
use App\Services\SchoolService;
use App\Support\PasswordPolicy;
use App\Support\UploadedImage;
use App\Http\Middleware\EnsureAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function index()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return redirect()->route('login');
    }

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('login');
    }

    public function login(Request $request)
    {
        if ($request->isMethod('GET')) {
            return $this->showLogin();
        }

        return $this->attemptLogin($request, false);
    }

    /**
     * One sign-in procedure for both entry points (same throttling, lockout and audit):
     *  - /login            ($developerPortal = false): school accounts only;
     *  - /developer/login  ($developerPortal = true):  the Developer account only.
     * An account used at the wrong entry point is answered exactly like a wrong
     * password, so neither page reveals that a Developer account exists.
     */
    protected function attemptLogin(Request $request, bool $developerPortal)
    {

        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        // 1. Brute-force protection, BEFORE any password work (so a locked attempt costs nothing).
        //    Counted per exact username + device (IP): a stranger cannot lock a teacher out,
        //    and teachers sharing the school Wi-Fi do not lock each other out.
        //    A larger per-device limit stops guessing across many usernames.
        //    The same message is shown whether or not the username exists.
        [$accountKey, $deviceKey] = $this->loginThrottleKeys($request, $username);
        foreach ([[$deviceKey, $this->maxAttemptsPerDevice()], [$accountKey, $this->maxAttemptsPerAccount()]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return back()->withInput($request->only('username'))->with('danger', $this->lockedMessage(RateLimiter::availableIn($key)));
            }
        }

        // Exact-case lookup (damtu and DAMTU are different accounts).
        $user = User::findByUsername($username);
        if ($user && $user->isDeveloper() !== $developerPortal) {
            $user = null;
        }

        // 2. Password verification
        $passwordOk = false;
        $legacyHashUnverifiable = false;
        $legacyBusy = false;
        $upgradedLegacyHash = false;
        if ($user) {
            if (LegacyPasswordService::isLegacyHash($user->password)) {
                // Old Flask (Werkzeug) hash: verify it, then upgrade to Laravel's own hash.
                // Never Hash::check() a non-bcrypt string, and never log the hash.
                $legacy = LegacyPasswordService::verify($password, $user->password);
                if ($legacy === LegacyPasswordService::OK) {
                    $passwordOk = true;
                    $user->update(['password' => Hash::make($password)]);
                    $upgradedLegacyHash = true;
                } elseif ($legacy === LegacyPasswordService::BUSY) {
                    $legacyBusy = true;
                } elseif ($legacy === LegacyPasswordService::UNVERIFIABLE) {
                    // Cannot be checked on this server (feature off / too heavy / unknown
                    // variant). Not a wrong-password attempt: do not count it.
                    $legacyHashUnverifiable = true;
                }
            } elseif (LegacyPasswordService::isNativeHash($user->password)) {
                $passwordOk = Hash::check($password, $user->password) || password_verify($password, $user->password);
            }
        }

        if ($legacyBusy) {
            return back()->with('danger', 'The server is busy checking other sign-ins. Please wait a few seconds and try again.');
        }

        if ($legacyHashUnverifiable) {
            return back()->with('danger', 'Your account uses an old password format that needs to be reset. Please see the Headmaster to have your password reset.');
        }

        if (!$user || !$passwordOk) {
            $decaySeconds = $this->lockoutMinutes() * 60;
            RateLimiter::hit($deviceKey, $decaySeconds);
            RateLimiter::hit($accountKey, $decaySeconds);

            if ($user) {
                // Kept for the record (shown nowhere as a lock): how many wrong passwords in a row.
                $attempts = ((int) $user->failed_login_attempts) + 1;
                $user->update(['failed_login_attempts' => $attempts]);
            }

            if (RateLimiter::tooManyAttempts($accountKey, $this->maxAttemptsPerAccount())) {
                if ($user) {
                    SchoolService::logAction(
                        $user,
                        'LOGIN_LOCKED',
                        "{$user->username} blocked for {$this->lockoutMinutes()} minutes on device {$request->ip()} after repeated wrong passwords"
                    );
                }
                return back()->withInput($request->only('username'))->with('danger', $this->lockedMessage(RateLimiter::availableIn($accountKey)));
            }

            return back()->withInput($request->only('username'))->with('danger', 'Incorrect username or password.');
        }

        if (!$user->active) {
            return back()->with('danger', 'This account has been deactivated. Please contact the Headmaster.');
        }

        // Correct password: forget earlier failures for this username on this device.
        RateLimiter::clear($accountKey);
        if ($user->failed_login_attempts || $user->locked_until) {
            $user->update(['failed_login_attempts' => 0, 'locked_until' => null]);
        }

        Auth::login($user, false);
        session(['last_activity' => SchoolService::now()->timestamp]);
        EnsureAuthenticated::remember($request, $user);

        if ($upgradedLegacyHash) {
            SchoolService::logAction($user, 'PASSWORD_HASH_UPGRADED', "{$user->username} password migrated from the old system format");
        }
        SchoolService::logAction($user, 'LOGIN', "{$user->username} logged in");

        if ($user->must_change_password) {
            return redirect()->route('change_password')
                ->with('info', 'Welcome! For your security, please set a new password before continuing.');
        }

        return redirect()->route($developerPortal ? 'developer.dashboard' : 'dashboard');
    }

    /** @return array{0:string,1:string} [username+device key, device key] */
    private function loginThrottleKeys(Request $request, string $username): array
    {
        $ip = (string) $request->ip();

        // sha1 of the EXACT username: damtu and DAMTU are counted separately.
        return ['login:account:' . sha1($username) . '|' . $ip, 'login:device:' . $ip];
    }

    private function maxAttemptsPerAccount(): int
    {
        return max(1, (int) config('kisauni.max_login_attempts', 5));
    }

    private function maxAttemptsPerDevice(): int
    {
        return max($this->maxAttemptsPerAccount(), (int) config('kisauni.max_login_attempts_per_ip', 50));
    }

    private function lockoutMinutes(): int
    {
        return max(1, (int) config('kisauni.login_lockout_minutes', 5));
    }

    private function lockedMessage(int $secondsLeft): string
    {
        $minutes = max(1, (int) ceil($secondsLeft / 60));

        return "Too many failed attempts. Please wait about {$minutes} minute(s) and try again.";
    }

    public function logout()
    {
        $user = Auth::user();
        if ($user) {
            SchoolService::logAction($user, 'LOGOUT', "{$user->username} logged out");
        }
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        // The maintenance bypass cookie belongs to the Developer's session: drop it on sign-out.
        return redirect()->route($user && $user->isDeveloper() ? 'developer.login' : 'login')
            ->withoutCookie('laravel_maintenance', config('session.path'), config('session.domain'))
            ->with('info', 'You have been logged out.');
    }

    public function showForgotPassword()
    {
        return view('forgot_password', ['temp_password' => null]);
    }

    public function forgotPassword(Request $request)
    {
        if ($request->isMethod('GET')) {
            return $this->showForgotPassword();
        }

        $username = trim($request->input('username', ''));
        if ($username) {
            $user = User::findByUsername($username);
            if ($user && $user->active) {
                SchoolService::logAction(
                    $user,
                    'PASSWORD_RESET_REQUESTED',
                    "{$user->username} asked for a password reset at the login page"
                );
            }
        }

        return redirect()->route('forgot_password')->with(
            'info',
            'Request received. Please see the Headmaster in person (or call the school office) to have your password reset - for security, it can no longer be reset from this page automatically.'
        );
    }

    public function showChangePassword()
    {
        return view('change_password', ['user' => Auth::user()]);
    }

    public function changePassword(Request $request)
    {
        if ($request->isMethod('GET')) {
            return $this->showChangePassword();
        }

        $user = Auth::user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('confirm_password', '');
        $newUsername = trim($request->input('new_username', ''));

        if (!Hash::check($current, $user->password) && !password_verify($current, $user->password)) {
            return back()->with('danger', 'Your current password is incorrect.');
        }
        if (!$newUsername) {
            return back()->with('danger', 'Username cannot be empty.');
        }
        if (mb_strlen($newUsername) > 191) {
            return back()->with('danger', 'Username is too long.');
        }
        if ($error = PasswordPolicy::error($new, $newUsername)) {
            return back()->with('danger', 'New ' . lcfirst($error));
        }
        if ($new !== $confirm) {
            return back()->with('danger', 'New password and confirmation do not match.');
        }
        if ($new === $current) {
            // Important on first login: the temporary password given by the Headmaster must be replaced.
            return back()->with('danger', 'Your new password must be different from your current password.');
        }

        $duplicate = User::usernameTaken($newUsername, $user->id);
        if ($duplicate) {
            return back()->with('danger', 'That username is already taken by another account. Choose a different one.');
        }

        $oldUsername = $user->username;
        $user->update([
            'password' => Hash::make($new),
            'username' => $newUsername,
            'must_change_password' => 0,
        ]);
        // This session stays signed in; any other open session of this account is signed out.
        $request->session()->regenerate();
        EnsureAuthenticated::remember($request, $user);

        $detail = 'User changed own password';
        if ($newUsername !== $oldUsername) {
            $detail .= " and username ({$oldUsername} -> {$newUsername})";
        }
        SchoolService::logAction($user, 'CHANGE_PASSWORD', $detail);

        return redirect()->route('dashboard')
            ->with('success', 'Your details have been updated successfully. Please remember your new username and password.');
    }

    public function profile(Request $request)
    {
        $user = Auth::user();

        if ($request->isMethod('post')) {
            $action = $request->input('action', 'upload_photo');

            if ($action === 'remove_photo') {
                $oldPhoto = $user->photo_path;
                $user->update(['photo_path' => null]);
                if ($oldPhoto && str_starts_with($oldPhoto, 'uploads/')) {
                    $oldAbs = storage_path('app/uploads/' . substr($oldPhoto, 8));
                    if (File::exists($oldAbs)) {
                        File::delete($oldAbs);
                    }
                }
                SchoolService::logAction($user, 'UPDATE_PROFILE', "{$user->username} removed their profile photo");
                return redirect()->route('profile')->with('info', 'Profile photo removed.');
            }

            if (!$request->hasFile('photo')) {
                return back()->with('danger', 'Please choose an image file first.');
            }

            $file = $request->file('photo');
            $ext = UploadedImage::extension($file); // decided from the real file content, not its name
            if ($ext === null) {
                return back()->with('danger', 'Only PNG or JPG images are allowed for profile photos.');
            }

            if ($file->getSize() > 2 * 1024 * 1024) {
                return back()->with('danger', 'That image is too large - please use a photo under 2MB.');
            }

            $uploadDir = storage_path('app/uploads');
            if (!File::exists($uploadDir)) {
                File::makeDirectory($uploadDir, 0755, true, true);
            }

            $filename = "user_{$user->id}_" . time() . '.' . $ext;
            $file->move($uploadDir, $filename);

            $oldPhoto = $user->photo_path;
            $user->update(['photo_path' => "uploads/{$filename}"]);

            if ($oldPhoto && str_starts_with($oldPhoto, 'uploads/')) {
                $oldAbs = storage_path('app/uploads/' . substr($oldPhoto, 8));
                if (File::exists($oldAbs)) {
                    File::delete($oldAbs);
                }
            }

            SchoolService::logAction($user, 'UPDATE_PROFILE', "{$user->username} updated their profile photo");
            return redirect()->route('profile')->with('success', 'Profile photo updated.');
        }

        $cls = $user->class_id ? SchoolClass::find($user->class_id) : null;
        return view('profile', ['user' => $user, 'cls' => $cls]);
    }
}
