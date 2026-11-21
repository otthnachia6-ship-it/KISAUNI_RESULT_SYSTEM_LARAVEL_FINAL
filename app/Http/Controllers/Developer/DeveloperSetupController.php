<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SchoolService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One-time creation of the FIRST Developer account from the web, for servers where
 * `php artisan developer:create` cannot be run (no terminal access).
 *
 * Rules:
 *  - Only a signed-in Headmaster/Administrator account (role 'headmaster') may use it,
 *    and must re-enter their own password.
 *  - It exists only while NO Developer account exists. As soon as one exists the page
 *    returns 404 for everyone, for good (further changes: `developer:create --reset`).
 *  - The new account is a normal Developer account: signs in only at /developer/login
 *    and is afterwards hidden from the school Users pages.
 *  - Recorded in the audit log.
 */
class DeveloperSetupController extends Controller
{
    private function developerExists(): bool
    {
        return User::where('role', SchoolService::ROLE_DEVELOPER)->exists();
    }

    public function setup(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->isHeadmaster() || $this->developerExists()) {
            abort(404);
        }

        if ($request->isMethod('GET')) {
            return view('developer.setup');
        }

        $username = trim((string) $request->input('username', ''));
        $fullName = trim((string) $request->input('full_name', '')) ?: 'System Developer';
        $password = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirmation', '');
        $headmasterPassword = (string) $request->input('headmaster_password', '');
        $back = fn (string $msg) => back()->withInput($request->only('username', 'full_name'))->with('danger', $msg);

        if (!Hash::check($headmasterPassword, $user->password) && !password_verify($headmasterPassword, $user->password)) {
            return $back('Your own (Headmaster) password is incorrect.');
        }
        if ($username === '' || mb_strlen($username) > 191) {
            return $back('Please choose a username for the Developer account (at most 191 characters).');
        }
        if (mb_strlen($fullName) > 191) {
            return $back('Full name is too long.');
        }
        if (User::usernameTaken($username)) {
            return $back('That username is already used by another account. Choose a different one.');
        }
        if ($error = PasswordPolicy::error($password, $username)) {
            return $back($error);
        }
        if ($password !== $confirm) {
            return $back('The two Developer passwords do not match.');
        }

        $created = DB::transaction(function () use ($username, $fullName, $password) {
            // Checked again inside the transaction: only ONE first Developer can ever be made here.
            if (User::where('role', SchoolService::ROLE_DEVELOPER)->lockForUpdate()->exists()) {
                return null;
            }

            return User::create([
                'username' => $username,
                'password' => Hash::make($password),
                'full_name' => $fullName,
                'role' => SchoolService::ROLE_DEVELOPER,
                'title' => 'Developer',
                'class_id' => null,
                'active' => 1,
                'must_change_password' => 0,
            ]);
        });

        if (!$created) {
            abort(404);
        }

        SchoolService::logAction($user, 'CREATE_DEVELOPER', "{$username} (one-time web setup by {$user->username})");

        // Sign the Headmaster out so the Developer sign-in page opens directly.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('developer.login')->with(
            'success',
            "Developer account '{$username}' created. Sign in here with it. The setup page is now closed."
        );
    }
}
