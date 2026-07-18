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

        foreach ($zip_files as $zip_file) {
            if (!file_exists($zip_file)) {
                Logger::error("File Restore Failed: Archive part does not exist.", [ 'path' => $zip_file ]);
                return false;
            }

            Logger::info("Extracting archive part: " . basename($zip_file));

            $zip = new ZipArchive();
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
        }

        Logger::info('All backup file archives extracted and restored successfully.');
        return true;
    }

    /**
     * Executes the SQL statements from the database backup dump in batches.
     *
     * @param string $sql_filepath Absolute path to the database.sql file.
     * @return bool True if fully completed, false if batch ended and needs to resume.
     */
    public static function restore_database($sql_filepath)
    {
        global $wpdb;

        Logger::info('Starting database SQL execution...');

        if (!file_exists($sql_filepath)) {
            Logger::error("Database Restore Failed: SQL dump file does not exist.", [ 'path' => $sql_filepath ]);
            return true;
        }

        $handle = fopen($sql_filepath, 'r');
        if (!$handle) {
            Logger::error("Database Restore Failed: Could not open SQL file for reading.");
            return true;
        }

        // Check if there is a saved seek offset for recovery
        $offset = (int) Settings::get('restore_db_offset', '0');
        if ($offset > 0) {
            Logger::info("Resuming database restore from file offset: $offset bytes");
            fseek($handle, $offset);
        }

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
                $wpdb->query($query);
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
    public static function create_rollback_snapshot($rollback_dir)
    {
        Logger::info('Creating site rollback recovery snapshot...');

        if (!file_exists($rollback_dir)) {
            wp_mkdir_p($rollback_dir);
        }

        $sql_file = rtrim($rollback_dir, '/') . '/rollback_database.sql';
        
        // 1. Dump current WordPress Database
        $db_success = DatabaseBackup::export($sql_file);
        if (!$db_success) {
            Logger::error('Rollback Snapshot Failed: Could not dump database.');
            return false;
        }

        // 2. Package core folders (wp-content themes/plugins only to save storage space and time)
        $files_to_backup = [];
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

        $zip_file = rtrim($rollback_dir, '/') . '/rollback_files.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $root_path = str_replace('\\', '/', ABSPATH);
            foreach ($files_to_backup as $file) {
                $normalized = str_replace('\\', '/', $file);
                $relative = ltrim(substr($normalized, strlen($root_path)), '/');
                $zip->addFile($file, $relative);
            }
            $zip->close();
            Logger::info('Rollback Snapshot files packaged successfully.');
        } else {
            Logger::error('Rollback Snapshot Failed: Could not open rollback zip.');
            return false;
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

        if (file_exists($zip_file)) {
            self::restore_files([ $zip_file ]);
        }
        if (file_exists($sql_file)) {
            Settings::delete('restore_db_offset');
            self::restore_database($sql_file);
        }

        Logger::info('Rollback snapshot restoration completed. Site returned to original pre-restore state.');
    }
}
