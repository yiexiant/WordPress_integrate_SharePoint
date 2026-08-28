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
     * @param string $backup_id     The remote backup folder/job ID.
     * @param string $filename      The remote filename to download.
     * @param string $destination   The local destination file path.
     * @param string $expected_hash Expected SHA-256 hash for verification.
     * @return bool True on success, false on failure.
     */
    public static function download_file($backup_id, $filename, $destination, $expected_hash = '')
    {
        Logger::info("Starting download of remote file: $filename");

        $drive_id = GraphClient::get_drive_id();
        $root_folder_id = GraphClient::get_root_folder_id();

        if (!$drive_id || !$root_folder_id) {
            Logger::error('Download Failed: SharePoint Drive or Root Folder configuration is missing.');
            return false;
        }

        // Resolve the remote backup subfolder ID
        $remote_folder_id = \SPSync\Storage\SharePointStorage::get_or_create_remote_backup_folder($backup_id);
        if (!$remote_folder_id) {
            Logger::error('Download Failed: Could not resolve remote backup subfolder ID.', [ 'backup_id' => $backup_id ]);
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
