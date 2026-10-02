<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191)->unique();
            $table->integer('standard')->nullable();
            $table->string('stream', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('last_promoted_year', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
