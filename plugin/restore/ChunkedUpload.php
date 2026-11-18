<?php
namespace SPSync\Restore;

use SPSync\Helpers\PrivateStorage;
use SPSync\Helpers\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Receive a backup of any size in small pieces.
 *
 * A multi-GB backup cannot be sent as one POST: the web server (Apache LimitRequestBody, LiteSpeed,
 * nginx client_max_body_size, ModSecurity, a CDN) answers "413 Request Entity Too Large" before PHP
 * runs, and PHP's upload_max_filesize/post_max_size would reject it anyway. The browser therefore sends
 * consecutive slices; each is appended to a private staging file and the result goes through the
 * same checks as a normal upload. Interrupted uploads resume from the bytes already received.
 *
 * State lives in user meta, so each administrator has at most one upload in progress.
 */
class ChunkedUpload
{
    const META = 'spsync_chunked_upload';
    const ARCHIVE = '.uploaded-backup.zip';
    const MARKER = '.chunked-upload';
    const MAX_AGE = 172800; // Unfinished uploads are kept two days for resuming.
    const PROTECTION_FILES = ['.htaccess', 'index.php', 'web.config'];

    /** Bytes the browser should send per request: below PHP's limits and common proxy/WAF body limits. */
    public static function chunk_bytes()
    {
        $php_limit = min(self::ini_bytes(ini_get('upload_max_filesize')), self::ini_bytes(ini_get('post_max_size')));
        $bytes = min(8 * 1048576, $php_limit - 65536); // leave room for the multipart envelope
        return (int) max(262144, (int) apply_filters('spsync_restore_chunk_bytes', $bytes));
    }

    /** "64M" -> 67108864; "0"/"-1"/empty mean unlimited. */
    public static function ini_bytes($value)
    {
        $value = trim((string) $value);
        if ($value === '' || (int) $value <= 0) {
            return PHP_INT_MAX;
        }
        $number = (float) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }
        return (int) $number;
    }

    /**
     * Start an upload, or resume the same one (same mode, file names and sizes).
     *
     * @param string $mode  'zip' (one backup ZIP) or 'files' (a backup folder / its individual files).
     * @param array  $files [['name' => string, 'size' => int], ...]
     * @return array|\WP_Error ['chunk_bytes' => int, 'received' => [name => bytes], 'resumed' => bool]
     */
    public static function begin($user_id, $mode, $files)
    {
        self::cleanup_abandoned($user_id);
        if (in_array(Settings::get('restore_status'), ['downloading', 'extracting', 'restoring_database', 'cleanup'], true)) {
            return new \WP_Error('restore_busy', 'A restore is already running. Wait for it to finish.');
        }
        $wanted = self::normalize_files($mode, $files);
        if (is_wp_error($wanted)) {
            return $wanted;
        }

        $state = self::state($user_id);
        if ($state && $state['phase'] === 'uploading' && $state['mode'] === $mode && $state['files'] === $wanted &&
            PrivateStorage::valid($state['directory'], 'import') && $state['started'] > time() - self::MAX_AGE) {
            return ['chunk_bytes' => self::chunk_bytes(), 'received' => self::received($state), 'resumed' => true];
        }
        self::discard($user_id);

        $total = array_sum($wanted);
        $directory = PrivateStorage::create('import');
        if (is_wp_error($directory)) {
            return $directory;
        }
        // A ZIP is unpacked next to itself, so it briefly needs twice its size.
        $needed = $total * ($mode === 'zip' ? 2 : 1) + 268435456;
        $free = @disk_free_space($directory);
        if ($free !== false && $free < $needed) {
            PrivateStorage::remove($directory, 'import');
            return new \WP_Error('disk_space', sprintf(
                'Not enough free space in the server\'s temporary folder: %s is needed to receive and check this backup, but only %s is free. Ask your host for more space, or point the plugin to a larger folder outside the website with the spsync_restore_temp_dir filter.',
                size_format($needed),
                size_format($free)
            ));
        }
        file_put_contents($directory . '/' . self::MARKER, (string) $user_id);
        self::save($user_id, [
            'directory' => $directory,
            'mode'      => $mode,
            'files'     => $wanted,
            'started'   => time(),
            'phase'     => 'uploading',
            'message'   => '',
        ]);
        return ['chunk_bytes' => self::chunk_bytes(), 'received' => array_fill_keys(array_keys($wanted), 0), 'resumed' => false];
    }

    /**
     * Append one slice. $offset must be where the file currently ends; a slice that was already
     * stored (its reply got lost and the browser sent it again) is accepted without writing twice.
     *
     * @param string $source Path of the received slice (the handler checks it is a real HTTP upload).
     * @return int|\WP_Error Bytes of this file received so far.
     */
    public static function append($user_id, $name, $offset, $source)
    {
        $state = self::state($user_id);
        if (!$state || $state['phase'] !== 'uploading' || !PrivateStorage::valid($state['directory'], 'import')) {
            return new \WP_Error('no_upload', 'This upload is no longer active. Choose the file again to continue.');
        }
        if (!is_string($name) || !isset($state['files'][$name])) {
            return new \WP_Error('unknown_file', 'This file is not part of the upload.');
        }
        $target = self::target($state, $name);
        clearstatcache(true, $target);
        $current = is_file($target) ? (int) filesize($target) : 0;
        $length = is_string($source) && is_file($source) ? (int) filesize($source) : 0;
        $offset = (int) $offset;
        if ($length <= 0 || $offset < 0 || $offset + $length > $state['files'][$name]) {
            return new \WP_Error('bad_chunk', 'A piece of the upload was empty or larger than the file.');
        }
        if ($offset + $length <= $current) {
            return $current; // duplicate of a slice already stored
        }
        if ($offset !== $current) {
            return new \WP_Error('resync', 'The upload got out of step; resuming from the last stored byte.');
        }

        $in = fopen($source, 'rb');
        $out = fopen($target, 'ab');
        if (!$in || !$out) {
            if ($in) { fclose($in); }
            if ($out) { fclose($out); }
            return new \WP_Error('write_failed', 'Could not store the uploaded piece. The server may be out of disk space.');
        }
        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        if ($copied !== $length) {
            // Never leave a half-written slice: cut the file back to where it was.
            $handle = fopen($target, 'r+b');
            if ($handle) {
                ftruncate($handle, $current);
                fclose($handle);
            }
            return new \WP_Error('write_failed', 'Could not store the uploaded piece completely. The server may be out of disk space.');
        }
        @chmod($target, 0600);
        @touch($state['directory'] . '/' . self::MARKER);
        return $current + $length;
    }

    /**
     * All bytes arrived: unpack/verify exactly like a normal upload.
     *
     * @return array|\WP_Error ['directory' => string, 'manifest' => array]
     */
    public static function finish($user_id)
    {
        $state = self::state($user_id);
        if (!$state || $state['phase'] !== 'uploading' || !PrivateStorage::valid($state['directory'], 'import')) {
            return new \WP_Error('no_upload', 'This upload is no longer active. Choose the file again.');
        }
        foreach (self::received($state) as $name => $bytes) {
            if ($bytes !== $state['files'][$name]) {
                return new \WP_Error('incomplete', 'The upload is not complete yet: ' . $name);
            }
        }
        $state['phase'] = 'verifying';
        self::save($user_id, $state);

        $directory = $state['directory'];
        @unlink($directory . '/' . self::MARKER);
        if ($state['mode'] === 'zip') {
            $archive = $directory . '/' . self::ARCHIVE;
            $manifest = LocalBackup::unpack_archive($archive, $directory);
            @unlink($archive);
        } else {
            $manifest = LocalBackup::validate($directory);
        }
        if (is_wp_error($manifest)) {
            LocalBackup::remove($directory);
            self::save($user_id, ['phase' => 'failed', 'message' => $manifest->get_error_message(), 'directory' => ''] + $state);
            return $manifest;
        }
        self::save($user_id, ['phase' => 'verified', 'directory' => ''] + $state);
        return ['directory' => $directory, 'manifest' => $manifest];
    }

    /** Record the outcome shown to a browser that lost the reply to "finish" and is polling. */
    public static function complete($user_id, $success, $message, $redirect)
    {
        $state = self::state($user_id) ?: ['files' => [], 'mode' => '', 'started' => time(), 'directory' => ''];
        self::save($user_id, ['phase' => $success ? 'done' : 'failed', 'message' => $message, 'redirect' => $redirect, 'directory' => ''] + $state);
    }

    /** @return array Phase (uploading|verifying|verified|done|failed|none), message and redirect. */
    public static function status($user_id)
    {
        $state = self::state($user_id);
        if (!$state) {
            return ['phase' => 'none', 'message' => '', 'redirect' => ''];
        }
        return ['phase' => $state['phase'], 'message' => (string) ($state['message'] ?? ''), 'redirect' => (string) ($state['redirect'] ?? '')];
    }

    /** Stop and delete an unfinished upload. */
    public static function discard($user_id)
    {
        $state = self::state($user_id);
        if ($state && $state['directory'] !== '' && $state['phase'] === 'uploading') {
            PrivateStorage::remove($state['directory'], 'import');
        }
        delete_user_meta($user_id, self::META);
    }

    /** Delete unfinished uploads nobody touched for two days (each holds up to several GB). */
    public static function cleanup_abandoned($user_id = 0)
    {
        $active = ($state = self::state($user_id)) ? realpath($state['directory']) : false;
        foreach (PrivateStorage::directories('import') as $directory) {
            $marker = $directory . '/' . self::MARKER;
            if (is_file($marker) && realpath($directory) !== $active && filemtime($marker) < time() - self::MAX_AGE) {
                PrivateStorage::remove($directory, 'import');
            }
        }
    }

    private static function normalize_files($mode, $files)
    {
        if (!in_array($mode, ['zip', 'files'], true) || !is_array($files) || !$files || count($files) > 1000) {
            return new \WP_Error('invalid_upload', 'Choose one backup ZIP, or a backup folder or its files.');
        }
        $wanted = [];
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['name'], $file['size']) || !is_string($file['name']) ||
                !is_numeric($file['size']) || (int) $file['size'] < 0 || isset($wanted[$file['name']])) {
                return new \WP_Error('invalid_upload', 'The list of files to upload is not valid.');
            }
            $wanted[$file['name']] = (int) $file['size'];
        }
        if ($mode === 'zip') {
            $name = key($wanted);
            if (count($wanted) !== 1 || !preg_match('/\.zip\z/iD', $name) || $wanted[$name] <= 0) {
                return new \WP_Error('invalid_zip_upload', 'Choose a backup ZIP file.');
            }
        } else {
            foreach (array_keys($wanted) as $name) {
                if (!LocalBackup::allowed_filename($name)) {
                    return new \WP_Error('invalid_file', 'The folder must contain only manifest.json, database.sql (if backed up) and the backup_part ZIP files: ' . $name);
                }
            }
            if (!isset($wanted['manifest.json'])) {
                return new \WP_Error('invalid_manifest', 'The backup folder needs its manifest.json.');
            }
        }
        $limit = (int) apply_filters('spsync_restore_max_upload_bytes', 200 * 1073741824);
        if (array_sum($wanted) > $limit) {
            return new \WP_Error('too_large', 'This backup is larger than the allowed ' . size_format($limit) . '.');
        }
        return $wanted;
    }

    private static function target($state, $name)
    {
        return $state['directory'] . '/' . ($state['mode'] === 'zip' ? self::ARCHIVE : $name);
    }

    private static function received($state)
    {
        $received = [];
        foreach (array_keys($state['files']) as $name) {
            $path = self::target($state, $name);
            clearstatcache(true, $path);
            $received[$name] = is_file($path) ? (int) filesize($path) : 0;
        }
        return $received;
    }

    private static function state($user_id)
    {
        $state = get_user_meta($user_id, self::META, true);
        return is_array($state) && isset($state['phase'], $state['directory'], $state['files'], $state['mode']) ? $state : null;
    }

    private static function save($user_id, $state)
    {
        update_user_meta($user_id, self::META, $state);
    }
}
