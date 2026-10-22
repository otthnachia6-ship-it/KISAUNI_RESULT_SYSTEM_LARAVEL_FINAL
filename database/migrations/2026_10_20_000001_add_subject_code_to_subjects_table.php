<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the ZEMS (Ozems) subject code to subjects so that result files can be
 * matched by code (101, 108 ...) instead of by name.
 *
 * Safe on production: the column is nullable, nothing is deleted, and the
 * back-fill only fills codes that are still empty and not already used.
 */
return new class extends Migration
{
    /** lower-case subject name => ZEMS code */
    private const KNOWN_CODES = [
        'english' => '101',
        'kiswahili' => '102',
        'mathematics' => '104',
        'dini' => '107',
        'arabic' => '108',
        's.jamii' => '110',
        'science and technology' => '114',
        'science & tech' => '114',
        'mazingira' => '121D',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('subjects', 'subject_code')) {
            return;
        }

        Schema::table('subjects', function (Blueprint $table) {
            $table->string('subject_code', 20)->nullable()->unique()->after('id');
        });

        foreach (self::KNOWN_CODES as $name => $code) {
            $taken = DB::table('subjects')->where('subject_code', $code)->exists();
            if ($taken) {
                continue;
            }
            $row = DB::table('subjects')
                ->whereRaw('LOWER(name) = ?', [$name])
                ->whereNull('subject_code')
                ->orderBy('id')
                ->first();
            if ($row) {
                DB::table('subjects')->where('id', $row->id)->update(['subject_code' => $code]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('subjects', 'subject_code')) {
            return;
        }

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['subject_code']);
            $table->dropColumn('subject_code');
        });
    }
};
