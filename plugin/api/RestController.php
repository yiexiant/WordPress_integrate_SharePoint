<?php
namespace SPSync\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use SPSync\Helpers\Settings;
use SPSync\Auth\GraphClient;
use SPSync\Storage\RemoteInventory;

if (!defined('ABSPATH')) {
    exit;
}

class RestController extends WP_REST_Controller
{
    const NAMESPACE = 'spsync/v1';

    /**
     * Initialize REST routes setup.
     */
    public static function init()
    {
        add_action('rest_api_init', [ __CLASS__, 'register_api_routes' ]);
    }

    /**
     * Registers routes for target REST endpoints.
     */
    public static function register_api_routes()
    {
        $controller = new self();

        register_rest_route(self::NAMESPACE, '/health', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $controller, 'get_health' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);

        register_rest_route(self::NAMESPACE, '/connection/test', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $controller, 'test_connection' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);

        register_rest_route(self::NAMESPACE, '/system/info', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $controller, 'get_system_info' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);

        register_rest_route(self::NAMESPACE, '/backup/status', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $controller, 'get_backup_status' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);

        register_rest_route(self::NAMESPACE, '/backup/start', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $controller, 'start_backup' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);

        register_rest_route(self::NAMESPACE, '/restore/start', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $controller, 'start_restore' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);

        register_rest_route(self::NAMESPACE, '/logs', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $controller, 'get_logs' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);

        register_rest_route(self::NAMESPACE, '/backups/verify', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $controller, 'verify_remote_backups' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);

        register_rest_route(self::NAMESPACE, '/database/search-replace', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $controller, 'search_and_replace_database' ],
                'permission_callback' => [ $controller, 'check_auth' ]
            ]
        ]);
    }

    /**
     * Authorizes incoming API request via checking header API Key.
     */
    public function check_auth($request)
    {
        $header_key = $request->get_header('X-SPSYNC-KEY');
        $stored_key = Settings::get('api_key');

        if (empty($stored_key)) {
            return new \WP_Error(
                'rest_forbidden',
                __('REST API operations are disabled because the Secret API key is uninitialized.', 'schedule-backup-plugin'),
                [ 'status' => 403 ]
            );
        }

        if (empty($header_key) || !hash_equals($stored_key, $header_key)) {
            return new \WP_Error(
                'rest_unauthorized',
                __('Unauthorized API Key.', 'schedule-backup-plugin'),
                [ 'status' => 401 ]
            );
        }

        return true;
    }

    /**
     * Endpoint callback: GET /spsync/v1/health
     */
    public function get_health($request)
    {
        global $wpdb;
        $table_settings = $wpdb->prefix . 'spsync_settings';
        $table_logs = $wpdb->prefix . 'spsync_logs';
        
        $db_status = 'connected';
        $settings_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_settings'");
        $logs_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_logs'");
        
        if (!$settings_exists || !$logs_exists) {
            $db_status = 'missing_tables';
        }

        return new WP_REST_Response([
            'status'     => 'healthy',
            'plugin'     => 'WordPress SharePoint Backup Plugin',
            'version'    => SPSYNC_VERSION,
            'database'   => $db_status,
            'timestamp'  => current_time('mysql', true)
        ], 200);
    }

    /**
     * Endpoint callback: POST /spsync/v1/connection/test
     */
    public function test_connection($request)
    {
        $result = GraphClient::test_connection();
        $status_code = $result['success'] ? 200 : 400;
        return new WP_REST_Response($result, $status_code);
    }

    /**
     * Endpoint callback: GET /spsync/v1/system/info
     */
    public function get_system_info($request)
    {
        global $wp_version, $wpdb;
        
        if (!function_exists('size_format')) {
            $formatting_file = ABSPATH . 'wp-includes/formatting.php';
            if (file_exists($formatting_file)) {
                require_once $formatting_file;
            }
        }

        $memory_limit = ini_get('memory_limit');
        $upload_max   = ini_get('upload_max_filesize');
        
        $free_space = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
        $total_space = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : false;

        // Calculate database size
        $db_size = 0;
        if ($wpdb) {
            $tables_status = $wpdb->get_results("SHOW TABLE STATUS LIKE '" . $wpdb->prefix . "%'", ARRAY_A);
            if (!empty($tables_status) && is_array($tables_status)) {
                foreach ($tables_status as $table_status) {
                    $db_size += (int) ($table_status['Data_length'] ?? 0) + (int) ($table_status['Index_length'] ?? 0);
                }
            }
        }

        $format_bytes = function($bytes) {
            if (!$bytes || $bytes <= 0) return 'unknown';
            if (function_exists('size_format')) return size_format($bytes);
            return round($bytes / (1024 * 1024), 2) . ' MB';
        };

        $db_size_format = $format_bytes($db_size);

        // Retrieve root folder setting
        $sharepoint_folder = \SPSync\Helpers\Settings::get('root_folder', 'WordPress Backups');

        return new WP_REST_Response([
            'wordpress_version' => $wp_version ?? 'Unknown',
            'php_version'       => PHP_VERSION,
            'plugin_version'    => SPSYNC_VERSION,
            'memory_limit'      => $memory_limit,
            'upload_max'        => $upload_max,
            'database_size'     => $db_size_format,
            'sharepoint_folder' => $sharepoint_folder,
            'site_folder'       => RemoteInventory::site_folder_name(),
            'site_url'          => home_url(),
            'disk'              => [
                'free_bytes'  => $free_space,
                'total_bytes' => $total_space,
                'free_format' => $format_bytes($free_space),
                'total_format'=> $format_bytes($total_space),
            ]
        ], 200);
    }

    /**
     * Endpoint callback: GET /spsync/v1/backup/status
     */
    public function get_backup_status($request)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';
        $restore = [
            'restore_job_id' => Settings::get('restore_job_active'),
            'status' => Settings::get('restore_status', 'completed'),
        ];
        $pending = get_option('spsync_api_backup_request');
        if (is_array($pending) && $pending['created_at'] > time() - 600 && !Settings::get('backup_running')) {
            return new WP_REST_Response([
                'backup_id' => $pending['backup_id'], 'status' => 'queued', 'progress' => 0,
                'total_files' => 0, 'uploaded_files' => 0, 'restore' => $restore,
            ], 200);
        }

        // Check if queue table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === null) {
            return new WP_REST_Response([
                'status'  => 'idle',
                'message' => 'Queue database table does not exist.',
                'restore' => $restore,
            ], 200);
        }

        // Get the latest backup job ID active in the queue
        $latest_backup_id = $wpdb->get_var("SELECT backup_id FROM $table ORDER BY id DESC LIMIT 1");
        $failed = get_option('spsync_api_backup_failed');
        if (is_array($failed) && (!$latest_backup_id || strcmp($failed['backup_id'], $latest_backup_id) >= 0)) {
            return new WP_REST_Response([
                'backup_id' => $failed['backup_id'], 'status' => 'failed', 'progress' => 0,
                'total_files' => 0, 'uploaded_files' => 0, 'restore' => $restore,
                'message' => 'The backup worker failed. Check execution logs.',
            ], 200);
        }
        
        if (empty($latest_backup_id)) {
            return new WP_REST_Response([
                'status'  => 'idle',
                'message' => 'No backups have been scheduled or run yet.',
                'restore' => $restore,
            ], 200);
        }

        // Count statuses for this backup ID
        $counts = $wpdb->get_results(
            $wpdb->prepare("SELECT status, COUNT(*) as qty FROM $table WHERE backup_id = %s GROUP BY status", $latest_backup_id),
            ARRAY_A
        );

        $statuses = [
            'queued'    => 0,
            'uploading' => 0,
            'done'      => 0,
            'error'     => 0,
            'missing'   => 0
        ];

        $total_files = 0;
        foreach ($counts as $row) {
            if (isset($statuses[$row['status']])) {
                $statuses[$row['status']] = (int) $row['qty'];
            }
            $total_files += (int) $row['qty'];
        }

        $uploaded_files = $statuses['done'];
        $failed_files   = $statuses['error'];
        $missing_files  = $statuses['missing'];
        $pending_files  = $statuses['queued'] + $statuses['uploading'];

        // Determine current phase status
        if ($missing_files > 0) {
            $status = 'missing';
            $phase  = 'Missing on Remote';
        } elseif ($pending_files > 0) {
            $status = 'uploading';
            $phase  = 'Uploading';
        } elseif ($failed_files > 0) {
            $status = 'failed';
            $phase  = 'Failed';
        } else {
            $status = 'completed';
            $phase  = 'Completed';
        }

        // Compute progress percent
        $progress = ($total_files > 0) ? round(($uploaded_files / $total_files) * 100) : 0;

        $restore_job_id = Settings::get('restore_job_active');
        $restore_status = Settings::get('restore_status', 'completed');

        return new WP_REST_Response([
            'backup_id'      => $latest_backup_id,
            'status'         => $status,
            'progress'       => $progress,
            'current_phase'  => $phase,
            'total_files'    => $total_files,
            'uploaded_files' => $uploaded_files,
            'failed_files'   => $failed_files,
            'pending_files'  => $pending_files,
            'restore'        => [
                'restore_job_id' => $restore_job_id,
                'status'         => $restore_status
            ]
        ], 200);
    }

    /**
     * Endpoint callback: POST /spsync/v1/backup/start
     */
    public function start_backup($request)
    {
        $pending = get_option('spsync_api_backup_request');
        if (is_array($pending) && $pending['created_at'] <= time() - 600) {
            delete_option('spsync_api_backup_request');
        }
        if (Settings::get('backup_running') || get_option('spsync_restore_start_lock') ||
            in_array(Settings::get('restore_status'), ['downloading', 'extracting', 'restoring_database', 'cleanup'], true)) {
            return new \WP_Error('backup_busy', 'Another backup or restoration is already running.', ['status' => 409]);
        }
        $backup_id = 'spsync-backup-' . current_time('Ymd-His');
        if (!add_option('spsync_api_backup_request', ['backup_id' => $backup_id, 'created_at' => time()], '', false)) {
            return new \WP_Error('backup_busy', 'A backup is already queued.', ['status' => 409]);
        }
        
        // Trigger backup locally and enqueue upload in background
        if (function_exists('as_enqueue_async_action')) {
            $scheduled = as_enqueue_async_action(\SPSync\Scheduler\Scheduler::EVENT_HOOK, [$backup_id]);
        } else {
            $scheduled = wp_schedule_single_event(time() + 2, \SPSync\Scheduler\Scheduler::EVENT_HOOK, [$backup_id]);
        }
        if (!$scheduled || is_wp_error($scheduled)) {
            delete_option('spsync_api_backup_request');
            return new \WP_Error('backup_schedule_failed', 'Could not schedule the backup worker.', ['status' => 500]);
        }

        return new WP_REST_Response([
            'success'   => true,
            'backup_id' => $backup_id,
            'message'   => 'Backup job successfully enqueued in the background.'
        ], 202);
    }

    /**
     * Endpoint callback: POST /spsync/v1/restore/start
     */
    public function start_restore($request)
    {
        $backup_id = $request->get_param('backup_id');
        if (!is_string($backup_id) || !preg_match('/\Aspsync-backup-[A-Za-z0-9_-]{1,80}\z/D', $backup_id)) {
            return new \WP_Error('missing_parameter', 'Missing backup_id parameter.', [ 'status' => 400 ]);
        }
        $pending = get_option('spsync_api_backup_request');
        if (Settings::get('backup_running') || get_option('spsync_restore_start_lock') ||
            (is_array($pending) && $pending['created_at'] > time() - 600) ||
            in_array(Settings::get('restore_status'), ['downloading', 'extracting', 'restoring_database', 'cleanup'], true)) {
            return new \WP_Error('restore_busy', 'Another backup or restoration is already queued or running.', ['status' => 409]);
        }

        // Optional: restore another site's backup (migration). Only a sibling folder name is accepted.
        $source = $request->get_param('source_site_folder');
        if ($source !== null && $source !== '') {
            if (!is_string($source) || RemoteInventory::sanitize_folder_name($source) !== $source) {
                return new \WP_Error('invalid_source', 'Invalid source_site_folder parameter.', [ 'status' => 400 ]);
            }
        } else {
            $source = null;
        }

        $restore_job_id = \SPSync\Restore\RestoreManager::start_restore($backup_id, $source);
        if (!$restore_job_id) {
            return new \WP_Error('restore_failed', 'Could not initiate restore job. The backup manifest or files may be missing on SharePoint storage.', [ 'status' => 500 ]);
        }

        return new WP_REST_Response([
            'success'        => true,
            'restore_job_id' => $restore_job_id,
            'message'        => 'Restore job successfully enqueued in the background.'
        ], 202);
    }

    /**
     * Endpoint callback: GET /spsync/v1/logs
     */
    public function get_logs($request)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_logs';
        
        $limit = min((int) $request->get_param('limit'), 100);
        if ($limit <= 0) {
            $limit = 50;
        }

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        return new WP_REST_Response($logs, 200);
    }

    /**
     * Endpoint callback: GET /spsync/v1/backups/verify
     */
    public function verify_remote_backups($request)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';

        if (!GraphClient::get_drive_id() || !GraphClient::get_root_folder_id()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'SharePoint credentials or root folder not configured.'
            ], 400);
        }

        // Only this site's folder (plus its own legacy root-level backups) is inspected.
        $remote = RemoteInventory::backups();
        if ($remote === false) {
            return new \WP_Error('verification_failed', 'SharePoint could not be fully listed. Existing backup statuses were not changed.', ['status' => 502]);
        }

        // Per-backup local view: how many components were uploaded and how many are already flagged missing.
        $local = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== null) {
            $rows = $wpdb->get_results(
                "SELECT backup_id, COUNT(*) AS total, SUM(status = 'done') AS done, SUM(status = 'missing') AS missing FROM $table WHERE backup_id != '' GROUP BY backup_id",
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $local[$row['backup_id']] = ['total' => (int) $row['total'], 'done' => (int) $row['done'], 'missing' => (int) $row['missing']];
            }
        }

        $verified = [];
        $incomplete = [];
        $missing = [];
        $pending = [];
        foreach ($remote as $name => $folder) {
            $expected = isset($local[$name]) ? $local[$name]['total'] : null;
            if ($expected !== null && $folder['child_count'] !== null && $folder['child_count'] < $expected) {
                $incomplete[] = $name;
            } else {
                $verified[] = $name;
            }
        }
        foreach ($local as $backup_id => $counts) {
            // Still uploading (or failed before upload): absence on SharePoint is expected, not "missing".
            if ($counts['done'] + $counts['missing'] < $counts['total']) {
                $pending[] = $backup_id;
                continue;
            }
            if (!isset($remote[$backup_id])) {
                $missing[] = $backup_id;
                $wpdb->update($table, [ 'status' => 'missing' ], [ 'backup_id' => $backup_id, 'status' => 'done' ]);
            } elseif ($counts['missing'] > 0) {
                // The folder reappeared (e.g. restored from the SharePoint recycle bin).
                $wpdb->update($table, [ 'status' => 'done' ], [ 'backup_id' => $backup_id, 'status' => 'missing' ]);
            }
        }

        return new WP_REST_Response([
            'success'            => true,
            'site_folder'        => RemoteInventory::site_folder_name(),
            'verified_backups'   => $verified,
            'incomplete_backups' => $incomplete,
            'missing_backups'    => $missing,
            'pending_backups'    => $pending,
            'remote_backups'     => array_keys($remote),
            'total_remote'       => count($remote)
        ], 200);
    }

    /**
     * Endpoint callback: POST /spsync/v1/database/search-replace
     */
    public function search_and_replace_database($request)
    {
        $old_url = $request->get_param('old_url');
        $new_url = $request->get_param('new_url');
        $dry_run = filter_var($request->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);

        if (empty($old_url) || empty($new_url)) {
            return new \WP_Error('invalid_params', 'Both old_url and new_url are required parameters.', [ 'status' => 400 ]);
        }

        $result = \SPSync\Database\DatabaseMigrator::search_and_replace($old_url, $new_url, $dry_run);

        $status_code = (!empty($result['success'])) ? 200 : 400;
        return new WP_REST_Response($result, $status_code);
    }
}
