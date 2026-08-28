<?php
namespace SPSync\Backup;

use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class BackupEngine
{
    /**
     * Start the backup process orchestration.
     *
     * @return string|false Backup ID on success, or false on failure.
     */
    public static function run($force_override = false)
    {
        global $wp_version, $wpdb;

        // Prevent process timeouts during long operations
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        // Concurrency locking check
        $lock = Settings::get('backup_running');
        if (!empty($lock) && !$force_override) {
            $lock_time = (int) $lock;
            $ten_mins_ago = time() - (10 * MINUTE_IN_SECONDS);
            if ($lock_time > $ten_mins_ago) {
                Logger::warning('Backup abort: Another backup process is currently running.', [
                    'lock_acquired_at' => date('Y-m-d H:i:s', $lock_time)
                ]);
                return false;
            }
            Logger::info('Stale backup lock detected and overridden.');
        }

        // Set concurrency lock
        Settings::set('backup_running', (string) time());

        // Generate unique backup ID matching Phase 2 structure
        $backup_id = 'spsync-backup-' . current_time('Ymd-His');
        Logger::info("Initializing backup orchestrator: $backup_id");

        // Define local storage paths within WordPress upload folder
        $upload_dir = wp_upload_dir();
        $base_dir = rtrim($upload_dir['basedir'], '/') . '/spsync-backups/' . $backup_id;
        
        // 1. Create and secure directories
        if (!file_exists($base_dir)) {
            wp_mkdir_p($base_dir);
        }

        // Lock down directory access
        self::secure_directory($base_dir);

        $db_file = $base_dir . '/database.sql';
        
        // 2. Export WordPress Database
        $db_success = DatabaseBackup::export($db_file);
        if (!$db_success) {
            Logger::error('Backup Failed: Database SQL export failed.');
            Settings::delete('backup_running');
            return false;
        }

        // 3. Scan WordPress directories
        $scanned_files = FileScanner::scan();
        if (empty($scanned_files)) {
            Logger::error('Backup Failed: Directory scanner found no matching assets.');
            Settings::delete('backup_running');
            return false;
        }

        // 4. Compress files into split ZIP parts
        $zip_parts = ZipBuilder::build($scanned_files, $base_dir);
        if (empty($zip_parts)) {
            Logger::error('Backup Failed: ZIP packaging and compression failed.');
            Settings::delete('backup_running');
            return false;
        }

        // 5. Gather file metrics and compute checksums
        $parts_info = [];
        
        // Database metrics
        $db_filesize = filesize($db_file);
        $db_hash = hash_file('sha256', $db_file);
        $parts_info[] = [
            'filename' => basename($db_file),
            'filesize' => $db_filesize,
            'checksum' => $db_hash
        ];

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
            'backup_type'       => 'full',
            'parts'             => $parts_info
        ];

        $manifest_file = $base_dir . '/manifest.json';
        $manifest_json = wp_json_encode($manifest_data, JSON_PRETTY_PRINT);
        
        if (file_put_contents($manifest_file, $manifest_json) === false) {
            Logger::error('Backup Failed: Could not write manifest.json file.');
            Settings::delete('backup_running');
            return false;
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
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }

        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php // Silence is golden\n");
        }
    }
}
