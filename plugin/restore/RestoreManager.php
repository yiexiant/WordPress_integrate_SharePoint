<?php
namespace SPSync\Restore;

use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class RestoreManager
{
    const ACTION_HOOK = 'spsync_process_restore_queue';

    /**
     * Initialize restore hooks.
     */
    public static function init()
    {
        add_action(self::ACTION_HOOK, [ __CLASS__, 'process_restore_loop' ]);
    }

    /**
     * Start the restore process.
     *
     * @param string $backup_id Backup folder identifier to restore.
     * @return string|false Restore Job ID or false on failure.
     */
    public static function start_restore($backup_id)
    {
        if (!add_option('spsync_restore_start_lock', time(), '', false)) {
            return false;
        }
        try {
            return self::prepare_restore($backup_id);
        } finally {
            delete_option('spsync_restore_start_lock');
        }
    }

    private static function prepare_restore($backup_id)
    {
        global $wpdb;

        if (empty($backup_id)) {
            return false;
        }
        if (in_array(Settings::get('restore_status'), ['downloading', 'extracting', 'restoring_database', 'cleanup'], true) || Settings::get('backup_running')) {
            Logger::warning('Restore aborted: another backup or restoration is in progress.');
            return false;
        }
        Settings::delete('restore_db_offset');

        $restore_job_id = 'spsync-restore-' . current_time('Ymd-His');
        Logger::info("Initializing restore job: $restore_job_id for backup: $backup_id");

        $upload_dir   = wp_upload_dir();
        $restore_dir  = rtrim($upload_dir['basedir'], '/') . '/spsync-backups/restore';
        $rollback_dir = rtrim($upload_dir['basedir'], '/') . '/spsync-backups/rollback';

        // 1. Create emergency rollback snapshot first
        $snapshot_success = RestoreEngine::create_rollback_snapshot($rollback_dir);
        if (!$snapshot_success) {
            Logger::error('Restore Aborted: Pre-restore rollback snapshot creation failed.');
            return false;
        }

        // 2. Download remote manifest file
        $manifest_local_path = $restore_dir . '/manifest.json';
        $download_manifest = DownloadManager::download_file($backup_id, 'manifest.json', $manifest_local_path);
        
        if (!$download_manifest) {
            Logger::error('Restore Aborted: Could not download manifest.json from SharePoint.');
            return false;
        }

        // 3. Read enqueued parts from manifest
        $manifest_content = file_get_contents($manifest_local_path);
        $manifest = json_decode($manifest_content, true);
        if (!$manifest || empty($manifest['parts'])) {
            Logger::error('Restore Aborted: manifest.json is invalid or contains no parts.');
            return false;
        }

        // Register job active parameters
        Settings::set('restore_job_active', $restore_job_id);
        Settings::set('restore_backup_id', $backup_id);
        Settings::set('restore_status', 'downloading');

        // Clear out any old restore queue table records
        $table = $wpdb->prefix . 'spsync_restore_items';
        $wpdb->query("TRUNCATE TABLE $table");

        // 4. Populate restore items table
        foreach ($manifest['parts'] as $part) {
            $wpdb->insert(
                $table,
                [
                    'restore_job_id' => $restore_job_id,
                    'filename'       => $part['filename'],
                    'status'         => 'waiting',
                    'retries'        => 0,
                    'downloaded'     => 0,
                    'restored'       => 0,
                    'created_at'     => current_time('mysql', true)
                ],
                [ '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
            );
        }

        Logger::info("Restore queue initialized. Launching restore background worker loop.", [
            'total_files_enqueued' => count($manifest['parts'])
        ]);

        self::trigger_worker();

        return $restore_job_id;
    }

    /**
     * Schedules the next restore processing cycle asynchronously.
     */
    public static function trigger_worker()
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_HOOK);
        } else {
            wp_schedule_single_event(time(), self::ACTION_HOOK);
        }
    }

    /**
     * Core background restore loop processor. Handles states.
     */
    public static function process_restore_loop()
    {
        global $wpdb;

        $active_job = Settings::get('restore_job_active');
        if (strpos($active_job, 'spsync-local-') === 0) {
            return; // Manual restores have their own worker and private staging directory.
        }
        $backup_id  = Settings::get('restore_backup_id');
        $status     = Settings::get('restore_status');

        if (empty($active_job) || empty($backup_id)) {
            return;
        }

        $table = $wpdb->prefix . 'spsync_restore_items';
        $upload_dir = wp_upload_dir();
        $restore_dir = rtrim($upload_dir['basedir'], '/') . '/spsync-backups/restore';

        // 1. Process Downloading Stage
        if ($status === 'downloading') {
            $item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE restore_job_id = %s AND downloaded = 0 AND status != 'error' ORDER BY id ASC LIMIT 1",
                $active_job
            ));

            if ($item) {
                // Find matching expected checksum
                $manifest_path = $restore_dir . '/manifest.json';
                $manifest = json_decode(file_get_contents($manifest_path), true);
                $expected_hash = '';
                foreach ($manifest['parts'] as $part) {
                    if ($part['filename'] === $item->filename) {
                        $expected_hash = $part['checksum'];
                        break;
                    }
                }

                $local_dest = $restore_dir . '/' . $item->filename;
                
                $wpdb->update($table, [ 'status' => 'downloading' ], [ 'id' => $item->id ]);
                $success = DownloadManager::download_file($backup_id, $item->filename, $local_dest, $expected_hash);

                if ($success) {
                    $wpdb->update($table, [ 'downloaded' => 1, 'status' => 'waiting' ], [ 'id' => $item->id ]);
                } else {
                    $retries = (int) $item->retries + 1;
                    if ($retries >= 3) {
                        $wpdb->update($table, [ 'status' => 'error', 'retries' => $retries ], [ 'id' => $item->id ]);
                        self::handle_restore_failure('Download failed after retries for file: ' . $item->filename);
                        return;
                    } else {
                        $wpdb->update($table, [ 'status' => 'waiting', 'retries' => $retries ], [ 'id' => $item->id ]);
                    }
                }
                
                self::trigger_worker();
                return;
            }

            Logger::info('All backup components downloaded. Entering extraction phase.');
            Settings::set('restore_status', 'extracting');
            self::trigger_worker();
            return;
        }

        // 2. Process Extraction & File Restore Stage
        if ($status === 'extracting') {
            $zip_items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE restore_job_id = %s AND filename LIKE '%.zip' ORDER BY id ASC",
                $active_job
            ));

            $zip_paths = [];
            foreach ($zip_items as $item) {
                $zip_paths[] = $restore_dir . '/' . $item->filename;
            }

            $success = !$zip_paths || RestoreEngine::restore_files($zip_paths);
            if ($success) {
                Logger::info('File extraction restoration complete.');
                foreach ($zip_items as $item) {
                    $wpdb->update($table, ['restored' => 1], ['id' => $item->id]);
                }
                Settings::set('restore_status', 'restoring_database');
            } else {
                self::handle_restore_failure('Backup file extraction failed.');
                return;
            }

            self::trigger_worker();
            return;
        }

        // 3. Process Database Restoration Stage (Resumable batches)
        if ($status === 'restoring_database') {
            $sql_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE restore_job_id = %s AND filename LIKE '%.sql' LIMIT 1",
                $active_job
            ));

            if ($sql_item) {
                $sql_path = $restore_dir . '/' . $sql_item->filename;
                
                // Executes batch statement run. Returns true if file parsing is finished.
                $db_done = RestoreEngine::restore_database($sql_path);

                if (is_wp_error($db_done)) {
                    self::handle_restore_failure($db_done->get_error_message());
                    return;
                }

                if ($db_done) {
                    $wpdb->update($table, [ 'restored' => 1 ], [ 'id' => $sql_item->id ]);
                    Settings::set('restore_status', 'cleanup');
                }
                
                self::trigger_worker();
                return;
            } else {
                Settings::set('restore_status', 'cleanup');
                self::trigger_worker();
                return;
            }
        }

        // 4. Process Cleanup & Completion Stage
        if ($status === 'cleanup') {
            Logger::info('Finalizing restore cleanup operations...');

            // Remove temporary download folder
            if (file_exists($restore_dir)) {
                $files = glob($restore_dir . '/*');
                if (!empty($files)) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                        }
                    }
                }
                @rmdir($restore_dir);
            }

            // Remove rollback dir
            $rollback_dir = rtrim($upload_dir['basedir'], '/') . '/spsync-backups/rollback';
            if (file_exists($rollback_dir)) {
                $files = glob($rollback_dir . '/*');
                if (!empty($files)) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                        }
                    }
                }
                @rmdir($rollback_dir);
            }

            // Mark restore status as completed for API queries
            Settings::set('restore_status', 'completed');

            Logger::info("RESTORE SUCCESSFUL: Website has been fully restored to backup point $backup_id.");
        }
    }

    /**
     * Executes automatic fallback rollback in case of restore failure.
     */
    private static function handle_restore_failure($reason)
    {
        Logger::error("Emergency Rollback Triggered: " . $reason);

        $upload_dir = wp_upload_dir();
        $rollback_dir = rtrim($upload_dir['basedir'], '/') . '/spsync-backups/rollback';

        RestoreEngine::trigger_rollback($rollback_dir);

        Settings::set('restore_status', 'failed');
    }
}
