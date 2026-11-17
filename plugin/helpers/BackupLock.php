<?php
namespace SPSync\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "One backup at a time" for the whole site.
 *
 * The `backup_running` setting holds the time of the last sign of life (heartbeat) of the running
 * backup. Creating the archives and uploading them both refresh it, so a long backup of a large site
 * never looks abandoned. It only counts as stale after STALE_AFTER seconds without any progress.
 * A backup whose files are still waiting to upload also keeps the site busy, so a second click can
 * never start a parallel backup.
 */
class BackupLock
{
    const KEY = 'backup_running';
    const ID_KEY = 'backup_running_id';
    const STARTING_KEY = 'backup_starting';
    const STALE_AFTER = 1800;
    const HEARTBEAT_EVERY = 30;

    private static $last_beat = 0;

    /**
     * Take the lock for $backup_id. Atomic: two workers racing for it can never both win.
     */
    public static function acquire($backup_id, $force = false)
    {
        $now = (string) time();
        $acquired = Settings::add(self::KEY, $now);
        if (!$acquired) {
            $current = (string) Settings::get(self::KEY, '');
            if ($force) {
                $acquired = Settings::set(self::KEY, $now);
            } elseif ($current === '') {
                $acquired = Settings::add(self::KEY, $now);
            } elseif (self::stale_value($current)) {
                $acquired = Settings::replace_if(self::KEY, $current, $now);
            }
        }
        if ($acquired) {
            Settings::set(self::ID_KEY, (string) $backup_id);
            self::$last_beat = time();
        }
        return $acquired;
    }

    /**
     * Show the lock is still in use. Throttled, and never re-creates a lock that was released.
     */
    public static function heartbeat()
    {
        if (time() - self::$last_beat < self::HEARTBEAT_EVERY) {
            return;
        }
        self::$last_beat = time();
        $current = (string) Settings::get(self::KEY, '');
        if ($current !== '') {
            Settings::replace_if(self::KEY, $current, (string) time());
        }
    }

    public static function release()
    {
        Settings::delete(self::KEY);
        Settings::delete(self::ID_KEY);
    }

    /** @return string The backup ID holding the lock, or ''. */
    public static function current_id()
    {
        return (string) Settings::get(self::ID_KEY, '');
    }

    /** @return int|null Unix time of the last heartbeat, or null when nothing holds the lock. */
    public static function last_beat()
    {
        $value = (string) Settings::get(self::KEY, '');
        return $value === '' ? null : (int) $value;
    }

    public static function is_held()
    {
        $value = (string) Settings::get(self::KEY, '');
        return $value !== '' && !self::stale_value($value);
    }

    public static function is_stale()
    {
        return self::stale_value((string) Settings::get(self::KEY, ''));
    }

    private static function stale_value($value)
    {
        return $value !== '' && is_numeric($value) && (int) $value < time() - self::STALE_AFTER;
    }

    /** Number of backup files still waiting to reach SharePoint (optionally for one backup). */
    public static function pending_uploads($backup_id = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';
        $sql = "SELECT COUNT(*) FROM $table WHERE status IN ('queued', 'uploading') AND retries < 3";
        if ($backup_id !== null) {
            $sql = $wpdb->prepare($sql . ' AND backup_id = %s', $backup_id);
        }
        return (int) $wpdb->get_var($sql);
    }

    /** True while a remote/manual backup request is accepted but its worker has not started yet. */
    public static function request_pending()
    {
        $pending = get_option('spsync_api_backup_request');
        return is_array($pending) && $pending['created_at'] > time() - 600;
    }

    /**
     * Why a new SharePoint backup cannot start right now.
     *
     * @return string|null Plain-language reason, or null when a backup may start.
     */
    public static function busy_reason()
    {
        if (get_option('spsync_restore_start_lock') ||
            in_array(Settings::get('restore_status'), ['downloading', 'extracting', 'restoring_database', 'cleanup'], true)) {
            return 'A restore is in progress. Wait for it to finish before backing up.';
        }
        if (self::request_pending()) {
            return 'A backup is already starting. It will appear in the list below in a moment.';
        }
        if (self::is_held()) {
            return 'A backup is already running. Wait for it to finish before starting another one.';
        }
        $pending = self::pending_uploads();
        if ($pending > 0) {
            return sprintf('A backup is still uploading to SharePoint (%d %s left). Wait for it to finish, or cancel it on the Backups screen.', $pending, $pending === 1 ? 'file' : 'files');
        }
        return null;
    }
}
