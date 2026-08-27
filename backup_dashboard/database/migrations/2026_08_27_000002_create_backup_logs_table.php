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
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->onDelete('cascade');
            $table->string('backup_id');
            $table->string('status')->default('queued'); // queued, uploading, completed, failed, missing
            $table->integer('total_files')->default(0);
            $table->integer('uploaded_files')->default(0);
            $table->bigInteger('file_size')->nullable();
            $table->boolean('is_removed_from_sharepoint')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'backup_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
