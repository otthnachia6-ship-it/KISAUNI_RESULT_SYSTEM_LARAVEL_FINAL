<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ZEMS numbers are no longer used: pupils are identified by the school's own Reg No.
 * The column is removed (it only ever held the ZEMS number; Reg No is untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('students', 'zems_no')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['zems_no']);
            $table->dropColumn('zems_no');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('students', 'zems_no')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->string('zems_no', 32)->nullable()->unique()->after('reg_no');
        });
    }
};
