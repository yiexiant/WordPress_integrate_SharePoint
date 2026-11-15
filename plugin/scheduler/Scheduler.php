<?php
namespace SPSync\Scheduler;

use SPSync\Helpers\Settings;
use SPSync\Backup\BackupEngine;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Scheduler
{
    const EVENT_HOOK = 'spsync_scheduled_backup_event';

    /**
     * Initialize scheduler hooks.
     */
    public static function init()
    {
        add_filter('cron_schedules', [ __CLASS__, 'register_cron_intervals' ]);
        add_action(self::EVENT_HOOK, [ __CLASS__, 'run_scheduled_backup' ], 10, 1);
    }

    /**
     * Define custom intervals for Weekly and Monthly since WordPress only provides hourly/daily by default.
     */
    public static function register_cron_intervals($schedules)
    {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once Weekly', 'schedule-backup-plugin')
            ];
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Once Monthly', 'schedule-backup-plugin')
            ];
        }
        return $schedules;
    }

    /**
     * Handler for cron execution. Runs backup process under lock.
     */
    public static function run_scheduled_backup($requested_backup_id = null)
    {
        Logger::info('Scheduled backup execution triggered by WP Cron.');
        
        try {
            $result = BackupEngine::run(false, 'sharepoint', null, $requested_backup_id);
        } catch (\Throwable $error) {
            $result = false;
            Logger::error('Backup worker stopped: ' . $error->getMessage());
        } finally {
            if ($requested_backup_id && empty($result)) {
                update_option('spsync_api_backup_failed', ['backup_id' => $requested_backup_id, 'created_at' => time()], false);
            }
            $pending = get_option('spsync_api_backup_request');
            if (is_array($pending) && $pending['backup_id'] === $requested_backup_id) {
                delete_option('spsync_api_backup_request');
            }
        }
        
        if ($result) {
            Logger::info("Scheduled backup run completed. Backup ID: $result");
        } else {
            Logger::error('Scheduled backup run failed.');
        }

        // If one-time backup was scheduled, set frequency back to manual
        $frequency = Settings::get('backup_frequency', 'manual');
        if ($frequency === 'monthly' && $requested_backup_id === null && !wp_next_scheduled(self::EVENT_HOOK)) {
            self::reschedule_from_settings();
        }
        if ($frequency === 'once' && $requested_backup_id === null) {
            Settings::set('backup_frequency', 'manual');
            Logger::info('One-time scheduled backup completed. Schedule reset to manual.');
        }
        return $result;
    }

    /**
     * Calculate the timestamp for the next run based on user-chosen frequency, time, day, or date.
     *
     * @param string $frequency Chosen frequency (daily, weekly, monthly, once).
     * @param string $time_str Chosen time HH:MM (e.g. 02:30).
     * @param int $day_of_week 0 (Sun) to 6 (Sat) for weekly.
     * @param int $day_of_month 1 to 28 for monthly.
     * @param string $specific_date YYYY-MM-DD for one-time backup.
     * @return int|false Unix timestamp of next run or false on error.
     */
    public static function calculate_next_timestamp($frequency, $time_str = '02:00', $day_of_week = 0, $day_of_month = 1, $specific_date = '')
    {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(get_option('timezone_string') ?: 'UTC');
        $now = new \DateTimeImmutable('now', $timezone);

        $parts = explode(':', $time_str);
        $hour = isset($parts[0]) ? (int) $parts[0] : 2;
        $minute = isset($parts[1]) ? (int) $parts[1] : 0;

        switch ($frequency) {
            case 'daily':
                $target = $now->setTime($hour, $minute, 0);
                if ($target <= $now) {
                    $target = $target->modify('+1 day');
                }
                return $target->getTimestamp();

            case 'weekly':
                $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $target_day = $day_names[(int) $day_of_week] ?? 'Sunday';
                
                $target = $now->modify("this {$target_day}")->setTime($hour, $minute, 0);
                if ($target <= $now) {
                    $target = $now->modify("next {$target_day}")->setTime($hour, $minute, 0);
                }
                return $target->getTimestamp();

            case 'monthly':
                $dom = max(1, min(28, (int) $day_of_month));
                $target = $now->setDate((int) $now->format('Y'), (int) $now->format('m'), $dom)->setTime($hour, $minute, 0);
                if ($target <= $now) {
                    $target = $target->modify('+1 month');
                }
                return $target->getTimestamp();

            case 'once':
                if (!empty($specific_date)) {
                    $target = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $specific_date . ' ' . sprintf('%02d:%02d', $hour, $minute), $timezone);
                    if ($target && $target > $now) {
                        return $target->getTimestamp();
                    }
                }
                // Fallback 1 hour from now
                return time() + 3600;

            default:
                return false;
        }
    }

    /** Re-arm the saved schedule (activation, upgrade, monthly re-arm). */
    public static function reschedule_from_settings()
    {
        self::update_schedule(
            Settings::get('backup_frequency', 'manual'),
            Settings::get('backup_time', '02:00'),
            Settings::get('backup_day_of_week', '0'),
            Settings::get('backup_day_of_month', '1'),
            Settings::get('backup_specific_date', '')
        );
    }

    /**
     * Update WordPress Cron schedule based on settings.
     */
    public static function update_schedule($frequency, $time_str = '02:00', $day_of_week = 0, $day_of_month = 1, $specific_date = '')
    {
        wp_clear_scheduled_hook(self::EVENT_HOOK);

        if ($frequency === 'manual' || empty($frequency)) {
            Logger::info('Backup schedule set to manual. Background cron schedules cleared.');
            return;
        }

        $timestamp = self::calculate_next_timestamp($frequency, $time_str, $day_of_week, $day_of_month, $specific_date);

        if (!$timestamp) {
            Logger::error('Failed to calculate next backup schedule timestamp.');
            return;
        }

        // Monthly runs are single events re-armed after each run, so they stay on the chosen
        // calendar day instead of drifting with a fixed 30-day interval.
        if ($frequency === 'once' || $frequency === 'monthly') {
            wp_schedule_single_event($timestamp, self::EVENT_HOOK);
            $formatted_date = function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s', $timestamp);
            Logger::info(sprintf('%s backup scheduled for %s', $frequency === 'once' ? 'One-time' : 'Monthly', $formatted_date));
        } else {
            $interval = ($frequency === 'daily') ? 'daily' : 'weekly';
            wp_schedule_event($timestamp, $interval, self::EVENT_HOOK);
            $formatted_date = function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s', $timestamp);
            Logger::info(sprintf('Recurring backup (%s) scheduled. Next run at %s', $frequency, $formatted_date));
        }
    }
}
