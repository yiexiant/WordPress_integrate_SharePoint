<?php
namespace SPSync\Admin;

use SPSync\Helpers\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only facts for the admin screens, phrased for people rather than for the database.
 */
class StatusSummary
{
    const ACTIVE_RESTORE = ['downloading', 'extracting', 'restoring_database', 'cleanup'];

    /** Credentials needed to reach SharePoint are filled in. */
    public static function sharepoint_configured()
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'site_url'] as $key) {
            if (Settings::get($key) === '' || Settings::get($key) === null) {
                return false;
            }
        }
        return true;
    }

    /** @return array|null ['success' => bool, 'message' => string, 'time' => int] from the last "Test connection". */
    public static function connection()
    {
        $saved = json_decode((string) Settings::get('connection_test', ''), true);
        return is_array($saved) && isset($saved['success'], $saved['time']) ? $saved : null;
    }

    public static function record_connection_test(array $result)
    {
        Settings::set('connection_test', wp_json_encode([
            'success' => !empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
            'time'    => time(),
        ]));
    }

    public static function is_automatic()
    {
        return in_array(Settings::get('backup_frequency', 'manual'), ['daily', 'weekly', 'monthly', 'once'], true);
    }

    /** @return array ['frequency', 'label' (plain sentence), 'next' (timestamp|null)] */
    public static function schedule()
    {
        $frequency = Settings::get('backup_frequency', 'manual');
        $time = Settings::get('backup_time', '02:00');
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        switch ($frequency) {
            case 'daily':
                $label = sprintf('Every day at %s', $time);
                break;
            case 'weekly':
                $label = sprintf('Every %s at %s', $days[(int) Settings::get('backup_day_of_week', '0')] ?? 'Sunday', $time);
                break;
            case 'monthly':
                $label = sprintf('On day %d of every month at %s', (int) Settings::get('backup_day_of_month', '1'), $time);
                break;
            case 'once':
                $label = sprintf('Once, on %s at %s', Settings::get('backup_specific_date', '?'), $time);
                break;
            default:
                $label = 'Off: backups only run when you start them';
        }
        $next = function_exists('wp_next_scheduled') ? wp_next_scheduled(\SPSync\Scheduler\Scheduler::EVENT_HOOK) : false;
        return ['frequency' => $frequency, 'label' => $label, 'next' => $next ?: null];
    }

    /**
     * Recent SharePoint backups, newest first.
     *
     * @return array<int, array{id:string, time:int, files:int, uploaded:int, size:int, state:string}>
     */
    public static function recent_backups($limit = 10)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === null) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT backup_id, COUNT(*) AS total, SUM(status = 'done') AS done, SUM(status = 'error') AS errors,
                    SUM(status = 'missing') AS missing, SUM(filesize) AS size, MAX(created_at) AS created_at
             FROM $table GROUP BY backup_id ORDER BY backup_id DESC LIMIT %d",
            (int) $limit
        ), ARRAY_A);

        $backups = [];
        foreach ((array) $rows as $row) {
            if (empty($row['backup_id'])) {
                continue;
            }
            $total = (int) ($row['total'] ?? 0);
            $done = (int) ($row['done'] ?? 0);
            if ((int) ($row['missing'] ?? 0) > 0) {
                $state = 'missing';
            } elseif ($total > 0 && $done === $total) {
                $state = 'completed';
            } elseif ((int) ($row['errors'] ?? 0) > 0 && $done + (int) $row['errors'] === $total) {
                $state = 'failed';
            } else {
                $state = 'uploading';
            }
            $backups[] = [
                'id'       => $row['backup_id'],
                'time'     => self::backup_time($row['backup_id'], $row['created_at'] ?? null),
                'files'    => $total,
                'uploaded' => $done,
                'size'     => (int) ($row['size'] ?? 0),
                'state'    => $state,
            ];
        }
        return $backups;
    }

    /** @return array|null The newest backup whose files all reached SharePoint. */
    public static function last_successful($backups = null)
    {
        foreach ($backups ?? self::recent_backups(30) as $backup) {
            if ($backup['state'] === 'completed') {
                return $backup;
            }
        }
        return null;
    }

    /** "Sat 14 Nov 2026, 02:00" in the site's timezone. */
    public static function when($timestamp)
    {
        return $timestamp ? wp_date('D j M Y, H:i', (int) $timestamp) : '—';
    }

    /** Human file size; "—" when unknown (size_format() returns false for 0). */
    public static function size($bytes)
    {
        return $bytes > 0 ? (string) size_format((int) $bytes, 1) : '—';
    }

    /** "3 hours ago" / "in 5 hours". */
    public static function relative($timestamp)
    {
        if (!$timestamp) {
            return 'never';
        }
        return $timestamp > time()
            ? 'in ' . human_time_diff(time(), (int) $timestamp)
            : human_time_diff((int) $timestamp, time()) . ' ago';
    }

    /** Status pill [level, label] for a backup state. */
    public static function backup_pill($state)
    {
        $pills = [
            'completed' => ['ok', 'Saved to SharePoint'],
            'uploading' => ['active', 'Uploading'],
            'failed'    => ['critical', 'Failed'],
            'missing'   => ['warning', 'Deleted from SharePoint'],
        ];
        return $pills[$state] ?? ['neutral', ucfirst((string) $state)];
    }

    /** Backup IDs embed the site-local time (spsync-backup-YYYYMMDD-HHMMSS). */
    public static function backup_time($backup_id, $created_at_utc = null)
    {
        if (preg_match('/\Aspsync-backup-(\d{8}-\d{6})\z/', (string) $backup_id, $m)) {
            $zone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $date = \DateTimeImmutable::createFromFormat('Ymd-His', $m[1], $zone);
            if ($date) {
                return $date->getTimestamp();
            }
        }
        return $created_at_utc ? (int) strtotime($created_at_utc . ' UTC') : 0;
    }

    /** @return array|null What is happening right now (backup or restore), for a live banner. */
    public static function activity()
    {
        $restore = Settings::get('restore_status', '');
        if (in_array($restore, self::ACTIVE_RESTORE, true)) {
            $phases = ['downloading' => 'Preparing the backup', 'extracting' => 'Restoring files', 'restoring_database' => 'Restoring the database', 'cleanup' => 'Finishing up'];
            return ['type' => 'restore', 'label' => 'Restoring your website: ' . $phases[$restore] . '…', 'stale' => false, 'since' => null];
        }
        $lock = (int) Settings::get('backup_running', '0');
        if ($lock > 0) {
            return [
                'type'  => 'backup',
                'label' => 'A backup is running…',
                'since' => $lock,
                // BackupEngine treats locks older than 10 minutes as stale.
                'stale' => $lock < time() - 10 * 60,
            ];
        }
        return null;
    }

    /** Steps a new user should complete, in order. */
    public static function setup_steps($backups = null)
    {
        $connection = self::connection();
        return [
            [
                'title' => 'Connect SharePoint',
                'text'  => 'Enter your Microsoft 365 details and test the connection.',
                'done'  => self::sharepoint_configured() && $connection && $connection['success'],
                'url'   => SettingsPage::url('settings', ['section' => 'sharepoint']),
                'cta'   => 'Open SharePoint settings',
            ],
            [
                'title' => 'Turn on automatic backups',
                'text'  => 'Choose how often your website is backed up.',
                'done'  => self::is_automatic(),
                'url'   => SettingsPage::url('settings', ['section' => 'schedule']),
                'cta'   => 'Choose a schedule',
            ],
            [
                'title' => 'Make your first backup',
                'text'  => 'Run one backup now to confirm everything works.',
                'done'  => self::last_successful($backups) !== null,
                'url'   => SettingsPage::url('backups'),
                'cta'   => 'Back up now',
            ],
        ];
    }

    /**
     * One headline answer: is this website protected?
     *
     * @return array{level:string, title:string, text:string}
     */
    public static function overall($backups = null)
    {
        $backups = $backups ?? self::recent_backups(30);
        $last = self::last_successful($backups);
        $schedule = self::schedule();
        $connection = self::connection();
        $ago = $last ? 'The last backup was ' . human_time_diff($last['time']) . ' ago.' : 'No backup has been saved to SharePoint yet.';

        if ($activity = self::activity()) {
            if (!$activity['stale']) {
                return ['level' => 'active', 'title' => $activity['label'], 'text' => 'You can leave this page; it keeps running on the server.'];
            }
        }
        if (!self::sharepoint_configured()) {
            return ['level' => 'setup', 'title' => 'Finish setting up your backups', 'text' => 'Connect SharePoint and choose a schedule so your website is backed up automatically. You can already download a backup to your computer.'];
        }
        if ($connection && !$connection['success']) {
            return ['level' => 'critical', 'title' => 'SharePoint can\'t be reached', 'text' => $connection['message'] . ' Check your SharePoint settings and test the connection again.'];
        }
        if (!empty($backups) && $backups[0]['state'] === 'failed') {
            return ['level' => 'critical', 'title' => 'The last backup failed', 'text' => 'Some files did not reach SharePoint. Open Activity for details, then try again.'];
        }
        $interval = ['daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS, 'monthly' => 31 * DAY_IN_SECONDS][$schedule['frequency']] ?? null;
        if ($interval && (!$last || $last['time'] < time() - $interval - 6 * HOUR_IN_SECONDS)) {
            return ['level' => 'critical', 'title' => 'Backups are overdue', 'text' => $ago . ' Your schedule is: ' . lcfirst($schedule['label']) . '.'];
        }
        if (!self::is_automatic()) {
            return ['level' => 'warning', 'title' => 'Automatic backups are off', 'text' => $ago . ' Turn on a schedule so you never have to remember.'];
        }
        if (!$last) {
            return ['level' => 'warning', 'title' => 'Waiting for the first backup', 'text' => 'Your schedule is set. Run a backup now to confirm everything works.'];
        }
        $next = $schedule['next'] ? ' The next one is in ' . human_time_diff($schedule['next']) . '.' : '';
        return ['level' => 'ok', 'title' => 'Your website is backed up', 'text' => $ago . $next];
    }
}
