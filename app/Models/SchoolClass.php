<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    use HasFactory;

    protected $table = 'classes';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'standard',
        'stream',
        'sort_order',
        'teacher_id',
        'last_promoted_year',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public function activeStudents()
    {
        return $this->hasMany(Student::class, 'class_id')->where('active', 1);
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'class_subjects', 'class_id', 'subject_id');
    }

    public function statuses()
    {
        return $this->hasMany(ExamClassStatus::class, 'class_id');
    }
}
