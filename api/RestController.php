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

        return new WP_REST_Response([
            'wordpress_version' => $wp_version,
            'php_version'       => PHP_VERSION,
            'plugin_version'    => SPSYNC_VERSION,
            'memory_limit'      => $memory_limit,
            'upload_max'        => $upload_max,
            'disk'              => [
                'free_bytes'  => $free_space,
                'total_bytes' => $total_space,
                'free_format' => $free_space ? size_format($free_space) : 'unknown',
                'total_format'=> $total_space ? size_format($total_space) : 'unknown',
            ]
        ], 200);
    }
}
