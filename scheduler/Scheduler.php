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
        add_action(self::EVENT_HOOK, [ __CLASS__, 'run_scheduled_backup' ]);
    }

    /**
     * Define custom intervals for Weekly and Monthly since WordPress only provides hourly/daily.
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
    public static function run_scheduled_backup()
    {
        Logger::info('Scheduled backup execution triggered by WP Cron.');
        
        $result = BackupEngine::run();
        
        if ($result) {
            Logger::info("Scheduled backup run completed. Backup ID: $result");
        } else {
            Logger::error('Scheduled backup run failed.');
        }
    }

    /**
     * Update WordPress Cron schedule based on settings.
     *
     * @param string $frequency Chosen frequency (manual/hourly/daily/weekly/monthly).
     */
    public static function update_schedule($frequency)
    {
        wp_clear_scheduled_hook(self::EVENT_HOOK);

        if ($frequency === 'manual' || empty($frequency)) {
            Logger::info('Backup schedule set to manual. Background cron schedules cleared.');
            return;
        }

        // Schedule next check event in 5 minutes
        $start_time = time() + 300;
        wp_schedule_event($start_time, $frequency, self::EVENT_HOOK);

        Logger::info("Successfully registered backup schedule recurrence: $frequency", [
            'first_run' => date('Y-m-d H:i:s', $start_time)
        ]);
    }
}
