<?php
namespace SPSync\Backup;

use SPSync\Auth\GraphClient;
use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;
use SPSync\Storage\RemoteInventory;

if (!defined('ABSPATH')) {
    exit;
}

class Retention
{
    /**
     * Enforce the retention limit over this site's backups only.
     *
     * Other sites sharing the SharePoint root are never counted or pruned. The listing must be
     * complete (all pages) and local tracking is removed only after SharePoint confirms deletion.
     *
     * @return int Number of backups deleted.
     */
    public static function enforce()
    {
        $limit = (int) Settings::get('retention_limit', '30');
        if ($limit <= 0) {
            Logger::info('Retention policy set to unlimited. Skipping remote folder cleanup.');
            return 0;
        }

        $drive_id = GraphClient::get_drive_id();
        $backups = RemoteInventory::backups();
        if (!$drive_id || $backups === false) {
            Logger::error('Retention skipped: this site\'s SharePoint backups could not be listed completely.');
            return 0;
        }

        $total_backups = count($backups);
        if ($total_backups <= $limit) {
            Logger::info(sprintf('Retention Enforcement: Remote backups count (%d) is within limit (%d). No cleanup required.', $total_backups, $limit));
            return 0;
        }

        // Keys are sorted by name: spsync-backup-YYYYMMDD-HHMMSS sorts chronologically.
        $excess = array_slice(array_values($backups), 0, $total_backups - $limit);
        Logger::info(sprintf('Retention Enforcement: Remote backups count (%d) exceeds limit (%d). Pruning %d oldest backups...', $total_backups, $limit, count($excess)));

        global $wpdb;
        $table = $wpdb->prefix . 'spsync_items';
        $deleted = 0;
        foreach ($excess as $target) {
            Logger::info('Pruning oldest backup folder from SharePoint: ' . $target['name']);
            // A successful DELETE returns 204 with no body (null); failures return false.
            $result = GraphClient::request(sprintf('drives/%s/items/%s', $drive_id, $target['id']), 'DELETE');
            if ($result === false) {
                Logger::error('Retention could not delete a backup folder; local tracking kept.', ['backup_id' => $target['name']]);
                continue;
            }
            $wpdb->delete($table, [ 'backup_id' => $target['name'] ], [ '%s' ]);
            $deleted++;
            Logger::info('Removed remote SharePoint folder and queue entries for: ' . $target['name']);
        }
        return $deleted;
    }
}
