<?php
namespace SPSync\Backup;

use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class ZipBuilder
{
    /** Formats that are already compressed; they are stored in the archive as they are. */
    const STORED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'jxl',
        'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'wmv', 'flv', 'mpg', 'mpeg',
        'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'wma',
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'jar', 'wpress',
        'woff', 'woff2', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub', 'svgz',
    ];

    public static function already_compressed($name)
    {
        return in_array(strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)), self::STORED_EXTENSIONS, true);
    }

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

        $has_zip_archive = class_exists('\ZipArchive');
        $can_store = $has_zip_archive && method_exists('\ZipArchive', 'setCompressionName');
        if (!$has_zip_archive) {
            if (!class_exists('PclZip')) {
                $pclzip_file = ABSPATH . 'wp-admin/includes/class-pclzip.php';
                if (file_exists($pclzip_file)) {
                    require_once $pclzip_file;
                }
            }
            if (!class_exists('PclZip')) {
                Logger::error('ZIP Builder Failed: Neither ZipArchive nor PclZip extension is available on this server.');
                return [];
            }
            Logger::info('ZipArchive extension not loaded in web server. Using WordPress built-in PclZip engine fallback.');
        }

        $created_parts = [];
        $root_path = str_replace('\\', '/', ABSPATH);

        // Group files into split batches
        $batches = [];
        $current_batch = [];
        $current_batch_size = 0;

        foreach ($files as $filepath) {
            $filesize = @filesize($filepath);
            if ($filesize === false) {
                continue;
            }

            if ($current_batch_size > 0 && ($current_batch_size + $filesize) > $split_limit) {
                $batches[] = $current_batch;
                $current_batch = [];
                $current_batch_size = 0;
            }

            $current_batch[] = $filepath;
            $current_batch_size += $filesize;
        }

        if (!empty($current_batch)) {
            $batches[] = $current_batch;
        }

        // Build zip for each batch
        foreach ($batches as $index => $batch_files) {
            // Large sites take many minutes to archive; keep the backup lock alive meanwhile.
            \SPSync\Helpers\BackupLock::heartbeat();
            $part_num = $index + 1;
            $zip_file_path = sprintf('%s/backup_part%03d.zip', rtrim($output_dir, '/'), $part_num);

            if ($has_zip_archive) {
                $zip = new \ZipArchive();
                if ($zip->open($zip_file_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                    $complete = true;
                    foreach ($batch_files as $filepath) {
                        $normalized = str_replace('\\', '/', $filepath);
                        $relative_name = (strpos($normalized, $root_path) === 0) 
                            ? ltrim(substr($normalized, strlen($root_path)), '/') 
                            : $normalized;
                        if (!$zip->addFile($filepath, $relative_name)) {
                            $complete = false;
                            break;
                        }
                        // Re-compressing photos, video and archives costs CPU time and saves ~nothing.
                        if ($can_store && self::already_compressed($relative_name)) {
                            $zip->setCompressionName($relative_name, \ZipArchive::CM_STORE);
                        }
                    }
                    $closed = $zip->close();
                    if ($complete && $closed) {
                        $created_parts[] = $zip_file_path;
                    } else {
                        Logger::error('ZIP Builder Failed: An archive could not be written completely.');
                    }
                } else {
                    Logger::error('ZIP Builder Failed: Could not open ZIP file for writing.', ['file' => $zip_file_path]);
                }
            } else {
                // Use WordPress native PclZip engine fallback
                $archive = new \PclZip($zip_file_path);
                $v_list = $archive->create($batch_files, PCLZIP_OPT_REMOVE_PATH, ABSPATH);
                if (is_array($v_list) && count($v_list) === count($batch_files)) {
                    $created_parts[] = $zip_file_path;
                } else {
                    Logger::error('ZIP Builder Failed (PclZip): ' . $archive->errorInfo(true), ['file' => $zip_file_path]);
                }
            }
        }

        if (count($created_parts) !== count($batches)) {
            foreach (array_keys($batches) as $index) {
                $part = sprintf('%s/backup_part%03d.zip', rtrim($output_dir, '/'), $index + 1);
                if (is_file($part)) {
                    @unlink($part);
                }
            }
            return [];
        }

        Logger::info('ZIP compilation complete. Split archives created successfully.', [
            'parts_count' => count($created_parts),
            'parts'       => array_map('basename', $created_parts)
        ]);

        return $created_parts;
    }
}
