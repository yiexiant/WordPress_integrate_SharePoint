<?php
namespace SPSync\Backup;

use SPSync\Helpers\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class BackupSelection
{
    /** Discover only real, readable directories contained by wp-content. */
    public static function directories()
    {
        $root = realpath(WP_CONTENT_DIR);
        $directories = [];
        if (!$root || !is_readable($root)) {
            return $directories;
        }
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink() || $entry->getFilename() === 'spsync-backups') {
                continue;
            }
            if (is_readable($entry->getPathname())) {
                $directories[$entry->getFilename()] = $entry->getPathname();
            }
        }
        ksort($directories, SORT_NATURAL | SORT_FLAG_CASE);
        return $directories;
    }

    public static function get()
    {
        $mode = Settings::get('backup_scope', 'full') === 'selected' ? 'selected' : 'full';
        $saved = json_decode(Settings::get('backup_directories', '[]'), true);
        $saved = is_array($saved) ? $saved : [];
        return [
            'mode' => $mode,
            'directories' => array_values(array_intersect(array_keys(self::directories()), $saved)),
            'database' => $mode === 'full' || Settings::get('backup_include_database', '0') === '1',
        ];
    }

    public static function save($mode, $directories, $database)
    {
        if (!in_array($mode, ['full', 'selected'], true)) {
            return new \WP_Error('invalid_scope', 'Choose the entire website or selected directories.');
        }
        if (!is_array($directories) || array_filter($directories, function ($value) { return !is_string($value); })) {
            return new \WP_Error('invalid_directories', 'Invalid directory selection.');
        }
        $available = self::directories();
        if (array_diff($directories, array_keys($available))) {
            return new \WP_Error('invalid_directories', 'A selected directory is unavailable. Refresh the page and choose again.');
        }
        if ($mode === 'selected' && !$directories && !$database) {
            return new \WP_Error('empty_selection', 'Select at least one directory or include the database.');
        }
        Settings::set('backup_scope', $mode);
        Settings::set('backup_directories', wp_json_encode(array_values(array_unique($directories))));
        Settings::set('backup_include_database', $database ? '1' : '0');
        return true;
    }
}
