<?php
namespace SPSync\Storage;

use SPSync\Auth\GraphClient;
use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knows where this site's backups live on SharePoint.
 *
 * Layout: {library}/{root folder}/{site folder}/spsync-backup-YYYYMMDD-HHMMSS/
 *
 * Before 1.4.0 every site wrote backup folders directly into the shared root folder, so sites
 * sharing a root could see, verify and prune each other's backups. Those legacy root-level
 * folders are still recognised, but only when this site's own upload queue recorded the ID
 * (or when an operator explicitly names the backup to restore).
 */
class RemoteInventory
{
    const BACKUP_PATTERN = '/\Aspsync-backup-[A-Za-z0-9_-]{1,80}\z/D';
    const GRAPH_BASE = 'https://graph.microsoft.com/v1.0/';
    const MAX_PAGES = 100;

    /** Stable per-site folder name, persisted on first use so a later URL change cannot orphan backups. */
    public static function site_folder_name()
    {
        $name = self::sanitize_folder_name(Settings::get('site_folder', ''));
        if ($name !== '') {
            return $name;
        }
        $parts = wp_parse_url(home_url());
        $candidate = isset($parts['host']) ? $parts['host'] : 'wordpress-site';
        if (!empty($parts['path']) && trim($parts['path'], '/') !== '') {
            $candidate .= '-' . trim($parts['path'], '/');
        }
        $name = self::sanitize_folder_name($candidate);
        if ($name === '') {
            $name = 'wordpress-site';
        }
        Settings::set('site_folder', $name);
        return $name;
    }

    /** Folder-safe for SharePoint and never confusable with a backup folder. */
    public static function sanitize_folder_name($name)
    {
        if (!is_string($name)) {
            return '';
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name));
        $name = trim(preg_replace('/-{2,}/', '-', $name), '-.');
        if (stripos($name, 'spsync-backup-') === 0) {
            $name = 'site-' . $name;
        }
        return substr($name, 0, 100);
    }

    /**
     * Lists every child of a drive item, following only Graph-hosted pagination links.
     *
     * @return array|false All children, or false when the listing failed or is incomplete.
     */
    public static function list_children($drive_id, $item_id)
    {
        $page = GraphClient::request(sprintf('drives/%s/items/%s/children', $drive_id, $item_id));
        $items = [];
        $pages = 0;
        while (true) {
            if (!is_array($page) || !isset($page['value']) || !is_array($page['value'])) {
                return false;
            }
            $items = array_merge($items, $page['value']);
            $next = isset($page['@odata.nextLink']) ? $page['@odata.nextLink'] : null;
            if (empty($next)) {
                return $items;
            }
            if (!is_string($next) || strpos($next, self::GRAPH_BASE) !== 0 || ++$pages > self::MAX_PAGES) {
                Logger::error('SharePoint returned an unsupported or incomplete folder listing.');
                return false;
            }
            $page = GraphClient::request(substr($next, strlen(self::GRAPH_BASE)));
        }
    }

    /** @return string|false Child folder ID by case-insensitive name. */
    public static function find_folder($items, $name)
    {
        foreach ((array) $items as $item) {
            if (is_array($item) && isset($item['folder'], $item['name'], $item['id']) && strcasecmp($item['name'], $name) === 0) {
                return $item['id'];
            }
        }
        return false;
    }

    /**
     * Resolve (and create when missing) this site's folder under the root backup folder.
     *
     * @param array|null $root_items Root listing already fetched by the caller.
     * @return string|false
     */
    public static function site_folder_id($root_items = null)
    {
        $drive = GraphClient::get_drive_id();
        $root = GraphClient::get_root_folder_id();
        if (!$drive || !$root) {
            return false;
        }
        $name = self::site_folder_name();
        $binding = $root . '|' . strtolower($name);
        $cached = Settings::get('site_folder_id', '');
        if ($cached !== '' && Settings::get('site_folder_binding', '') === $binding) {
            // Trust the cache unless a fresh root listing shows the folder was deleted.
            if ($root_items === null || in_array($cached, array_column(array_filter((array) $root_items, 'is_array'), 'id'), true)) {
                return $cached;
            }
            self::forget();
        }

        if ($root_items === null) {
            $root_items = self::list_children($drive, $root);
            if ($root_items === false) {
                return false;
            }
        }
        $id = self::find_folder($root_items, $name);
        if (!$id) {
            $created = GraphClient::request(sprintf('drives/%s/items/%s/children', $drive, $root), 'POST', [
                'name'                              => $name,
                'folder'                            => new \stdClass(),
                '@microsoft.graph.conflictBehavior' => 'fail',
            ]);
            $id = is_array($created) && !empty($created['id']) ? $created['id'] : false;
            if (!$id) {
                Logger::error('Could not create this site\'s SharePoint backup folder.', ['site_folder' => $name]);
                return false;
            }
            Logger::info('Created this site\'s SharePoint backup folder.', ['site_folder' => $name]);
        }
        Settings::set('site_folder_id', $id);
        Settings::set('site_folder_binding', $binding);
        return $id;
    }

    /** Forget cached folder IDs (connection test, folder rename). */
    public static function forget()
    {
        Settings::delete('site_folder_id');
        Settings::delete('site_folder_binding');
    }

    /** Backup IDs this site's own upload queue has recorded. */
    public static function known_backup_ids()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';
        $ids = $wpdb->get_col("SELECT DISTINCT backup_id FROM $table WHERE backup_id != ''");
        return is_array($ids) ? $ids : [];
    }

    /**
     * Backups that belong to this site.
     *
     * @param array|null $known_ids Legacy root-level IDs this site may claim; defaults to its upload queue.
     * @param string|null $source_folder Read another site's folder instead (explicit cross-site restore).
     * @return array|false name => [id, name, child_count, location], oldest first; false if any listing failed.
     */
    public static function backups($known_ids = null, $source_folder = null)
    {
        $drive = GraphClient::get_drive_id();
        $root = GraphClient::get_root_folder_id();
        if (!$drive || !$root) {
            return false;
        }
        $root_items = self::list_children($drive, $root);
        if ($root_items === false) {
            return false;
        }
        if ($source_folder !== null) {
            $folder = self::find_folder($root_items, $source_folder);
            $folder_items = $folder ? self::list_children($drive, $folder) : [];
        } else {
            $folder = self::site_folder_id($root_items);
            $folder_items = $folder ? self::list_children($drive, $folder) : false;
        }
        if ($folder_items === false) {
            return false;
        }

        $known = array_flip($known_ids === null ? self::known_backup_ids() : $known_ids);
        $backups = [];
        // Site-folder entries are processed last so they win over a legacy duplicate.
        foreach ([['legacy', $root_items], ['site', $folder_items]] as $source) {
            list($location, $items) = $source;
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['folder'], $item['id'], $item['name']) ||
                    !preg_match(self::BACKUP_PATTERN, $item['name']) ||
                    ($location === 'legacy' && !isset($known[$item['name']]))) {
                    continue;
                }
                $backups[$item['name']] = [
                    'id'          => $item['id'],
                    'name'        => $item['name'],
                    'child_count' => is_array($item['folder']) && isset($item['folder']['childCount']) ? (int) $item['folder']['childCount'] : null,
                    'location'    => $location,
                ];
            }
        }
        ksort($backups, SORT_STRING);
        return $backups;
    }

    /**
     * Locate one backup folder without creating anything.
     *
     * @return string|false
     */
    public static function find_backup_folder($backup_id, $source_folder = null)
    {
        if (!is_string($backup_id) || !preg_match(self::BACKUP_PATTERN, $backup_id)) {
            return false;
        }
        $backups = self::backups([$backup_id], $source_folder);
        return is_array($backups) && isset($backups[$backup_id]) ? $backups[$backup_id]['id'] : false;
    }
}
