<?php
namespace SPSync\Restore;

use SPSync\Helpers\PrivateStorage;

if (!defined('ABSPATH')) {
    exit;
}

/** Import a plugin backup folder without placing uploaded SQL/archives on the web. */
class LocalBackup
{
    public static function upload($files)
    {
        if (empty($files['name']) || !is_array($files['name'])) {
            return new \WP_Error('no_backup', 'Choose a backup folder or its manifest and all backup parts.');
        }
        $directory = PrivateStorage::create('import');
        if (is_wp_error($directory)) {
            return $directory;
        }
        $seen = [];
        foreach ($files['name'] as $index => $name) {
            // These protection files can accompany a downloaded backup folder.
            if (in_array($name, ['.htaccess', 'index.php', 'web.config'], true)) {
                continue;
            }
            if (!is_string($name) || !self::allowed_filename($name) || isset($seen[$name])) {
                self::remove($directory);
                return new \WP_Error('invalid_file', 'The folder must contain one manifest.json, database.sql (if backed up), and the ZIP parts. Duplicate or unexpected files are not allowed.');
            }
            if (!isset($files['error'][$index], $files['tmp_name'][$index]) || $files['error'][$index] !== UPLOAD_ERR_OK) {
                self::remove($directory);
                return new \WP_Error('upload_failed', 'A backup file did not upload completely. Check the server upload size and file-count limits.');
            }
            $destination = $directory . '/' . $name;
            if (!is_uploaded_file($files['tmp_name'][$index]) || !move_uploaded_file($files['tmp_name'][$index], $destination)) {
                self::remove($directory);
                return new \WP_Error('upload_failed', 'Could not safely receive the uploaded backup files.');
            }
            @chmod($destination, 0600);
            $seen[$name] = true;
        }
        $manifest = self::validate($directory);
        if (is_wp_error($manifest)) {
            self::remove($directory);
            return $manifest;
        }
        return ['directory' => $directory, 'manifest' => $manifest];
    }

    public static function allowed_filename($name)
    {
        return is_string($name) && (bool) preg_match('/\A(?:manifest\.json|database\.sql|backup_part[0-9]+\.zip)\z/D', $name);
    }

    public static function validate($directory)
    {
        if (!self::is_staging_directory($directory)) {
            return new \WP_Error('invalid_staging', 'The uploaded backup is no longer available. Upload it again.');
        }
        $path = $directory . '/manifest.json';
        if (!is_file($path) || is_link($path) || filesize($path) > 1048576) {
            return new \WP_Error('invalid_manifest', 'The backup needs a valid manifest.json (maximum 1 MB).');
        }
        $manifest = json_decode(file_get_contents($path), true);
        if (!is_array($manifest) || empty($manifest['backup_id']) || !is_string($manifest['backup_id']) ||
            empty($manifest['parts']) || !is_array($manifest['parts'])) {
            return new \WP_Error('invalid_manifest', 'This is not a supported plugin backup manifest.');
        }
        $seen = [];
        foreach ($manifest['parts'] as $part) {
            if (!is_array($part) || empty($part['filename']) || !self::allowed_filename($part['filename']) ||
                $part['filename'] === 'manifest.json' || isset($seen[$part['filename']]) ||
                !isset($part['filesize'], $part['checksum']) || !is_numeric($part['filesize']) || $part['filesize'] < 0 ||
                !is_string($part['checksum']) || !preg_match('/\A[a-f0-9]{64}\z/iD', $part['checksum'])) {
                return new \WP_Error('invalid_manifest', 'The manifest contains an invalid or duplicate backup part.');
            }
            $file = $directory . '/' . $part['filename'];
            if (!is_file($file) || is_link($file) || filesize($file) != $part['filesize'] ||
                !hash_equals(strtolower($part['checksum']), hash_file('sha256', $file))) {
                return new \WP_Error('invalid_part', 'A backup part is missing, incomplete, or has the wrong checksum: ' . $part['filename']);
            }
            if (substr($part['filename'], -4) === '.zip') {
                $valid = RestoreEngine::validate_archive($file);
                if (is_wp_error($valid)) {
                    return $valid;
                }
            }
            $seen[$part['filename']] = true;
        }
        foreach (glob($directory . '/*') as $file) {
            if (is_file($file) && basename($file) !== 'manifest.json' && !isset($seen[basename($file)])) {
                return new \WP_Error('unexpected_part', 'The upload contains a file not listed in the manifest.');
            }
        }
        return $manifest;
    }

    public static function is_staging_directory($directory)
    {
        return PrivateStorage::valid($directory, 'import');
    }

    public static function remove($directory)
    {
        PrivateStorage::remove($directory, 'import');
    }
}
