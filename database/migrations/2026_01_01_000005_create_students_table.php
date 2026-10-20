<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('reg_no', 191)->unique();
            $table->string('full_name', 191);
            $table->string('gender', 20)->default('Unknown');
            $table->tinyInteger('gender_confirmed')->default(0);
            $table->unsignedBigInteger('class_id');
            $table->tinyInteger('active')->default(1);
            $table->text('leave_reason')->nullable();
            $table->string('created_at', 50);
            $table->timestamp('updated_at')->nullable();

            $table->foreign('class_id')->references('id')->on('classes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
