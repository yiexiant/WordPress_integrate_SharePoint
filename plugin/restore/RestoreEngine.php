<?php
namespace SPSync\Restore;

use SPSync\Helpers\Settings;
use SPSync\Backup\DatabaseBackup;
use SPSync\Logger\Logger;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

class RestoreEngine
{
    /** Validate paths, symlinks and expanded size before an archive can write files. */
    public static function archive_entries($zip_file)
    {
        $entries = [];
        if (class_exists('\\ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zip_file) !== true) {
                return new \WP_Error('invalid_zip', 'A backup ZIP part cannot be opened.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $opsys = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if ($type && !in_array($type, [0100000, 0040000], true)) {
                    $zip->close();
                    return new \WP_Error('unsafe_zip', 'Backup archives cannot contain symlinks or special files.');
                }
                if (!$stat) {
                    $zip->close();
                    return new \WP_Error('invalid_zip', 'A backup ZIP entry cannot be read.');
                }
                $entries[] = ['filename' => $stat['name'], 'size' => $stat['size']];
            }
            $zip->close();
        } else {
            if (!class_exists('PclZip')) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            $archive = new \PclZip($zip_file);
            $list = $archive->listContent();
            if (!is_array($list)) {
                return new \WP_Error('invalid_zip', 'A backup ZIP part cannot be read.');
            }
            $entries = $list;
        }
        $total = 0;
        $seen = [];
        $limit = (float) apply_filters('spsync_restore_max_expanded_bytes', 2 * 1024 * 1024 * 1024);
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', $entry['filename']);
            $segments = explode('/', rtrim($name, '/'));
            if (!$name || preg_match('/[\x00-\x1f\x7f:]/', $name) || $name[0] === '/' ||
                array_intersect($segments, ['..', '.', '', 'spsync-backups']) || isset($seen[strtolower($name)])) {
                return new \WP_Error('unsafe_zip', 'A backup ZIP contains an unsafe or duplicate file path.');
            }
            $seen[strtolower($name)] = true;
            $target = rtrim(ABSPATH, '/\\');
            foreach ($segments as $segment) {
                if (preg_match('/[. ]\z|\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/i', $segment)) {
                    return new \WP_Error('unsafe_zip', 'A backup ZIP contains a path that cannot be restored safely on supported servers.');
                }
                $target .= '/' . $segment;
                if (is_link($target)) {
                    return new \WP_Error('unsafe_zip', 'A restore destination contains a symbolic link.');
                }
            }
            $total += (float) $entry['size'];
            if ($total > $limit) {
                return new \WP_Error('zip_size', 'A backup part exceeds the allowed expanded archive size.');
            }
        }
        return $entries;
    }

    public static function validate_archive($zip_file)
    {
        $entries = self::archive_entries($zip_file);
        return is_wp_error($entries) ? $entries : true;
    }

    /**
     * Extracts target ZIP backup parts back into WordPress directory.
     *
     * @param array $zip_files List of absolute paths of ZIP files to extract.
     * @return bool True on success, false on failure.
     */
    public static function restore_files($zip_files)
    {
        Logger::info('Starting file restoration from archives...');

        if (empty($zip_files)) {
            Logger::error('File Restore Failed: No ZIP archives provided.');
            return false;
        }

        $dest_dir = ABSPATH;

        // Check every part before overwriting any files.
        foreach ($zip_files as $zip_file) {
            $valid = self::validate_archive($zip_file);
            if (is_wp_error($valid)) {
                Logger::error($valid->get_error_message());
                return false;
            }
        }

        foreach ($zip_files as $zip_file) {
            if (!file_exists($zip_file)) {
                Logger::error("File Restore Failed: Archive part does not exist.", [ 'path' => $zip_file ]);
                return false;
            }

            Logger::info("Extracting archive part: " . basename($zip_file));

            if (class_exists('\ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open($zip_file) === true) {
                    if ($zip->extractTo($dest_dir) === false) {
                        Logger::error("Extraction failed for archive: " . basename($zip_file));
                        $zip->close();
                        return false;
                    }
                    $zip->close();
                } else {
                    Logger::error("Could not open ZIP file for extraction: " . basename($zip_file));
                    return false;
                }
            } else {
                if (!class_exists('PclZip')) {
                    $pclzip_file = ABSPATH . 'wp-admin/includes/class-pclzip.php';
                    if (file_exists($pclzip_file)) {
                        require_once $pclzip_file;
                    }
                }
                $archive = new \PclZip($zip_file);
                if ($archive->extract(PCLZIP_OPT_PATH, $dest_dir) == 0) {
                    Logger::error("Extraction failed (PclZip) for archive: " . basename($zip_file));
                    return false;
                }
            }
        }

        Logger::info('All backup file archives extracted and restored successfully.');
        return true;
    }

    /**
     * Executes the SQL statements from the database backup dump in batches.
     *
     * @param string $sql_filepath Absolute path to the database.sql file.
     * @return bool|\WP_Error True when complete, false for another batch, or a failure.
     */
    public static function restore_database($sql_filepath)
    {
        global $wpdb;

        Logger::info('Starting database SQL execution...');

        if (!file_exists($sql_filepath)) {
            Logger::error("Database Restore Failed: SQL dump file does not exist.", [ 'path' => $sql_filepath ]);
            return new \WP_Error('missing_sql', 'The database backup file is missing.');
        }

        $handle = fopen($sql_filepath, 'r');
        if (!$handle) {
            Logger::error("Database Restore Failed: Could not open SQL file for reading.");
            return new \WP_Error('unreadable_sql', 'The database backup file cannot be read.');
        }

        // Check if there is a saved seek offset for recovery
        $offset = (int) Settings::get('restore_db_offset', '0');
        if ($offset > 0) {
            Logger::info("Resuming database restore from file offset: $offset bytes");
            fseek($handle, $offset);
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

        $query = '';
        $stmt_count = 0;
        $batch_limit = 500;
        $completed = true;

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            
            // Skip comments and empty lines
            if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }
            if (strpos($trimmed, '/*') === 0 && substr($trimmed, -2) === '*/') {
                continue;
            }

            $query .= $line;

            // Execute query if the line ends with a semicolon
            if (substr($trimmed, -1) === ';') {
                // Legacy backups include the tables controlling this running restore.
                $control_tables = ['spsync_settings', 'spsync_items', 'spsync_restore_items', 'spsync_logs'];
                $skip = false;
                foreach ($control_tables as $suffix) {
                    if (preg_match('/\A\s*(?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO)\s+`' . preg_quote($wpdb->prefix . $suffix, '/') . '`/i', $query)) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip && $wpdb->query($query) === false) {
                    fclose($handle);
                    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
                    return new \WP_Error('sql_failed', 'Database restoration stopped because an SQL statement failed. Check the execution logs.');
                }
                $query = '';
                $stmt_count++;

                // Yield and checkpoint after 500 statements to prevent execution timeouts
                if ($stmt_count >= $batch_limit) {
                    $current_offset = ftell($handle);
                    Settings::set('restore_db_offset', (string) $current_offset);
                    Logger::info(sprintf('Executed database batch of %d queries. Saved checkpoint offset: %d', $stmt_count, $current_offset));
                    $completed = false;
                    break;
                }
            }
        }

        fclose($handle);
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

        if ($completed && trim($query) !== '') {
            return new \WP_Error('incomplete_sql', 'The database backup ends with an incomplete SQL statement.');
        }

        if ($completed) {
            Settings::delete('restore_db_offset');
            Logger::info('Database SQL restoration completed successfully.');
        }

        return $completed;
    }

    /**
     * Creates a local fallback recovery checkpoint of the current site state.
     *
     * @param string $rollback_dir Local folder to write rollback files.
     * @return bool True on success, false on failure.
     */
    public static function create_rollback_snapshot($rollback_dir, $selected_files = null, $include_database = true)
    {
        Logger::info('Creating site rollback recovery snapshot...');

        if (!file_exists($rollback_dir)) {
            wp_mkdir_p($rollback_dir);
        }

        $sql_file = rtrim($rollback_dir, '/') . '/rollback_database.sql';
        
        // 1. Dump current WordPress Database
        $db_success = !$include_database || DatabaseBackup::export($sql_file);
        if (!$db_success) {
            Logger::error('Rollback Snapshot Failed: Could not dump database.');
            return false;
        }

        // 2. Package core folders (wp-content themes/plugins only to save storage space and time)
        $files_to_backup = [];
        if ($selected_files !== null) {
            $files_to_backup = array_values(array_filter($selected_files, 'is_file'));
        } else {
            $target_dirs = [
                ABSPATH . 'wp-content/themes',
                ABSPATH . 'wp-content/plugins'
            ];
        
            foreach ($target_dirs as $dir) {
                if (file_exists($dir)) {
                    $dir_iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
                    $iterator = new \RecursiveIteratorIterator($dir_iterator, \RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($iterator as $file_info) {
                        if ($file_info->isFile()) {
                            $files_to_backup[] = $file_info->getRealPath();
                        }
                    }
                }
            }

            if (file_exists(ABSPATH . 'wp-config.php')) {
                $files_to_backup[] = ABSPATH . 'wp-config.php';
            }
            if (file_exists(ABSPATH . '.htaccess')) {
                $files_to_backup[] = ABSPATH . '.htaccess';
            }
        }

        if (!$files_to_backup) {
            return true;
        }

        $zip_file = rtrim($rollback_dir, '/') . '/rollback_files.zip';
        if (class_exists('\ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $root_path = str_replace('\\', '/', ABSPATH);
                foreach ($files_to_backup as $file) {
                    $normalized = str_replace('\\', '/', $file);
                    $relative = ltrim(substr($normalized, strlen($root_path)), '/');
                    if (!$zip->addFile($file, $relative)) {
                        $zip->close();
                        return false;
                    }
                }
                if (!$zip->close()) {
                    return false;
                }
                Logger::info('Rollback Snapshot files packaged successfully.');
            } else {
                Logger::error('Rollback Snapshot Failed: Could not open rollback zip.');
                return false;
            }
        } else {
            if (!class_exists('PclZip')) {
                $pclzip_file = ABSPATH . 'wp-admin/includes/class-pclzip.php';
                if (file_exists($pclzip_file)) {
                    require_once $pclzip_file;
                }
            }
            if (class_exists('PclZip')) {
                $archive = new \PclZip($zip_file);
                $v_list = $archive->create($files_to_backup, PCLZIP_OPT_REMOVE_PATH, ABSPATH);
                if ($v_list != 0) {
                    Logger::info('Rollback Snapshot files packaged successfully (PclZip).');
                } else {
                    Logger::error('Rollback Snapshot Failed (PclZip): ' . $archive->errorInfo(true));
                    return false;
                }
            } else {
                Logger::error('Rollback Snapshot Failed: Neither ZipArchive nor PclZip is available.');
                return false;
            }
        }

        return true;
    }

    /**
     * Executes recovery rollback to restore original site snapshot.
     *
     * @param string $rollback_dir Local folder containing rollback files.
     */
    public static function trigger_rollback($rollback_dir)
    {
        Logger::warning('RESTORATION FAILURE: Triggering emergency rollback snapshot restore...');

        $sql_file = rtrim($rollback_dir, '/') . '/rollback_database.sql';
        $zip_file = rtrim($rollback_dir, '/') . '/rollback_files.zip';

        $success = true;
        if (file_exists($zip_file)) {
            $success = self::restore_files([ $zip_file ]);
        }
        if (file_exists($sql_file)) {
            Settings::delete('restore_db_offset');
            do {
                $done = self::restore_database($sql_file);
            } while ($done === false);
            $success = $success && !is_wp_error($done);
        }

        Logger::log($success ? 'info' : 'error', $success ? 'Rollback snapshot restoration completed.' : 'Rollback could not be completed. Recovery files have been retained.');
        return $success;
    }
}
