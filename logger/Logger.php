<?php
namespace SPSync\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Logger
{
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';
    const LEVEL_DEBUG   = 'debug';

    /**
     * Initialize logger hooks and cron events.
     */
    public static function init()
    {
        add_action('spsync_daily_cleanup', [ __CLASS__, 'rotate_logs' ]);
        
        if (!wp_next_scheduled('spsync_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'spsync_daily_cleanup');
        }
    }

    /**
     * Core logging function to record log in custom database table.
     *
     * @param string $level    Log level (info/warning/error/debug)
     * @param string $message  Log message
     * @param array  $context  Optional metadata context
     */
    public static function log($level, $message, $context = [])
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_logs';

        $context_str = null;
        if (!empty($context)) {
            $context_str = wp_json_encode($context);
            if (is_wp_error($context_str)) {
                $context_str = wp_json_encode(['error' => 'Unable to encode context as JSON']);
            }
        }

        $wpdb->insert(
            $table,
            [
                'level'      => sanitize_text_field($level),
                'message'    => sanitize_textarea_field($message),
                'context'    => $context_str,
                'created_at' => current_time('mysql', true) // Store in UTC
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
    }

    public static function info($message, $context = [])
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    public static function warning($message, $context = [])
    {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    public static function error($message, $context = [])
    {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    public static function debug($message, $context = [])
    {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Daily log rotation. Deletes log statements older than 30 days.
     */
    public static function rotate_logs()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_logs';
        
        $days_limit = 30;
        $threshold = gmdate('Y-m-d H:i:s', time() - ($days_limit * DAY_IN_SECONDS));
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE created_at < %s",
                $threshold
            )
        );
        
        self::info('Pruned logs older than 30 days during scheduled cleanup.');
    }
}
