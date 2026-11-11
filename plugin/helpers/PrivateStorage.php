<?php
namespace SPSync\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/** Private temporary storage shared by local imports and device downloads. */
class PrivateStorage
{
    public static function create($kind)
    {
        $parent = self::parent_directory();
        if (!$parent || !is_writable($parent)) {
            return new \WP_Error('private_storage', 'A writable temporary directory outside the website is required.');
        }
        $directory = $parent . '/spsync-' . $kind . '-' . wp_generate_uuid4();
        if (!in_array($kind, ['import', 'download'], true) || !mkdir($directory, 0700)) {
            return new \WP_Error('staging_failed', 'Could not create private backup storage.');
        }
        return $directory;
    }

    public static function valid($directory, $kind)
    {
        if (!in_array($kind, ['import', 'download'], true) || !is_string($directory) ||
            !preg_match('/\Aspsync-' . $kind . '-[a-f0-9-]{36}\z/D', basename($directory)) || is_link($directory)) {
            return false;
        }
        $parent = self::parent_directory();
        $resolved = realpath($directory);
        return $parent && $resolved && is_dir($resolved) && dirname($resolved) === $parent;
    }

    public static function remove($directory, $kind)
    {
        if (!self::valid($directory, $kind)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($directory);
    }

    private static function parent_directory()
    {
        $parent = realpath(apply_filters('spsync_restore_temp_dir', sys_get_temp_dir()));
        if (!$parent || self::inside($parent, ABSPATH) || (!empty($_SERVER['DOCUMENT_ROOT']) && self::inside($parent, $_SERVER['DOCUMENT_ROOT']))) {
            return false;
        }
        return $parent;
    }

    private static function inside($path, $root)
    {
        $root = realpath($root);
        if (!$root) {
            return false;
        }
        $path = strtolower(str_replace('\\', '/', $path));
        $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');
        return $path === $root || strpos($path . '/', $root . '/') === 0;
    }
}
