<?php

namespace App\Support;

/**
 * One rule for every place a password is set: Change Password, Add User and Edit User.
 * Existing passwords keep working; the rule applies only when a new password is chosen.
 */
final class PasswordPolicy
{
    public static function minLength(): int
    {
        return max(4, (int) config('kisauni.min_password_length', 4));
    }

    /** A message explaining why the password cannot be used, or null when it is fine. */
    public static function error(string $password, ?string $username = null): ?string
    {
        $min = self::minLength();
        if (mb_strlen($password) < $min) {
            return "Password must be at least {$min} characters long.";
        }
        if (mb_strlen($password) > 72) {
            // bcrypt only uses the first 72 bytes; longer would silently be cut.
            return 'Password is too long (at most 72 characters).';
        }
        if ($username !== null && $username !== '' && mb_strtolower($password) === mb_strtolower($username)) {
            return 'Password must not be the same as the username.';
        }

        return null;
    }
}
