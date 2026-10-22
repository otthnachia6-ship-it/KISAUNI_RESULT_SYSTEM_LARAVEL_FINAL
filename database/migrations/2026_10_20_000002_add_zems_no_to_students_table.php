<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the government ZEMS number (e.g. ZE0423951897) to students so that
 * results exported from Ozems can be matched to the right pupil.
 * Nullable + unique: existing students keep working, numbers are filled in later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('students', 'zems_no')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->string('zems_no', 32)->nullable()->unique()->after('reg_no');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('students', 'zems_no')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['zems_no']);
            $table->dropColumn('zems_no');
        });
    }
};
