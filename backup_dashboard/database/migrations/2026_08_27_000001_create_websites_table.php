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
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('api_key');
            $table->string('status')->default('offline'); // online, offline, error
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->json('system_info')->nullable();
            $table->string('sharepoint_status')->default('unconfigured'); // connected, error, unconfigured
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
