<?php
namespace SPSync\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use SPSync\Helpers\Settings;
use SPSync\Auth\GraphClient;

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
        global $wp_version;
        
        $memory_limit = ini_get('memory_limit');
        $upload_max   = ini_get('upload_max_filesize');
        
        $free_space = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
        $total_space = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : false;

        // Calculate database size
        $db_size = 0;
        $tables_status = $wpdb->get_results("SHOW TABLE STATUS LIKE '" . $wpdb->prefix . "%'", ARRAY_A);
        if (!empty($tables_status)) {
            foreach ($tables_status as $table_status) {
                $db_size += (int) ($table_status['Data_length'] ?? 0) + (int) ($table_status['Index_length'] ?? 0);
            }
        }
        $db_size_format = $db_size > 0 ? size_format($db_size) : 'unknown';

        // Retrieve root folder setting
        $sharepoint_folder = \SPSync\Helpers\Settings::get('root_folder', 'WordPress Backups');

        return new WP_REST_Response([
            'wordpress_version' => $wp_version,
            'php_version'       => PHP_VERSION,
            'plugin_version'    => SPSYNC_VERSION,
            'memory_limit'      => $memory_limit,
            'upload_max'        => $upload_max,
            'database_size'     => $db_size_format,
            'sharepoint_folder' => $sharepoint_folder,
            'disk'              => [
                'free_bytes'  => $free_space,
                'total_bytes' => $total_space,
                'free_format' => $free_space ? size_format($free_space) : 'unknown',
                'total_format'=> $total_space ? size_format($total_space) : 'unknown',
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

        // Check if queue table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === null) {
            return new WP_REST_Response([
                'status'  => 'idle',
                'message' => 'Queue database table does not exist.'
            ], 200);
        }

        // Get the latest backup job ID active in the queue
        $latest_backup_id = $wpdb->get_var("SELECT backup_id FROM $table ORDER BY id DESC LIMIT 1");
        
        if (empty($latest_backup_id)) {
            return new WP_REST_Response([
                'status'  => 'idle',
                'message' => 'No backups have been scheduled or run yet.'
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
            'error'     => 0
        ];

        $total_files = 0;
        foreach ($counts as $row) {
            $statuses[$row['status']] = (int) $row['qty'];
            $total_files += (int) $row['qty'];
        }

        $uploaded_files = $statuses['done'];
        $failed_files   = $statuses['error'];
        $pending_files  = $statuses['queued'] + $statuses['uploading'];

        // Determine current phase status
        if ($pending_files > 0) {
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

        return new WP_REST_Response([
            'backup_id'      => $latest_backup_id,
            'status'         => $status,
            'progress'       => $progress,
            'current_phase'  => $phase,
            'total_files'    => $total_files,
            'uploaded_files' => $uploaded_files,
            'failed_files'   => $failed_files,
            'pending_files'  => $pending_files
        ], 200);
    }

    /**
     * Endpoint callback: POST /spsync/v1/backup/start
     */
    public function start_backup($request)
    {
        $backup_id = 'spsync-backup-' . current_time('Ymd-His');
        
        // Trigger backup locally and enqueue upload in background
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(\SPSync\Scheduler\Scheduler::EVENT_HOOK);
        } else {
            wp_schedule_single_event(time() + 2, \SPSync\Scheduler\Scheduler::EVENT_HOOK);
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
        if (empty($backup_id)) {
            return new \WP_Error('missing_parameter', 'Missing backup_id parameter.', [ 'status' => 400 ]);
        }

        $restore_job_id = \SPSync\Restore\RestoreManager::start_restore($backup_id);
        if (!$restore_job_id) {
            return new \WP_Error('restore_failed', 'Could not initiate restore job.', [ 'status' => 500 ]);
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
}
