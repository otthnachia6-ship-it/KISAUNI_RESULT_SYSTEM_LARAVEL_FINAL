<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $table = 'students';

    public $timestamps = false;

    protected $fillable = [
        'reg_no',
        'full_name',
        'gender',
        'gender_confirmed',
        'class_id',
        'active',
        'leave_reason',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'gender_confirmed' => 'integer',
        'active' => 'integer',
    ];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function marks()
    {
        return $this->hasMany(Mark::class, 'student_id');
    }
}
