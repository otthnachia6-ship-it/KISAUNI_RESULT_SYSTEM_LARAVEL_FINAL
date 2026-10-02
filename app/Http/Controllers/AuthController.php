<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

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

        $username = trim($request->input('username', ''));
        $password = (string) $request->input('password', '');

        $user = User::where('username', $username)->first();

        // 1. Brute-force lockout check
        if ($user && $user->locked_until) {
            $lockedUntil = Carbon::parse($user->locked_until, 'Africa/Dar_es_Salaam');
            $now = SchoolService::now();
            if ($now->lt($lockedUntil)) {
                // absolute:true keeps this positive under Carbon 3's new
                // signed diffIn*() behaviour (see BackupService for details).
                $minutesLeft = max(1, (int) ceil($lockedUntil->diffInSeconds($now, true) / 60));
                return back()->with('danger', "Too many failed attempts. This account is locked - try again in about {$minutesLeft} minute(s).");
            }
            // Lock expired -> reset
            $user->update(['failed_login_attempts' => 0, 'locked_until' => null]);
        }

        // 2. Password verification
        $passwordOk = false;
        $legacyHashUnverifiable = false;
        if ($user) {
            if (Hash::check($password, $user->password) || password_verify($password, $user->password)) {
                $passwordOk = true;
            } elseif (str_starts_with($user->password, 'scrypt:') || str_starts_with($user->password, 'pbkdf2:')) {
                // Legacy Werkzeug (Python/Flask) hash - verified in pure PHP,
                // no external interpreter needed (shared hosting/cPanel safe).
                $legacyResult = SchoolService::verifyLegacyWerkzeugHash($password, $user->password);
                if ($legacyResult === true) {
                    $passwordOk = true;
                    // Upgrade legacy hash to modern bcrypt
                    $user->update(['password' => Hash::make($password)]);
                } elseif ($legacyResult === null) {
                    // Unsupported legacy scheme (e.g. scrypt) - cannot be verified
                    // in pure PHP. Don't count this as a wrong-password attempt;
                    // send them to get their password reset instead.
                    $legacyHashUnverifiable = true;
                }
            }
        }

        if ($legacyHashUnverifiable) {
            return back()->with('danger', 'Your account uses an old password format that needs to be reset. Please see the Headmaster to have your password reset.');
        }

        if (!$user || !$passwordOk) {
            if ($user) {
                $maxAttempts = config('kisauni.max_login_attempts');
                $lockoutMinutes = config('kisauni.login_lockout_minutes');
                $attempts = ($user->failed_login_attempts ?? 0) + 1;

                if ($attempts >= $maxAttempts) {
                    $lockedUntilStr = SchoolService::now()->addMinutes($lockoutMinutes)->format('Y-m-d H:i:s');
                    $user->update([
                        'failed_login_attempts' => $attempts,
                        'locked_until' => $lockedUntilStr,
                    ]);
                    SchoolService::logAction(
                        $user,
                        'LOGIN_LOCKED',
                        "{$user->username} account locked for {$lockoutMinutes} minutes after {$attempts} failed login attempts"
                    );
                    return back()->with('danger', "Too many failed attempts. This account is now locked for {$lockoutMinutes} minutes.");
                } else {
                    $user->update(['failed_login_attempts' => $attempts]);
                    return back()->with('danger', 'Incorrect username or password.');
                }
            }
            return back()->with('danger', 'Incorrect username or password.');
        }

        if (!$user->active) {
            return back()->with('danger', 'This account has been deactivated. Please contact the Headmaster.');
        }

        // Clear failed attempts
        if ($user->failed_login_attempts || $user->locked_until) {
            $user->update(['failed_login_attempts' => 0, 'locked_until' => null]);
        }

        Auth::login($user, false);
        session(['last_activity' => SchoolService::now()->timestamp]);

        SchoolService::logAction($user, 'LOGIN', "{$user->username} logged in");

        if ($user->must_change_password) {
            return redirect()->route('change_password')
                ->with('info', 'Welcome! For your security, please set a new password before continuing.');
        }

        return redirect()->route('dashboard');
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

        return redirect()->route('login')->with('info', 'You have been logged out.');
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
            $user = User::where('username', $username)->first();
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
        if (mb_strlen($new) < 4) {
            return back()->with('danger', 'New password must be at least 4 characters long.');
        }
        if ($new !== $confirm) {
            return back()->with('danger', 'New password and confirmation do not match.');
        }
        if (!$newUsername) {
            return back()->with('danger', 'Username cannot be empty.');
        }

        $duplicate = User::where('username', $newUsername)->where('id', '!=', $user->id)->exists();
        if ($duplicate) {
            return back()->with('danger', 'That username is already taken by another account. Choose a different one.');
        }

        $oldUsername = $user->username;
        $user->update([
            'password' => Hash::make($new),
            'username' => $newUsername,
            'must_change_password' => 0,
        ]);

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
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
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
