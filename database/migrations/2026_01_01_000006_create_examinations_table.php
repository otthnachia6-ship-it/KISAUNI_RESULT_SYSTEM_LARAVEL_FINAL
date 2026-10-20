<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examinations', function (Blueprint $table) {
            $table->id();
            $table->string('exam_type', 100);
            $table->string('academic_year', 50);
            $table->string('created_at', 50);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['exam_type', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examinations');
    }
};
