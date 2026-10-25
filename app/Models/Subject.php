<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $table = 'subjects';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'subject_code',
    ];

    public function classes()
    {
        return $this->belongsToMany(SchoolClass::class, 'class_subjects', 'subject_id', 'class_id');
    }

    public function marks()
    {
        return $this->hasMany(Mark::class, 'subject_id');
    }

    /** Subject codes are text ("104", "121D"): trimmed, upper-case, "108.0" from Excel becomes "108". */
    public static function normalizeCode(?string $raw): string
    {
        $code = trim((string) $raw);
        $code = preg_replace('/^\xEF\xBB\xBF/', '', $code);
        $code = preg_replace('/\.0+$/', '', $code);

        return strtoupper($code);
    }
}
