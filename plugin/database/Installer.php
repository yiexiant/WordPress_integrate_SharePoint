<?php
namespace SPSync\Database;

if (!defined('ABSPATH')) {
    exit;
}

class Installer
{
    public static function init()
    {
        // Placeholders for future database-related hooks (e.g. migration updates)
    }

    /**
     * Run on plugin activation to build/upgrade database schema.
     */
    public static function activate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_settings = $wpdb->prefix . 'spsync_settings';
        $table_logs = $wpdb->prefix . 'spsync_logs';
        $table_items = $wpdb->prefix . 'spsync_items';
        $table_restore = $wpdb->prefix . 'spsync_restore_items';

        // Note: dbDelta requires two spaces after PRIMARY KEY and lowercase table structures.
        $sql_settings = "CREATE TABLE $table_settings (
            option_name varchar(191) NOT NULL,
            option_value longtext NOT NULL,
            PRIMARY KEY  (option_name)
        ) $charset_collate;";

        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level)
        ) $charset_collate;";

        $sql_items = "CREATE TABLE $table_items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            backup_id varchar(50) NOT NULL,
            filename varchar(255) NOT NULL,
            filesize bigint(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            retries int(11) NOT NULL DEFAULT 0,
            checksum varchar(64) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY backup_id (backup_id)
        ) $charset_collate;";

        $sql_restore = "CREATE TABLE $table_restore (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restore_job_id varchar(50) NOT NULL,
            filename varchar(255) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'waiting',
            retries int(11) NOT NULL DEFAULT 0,
            downloaded tinyint(1) NOT NULL DEFAULT 0,
            restored tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY restore_job_id (restore_job_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_settings);
        dbDelta($sql_logs);
        dbDelta($sql_items);
        dbDelta($sql_restore);

        // Seed default configurations and generate initial secure REST API key if not present
        self::seed_default_settings();
    }

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate()
    {
        // Retention of database tables on deactivation is a standard practice
        // to prevent accidental data loss. Deletion should occur on uninstall.php instead.
    }

    /**
     * Seed initial configuration options.
     */
    private static function seed_default_settings()
    {
        global $wpdb;
        $table_settings = $wpdb->prefix . 'spsync_settings';

        $defaults = [
            'api_key'          => self::generate_secure_key(),
            'tenant_id'        => '',
            'client_id'        => '',
            'client_secret'    => '',
            'site_url'         => '',
            'document_library' => '',
            'root_folder'      => 'WordPress Backups',
            'encrypt_secret'   => '1',
            'retention_limit'  => '30',
            'backup_frequency' => 'manual'
        ];

        foreach ($defaults as $name => $val) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_settings WHERE option_name = %s",
                $name
            ));
            if (!$exists) {
                $wpdb->insert($table_settings, [
                    'option_name'  => $name,
                    'option_value' => $val
                ]);
            }
        }
    }

    /**
     * Generates a cryptographically secure random API key.
     */
    private static function generate_secure_key()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(32));
            } catch (\Exception $e) {
                // Fallback
            }
        }
        return wp_generate_password(64, true, true);
    }
}
