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
    /** Upload ranges must be multiples of 320 KiB. */
    const CHUNK_UNIT = 327680;
    /** 32 x 320 KiB = 10 MiB per request (was 3.2 MiB: three times the requests for the same data). */
    const DEFAULT_CHUNK_UNITS = 32;
    /** 191 x 320 KiB stays below Microsoft's 60 MiB per-request limit. */
    const MAX_CHUNK_UNITS = 191;
    const MAX_CHUNK_RETRIES = 5;

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
        $filepath = \SPSync\Helpers\LocalPaths::backup_dir($backup_id) . '/' . $filename;
        if (!file_exists($filepath)) {
            // Queued before the 1.4.0 private-directory move.
            $filepath = \SPSync\Helpers\LocalPaths::legacy_backup_dir($backup_id) . '/' . $filename;
        }

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
            // Stop between chunks if the backup was cancelled (its queue row is gone).
            $still_wanted = function () use ($wpdb, $table, $item) {
                return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id = %d", $item->id)) > 0;
            };
            $result = self::upload_large_file($filepath, $remote_folder_id, $still_wanted);
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
        $drive_id = GraphClient::get_drive_id();
        $site_folder_id = RemoteInventory::site_folder_id();

        if (!$drive_id || !$site_folder_id) {
            return false;
        }

        // Backups are created inside this site's own folder, never the shared root.
        $transient_key = 'spsync_remote_folder_' . md5($site_folder_id . '|' . $backup_id);
        $cached_id = get_transient($transient_key);
        if ($cached_id) {
            return $cached_id;
        }

        $endpoint = sprintf('drives/%s/items/%s/children', $drive_id, $site_folder_id);
        $children = RemoteInventory::list_children($drive_id, $site_folder_id);
        $existing = $children === false ? false : RemoteInventory::find_folder($children, $backup_id);
        if ($existing) {
            set_transient($transient_key, $existing, 3600); // Cache for 1 hour
            return $existing;
        }

        // Folder not found; create it. 'fail' (not 'replace') so an incomplete listing can never
        // wipe an existing backup folder; on conflict, look the folder up again.
        $body = [
            'name'                              => $backup_id,
            'folder'                            => new \stdClass(),
            '@microsoft.graph.conflictBehavior' => 'fail'
        ];

        $create_data = GraphClient::request($endpoint, 'POST', $body);
        $folder_id = is_array($create_data) && !empty($create_data['id']) ? $create_data['id'] : false;
        if (!$folder_id) {
            $children = RemoteInventory::list_children($drive_id, $site_folder_id);
            $folder_id = $children === false ? false : RemoteInventory::find_folder($children, $backup_id);
        }
        if ($folder_id) {
            set_transient($transient_key, $folder_id, 3600);
        }
        return $folder_id;
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
            'timeout'   => 60,
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
    public static function upload_large_file($filepath, $folder_id, $still_wanted = null)
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
        $chunk_size = self::chunk_size();

        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            Logger::error("Could not read local file for chunk upload.", [ 'file' => $filename ]);
            return false;
        }

        Logger::info("Created SharePoint upload session. Pushing chunks...", [
            'file'  => $filename,
            'size'  => size_format($filesize),
            'chunk' => size_format($chunk_size),
        ]);

        $offset = 0;
        $success = true;
        $result_data = null;

        while ($offset < $filesize && $success) {
            if ($still_wanted && !$still_wanted()) {
                Logger::info('Upload stopped: the backup was cancelled.', ['file' => $filename]);
                $success = false;
                break;
            }
            \SPSync\Helpers\BackupLock::heartbeat();

            $bytes_to_read = min($chunk_size, $filesize - $offset);
            fseek($handle, $offset);
            $chunk_data = fread($handle, $bytes_to_read);
            if ($chunk_data === false || strlen($chunk_data) !== $bytes_to_read) {
                Logger::error('Could not read the next chunk of the local file.', ['file' => $filename, 'offset' => $offset]);
                $success = false;
                break;
            }
            $start = $offset;
            $end = $offset + $bytes_to_read - 1;

            // Network errors, 5xx and throttling are retried with back-off (Microsoft's guidance);
            // other errors are not.
            $attempt = 0;
            while (true) {
                $response = wp_remote_request($upload_url, [
                    'method'    => 'PUT',
                    'headers'   => [
                        'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $filesize),
                        'Content-Type'  => 'application/octet-stream',
                    ],
                    'body'      => $chunk_data,
                    'timeout'   => 180,
                    'sslverify' => true,
                ]);
                $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
                $resp_data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);

                if ($code === 200 || $code === 201) {
                    $result_data = $resp_data;
                    $offset = $filesize;
                    break;
                }
                if ($code === 202) {
                    // Continue where SharePoint says it wants the next bytes.
                    $offset = self::next_expected_offset($resp_data, $end + 1, $filesize);
                    break;
                }
                if ($code === 416) {
                    // This range already arrived (the reply to an earlier try was lost): ask where to resume.
                    $resume = self::next_expected_offset(self::session_status($upload_url), false, $filesize);
                    if ($resume === false) {
                        $success = false;
                    } else {
                        $offset = $resume;
                    }
                    break;
                }
                if (++$attempt > self::MAX_CHUNK_RETRIES || !($code === 0 || $code === 429 || $code >= 500)) {
                    Logger::error('Chunk upload failed.', [
                        'file'     => $filename,
                        'range'    => "$start-$end",
                        'http'     => $code ?: $response->get_error_message(),
                        'response' => $resp_data,
                    ]);
                    $success = false;
                    break;
                }
                $retry_after = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_header($response, 'retry-after');
                $wait = $retry_after > 0 ? min($retry_after, 120) : min(2 ** $attempt, 30);
                Logger::warning("Chunk upload interrupted (attempt $attempt); retrying in {$wait}s.", [
                    'file'  => $filename,
                    'range' => "$start-$end",
                    'http'  => $code ?: $response->get_error_message(),
                ]);
                sleep($wait);
            }
        }

        fclose($handle);

        if (!$success) {
            // Delete failed upload session to prune remote temporary space
            wp_remote_request($upload_url, [ 'method' => 'DELETE', 'timeout' => 10 ]);
            return false;
        }

        return $result_data;
    }

    /**
     * Bytes per upload request: 10 MiB by default, Microsoft's recommended size for stable connections.
     * Always a multiple of 320 KiB and below the 60 MiB per-request limit (filter: spsync_upload_chunk_bytes).
     */
    public static function chunk_size()
    {
        $bytes = (int) apply_filters('spsync_upload_chunk_bytes', self::DEFAULT_CHUNK_UNITS * self::CHUNK_UNIT);
        $units = max(1, min(self::MAX_CHUNK_UNITS, intdiv($bytes, self::CHUNK_UNIT)));
        return $units * self::CHUNK_UNIT;
    }

    /**
     * Start of the first range SharePoint still expects ("nextExpectedRanges": ["26-"]).
     *
     * @param array|null $data     Upload session response.
     * @param int|false  $fallback Value when the response names no usable range.
     * @return int|false
     */
    public static function next_expected_offset($data, $fallback, $filesize)
    {
        if (is_array($data) && !empty($data['nextExpectedRanges'][0]) && preg_match('/\A(\d+)-/', (string) $data['nextExpectedRanges'][0], $m)) {
            $start = (int) $m[1];
            if ($start >= 0 && $start < $filesize) {
                return $start;
            }
        }
        return $fallback;
    }

    /** @return array|null The upload session's current status (which ranges are still missing). */
    private static function session_status($upload_url)
    {
        $response = wp_remote_get($upload_url, ['timeout' => 30, 'sslverify' => true]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        return json_decode(wp_remote_retrieve_body($response), true);
    }
}
