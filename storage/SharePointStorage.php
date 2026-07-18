<?php
namespace SPSync\Storage;

use SPSync\Auth\GraphClient;
use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class SharePointStorage
{
    /**
     * Upload a single queue item from wp_spsync_items.
     *
     * @param object $item Queue item object containing id, backup_id, filename, checksum, etc.
     * @return bool True on success, false on failure.
     */
    public static function upload_queue_item($item)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';

        $backup_id = $item->backup_id;
        $filename  = $item->filename;

        // Resolve absolute local filepath
        $upload_dir = wp_upload_dir();
        $filepath = rtrim($upload_dir['basedir'], '/') . '/spsync-backups/' . $backup_id . '/' . $filename;

        if (!file_exists($filepath)) {
            Logger::error("Upload Failed: Local file does not exist.", [
                'backup_id' => $backup_id,
                'filename'  => $filename,
                'path'      => $filepath
            ]);
            $wpdb->update($table, [ 'status' => 'error' ], [ 'id' => $item->id ]);
            return false;
        }

        // Transition status to uploading
        $wpdb->update($table, [ 'status' => 'uploading' ], [ 'id' => $item->id ]);

        // Resolve or create remote subfolder for this backup YYYYMMDD
        $remote_folder_id = self::get_or_create_remote_backup_folder($backup_id);
        if (!$remote_folder_id) {
            Logger::error("Upload Failed: Could not resolve remote backup subfolder ID.", [
                'backup_id' => $backup_id
            ]);
            $wpdb->update($table, [ 'status' => 'error' ], [ 'id' => $item->id ]);
            return false;
        }

        $filesize = filesize($filepath);
        $result = false;

        // Use chunked upload session for files 4MB or larger
        $four_mb = 4 * 1024 * 1024;
        
        if ($filesize < $four_mb) {
            $result = self::upload_small_file($filepath, $remote_folder_id);
        } else {
            $result = self::upload_large_file($filepath, $remote_folder_id);
        }

        if ($result && !empty($result['id'])) {
            // Successfully uploaded, record details and transition status
            $wpdb->update(
                $table,
                [
                    'status'  => 'done',
                    'retries' => 0
                ],
                [ 'id' => $item->id ]
            );
            Logger::info("Successfully uploaded file to SharePoint.", [
                'backup_id' => $backup_id,
                'filename'  => $filename,
                'web_url'   => isset($result['webUrl']) ? $result['webUrl'] : ''
            ]);
            return true;
        } else {
            // Failed upload attempt
            $retries = (int) $item->retries + 1;
            $new_status = ($retries >= 3) ? 'error' : 'queued';
            
            $wpdb->update(
                $table,
                [
                    'status'  => $new_status,
                    'retries' => $retries
                ],
                [ 'id' => $item->id ]
            );
            
            Logger::error("Upload attempt failed for queue item.", [
                'backup_id' => $backup_id,
                'filename'  => $filename,
                'attempt'   => $retries,
                'next_state'=> $new_status
            ]);
            return false;
        }
    }

    /**
     * Resolves or creates a subfolder matching backup_id on SharePoint.
     *
     * @param string $backup_id Backup identifier.
     * @return string|false Folder ID or false on failure.
     */
    public static function get_or_create_remote_backup_folder($backup_id)
    {
        $transient_key = 'spsync_remote_folder_id_' . $backup_id;
        $cached_id = get_transient($transient_key);
        if ($cached_id) {
            return $cached_id;
        }

        $drive_id = GraphClient::get_drive_id();
        $root_folder_id = GraphClient::get_root_folder_id();

        if (!$drive_id || !$root_folder_id) {
            return false;
        }

        // Scan root backup folder children to see if subfolder is already present
        $endpoint = sprintf('drives/%s/items/%s/children', $drive_id, $root_folder_id);
        $data = GraphClient::request($endpoint);

        if ($data && !empty($data['value'])) {
            foreach ($data['value'] as $item) {
                if (strcasecmp($item['name'], $backup_id) === 0 && isset($item['folder'])) {
                    set_transient($transient_key, $item['id'], 3600); // Cache for 1 hour
                    return $item['id'];
                }
            }
        }

        // Folder not found; create it
        $body = [
            'name'                              => $backup_id,
            'folder'                            => new \stdClass(),
            '@microsoft.graph.conflictBehavior' => 'replace'
        ];

        $create_data = GraphClient::request($endpoint, 'POST', $body);
        if ($create_data && !empty($create_data['id'])) {
            set_transient($transient_key, $create_data['id'], 3600);
            return $create_data['id'];
        }

        return false;
    }

    /**
     * Uploads files smaller than 4MB using simple PUT request.
     *
     * @param string $filepath  Absolute local file path.
     * @param string $folder_id Remote parent folder ID.
     * @return array|false JSON response on success, false on failure.
     */
    public static function upload_small_file($filepath, $folder_id)
    {
        $drive_id = GraphClient::get_drive_id();
        if (!$drive_id) {
            return false;
        }

        $filename = basename($filepath);
        $endpoint = sprintf('drives/%s/items/%s:/%s:/content', $drive_id, $folder_id, rawurlencode($filename));

        $token = \SPSync\Auth\OAuth::get_access_token();
        if (!$token) {
            return false;
        }

        $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');
        $content = file_get_contents($filepath);

        $args = [
            'method'    => 'PUT',
            'headers'   => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/octet-stream'
            ],
            'body'      => $content,
            'timeout'   => 30,
            'sslverify' => true
        ];

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            Logger::error("Simple Upload Network Error: " . $response->get_error_message(), [ 'file' => $filename ]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $err = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            Logger::error("Simple Upload Failed (HTTP $code): " . $err, [ 'file' => $filename ]);
            return false;
        }

        return $data;
    }

    /**
     * Uploads files larger than 4MB using upload session chunk uploads.
     *
     * @param string $filepath  Absolute local file path.
     * @param string $folder_id Remote parent folder ID.
     * @return array|false JSON response on success, false on failure.
     */
    public static function upload_large_file($filepath, $folder_id)
    {
        $drive_id = GraphClient::get_drive_id();
        if (!$drive_id) {
            return false;
        }

        $filename = basename($filepath);
        
        // 1. Create Upload Session
        $endpoint = sprintf('drives/%s/items/%s:/%s:/createUploadSession', $drive_id, $folder_id, rawurlencode($filename));
        $body = [
            'item' => [
                '@microsoft.graph.conflictBehavior' => 'replace',
                'name'                              => $filename
            ]
        ];

        $session_data = GraphClient::request($endpoint, 'POST', $body);
        if (!$session_data || empty($session_data['uploadUrl'])) {
            Logger::error("Upload Session creation failed.", [ 'file' => $filename ]);
            return false;
        }

        $upload_url = $session_data['uploadUrl'];
        $filesize = filesize($filepath);

        // Chunk size: 10 MB (must be multiple of 327,680 bytes)
        $chunk_size = 10 * 1024 * 1024; // 10,485,760 bytes

        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            Logger::error("Could not read local file for chunk upload.", [ 'file' => $filename ]);
            return false;
        }

        Logger::info("Created SharePoint upload session. Pushing chunks...", [
            'file' => $filename,
            'size' => size_format($filesize)
        ]);

        $offset = 0;
        $success = true;
        $result_data = null;

        while ($offset < $filesize && $success) {
            $bytes_to_read = min($chunk_size, $filesize - $offset);
            $chunk_data = fread($handle, $bytes_to_read);
            
            $start = $offset;
            $end = $offset + $bytes_to_read - 1;
            
            // Try uploading chunk (max 3 retries on network transient issues)
            $chunk_success = false;
            $retries = 0;
            
            while (!$chunk_success && $retries < 3) {
                $args = [
                    'method'    => 'PUT',
                    'headers'   => [
                        'Content-Length' => $bytes_to_read,
                        'Content-Range'  => sprintf('bytes %d-%d/%d', $start, $end, $filesize),
                        'Content-Type'   => 'application/octet-stream'
                    ],
                    'body'      => $chunk_data,
                    'timeout'   => 45,
                    'sslverify' => true
                ];

                $response = wp_remote_request($upload_url, $args);
                
                if (is_wp_error($response)) {
                    $retries++;
                    Logger::warning("Chunk upload network failure (Attempt $retries): " . $response->get_error_message(), [
                        'file' => $filename,
                        'range'=> "$start-$end"
                    ]);
                    sleep(2);
                    continue;
                }

                $code = wp_remote_retrieve_response_code($response);
                $resp_body = wp_remote_retrieve_body($response);
                $resp_data = json_decode($resp_body, true);

                if ($code === 200 || $code === 201) {
                    // Upload completed successfully on the final chunk
                    $chunk_success = true;
                    $result_data = $resp_data;
                } elseif ($code === 202) {
                    // Chunk accepted (session active, expect more chunks)
                    $chunk_success = true;
                } elseif ($code === 429) {
                    // Throttling
                    $retries++;
                    $retry_after = isset($response['headers']['retry-after']) ? (int) $response['headers']['retry-after'] : 5;
                    Logger::warning("Throttled by Microsoft Graph (429). Retrying after $retry_after seconds.", [ 'file' => $filename ]);
                    sleep($retry_after);
                } else {
                    // Unrecoverable chunk writing error
                    Logger::error("Chunk upload failed with HTTP code $code.", [
                        'file'    => $filename,
                        'range'   => "$start-$end",
                        'response'=> $resp_data
                    ]);
                    $success = false;
                    break;
                }
            }

            if (!$chunk_success) {
                Logger::error("Chunk upload failed after maximum retries.", [ 'file' => $filename, 'range' => "$start-$end" ]);
                $success = false;
                break;
            }

            $offset += $bytes_to_read;
        }

        fclose($handle);

        if (!$success) {
            // Delete failed upload session to prune remote temporary space
            wp_remote_request($upload_url, [ 'method' => 'DELETE', 'timeout' => 10 ]);
            return false;
        }

        return $result_data;
    }
}
