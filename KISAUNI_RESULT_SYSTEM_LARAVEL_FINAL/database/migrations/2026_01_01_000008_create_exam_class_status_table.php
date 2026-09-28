<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_class_status', function (Blueprint $table) {
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('class_id');
            $table->string('status', 50)->default('draft');
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('submitted_at', 50)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_at', 50)->nullable();

            $table->primary(['exam_id', 'class_id']);
            $table->foreign('exam_id')->references('id')->on('examinations')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_class_status');
    }
};
