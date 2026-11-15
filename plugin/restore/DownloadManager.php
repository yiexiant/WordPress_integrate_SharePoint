<?php
namespace SPSync\Restore;

use SPSync\Auth\GraphClient;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class DownloadManager
{
    /**
     * Downloads a file from SharePoint using Microsoft Graph and streams it directly to disk.
     *
     * @param string $remote_folder_id SharePoint item ID of the backup folder (resolved by RemoteInventory).
     * @param string $filename         The remote filename to download.
     * @param string $destination      The local destination file path.
     * @param string $expected_hash    Expected SHA-256 hash for verification.
     * @return bool True on success, false on failure.
     */
    public static function download_file($remote_folder_id, $filename, $destination, $expected_hash = '')
    {
        Logger::info("Starting download of remote file: $filename");

        $drive_id = GraphClient::get_drive_id();
        if (!$drive_id || empty($remote_folder_id)) {
            Logger::error('Download Failed: SharePoint drive or backup folder is unknown.');
            return false;
        }

        // Retrieve the download link from Microsoft Graph
        $endpoint = sprintf('drives/%s/items/%s:/%s', $drive_id, $remote_folder_id, rawurlencode($filename));
        $data = GraphClient::request($endpoint);

        if (!$data || empty($data['@microsoft.graph.downloadUrl'])) {
            Logger::error("Download Failed: Could not retrieve download URL for '$filename'.");
            return false;
        }

        $download_url = $data['@microsoft.graph.downloadUrl'];

        $dir = dirname($destination);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Request file download using WordPress native HTTP API streaming directly to disk.
        // This parses chunked buffers and prevents memory limit exhaustions.
        $args = [
            'timeout'   => 120, 
            'stream'    => true,
            'filename'  => $destination,
            'sslverify' => true
        ];

        Logger::debug("Streaming file directly to: $destination");
        $response = wp_remote_get($download_url, $args);

        if (is_wp_error($response)) {
            Logger::error("Download Network Error: " . $response->get_error_message(), [ 'file' => $filename ]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            Logger::error("Download Failed: Received HTTP status code $code.", [ 'file' => $filename ]);
            @unlink($destination);
            return false;
        }

        // Validate SHA-256 checksum against manifest hash
        if (!empty($expected_hash)) {
            $actual_hash = hash_file('sha256', $destination);
            if (strcasecmp($actual_hash, $expected_hash) !== 0) {
                Logger::error("Checksum Verification Failed for '$filename'. Expected: $expected_hash, got: $actual_hash");
                @unlink($destination);
                return false;
            }
            Logger::info("Checksum Verification Succeeded for '$filename'.");
        }

        Logger::info("Successfully downloaded and saved: $filename");
        return true;
    }
}
