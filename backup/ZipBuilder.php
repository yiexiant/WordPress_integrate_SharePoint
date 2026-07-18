<?php
namespace SPSync\Backup;

use ZipArchive;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class ZipBuilder
{
    /**
     * Compresses the scanned files into split ZIP archives based on file size sum.
     *
     * @param array  $files       Absolute paths of target files to compress.
     * @param string $output_dir  Target directory to write ZIP files to.
     * @param int    $split_limit Maximum cumulative size of files added before creating next part (default: 50MB).
     * @return array List of created absolute ZIP file paths.
     */
    public static function build($files, $output_dir, $split_limit = 52428800)
    {
        Logger::info('Starting ZIP compilation and compression...');

        if (empty($files)) {
            Logger::warning('ZIP Builder: File list is empty. No zip created.');
            return [];
        }

        if (!file_exists($output_dir)) {
            wp_mkdir_p($output_dir);
        }

        $created_parts = [];
        $part_index = 1;
        $current_zip_size = 0;
        
        $zip = new ZipArchive();
        $current_zip_file = sprintf('%s/backup_part%03d.zip', rtrim($output_dir, '/'), $part_index);
        
        if ($zip->open($current_zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Logger::error('ZIP Builder Failed: Could not open ZIP file for writing.', [ 'file' => $current_zip_file ]);
            return [];
        }

        $created_parts[] = $current_zip_file;
        $root_path = str_replace('\\', '/', ABSPATH);

        foreach ($files as $filepath) {
            $normalized_filepath = str_replace('\\', '/', $filepath);
            
            $filesize = @filesize($filepath);
            if ($filesize === false) {
                continue;
            }

            // Check if adding this file exceeds the chunk threshold.
            // Split only if the current ZIP already contains elements.
            if ($current_zip_size > 0 && ($current_zip_size + $filesize) > $split_limit) {
                $zip->close();
                
                $part_index++;
                $current_zip_file = sprintf('%s/backup_part%03d.zip', rtrim($output_dir, '/'), $part_index);
                
                $zip = new ZipArchive();
                if ($zip->open($current_zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    Logger::error('ZIP Builder Failed: Could not open next ZIP part for writing.', [ 'file' => $current_zip_file ]);
                    return $created_parts;
                }
                
                $created_parts[] = $current_zip_file;
                $current_zip_size = 0;
            }

            // Calculate relative path inside the zip file structure (relative to WordPress root)
            $relative_name = $normalized_filepath;
            if (strpos($normalized_filepath, $root_path) === 0) {
                $relative_name = ltrim(substr($normalized_filepath, strlen($root_path)), '/');
            }

            if ($zip->addFile($filepath, $relative_name)) {
                $current_zip_size += $filesize;
            } else {
                Logger::warning('ZIP Builder: Could not add file to ZIP.', [ 'file' => $filepath ]);
            }
        }

        $zip->close();

        Logger::info('ZIP compilation complete. Split archives created successfully.', [
            'parts_count' => count($created_parts),
            'parts'       => array_map('basename', $created_parts)
        ]);

        return $created_parts;
    }
}
