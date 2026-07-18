<?php
namespace SPSync\Backup;

use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class DatabaseBackup
{
    /**
     * Exports the WordPress database structure and data to an SQL dump file.
     *
     * @param string $output_file Full path to the destination .sql file.
     * @return bool True on success, false on failure.
     */
    public static function export($output_file)
    {
        global $wpdb;

        Logger::info('Starting database backup export...');

        // Ensure the directory exists
        $dir = dirname($output_file);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $handle = fopen($output_file, 'w');
        if (!$handle) {
            Logger::error('Database Backup Failed: Could not open output file for writing.', [
                'file' => $output_file
            ]);
            return false;
        }

        // Write SQL header info
        fwrite($handle, "-- WordPress SharePoint Backup SQL Dump\n");
        fwrite($handle, "-- Generation Time: " . current_time('mysql') . "\n");
        fwrite($handle, "-- Database Host: " . DB_HOST . "\n");
        fwrite($handle, "-- Database Name: " . DB_NAME . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        // Get all tables in the current WordPress database prefix
        $prefix = $wpdb->prefix;
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$prefix}%'");

        if (empty($tables)) {
            Logger::error('Database Backup Failed: No tables found matching prefix ' . $prefix);
            fclose($handle);
            return false;
        }

        foreach ($tables as $table) {
            Logger::debug("Backing up table structure: $table");

            // 1. Structure (DROP and CREATE)
            fwrite($handle, "DROP TABLE IF EXISTS `" . esc_sql($table) . "`;\n");
            
            $create_query = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            if (!$create_query || empty($create_query[1])) {
                Logger::warning("Could not retrieve structure for table: $table");
                continue;
            }
            
            fwrite($handle, $create_query[1] . ";\n\n");

            // 2. Data Insertion (Chunked queries)
            $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            if ($row_count == 0) {
                continue;
            }

            Logger::debug("Exporting table data ($row_count rows): $table");

            $batch_size = 500;
            $offset = 0;

            while ($offset < $row_count) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `$table` LIMIT %d OFFSET %d", $batch_size, $offset),
                    ARRAY_A
                );

                if (!empty($rows)) {
                    $insert_queries = [];
                    foreach ($rows as $row) {
                        $escaped_values = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $escaped_values[] = 'NULL';
                            } else {
                                $escaped_values[] = "'" . esc_sql($val) . "'";
                            }
                        }
                        $insert_queries[] = "INSERT INTO `" . esc_sql($table) . "` VALUES (" . implode(', ', $escaped_values) . ");";
                    }
                    fwrite($handle, implode("\n", $insert_queries) . "\n");
                }

                $offset += $batch_size;
                
                if (function_exists('usleep')) {
                    usleep(1000); // 1ms pause to yield CPU resources
                }
            }

            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        Logger::info('Database backup export completed successfully.', [
            'file' => basename($output_file),
            'size' => size_format(filesize($output_file))
        ]);

        return true;
    }
}
