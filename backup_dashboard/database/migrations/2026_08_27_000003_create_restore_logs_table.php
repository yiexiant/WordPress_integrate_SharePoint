<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('restore_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->onDelete('cascade');
            $table->string('backup_id');
            $table->string('restore_job_id');
            $table->string('status')->default('pending'); // pending, downloading, extracting, restoring_database, completed, failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'restore_job_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restore_logs');
    }
};
