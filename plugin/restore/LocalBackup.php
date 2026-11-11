<?php
namespace SPSync\Restore;

use SPSync\Helpers\PrivateStorage;

if (!defined('ABSPATH')) {
    exit;
}

/** Import a plugin backup folder without placing uploaded SQL/archives on the web. */
class LocalBackup
{
    public static function upload_zip($file)
    {
        if (!is_array($file) || !isset($file['name'], $file['error'], $file['tmp_name']) ||
            !is_string($file['name']) || !preg_match('/\.zip\z/iD', $file['name']) || $file['error'] !== UPLOAD_ERR_OK ||
            !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new \WP_Error('invalid_zip_upload', 'Choose a complete backup ZIP file. Check the server upload limit if the upload was interrupted.');
        }
        $directory = PrivateStorage::create('import');
        if (is_wp_error($directory)) {
            return $directory;
        }
        $archive = $directory . '/.uploaded-backup.zip';
        if (!move_uploaded_file($file['tmp_name'], $archive)) {
            self::remove($directory);
            return new \WP_Error('zip_upload_failed', 'Could not safely receive the backup ZIP.');
        }
        @chmod($archive, 0600);
        $manifest = self::unpack_archive($archive, $directory);
        @unlink($archive);
        if (is_wp_error($manifest)) {
            self::remove($directory);
            return $manifest;
        }
        return ['directory' => $directory, 'manifest' => $manifest];
    }

    /** Accept a flat package or one backup folder, never an arbitrary website ZIP. */
    public static function unpack_archive($archive, $directory)
    {
        if (!self::is_staging_directory($directory)) {
            return new \WP_Error('invalid_staging', 'Invalid private backup staging directory.');
        }
        $entries = RestoreEngine::archive_entries($archive, null);
        if (is_wp_error($entries)) {
            return $entries;
        }
        if (count($entries) > 10000) {
            return new \WP_Error('zip_entries', 'The backup package contains too many entries.');
        }
        $manifest_roots = [];
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', $entry['filename']);
            if (basename($name) === 'manifest.json' && substr($name, -1) !== '/') {
                $manifest_roots[] = dirname($name);
            }
        }
        if (count($manifest_roots) !== 1) {
            return new \WP_Error('zip_manifest', 'Choose a ZIP containing exactly one plugin backup folder with manifest.json and all its parts.');
        }
        $root = $manifest_roots[0];
        $parts = [];
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', $entry['filename']);
            if (substr($name, -1) === '/') {
                continue;
            }
            if (dirname($name) !== $root) {
                return new \WP_Error('zip_layout', 'The ZIP must contain one backup folder, not multiple backups or website files.');
            }
            $filename = basename($name);
            if (in_array($filename, ['.htaccess', 'index.php', 'web.config'], true)) {
                continue;
            }
            if (!self::allowed_filename($filename) || file_exists($directory . '/' . $filename)) {
                return new \WP_Error('zip_layout', 'The ZIP contains an unexpected or duplicate backup component.');
            }
            if ($filename === 'manifest.json' && $entry['size'] > 1048576) {
                return new \WP_Error('invalid_manifest', 'The backup manifest exceeds the 1 MB limit.');
            }
            $parts[] = $entry;
        }
        if (class_exists('\\ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) {
                return new \WP_Error('invalid_zip', 'The backup ZIP cannot be opened.');
            }
            try {
                foreach ($parts as $part) {
                    $source = $zip->getStream($part['filename']);
                    if (!$source) {
                        return new \WP_Error('zip_read', 'A ZIP component cannot be read. Encrypted ZIP packages are not supported.');
                    }
                    $destination = $directory . '/' . basename(str_replace('\\', '/', $part['filename']));
                    $target = fopen($destination, 'xb');
                    if (!$target) {
                        fclose($source);
                        return new \WP_Error('zip_write', 'Could not create a private backup component.');
                    }
                    // Bound writes even if a corrupted ZIP lies about expanded size.
                    $copied = stream_copy_to_stream($source, $target, (int) $part['size'] + 1);
                    fclose($source);
                    fclose($target);
                    @chmod($destination, 0600);
                    if ($copied !== (int) $part['size']) {
                        return new \WP_Error('zip_write', 'A ZIP component was truncated or could not be written completely.');
                    }
                }
            } finally {
                $zip->close();
            }
        } else {
            $zip = new \PclZip($archive); // archive_entries() loaded WordPress's fallback.
            $list = $zip->extract(PCLZIP_OPT_PATH, $directory, PCLZIP_OPT_REMOVE_ALL_PATH, PCLZIP_OPT_BY_NAME, array_column($parts, 'filename'), PCLZIP_OPT_SET_CHMOD, 0600, PCLZIP_OPT_STOP_ON_ERROR);
            if (!is_array($list) || count($list) !== count($parts)) {
                return new \WP_Error('zip_extract', 'Could not extract every backup component.');
            }
            foreach ($list as $part) {
                if ($part['status'] !== 'ok') {
                    return new \WP_Error('zip_extract', 'A backup component could not be extracted.');
                }
            }
        }
        return self::validate($directory);
    }

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
