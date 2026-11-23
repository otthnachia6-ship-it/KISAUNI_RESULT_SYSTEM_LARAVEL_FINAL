<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'username',
        'action',
        'details',
        'created_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Only the entries that school staff (Headmaster / Administrator / Class Teacher)
     * are meant to see: everything EXCEPT actions performed by a Developer account.
     *
     * The Developer is the system technician; their technical work (backups, cache,
     * maintenance, migrations and any school action they perform) is kept out of the
     * school-facing Audit Log. Nothing is deleted: the full record stays in the table
     * and the Developer's own pages still show every entry.
     *
     * System actions (user_id NULL, e.g. the scheduled cleanup) stay visible.
     */
    public function scopeSchoolVisible($query)
    {
        $developerIds = User::where('role', 'developer')->pluck('id')->all();
        if ($developerIds === []) {
            return $query;
        }

        return $query->where(function ($q) use ($developerIds) {
            $q->whereNull('user_id')->orWhereNotIn('user_id', $developerIds);
        });
    }
}
