<?php
namespace SPSync\Backup;

use SPSync\Auth\GraphClient;
use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Retention
{
    /**
     * Enforces the backup retention policy by deleting oldest backups when the limit is exceeded.
     */
    public static function enforce()
    {
        $limit = (int) Settings::get('retention_limit', '30');
        if ($limit <= 0) {
            Logger::info('Retention policy set to unlimited. Skipping remote folder cleanup.');
            return;
        }

        $drive_id = GraphClient::get_drive_id();
        $root_folder_id = GraphClient::get_root_folder_id();

        if (!$drive_id || !$root_folder_id) {
            Logger::error('Retention Cleanup Failed: Could not resolve SharePoint Drive or Root Folder IDs.');
            return;
        }

        // Fetch children folders of the Root Backup directory
        $endpoint = sprintf('drives/%s/items/%s/children', $drive_id, $root_folder_id);
        $data = GraphClient::request($endpoint);

        if (!$data || empty($data['value'])) {
            return;
        }

        $backups = [];
        foreach ($data['value'] as $item) {
            // Select folder directories matching our specific backup name prefix
            if (isset($item['folder']) && strpos($item['name'], 'spsync-backup-') === 0) {
                $backups[] = [
                    'id'   => $item['id'],
                    'name' => $item['name']
                ];
            }
        }

        $total_backups = count($backups);
        if ($total_backups <= $limit) {
            Logger::info(sprintf('Retention Enforcement: Remote backups count (%d) is within limit (%d). No cleanup required.', $total_backups, $limit));
            return;
        }

        // Sort backups alphabetically by folder name (equivalent to chronological order due to YYYYMMDD-HHMMSS naming structure)
        usort($backups, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        // Determine excess amount to delete
        $excess_count = $total_backups - $limit;
        Logger::info(sprintf('Retention Enforcement: Remote backups count (%d) exceeds limit (%d). Pruning %d oldest backups...', $total_backups, $limit, $excess_count));

        for ($i = 0; $i < $excess_count; $i++) {
            $target = $backups[$i];
            $folder_id = $target['id'];
            $backup_name = $target['name'];

            Logger::info("Pruning oldest backup folder from SharePoint: $backup_name");

            // Execute DELETE request to MS Graph
            $delete_endpoint = sprintf('drives/%s/items/%s', $drive_id, $folder_id);
            GraphClient::request($delete_endpoint, 'DELETE');

            // Delete corresponding local database rows in queue table
            global $wpdb;
            $table = $wpdb->prefix . 'spsync_items';
            $wpdb->delete($table, [ 'backup_id' => $backup_name ], [ '%s' ]);

            Logger::info("Successfully removed remote SharePoint folder and database queue entries for: $backup_name");
        }
    }
}
