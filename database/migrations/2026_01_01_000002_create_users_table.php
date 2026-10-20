<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 191)->unique();
            $table->string('password', 255);
            $table->string('full_name', 191);
            $table->string('role', 50); // 'headmaster' or 'class_teacher'
            $table->unsignedBigInteger('class_id')->nullable();
            $table->tinyInteger('active')->default(1);
            $table->tinyInteger('must_change_password')->default(0);
            $table->string('photo_path', 255)->nullable();
            $table->string('title', 100)->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->string('locked_until', 50)->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->foreign('class_id')->references('id')->on('classes')->nullOnDelete();
        });

        // Add foreign key constraint to classes.teacher_id now that users table exists
        Schema::table('classes', function (Blueprint $table) {
            $table->foreign('teacher_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
        });
        Schema::dropIfExists('users');
    }
};
