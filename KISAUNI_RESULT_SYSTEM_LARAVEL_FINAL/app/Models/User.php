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
