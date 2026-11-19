<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed-up indexes for the questions the system asks most often:
 *  - marks of one examination in one class (marks sheet, review, analytics, reports);
 *  - active pupils of one class (almost every page).
 * Only indexes are added: no data is changed, deleted or moved. Safe to run on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasIndex('marks', 'marks_exam_class_index')) {
            Schema::table('marks', function (Blueprint $table) {
                $table->index(['exam_id', 'class_id'], 'marks_exam_class_index');
            });
        }

        if (!Schema::hasIndex('students', 'students_class_active_index')) {
            Schema::table('students', function (Blueprint $table) {
                $table->index(['class_id', 'active'], 'students_class_active_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('marks', 'marks_exam_class_index')) {
            Schema::table('marks', function (Blueprint $table) {
                $table->dropIndex('marks_exam_class_index');
            });
        }

        if (Schema::hasIndex('students', 'students_class_active_index')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropIndex('students_class_active_index');
            });
        }
    }
};
