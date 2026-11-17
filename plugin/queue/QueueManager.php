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
    const RUNNER_KEY = 'queue_runner';
    const RUNNER_STALE_AFTER = 1800;
    /** Seconds one run keeps uploading before handing over to a fresh run (below Action Scheduler's 300 s timeout). */
    const TIME_BUDGET = 150;

    /**
     * Initialize background hooks.
     */
    public static function init()
    {
        add_action(self::ACTION_HOOK, [ __CLASS__, 'process_queue_batch' ]);
        add_action('admin_init', [ __CLASS__, 'resume_if_stalled' ]);
    }

    /**
     * Safety net: if files are waiting but no upload run is active or scheduled (for example after a
     * server restart or a plugin update), start one. Checked at most once a minute from the admin.
     */
    public static function resume_if_stalled()
    {
        if (get_transient('spsync_queue_watchdog')) {
            return;
        }
        set_transient('spsync_queue_watchdog', 1, MINUTE_IN_SECONDS);
        if (self::runner_active() || self::is_already_scheduled() || \SPSync\Helpers\BackupLock::pending_uploads() === 0) {
            return;
        }
        Logger::info('Upload queue had waiting files but no active run; resuming it.');
        self::trigger_next();
    }

    private static function runner_active()
    {
        $since = (int) Settings::get(self::RUNNER_KEY, '0');
        return $since > time() - self::RUNNER_STALE_AFTER;
    }

    /** Become the only active upload run. Atomic; a run silent for 30 minutes can be taken over. */
    private static function claim_runner()
    {
        $now = (string) time();
        if (Settings::add(self::RUNNER_KEY, $now)) {
            return true;
        }
        $current = (string) Settings::get(self::RUNNER_KEY, '');
        return $current !== '' && (int) $current <= time() - self::RUNNER_STALE_AFTER && Settings::replace_if(self::RUNNER_KEY, $current, $now);
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
     * Is a *future* upload run already waiting?
     *
     * Only pending actions count. as_next_scheduled_action() also reports the action that is running
     * right now, so a run asking "is the next run scheduled?" always got "yes" and the queue stopped
     * after its first batch until something else (such as another backup) restarted it.
     *
     * @return bool
     */
    private static function is_already_scheduled()
    {
        if (function_exists('as_get_scheduled_actions') && class_exists('ActionScheduler_Store')) {
            return (bool) as_get_scheduled_actions([
                'hook'     => self::ACTION_HOOK,
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
            ], 'ids');
        }
        if (function_exists('as_next_scheduled_action')) {
            return as_next_scheduled_action(self::ACTION_HOOK) !== false;
        }
        return wp_next_scheduled(self::ACTION_HOOK) !== false;
    }

    /**
     * Scheduled hook handler. Uploads queued files one after another until the time budget is used,
     * then hands over to a fresh run. Only one run is active at a time, so a file is never sent twice.
     */
    public static function process_queue_batch()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';

        if (!self::claim_runner()) {
            Logger::debug('Another upload run is active; this one exits.');
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $started = time();
        $budget = max(30, (int) apply_filters('spsync_upload_time_budget', self::TIME_BUDGET));
        $touched = [];
        $uploaded = 0;
        try {
            do {
                $item = $wpdb->get_row(
                    "SELECT * FROM $table WHERE status IN ('queued', 'uploading') AND retries < 3 ORDER BY id ASC LIMIT 1"
                );
                if (!$item) {
                    break;
                }
                Settings::set(self::RUNNER_KEY, (string) time());
                \SPSync\Helpers\BackupLock::heartbeat();
                SharePointStorage::upload_queue_item($item);
                $touched[$item->backup_id] = true;
                $uploaded++;
            } while (time() - $started < $budget);

            // Judge each backup on all of its own rows, not on this run alone.
            foreach (array_keys($touched) as $backup_id) {
                self::finalize_backup($backup_id);
            }
        } finally {
            Settings::delete(self::RUNNER_KEY);
        }

        $remaining = \SPSync\Helpers\BackupLock::pending_uploads();
        Logger::info(sprintf('Upload run finished: %d file(s) processed in %d s, %d waiting.', $uploaded, time() - $started, $remaining));
        if ($remaining > 0) {
            self::trigger_next();
        } else {
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
        if (\SPSync\Helpers\LocalPaths::remove_backup($backup_id)) {
            Logger::info("Local backup directory pruned: $backup_id");
        }
    }
}
