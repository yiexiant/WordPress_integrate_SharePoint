<?php
namespace SPSync\Auth;

use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class GraphClient
{
    /**
     * Sends an authenticated request to Microsoft Graph API.
     *
     * @param string $endpoint Graph API relative path (e.g. 'sites/root')
     * @param string $method   HTTP Method
     * @param array|null $body Request body
     * @return array|false JSON response data or false on failure.
     */
    public static function request($endpoint, $method = 'GET', $body = null)
    {
        $token = OAuth::get_access_token();
        if (!$token) {
            return false;
        }

        $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');

        $args = [
            'method'    => $method,
            'headers'   => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ],
            'timeout'   => 20,
            'sslverify' => true
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            Logger::error('Graph API Request Network Error: ' . $response->get_error_message(), [
                'endpoint' => $endpoint
            ]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($code < 200 || $code >= 300) {
            $message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            Logger::error('Graph API Error (HTTP ' . $code . '): ' . $message, [
                'endpoint' => $endpoint,
                'response' => $data
            ]);
            return false;
        }

        return $data;
    }

    /**
     * Discover and cache the SharePoint Site ID.
     *
     * @return string|false Site ID or false on failure.
     */
    public static function get_site_id()
    {
        $cached = Settings::get('site_id');
        if (!empty($cached)) {
            return $cached;
        }

        $site_url = Settings::get('site_url');
        if (empty($site_url)) {
            return false;
        }

        // Parse host and path from site_url
        $parsed = wp_parse_url($site_url);
        if (empty($parsed['host']) || empty($parsed['path'])) {
            Logger::error('SharePoint Discovery Failed: Invalid Site URL format.', [ 'site_url' => $site_url ]);
            return false;
        }

        $host = $parsed['host'];
        $path = trim($parsed['path'], '/');

        // Request format: GET /sites/{host}:/{path}
        $endpoint = sprintf('sites/%s:/%s', $host, $path);
        
        $data = self::request($endpoint);
        if (!$data || empty($data['id'])) {
            return false;
        }

        $site_id = $data['id'];
        Settings::set('site_id', $site_id);
        Logger::info('SharePoint Site ID discovered and cached.', [ 'site_id' => $site_id ]);
        
        return $site_id;
    }

    /**
     * Discover and cache the SharePoint Drive (Document Library) ID.
     *
     * @return string|false Drive ID or false on failure.
     */
    public static function get_drive_id()
    {
        $cached = Settings::get('drive_id');
        if (!empty($cached)) {
            return $cached;
        }

        $site_id = self::get_site_id();
        if (!$site_id) {
            return false;
        }

        $doc_lib = Settings::get('document_library');
        if (empty($doc_lib)) {
            $doc_lib = 'Documents'; // Default standard library name in SharePoint
        }

        // Retrieve drives listed under the discovered site
        $endpoint = sprintf('sites/%s/drives', $site_id);
        $data = self::request($endpoint);
        
        if (!$data || empty($data['value'])) {
            return false;
        }

        foreach ($data['value'] as $drive) {
            if (strcasecmp($drive['name'], $doc_lib) === 0) {
                $drive_id = $drive['id'];
                Settings::set('drive_id', $drive_id);
                Logger::info('SharePoint Drive ID discovered and cached.', [
                    'library'  => $doc_lib,
                    'drive_id' => $drive_id
                ]);
                return $drive_id;
            }
        }

        Logger::error('SharePoint Discovery Failed: Document library not found.', [ 'library' => $doc_lib ]);
        return false;
    }

    /**
     * Tests SharePoint connection and configuration validity.
     *
     * @return array Status report contains 'success' and 'message'.
     */
    public static function test_connection()
    {
        // 1. Force refresh authentication token
        $token = OAuth::refresh_access_token();
        if (!$token) {
            return [
                'success' => false,
                'message' => 'Authentication failed. Please verify Tenant ID, Client ID, and Client Secret.'
            ];
        }

        // Clear existing cache to guarantee a full network test
        Settings::delete('site_id');
        Settings::delete('drive_id');

        // 2. Discover SharePoint Site
        $site_id = self::get_site_id();
        if (!$site_id) {
            return [
                'success' => false,
                'message' => 'Could not resolve SharePoint Site ID. Verify the Site URL.'
            ];
        }

        // 3. Discover Drive ID
        $drive_id = self::get_drive_id();
        if (!$drive_id) {
            return [
                'success' => false,
                'message' => 'Could not locate Document Library. Verify the Document Library name.'
            ];
        }

        return [
            'success' => true,
            'message' => 'Connection verified successfully! Authenticated with Microsoft Entra ID and reached SharePoint library.'
        ];
    }
}
