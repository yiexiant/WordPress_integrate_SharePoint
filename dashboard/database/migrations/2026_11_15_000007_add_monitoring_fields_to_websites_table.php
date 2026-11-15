<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data reported by plugin 1.4.0+ (backup/status, system/info) for unattended monitoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('site_folder')->nullable()->after('url');
            $table->string('plugin_version')->nullable()->after('site_folder');
            $table->string('backup_frequency')->nullable()->after('last_backup_at');
            $table->timestamp('next_backup_at')->nullable()->after('backup_frequency');
            $table->boolean('backup_overdue')->default(false)->after('next_backup_at');
            $table->text('last_error')->nullable()->after('backup_overdue');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['site_folder', 'plugin_version', 'backup_frequency', 'next_backup_at', 'backup_overdue', 'last_error']);
        });
    }
};
