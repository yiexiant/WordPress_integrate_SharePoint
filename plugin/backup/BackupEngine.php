<?php
namespace SPSync\Backup;

use SPSync\Helpers\BackupLock;
use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class BackupEngine
{
    /**
     * Accept a request for a SharePoint backup and hand it to a background worker.
     *
     * Used by "Back up now" and the central dashboard. Only one request can be accepted at a
     * time: the check and the reservation happen inside an atomic critical section, so a
     * double-click or two dashboards clicking together still start a single backup.
     *
     * @return string|\WP_Error The accepted backup ID, or why it was refused.
     */
    public static function request()
    {
        if (!Settings::add(BackupLock::STARTING_KEY, (string) time())) {
            $since = (string) Settings::get(BackupLock::STARTING_KEY, '');
            if ((int) $since > time() - 60 || !Settings::replace_if(BackupLock::STARTING_KEY, $since, (string) time())) {
                return new \WP_Error('backup_busy', 'A backup is already starting.');
            }
        }
        try {
            $pending = get_option('spsync_api_backup_request');
            if (is_array($pending) && $pending['created_at'] <= time() - 600) {
                delete_option('spsync_api_backup_request');
            }
            $reason = BackupLock::busy_reason();
            if ($reason !== null) {
                return new \WP_Error('backup_busy', $reason);
            }
            $backup_id = 'spsync-backup-' . current_time('Ymd-His');
            if (!add_option('spsync_api_backup_request', ['backup_id' => $backup_id, 'created_at' => time()], '', false)) {
                return new \WP_Error('backup_busy', 'A backup is already starting.');
            }
            $scheduled = function_exists('as_enqueue_async_action')
                ? as_enqueue_async_action(\SPSync\Scheduler\Scheduler::EVENT_HOOK, [$backup_id])
                : wp_schedule_single_event(time() + 2, \SPSync\Scheduler\Scheduler::EVENT_HOOK, [$backup_id]);
            if (!$scheduled || is_wp_error($scheduled)) {
                delete_option('spsync_api_backup_request');
                return new \WP_Error('backup_schedule_failed', 'Could not schedule the backup worker.');
            }
            Logger::info('Backup request accepted; it will run in the background.', ['backup_id' => $backup_id]);
            return $backup_id;
        } finally {
            Settings::delete(BackupLock::STARTING_KEY);
        }
    }

    /**
     * Start the backup process orchestration.
     *
     * @return string|false Backup ID on success, or false on failure.
     */
    public static function run($force_override = false, $destination = 'sharepoint', $private_directory = null, $requested_backup_id = null)
    {
        global $wp_version, $wpdb;
        if ($requested_backup_id !== null && (!is_string($requested_backup_id) || !preg_match('/\Aspsync-backup-[0-9]{8}-[0-9]{6}\z/D', $requested_backup_id))) {
            return false;
        }
        $pending = get_option('spsync_api_backup_request');
        if (is_array($pending) && $pending['created_at'] > time() - 600 && $pending['backup_id'] !== $requested_backup_id) {
            return false;
        }

        if (!in_array($destination, ['sharepoint', 'device'], true) ||
            ($destination === 'device' && !\SPSync\Helpers\PrivateStorage::valid($private_directory, 'download'))) {
            return false;
        }

        if (get_option('spsync_restore_start_lock') || in_array(Settings::get('restore_status'), ['downloading', 'extracting', 'restoring_database', 'cleanup'], true)) {
            Logger::warning('Backup aborted: a restoration is in progress.');
            return false;
        }
        $selection = BackupSelection::get();
        if ($selection['mode'] === 'selected' && !$selection['directories'] && !$selection['database']) {
            Logger::error('Backup aborted: no selected directories remain available. Review Backup Contents.');
            return false;
        }

        // Prevent process timeouts during long operations
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        // One backup at a time. Files of an earlier backup still waiting to upload also count, so
        // repeated clicks can never pile up parallel multi-GB backups.
        if (!$force_override && $destination === 'sharepoint' && BackupLock::pending_uploads() > 0) {
            Logger::warning('Backup not started: an earlier backup is still uploading to SharePoint.');
            return false;
        }
        $backup_id = $requested_backup_id ?: 'spsync-backup-' . current_time('Ymd-His');
        if (!BackupLock::acquire($backup_id, $force_override)) {
            Logger::warning('Backup not started: another backup is currently running.', [
                'running_backup' => BackupLock::current_id(),
            ]);
            return false;
        }
        Logger::info("Initializing backup orchestrator: $backup_id");

        // Define local storage paths within WordPress upload folder
        $upload_dir = wp_upload_dir();
        $base_dir = $destination === 'device' ? $private_directory . '/artifacts' : \SPSync\Helpers\LocalPaths::backup_dir($backup_id);
        
        // 1. Create and secure directories
        if (!file_exists($base_dir)) {
            wp_mkdir_p($base_dir);
        }

        // Lock down directory access
        self::secure_directory($base_dir);

        $db_file = $base_dir . '/database.sql';
        
        // 2. Export WordPress Database
        $db_success = !$selection['database'] || DatabaseBackup::export($db_file);
        if (!$db_success) {
            Logger::error('Backup Failed: Database SQL export failed.');
            BackupLock::release();
            return false;
        }

        // 3. Scan WordPress directories
        $roots = $selection['mode'] === 'full' ? null : array_values(array_intersect_key(BackupSelection::directories(), array_flip($selection['directories'])));
        $scanned_files = FileScanner::scan([], $roots);
        if (empty($scanned_files) && !$selection['database']) {
            Logger::error('Backup Failed: Directory scanner found no matching assets.');
            BackupLock::release();
            return false;
        }

        // 4. Compress files into split ZIP parts
        $zip_parts = $scanned_files ? ZipBuilder::build($scanned_files, $base_dir) : [];
        if ($scanned_files && empty($zip_parts)) {
            Logger::error('Backup Failed: ZIP packaging and compression failed.');
            BackupLock::release();
            return false;
        }

        // 5. Gather file metrics and compute checksums
        $parts_info = [];
        
        // Database metrics
        if ($selection['database']) {
            $db_filesize = filesize($db_file);
            $db_hash = hash_file('sha256', $db_file);
            $parts_info[] = [
                'filename' => basename($db_file),
                'filesize' => $db_filesize,
                'checksum' => $db_hash
            ];
        }

        // Zipped parts metrics
        foreach ($zip_parts as $part) {
            $parts_info[] = [
                'filename' => basename($part),
                'filesize' => filesize($part),
                'checksum' => hash_file('sha256', $part)
            ];
        }

        // Compile manifest data
        $manifest_data = [
            'backup_id'         => $backup_id,
            'timestamp'         => current_time('mysql', true),
            'wordpress_version' => $wp_version,
            'php_version'       => PHP_VERSION,
            'plugin_version'    => SPSYNC_VERSION,
            'backup_type'       => $selection['mode'],
            'selection'         => $selection,
            'parts'             => $parts_info
        ];

        $manifest_file = $base_dir . '/manifest.json';
        $manifest_json = wp_json_encode($manifest_data, JSON_PRETTY_PRINT);
        
        if (file_put_contents($manifest_file, $manifest_json) === false) {
            Logger::error('Backup Failed: Could not write manifest.json file.');
            BackupLock::release();
            return false;
        }

        if ($destination === 'device') {
            BackupLock::release();
            Logger::info('Backup created in private storage for device download.', ['backup_id' => $backup_id]);
            return $backup_id;
        }

        // Compute manifest metrics
        $manifest_filesize = filesize($manifest_file);
        $manifest_hash = hash_file('sha256', $manifest_file);

        // 6. Insert items into database upload queue table
        $table = $wpdb->prefix . 'spsync_items';
        
        // Assemble queue payload (database, zips, and the manifest itself)
        $queue_items = array_merge($parts_info, [[
            'filename' => basename($manifest_file),
            'filesize' => $manifest_filesize,
            'checksum' => $manifest_hash
        ]]);

        Logger::info('Populating database upload queue with backup components...');
        
        foreach ($queue_items as $item) {
            $wpdb->insert(
                $table,
                [
                    'backup_id'  => $backup_id,
                    'filename'   => $item['filename'],
                    'filesize'   => $item['filesize'],
                    'status'     => 'queued',
                    'retries'    => 0,
                    'checksum'   => $item['checksum'],
                    'created_at' => current_time('mysql', true)
                ],
                [ '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
            );
        }

        Logger::info("Backup $backup_id completed locally. Queue ready for SharePoint upload engine.", [
            'total_queue_items' => count($queue_items)
        ]);

        // Trigger the async queue runner to process uploads in the background
        \SPSync\Queue\QueueManager::trigger();

        return $backup_id;
    }

    /**
     * Creates security files in target backup folder to block directory execution.
     *
     * @param string $dir Target directory.
     */
    private static function secure_directory($dir)
    {
        \SPSync\Helpers\LocalPaths::protect($dir);
    }
}
