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

        $touched = [];
        foreach ($items as $item) {
            \SPSync\Helpers\BackupLock::heartbeat();
            SharePointStorage::upload_queue_item($item);
            $touched[$item->backup_id] = true;
        }

        // Judge each backup on all of its own rows, not on this batch alone.
        foreach (array_keys($touched) as $backup_id) {
            self::finalize_backup($backup_id);
        }

        // Check if there are still pending or failed-but-retriable items in the queue
        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status IN ('queued', 'uploading') AND retries < 3");

        if ($remaining > 0) {
            Logger::info(sprintf('Completed batch. %d items remaining in queue. Scheduling next run.', $remaining));
            self::trigger_next();
        } else {
            // Release the concurrency lock
            \SPSync\Helpers\BackupLock::release();
        }
    }

    /**
     * Once a backup has no pending rows: if every component is on SharePoint, prune the local copy
     * and enforce retention; otherwise keep the local copy so nothing unrecoverable is deleted.
     */
    private static function finalize_backup($backup_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total, SUM(status = 'done') AS done, SUM(status IN ('queued', 'uploading') AND retries < 3) AS pending FROM $table WHERE backup_id = %s",
            $backup_id
        ));
        if (!$counts || (int) $counts->pending > 0) {
            return;
        }
        if ((int) $counts->total > 0 && (int) $counts->done === (int) $counts->total) {
            Logger::info('All backup files uploaded to SharePoint successfully. Cleaning up local files.', ['backup_id' => $backup_id]);
            self::prune_local_backup_files($backup_id);
            \SPSync\Backup\Retention::enforce();
        } else {
            Logger::warning('Backup upload finished with failed components; the local copy was kept for recovery.', [
                'backup_id' => $backup_id,
                'uploaded'  => (int) $counts->done,
                'total'     => (int) $counts->total,
            ]);
        }
    }

    /**
     * Prunes local files after a backup has been successfully archived remotely.
     *
     * @param string $backup_id Backup identifier.
     */
    private static function prune_local_backup_files($backup_id)
    {
        foreach ([\SPSync\Helpers\LocalPaths::backup_dir($backup_id), \SPSync\Helpers\LocalPaths::legacy_backup_dir($backup_id)] as $dir) {
            self::remove_directory($dir, $backup_id);
        }
    }

    private static function remove_directory($dir, $backup_id)
    {
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
            @unlink($dir . '/web.config');
            @rmdir($dir);
            Logger::info("Local backup directory pruned: $backup_id");
        }
    }
}
