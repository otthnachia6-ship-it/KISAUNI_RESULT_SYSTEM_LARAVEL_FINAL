<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';

    public $timestamps = true;

    protected $fillable = [
        'username',
        'password',
        'full_name',
        'role',
        'class_id',
        'active',
        'must_change_password',
        'photo_path',
        'title',
        'failed_login_attempts',
        'locked_until',
        'remember_token',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'active' => 'integer',
        'must_change_password' => 'integer',
        'failed_login_attempts' => 'integer',
    ];

    /**
     * Exact-case username lookup - the single source of truth for finding an
     * account by username. "damtu" and "DAMTU" are two different teachers.
     *
     * The column uses utf8mb4_bin on MySQL/MariaDB (see the
     * make_users_username_case_sensitive migration), so the query is already
     * exact there. The strict PHP comparison below keeps the rule intact even
     * if the column is ever changed back to a case-insensitive collation:
     * a case-variant can never be returned for a different account.
     */
    public static function findByUsername(?string $username): ?self
    {
        if ($username === null || $username === '') {
            return null;
        }

        return static::where('username', $username)
            ->orderBy('id')
            ->get()
            ->first(fn (self $u) => $u->username === $username);
    }

    /**
     * True when another account (not $exceptId) already uses exactly this username.
     */
    public static function usernameTaken(string $username, ?int $exceptId = null): bool
    {
        return static::where('username', $username)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get()
            ->contains(fn (self $u) => $u->username === $username);
    }

    public function isHeadmaster(): bool
    {
        return $this->role === 'headmaster';
    }

    public function isClassTeacher(): bool
    {
        return $this->role === 'class_teacher';
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function taughtClass()
    {
        return $this->hasOne(SchoolClass::class, 'teacher_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }
}
