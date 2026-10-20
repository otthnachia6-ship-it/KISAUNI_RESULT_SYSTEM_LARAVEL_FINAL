<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Examination extends Model
{
    use HasFactory;

    protected $table = 'examinations';

    public $timestamps = false;

    protected $fillable = [
        'exam_type',
        'academic_year',
        'created_at',
        'updated_at',
    ];

    public function marks()
    {
        return $this->hasMany(Mark::class, 'exam_id');
    }

    public function statuses()
    {
        return $this->hasMany(ExamClassStatus::class, 'exam_id');
    }
}
