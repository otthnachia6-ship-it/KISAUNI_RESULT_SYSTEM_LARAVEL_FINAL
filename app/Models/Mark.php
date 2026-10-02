<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mark extends Model
{
    use HasFactory;

    protected $table = 'marks';

    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'exam_id',
        'subject_id',
        'class_id',
        'score',
        'entered_by',
        'updated_at',
    ];

    protected $casts = [
        'score' => 'float',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function examination()
    {
        return $this->belongsTo(Examination::class, 'exam_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
