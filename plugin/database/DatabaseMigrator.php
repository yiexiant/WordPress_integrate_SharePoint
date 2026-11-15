<?php
namespace SPSync\Database;

use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class DatabaseMigrator
{
    /**
     * Safely search and replace URLs across the WordPress database,
     * maintaining PHP serialization integrity for options and metadata.
     *
     * @param string $old_url Old URL to search for.
     * @param string $new_url New URL to replace with.
     * @param bool   $dry_run If true, only calculates changes without modifying rows.
     * @return array Summary of tables scanned, rows updated, and replacement counts.
     */
    public static function search_and_replace($old_url, $new_url, $dry_run = false)
    {
        global $wpdb;

        $start_time = microtime(true);
        $old_url    = rtrim(trim($old_url), '/');
        $new_url    = rtrim(trim($new_url), '/');

        if (empty($old_url) || empty($new_url)) {
            return [
                'success' => false,
                'message' => 'Both Old URL and New URL are required.',
            ];
        }

        foreach ([$old_url, $new_url] as $url) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                return [
                    'success' => false,
                    'message' => 'Both URLs must be absolute http:// or https:// addresses.',
                ];
            }
        }

        if (strcasecmp($old_url, $new_url) === 0) {
            return [
                'success' => false,
                'message' => 'Old URL and New URL are identical. No replacement necessary.',
            ];
        }

        Logger::info(sprintf(
            "Starting Database URL Search & Replace (%s): '%s' -> '%s'",
            $dry_run ? 'DRY RUN' : 'LIVE',
            $old_url,
            $new_url
        ));

        // Retrieve all tables with the current WordPress table prefix
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
        
        $total_rows_updated = 0;
        $total_replacements = 0;
        $tables_report      = [];

        // Exclude internal temporary and log tables from URL replacement
        $excluded_tables = [
            $wpdb->prefix . 'spsync_logs',
            $wpdb->prefix . 'spsync_items',
            $wpdb->prefix . 'spsync_restore_items',
            $wpdb->prefix . 'spsync_settings',
        ];

        foreach ($tables as $table) {
            if (in_array($table, $excluded_tables, true)) {
                continue;
            }

            // Find Primary Key for the table
            $pk_row = $wpdb->get_row("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            $primary_key = $pk_row ? $pk_row->Column_name : null;

            // Find text/char/blob columns
            $columns_info = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`");
            $text_columns = [];

            foreach ($columns_info as $col) {
                $type = strtolower($col->Type);
                if (
                    strpos($type, 'varchar') !== false ||
                    strpos($type, 'text') !== false ||
                    strpos($type, 'blob') !== false ||
                    strpos($type, 'char') !== false ||
                    strpos($type, 'longtext') !== false ||
                    strpos($type, 'mediumtext') !== false
                ) {
                    $text_columns[] = $col->Field;
                }
            }

            if (empty($text_columns)) {
                continue;
            }

            // Search rows containing the old URL in any of the candidate columns.
            // The value is bound through prepare(); esc_like() alone does not quote SQL.
            $like = '%' . $wpdb->esc_like($old_url) . '%';
            $where_clauses = [];
            foreach ($text_columns as $col) {
                $where_clauses[] = $wpdb->prepare('`' . str_replace('`', '``', $col) . '` LIKE %s', $like);
            }
            $where_sql = implode(' OR ', $where_clauses);

            $rows = $wpdb->get_results("SELECT * FROM `{$table}` WHERE {$where_sql}", ARRAY_A);

            if (empty($rows)) {
                continue;
            }

            $table_rows_updated = 0;
            $table_replacements = 0;

            foreach ($rows as $row) {
                $row_changed = false;
                $update_data = [];

                foreach ($text_columns as $col) {
                    $original_value = $row[$col];

                    if (is_string($original_value) && strpos($original_value, $old_url) !== false) {
                        $count = 0;
                        $replaced_value = self::recursive_replace($original_value, $old_url, $new_url, $count);

                        if ($original_value !== $replaced_value) {
                            $update_data[$col] = $replaced_value;
                            $table_replacements += $count;
                            $row_changed = true;
                        }
                    }
                }

                if ($row_changed) {
                    $table_rows_updated++;

                    if (!$dry_run) {
                        if ($primary_key && isset($row[$primary_key])) {
                            $wpdb->update($table, $update_data, [ $primary_key => $row[$primary_key] ]);
                        } else {
                            // Fallback if no primary key: match on exact original row values
                            $wpdb->update($table, $update_data, $row);
                        }
                    }
                }
            }

            if ($table_rows_updated > 0) {
                $tables_report[] = [
                    'table'        => $table,
                    'rows_updated' => $table_rows_updated,
                    'replacements' => $table_replacements,
                ];
                $total_rows_updated += $table_rows_updated;
                $total_replacements += $table_replacements;
            }
        }

        // Post-update housekeeping (flush caches & rewrite rules)
        if (!$dry_run) {
            // Explicitly ensure siteurl and home are updated if in wp_options
            update_option('siteurl', $new_url);
            update_option('home', $new_url);

            if (function_exists('flush_rewrite_rules')) {
                flush_rewrite_rules(false);
            }
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            Logger::info(sprintf(
                "Database URL Update Complete: %d replacements across %d rows in %d tables.",
                $total_replacements,
                $total_rows_updated,
                count($tables_report)
            ));
        } else {
            Logger::info(sprintf(
                "Database URL Dry Run Complete: %d potential replacements across %d rows in %d tables.",
                $total_replacements,
                $total_rows_updated,
                count($tables_report)
            ));
        }

        $duration = round(microtime(true) - $start_time, 2);

        return [
            'success'            => true,
            'dry_run'            => $dry_run,
            'old_url'            => $old_url,
            'new_url'            => $new_url,
            'tables_scanned'     => count($tables),
            'tables_updated'     => count($tables_report),
            'rows_updated'       => $total_rows_updated,
            'replacements_count' => $total_replacements,
            'duration_seconds'   => $duration,
            'tables'             => $tables_report,
            'message'            => $dry_run
                ? sprintf("Dry Run Complete: Found %d match(es) across %d row(s) in %d table(s). No database changes were applied.", $total_replacements, $total_rows_updated, count($tables_report))
                : sprintf("Live URL Update Complete: Successfully updated %d row(s) with %d replacement(s) across %d table(s) in %.2f seconds.", $total_rows_updated, $total_replacements, count($tables_report), $duration),
        ];
    }

    /**
     * Recursively traverses strings, serialized data, arrays, and objects
     * to safely replace occurrences while preserving serialization length metadata.
     *
     * @param mixed  $data Data to scan and replace.
     * @param string $old  Target search string.
     * @param string $new  Replacement string.
     * @param int    &$count Reference counter for replacements.
     * @return mixed Modified data.
     */
    public static function recursive_replace($data, $old, $new, &$count = 0)
    {
        if (is_string($data)) {
            // Check if string is serialized
            if (self::is_serialized($data)) {
                $unserialized = @unserialize($data);
                if ($unserialized !== false || $data === 'b:0;') {
                    $replaced_unserialized = self::recursive_replace($unserialized, $old, $new, $count);
                    return serialize($replaced_unserialized);
                }
            }

            // Normal string replace
            $occurrences = substr_count($data, $old);
            if ($occurrences > 0) {
                $count += $occurrences;
                return str_replace($old, $new, $data);
            }

            return $data;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $new_key = is_string($key) ? self::recursive_replace($key, $old, $new, $count) : $key;
                $result[$new_key] = self::recursive_replace($value, $old, $new, $count);
            }
            return $result;
        }

        if (is_object($data)) {
            $result = clone $data;
            $props  = get_object_vars($data);
            foreach ($props as $prop => $value) {
                $result->$prop = self::recursive_replace($value, $old, $new, $count);
            }
            return $result;
        }

        return $data;
    }

    /**
     * Helper to test if a string is serialized.
     */
    private static function is_serialized($data)
    {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ($data === 'N;') {
            return true;
        }
        if (strlen($data) < 4 || $data[1] !== ':') {
            return false;
        }
        $last = substr($data, -1);
        if ($last !== ';' && $last !== '}') {
            return false;
        }
        $token = $data[0];
        switch ($token) {
            case 's':
                if ($data[strlen($data) - 2] !== '"') {
                    return false;
                }
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$/", $data);
        }
        return false;
    }
}
