<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = DB::table('users as u')
            ->leftJoin('classes as c', 'u.class_id', '=', 'c.id')
            ->select('u.*', 'c.name as class_name')
            ->orderBy('u.role', 'desc')
            ->orderBy('u.full_name')
            ->get();

        return view('users', ['users' => $users]);
    }

    public function showAdd()
    {
        $classes = SchoolClass::orderBy('sort_order')->get();
        return view('user_form', [
            'classes' => $classes,
            'user' => null,
            'mode' => 'add',
            'role_choice' => null,
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('GET')) {
            return $this->showAdd();
        }

        $user = Auth::user();
        $username = trim($request->input('username', ''));
        $fullName = trim($request->input('full_name', ''));
        $password = (string) $request->input('password', '');
        $roleChoice = $request->input('role');

        $role = SchoolService::ROLE_CLASS_TEACHER;
        $title = null;
        if ($roleChoice === 'administrator') {
            $role = SchoolService::ROLE_HEADMASTER;
            $title = 'Administrator';
        } elseif ($roleChoice === 'headmaster') {
            $role = SchoolService::ROLE_HEADMASTER;
            $title = 'Headmaster';
        }

        $classId = ($role === SchoolService::ROLE_CLASS_TEACHER) ? ($request->input('class_id') ?: null) : null;

        if ($error = $this->accountError($username, $fullName, $password, true, $classId)) {
            return back()->withInput($request->except('password'))->with('danger', $error);
        }

        if (User::usernameTaken($username)) {
            return back()->withInput()->with('danger', 'This username is already taken.');
        }

        $newUser = User::create([
            'username' => $username,
            'password' => Hash::make($password),
            'full_name' => $fullName,
            'role' => $role,
            'title' => $title,
            'class_id' => $classId,
            'active' => 1,
            'must_change_password' => 1,
            'created_at' => SchoolService::nowStr(),
        ]);

        if ($role === SchoolService::ROLE_CLASS_TEACHER && $classId) {
            // Also takes the class away from its previous teacher, so only one teacher has access.
            SchoolService::assignClassTeacher((int) $classId, (int) $newUser->id);
        }

        SchoolService::logAction($user, 'ADD_USER', "{$username} (" . ($title ?: $role) . ')');

        return redirect()->route('users')->with(
            'success',
            "Account for {$fullName} created successfully. Share the username and temporary password with them — they will be asked to set a new password on first login."
        );
    }

    /**
     * Checks the account fields. Returns a message when something cannot be used, or null when all is fine.
     * The password rule is the same one used on the Change Password page (PasswordPolicy).
     */
    private function accountError(string $username, string $fullName, string $password, bool $passwordRequired, $classId): ?string
    {
        if ($username === '') {
            return 'Username cannot be empty.';
        }
        if (mb_strlen($username) > 191) {
            return 'Username is too long.';
        }
        if ($fullName === '') {
            return 'Full Name cannot be empty.';
        }
        if (mb_strlen($fullName) > 191) {
            return 'Full Name is too long.';
        }
        if ($passwordRequired && $password === '') {
            return 'Please give a temporary password for the new account.';
        }
        if ($password !== '' && ($passwordError = PasswordPolicy::error($password, $username))) {
            return $passwordError;
        }
        if ($classId && !SchoolClass::whereKey($classId)->exists()) {
            return 'Please choose a valid Class.';
        }

        return null;
    }

    public function showEdit($id)
    {
        $targetUser = User::find($id);
        if (!$targetUser) {
            abort(404);
        }

        $classes = SchoolClass::orderBy('sort_order')->get();

        $roleChoice = 'class_teacher';
        if ($targetUser->role === SchoolService::ROLE_HEADMASTER) {
            $roleChoice = ($targetUser->title === 'Administrator') ? 'administrator' : 'headmaster';
        }

        return view('user_form', [
            'classes' => $classes,
            'user' => $targetUser,
            'mode' => 'edit',
            'role_choice' => $roleChoice,
        ]);
    }

    public function edit(Request $request, $id)
    {
        if ($request->isMethod('GET')) {
            return $this->showEdit($id);
        }

        $currentUser = Auth::user();
        $targetUser = User::find($id);
        if (!$targetUser) {
            abort(404);
        }

        $username = trim($request->input('username', ''));
        $fullName = trim($request->input('full_name', ''));
        $roleChoice = $request->input('role');
        $newPassword = trim($request->input('password', ''));
        $requestedClass = $request->input('class_id') ?: null;

        // Password is optional when editing: empty means "keep the current password".
        if ($error = $this->accountError($username, $fullName, $newPassword, false, $requestedClass)) {
            return back()->withInput($request->except('password'))->with('danger', $error);
        }

        $duplicate = User::usernameTaken($username, (int) $id);
        if ($duplicate) {
            return back()->withInput()->with('danger', 'This username is already taken by another user.');
        }

        $role = SchoolService::ROLE_CLASS_TEACHER;
        $title = null;
        if ($roleChoice === 'administrator') {
            $role = SchoolService::ROLE_HEADMASTER;
            $title = 'Administrator';
        } elseif ($roleChoice === 'headmaster') {
            $role = SchoolService::ROLE_HEADMASTER;
            $title = 'Headmaster';
        }

        $classId = ($role === SchoolService::ROLE_CLASS_TEACHER) ? ($request->input('class_id') ?: null) : null;

        // Never leave the school without anyone who can manage it.
        if ($targetUser->isHeadmaster() && $role !== SchoolService::ROLE_HEADMASTER) {
            if ((int) $targetUser->id === (int) $currentUser->id) {
                return back()->withInput($request->except('password'))->with('danger', 'You cannot remove Headmaster rights from your own account. Ask another Headmaster/Administrator to do it.');
            }
            $otherHeads = User::where('role', SchoolService::ROLE_HEADMASTER)->where('active', 1)->where('id', '!=', $targetUser->id)->count();
            if ($otherHeads === 0) {
                return back()->withInput($request->except('password'))->with('danger', 'This is the only active Headmaster/Administrator account, so it must keep that role.');
            }
        }

        $oldUsername = $targetUser->username;
        $updateData = [
            'username' => $username,
            'full_name' => $fullName,
            'role' => $role,
            'title' => $title,
            'class_id' => $classId,
        ];

        if ($newPassword !== '') {
            $updateData['password'] = Hash::make($newPassword);
            $updateData['must_change_password'] = 1;
        }

        unset($updateData['class_id']); // set below, together with the class side
        DB::transaction(function () use ($targetUser, $updateData, $role, $classId) {
            $targetUser->update($updateData);

            // Keep users.class_id and classes.teacher_id in step: the previous teacher of the
            // chosen class loses access, and this user leaves any class they had before.
            if ($role === SchoolService::ROLE_CLASS_TEACHER && $classId) {
                SchoolService::assignClassTeacher((int) $classId, (int) $targetUser->id);
            } else {
                SchoolService::unassignClassTeacher((int) $targetUser->id);
            }
        });

        $detail = $oldUsername . ($username !== $oldUsername ? " -> renamed to {$username}" : '');
        if ($newPassword !== '') {
            $detail .= ' (password reset; their open sessions are signed out)';
        }
        SchoolService::logAction($currentUser, 'EDIT_USER', $detail);

        return redirect()->route('users')->with('success', 'User details updated successfully.');
    }

    public function toggle($id)
    {
        $currentUser = Auth::user();
        $targetUser = User::find($id);
        if (!$targetUser) {
            abort(404);
        }

        if ($targetUser->role === SchoolService::ROLE_HEADMASTER) {
            return redirect()->route('users')->with('warning', 'You cannot deactivate the Headmaster account.');
        }

        $newStatus = $targetUser->active ? 0 : 1;
        $targetUser->update(['active' => $newStatus]);

        SchoolService::logAction(
            $currentUser,
            'TOGGLE_USER',
            "{$targetUser->username} -> active={$newStatus}"
        );

        return redirect()->route('users')->with('info', 'Account status updated.');
    }

    public function delete($id)
    {
        $currentUser = Auth::user();
        $targetUser = User::find($id);
        if (!$targetUser) {
            abort(404);
        }

        if ($targetUser->role === SchoolService::ROLE_HEADMASTER) {
            return redirect()->route('users')->with('danger', 'You cannot delete the Headmaster account.');
        }

        if ($id == $currentUser->id) {
            return redirect()->route('users')->with('danger', 'You cannot delete the account you are currently logged in as.');
        }

        SchoolClass::where('teacher_id', $id)->update(['teacher_id' => null]);
        $targetUser->delete();

        SchoolService::logAction(
            $currentUser,
            'DELETE_USER',
            "{$targetUser->username} ({$targetUser->full_name}, role={$targetUser->role})"
        );

        return redirect()->route('users')->with(
            'info',
            "Account '{$targetUser->username}' has been permanently deleted."
        );
    }
}
