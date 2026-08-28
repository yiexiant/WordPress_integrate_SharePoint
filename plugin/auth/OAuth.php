<?php
namespace SPSync\Auth;

use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class OAuth
{
    const TOKEN_TRANSIENT_KEY = 'spsync_ms_graph_token';

    /**
     * Get a valid access token. Checks cache first, retrieves new one if expired.
     *
     * @return string|false Access token or false on failure.
     */
    public static function get_access_token()
    {
        $token = get_transient(self::TOKEN_TRANSIENT_KEY);
        if ($token) {
            return $token;
        }

        return self::refresh_access_token();
    }

    /**
     * Authenticates with Microsoft Entra ID and retrieves a new token.
     *
     * @return string|false Token or false on failure.
     */
    public static function refresh_access_token()
    {
        $tenant_id     = Settings::get('tenant_id');
        $client_id     = Settings::get('client_id');
        $client_secret = Settings::get('client_secret');

        if (empty($tenant_id) || empty($client_id) || empty($client_secret)) {
            Logger::error('OAuth Failed: Configuration is incomplete.', [
                'tenant_id_set'     => !empty($tenant_id),
                'client_id_set'     => !empty($client_id),
                'client_secret_set' => !empty($client_secret)
            ]);
            return false;
        }

        $url = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', $tenant_id);

        $body = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'scope'         => 'https://graph.microsoft.com/.default',
        ];

        Logger::info('Attempting to retrieve Microsoft Entra ID token...');

        $response = wp_remote_post($url, [
            'body'      => $body,
            'timeout'   => 15,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            Logger::error('OAuth Network Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_response = wp_remote_retrieve_body($response);
        $data = json_decode($body_response, true);

        if ($code !== 200) {
            $error_msg = isset($data['error_description']) ? $data['error_description'] : 'Unknown OAuth Error';
            Logger::error('OAuth Authentication Error (HTTP ' . $code . '): ' . $error_msg, [
                'response' => $data
            ]);
            return false;
        }

        if (empty($data['access_token'])) {
            Logger::error('OAuth Response Invalid: access_token not found.', [
                'response' => $data
            ]);
            return false;
        }

        $token = $data['access_token'];
        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;

        // Cache the token. Subtract 5 minutes buffer (typically caches for ~55 minutes)
        $cache_duration = max(60, $expires_in - 300);
        set_transient(self::TOKEN_TRANSIENT_KEY, $token, $cache_duration);

        Logger::info('Microsoft Entra ID token retrieved and cached successfully.', [
            'expires_in_seconds' => $expires_in,
            'cache_duration'     => $cache_duration
        ]);

        return $token;
    }
}
