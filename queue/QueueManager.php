<?php
namespace SPSync\Queue;

use SPSync\Helpers\Settings;
use SPSync\Storage\SharePointStorage;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class QueueManager
{
    const ACTION_HOOK = 'spsync_process_upload_queue';

    /**
     * Initialize background hooks.
     */
    public static function init()
    {
        add_action(self::ACTION_HOOK, [ __CLASS__, 'process_queue_batch' ]);
    }

    /**
     * Start the upload queue processing in the background.
     */
    public static function trigger()
    {
        self::trigger_next();
    }

    /**
     * Schedules the next queue processing action asynchronously.
     */
    public static function trigger_next()
    {
        if (self::is_already_scheduled()) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_HOOK);
            Logger::debug('Asynchronous upload batch enqueued via Action Scheduler.');
        } else {
            wp_schedule_single_event(time(), self::ACTION_HOOK);
            Logger::debug('Asynchronous upload batch scheduled via WP Cron.');
        }
    }

    /**
     * Checks if the upload queue processing is already scheduled.
     *
     * @return bool True if scheduled, false otherwise.
     */
    private static function is_already_scheduled()
    {
        if (function_exists('as_next_scheduled_action')) {
            return as_next_scheduled_action(self::ACTION_HOOK) !== false;
        }
        return wp_next_scheduled(self::ACTION_HOOK) !== false;
    }

    /**
     * Scheduled hook handler. Processes a batch of queue items.
     */
    public static function process_queue_batch()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';

        $batch_size = (int) Settings::get('batch_size', '5');
        if ($batch_size <= 0) {
            $batch_size = 5;
        }

        // Select the next batch of queued or stalled uploading items
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status IN ('queued', 'uploading') AND retries < 3 ORDER BY id ASC LIMIT %d",
            $batch_size
        ));

        if (empty($items)) {
            Logger::info('Background upload queue processing finished. No items left in the queue.');
            return;
        }

        Logger::info(sprintf('Starting background upload batch processing of %d files...', count($items)));

        $has_failures = false;

        foreach ($items as $item) {
            $success = SharePointStorage::upload_queue_item($item);
            if (!$success) {
                $has_failures = true;
            }
        }

        // Check if there are still pending or failed-but-retriable items in the queue
        $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status IN ('queued', 'uploading') AND retries < 3");

        if ($remaining > 0) {
            Logger::info(sprintf('Completed batch. %d items remaining in queue. Scheduling next run.', $remaining));
            self::trigger_next();
        } else {
            if ($has_failures) {
                Logger::warning('Upload queue processing completed, but some items failed after retries.');
            } else {
                Logger::info('All queued backup files uploaded to SharePoint successfully! Cleaning up local files.');
                self::prune_local_backup_files($items[0]->backup_id);
                
                // Enforce backup retention policies
                \SPSync\Backup\Retention::enforce();
            }
            // Release the concurrency lock
            Settings::delete('backup_running');
        }
    }

    /**
     * Prunes local files after a backup has been successfully archived remotely.
     *
     * @param string $backup_id Backup identifier.
     */
    private static function prune_local_backup_files($backup_id)
    {
        $upload_dir = wp_upload_dir();
        $dir = rtrim($upload_dir['basedir'], '/') . '/spsync-backups/' . $backup_id;

        if (file_exists($dir)) {
            $files = glob($dir . '/*');
            if (!empty($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @unlink($dir . '/.htaccess');
            @unlink($dir . '/index.php');
            @rmdir($dir);
            Logger::info("Local backup directory pruned: $backup_id");
        }
    }
}
