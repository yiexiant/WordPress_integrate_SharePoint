<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Website;
use App\Models\BackupLog;
use App\Models\RestoreLog;
use App\Models\ActivityLog;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Main Production Site
        $prodSite = Website::create([
            'name'              => 'E-Commerce Main Store',
            'url'               => 'http://backup.test',
            'api_key'           => 'spsync_demo_key_77a9fe7786fefaa0344b2a14b00f182fd195bb07d48bedb7f9655',
            'status'            => 'online',
            'last_sync_at'      => now()->subMinutes(12),
            'last_backup_at'    => now()->subHours(2),
            'sharepoint_status' => 'connected',
            'system_info'       => [
                'wordpress_version' => '6.7.1',
                'php_version'       => '8.3.30',
                'memory_limit'      => '512M',
                'upload_max'        => '100M',
                'database_size'     => '48.25 MB',
                'sharepoint_folder' => 'WordPress Backups/Production',
                'disk'              => [
                    'free_bytes'   => 128849018880,
                    'total_bytes'  => 512000000000,
                    'free_format'  => '120 GB',
                    'total_format' => '476 GB',
                ]
            ]
        ]);

        // 2. Create Staging Site
        $stageSite = Website::create([
            'name'              => 'Staging & Dev Environment',
            'url'               => 'http://dev.test',
            'api_key'           => 'spsync_demo_key_99a29daaf0b040defb9429e3ce69e2148ea83476',
            'status'            => 'online',
            'last_sync_at'      => now()->subMinutes(30),
            'last_backup_at'    => now()->subHours(6),
            'sharepoint_status' => 'connected',
            'system_info'       => [
                'wordpress_version' => '6.7.1',
                'php_version'       => '8.3.30',
                'memory_limit'      => '256M',
                'upload_max'        => '64M',
                'database_size'     => '14.80 MB',
                'sharepoint_folder' => 'WordPress Backups/Staging',
                'disk'              => [
                    'free_bytes'   => 128849018880,
                    'total_bytes'  => 512000000000,
                    'free_format'  => '120 GB',
                    'total_format' => '476 GB',
                ]
            ]
        ]);

        // Backup Logs for Production Site
        // A) Synced & Verified on SharePoint
        BackupLog::create([
            'website_id'                 => $prodSite->id,
            'backup_id'                  => 'spsync-backup-20260827-140000',
            'status'                     => 'completed',
            'total_files'                => 4,
            'uploaded_files'             => 4,
            'file_size'                  => 185490000,
            'is_removed_from_sharepoint' => false,
            'verified_at'                => now()->subMinutes(15),
            'created_at'                 => now()->subHours(4),
        ]);

        BackupLog::create([
            'website_id'                 => $prodSite->id,
            'backup_id'                  => 'spsync-backup-20260827-160000',
            'status'                     => 'completed',
            'total_files'                => 4,
            'uploaded_files'             => 4,
            'file_size'                  => 186200000,
            'is_removed_from_sharepoint' => false,
            'verified_at'                => now()->subMinutes(15),
            'created_at'                 => now()->subHours(2),
        ]);

        // B) Backup that was DELETED on SharePoint (Requirement 5 Demonstration)
        BackupLog::create([
            'website_id'                 => $prodSite->id,
            'backup_id'                  => 'spsync-backup-20260826-080000',
            'status'                     => 'missing',
            'total_files'                => 4,
            'uploaded_files'             => 4,
            'file_size'                  => 179500000,
            'is_removed_from_sharepoint' => true,
            'verified_at'                => now()->subMinutes(15),
            'created_at'                 => now()->subDays(1),
        ]);

        // Backup Logs for Staging Site
        BackupLog::create([
            'website_id'                 => $stageSite->id,
            'backup_id'                  => 'spsync-backup-20260827-120000',
            'status'                     => 'completed',
            'total_files'                => 2,
            'uploaded_files'             => 2,
            'file_size'                  => 45000000,
            'is_removed_from_sharepoint' => false,
            'verified_at'                => now()->subMinutes(20),
            'created_at'                 => now()->subHours(6),
        ]);

        // Restore Logs with Restoration Duration (Requirement 4 Demonstration)
        RestoreLog::create([
            'website_id'       => $prodSite->id,
            'backup_id'        => 'spsync-backup-20260827-140000',
            'restore_job_id'   => 'spsync-restore-20260827-151000',
            'status'           => 'completed',
            'started_at'       => now()->subHours(3)->subSeconds(84),
            'completed_at'     => now()->subHours(3),
            'duration_seconds' => 84, // 1m 24s
            'created_at'       => now()->subHours(3),
        ]);

        RestoreLog::create([
            'website_id'       => $stageSite->id,
            'backup_id'        => 'spsync-backup-20260827-120000',
            'restore_job_id'   => 'spsync-restore-20260827-130000',
            'status'           => 'completed',
            'started_at'       => now()->subHours(5)->subSeconds(45),
            'completed_at'     => now()->subHours(5),
            'duration_seconds' => 45, // 45s
            'created_at'       => now()->subHours(5),
        ]);

        // Initial Activity Logs
        ActivityLog::create([
            'website_id' => $prodSite->id,
            'action'     => 'connection_test',
            'level'      => 'success',
            'message'    => 'SharePoint connection verified for E-Commerce Main Store.',
            'created_at' => now()->subMinutes(15),
        ]);

        ActivityLog::create([
            'website_id' => $prodSite->id,
            'action'     => 'verification',
            'level'      => 'warning',
            'message'    => '1 backup (spsync-backup-20260826-080000) was detected as deleted/removed from SharePoint.',
            'created_at' => now()->subMinutes(15),
        ]);

        ActivityLog::create([
            'website_id' => $stageSite->id,
            'action'     => 'backup_trigger',
            'level'      => 'info',
            'message'    => 'Backup spsync-backup-20260827-120000 completed and synced to SharePoint.',
            'created_at' => now()->subHours(6),
        ]);
    }
}
