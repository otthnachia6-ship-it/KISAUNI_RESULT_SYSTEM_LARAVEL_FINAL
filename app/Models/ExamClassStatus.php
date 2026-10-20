<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamClassStatus extends Model
{
    use HasFactory;

    protected $table = 'exam_class_status';

    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = ['exam_id', 'class_id'];

    protected $fillable = [
        'exam_id',
        'class_id',
        'status',
        'remarks',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
    ];

    public function examination()
    {
        return $this->belongsTo(Examination::class, 'exam_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Kisauni fix: this table has a real composite primary key
     * (exam_id, class_id) - exactly like the Flask/SQLite original.
     * Eloquent's $primaryKey property only supports a single column
     * out of the box; setting it to an array (as above) makes
     * getKeyForSaveQuery() try to use an array as an array offset,
     * crashing every ->update()/->save()/->delete() call on a loaded
     * instance with "Cannot access offset of type array on array".
     * Overriding setKeysForSaveQuery() to build the WHERE clause from
     * both columns ourselves is the standard, minimal fix for a
     * composite-key model in Eloquent - it does not change the schema
     * or any calling code, it just teaches the model how to find
     * "itself" again when saving.
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('exam_id', $this->getAttribute('exam_id'))
            ->where('class_id', $this->getAttribute('class_id'));
    }

    protected function setKeysForSelectQuery($query)
    {
        return $this->setKeysForSaveQuery($query);
    }
}
