<?php
namespace SPSync\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveCallbackFilterIterator;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class FileScanner
{
    /**
     * Recursively scans the WordPress installation and lists target files.
     *
     * @param array $exclusions Custom directories to exclude.
     * @return array List of absolute file paths matching scan criteria.
     */
    public static function scan($exclusions = [])
    {
        Logger::info('Starting directory file scanning...');

        $root_path = ABSPATH;
        $files = [];

        // Build default exclusions matching Phase 2 specifications
        $default_exclusions = [
            '.git',
            'node_modules',
            'cache',
            'logs',
            'tmp',
            'spsync-backups' // Prevent recursive backing up of our own backup repository
        ];
        
        $exclude_dirs = array_merge($default_exclusions, $exclusions);
        
        $exclude_extensions = [
            'log',
            'tmp',
            'zip',
            'gz',
            'tar'
        ];

        if (!file_exists($root_path)) {
            Logger::error('File Scanner Failed: WordPress root directory path does not exist.', [ 'path' => $root_path ]);
            return [];
        }

        $dir_iterator = new RecursiveDirectoryIterator($root_path, RecursiveDirectoryIterator::SKIP_DOTS);
        
        // Use RecursiveCallbackFilterIterator to prevent scanning inside excluded folders entirely
        $filter_iterator = new RecursiveCallbackFilterIterator($dir_iterator, function ($current, $key, $iterator) use ($exclude_dirs) {
            if ($current->isDir()) {
                $dir_name = $current->getFilename();
                if (in_array($dir_name, $exclude_dirs, true)) {
                    return false;
                }
            }
            return true;
        });

        $iterator = new RecursiveIteratorIterator($filter_iterator, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $file_info) {
            if ($file_info->isFile()) {
                $filepath = $file_info->getRealPath();
                $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

                // Skip files matching excluded extensions
                if (in_array($ext, $exclude_extensions, true)) {
                    continue;
                }

                // Normalize path to verify sub-directory matches (safety fallback)
                $normalized_path = str_replace('\\', '/', $filepath);
                $is_excluded = false;
                foreach ($exclude_dirs as $dir) {
                    if (strpos($normalized_path, '/' . $dir . '/') !== false) {
                        $is_excluded = true;
                        break;
                    }
                }

                if (!$is_excluded) {
                    $files[] = $filepath;
                }
            }
        }

        Logger::info('File scanning completed successfully.', [
            'total_files_scanned' => count($files)
        ]);

        return $files;
    }
}
