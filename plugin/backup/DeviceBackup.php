<?php
namespace SPSync\Backup;

use SPSync\Helpers\PrivateStorage;
use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class DeviceBackup
{
    const ACTION_HOOK = 'spsync_create_device_backup';
    const CLEANUP_HOOK = 'spsync_expire_device_backup';
    const LIFETIME = 86400;

    public static function init()
    {
        add_action(self::ACTION_HOOK, [__CLASS__, 'run'], 10, 1);
        add_action(self::CLEANUP_HOOK, [__CLASS__, 'expire'], 10, 1);
    }

    private static function key($id)
    {
        return 'spsync_device_job_' . $id;
    }

    public static function get($id, $user_id)
    {
        if (!is_string($id) || !preg_match('/\A[a-f0-9-]{36}\z/D', $id)) {
            return false;
        }
        $job = get_option(self::key($id));
        return is_array($job) && (int) $job['user_id'] === (int) $user_id ? $job : false;
    }

    public static function start($user_id)
    {
        if (!add_option('spsync_device_start_lock', time(), '', false)) {
            return new \WP_Error('busy', 'Another download backup is being prepared. Try again shortly.');
        }
        try {
            $old_id = get_user_meta($user_id, 'spsync_device_backup', true);
            $old = self::get($old_id, $user_id);
            if ($old && in_array($old['status'], ['queued', 'running'], true) && $old['expires_at'] > time()) {
                return new \WP_Error('busy', 'Your backup is already queued or running. Refresh its status below.');
            }
            $directory = PrivateStorage::create('download');
            if (is_wp_error($directory)) {
                return $directory;
            }
            $id = wp_generate_uuid4();
            $job = ['id' => $id, 'user_id' => (int) $user_id, 'directory' => $directory, 'status' => 'queued', 'expires_at' => time() + self::LIFETIME, 'error' => ''];
            add_option(self::key($id), $job, '', false);
            $expiry = wp_schedule_single_event($job['expires_at'], self::CLEANUP_HOOK, [$id]);
            $scheduled = $expiry && !is_wp_error($expiry) ? (function_exists('as_enqueue_async_action') ? as_enqueue_async_action(self::ACTION_HOOK, [$id]) : wp_schedule_single_event(time(), self::ACTION_HOOK, [$id])) : false;
            if (!$scheduled || is_wp_error($scheduled)) {
                self::discard($id, $user_id);
                return new \WP_Error('schedule_failed', 'Could not schedule your backup. Check WordPress cron.');
            }
            update_user_meta($user_id, 'spsync_device_backup', $id);
            if ($old && (!in_array($old['status'], ['queued', 'running'], true) || $old['expires_at'] <= time())) {
                self::discard($old_id, $user_id);
            }
            return $id;
        } finally {
            delete_option('spsync_device_start_lock');
        }
    }

    public static function run($id)
    {
        $job = get_option(self::key($id));
        if (!is_array($job) || $job['status'] !== 'queued' || $job['expires_at'] <= time() ||
            !PrivateStorage::valid($job['directory'], 'download') || !add_option(self::key($id) . '_lock', time(), '', false)) {
            return;
        }
        $job['status'] = 'running';
        $job['started_at'] = time();
        update_option(self::key($id), $job, false);
        $finished = false;
        register_shutdown_function(function () use (&$finished, $id) {
            if (!$finished) {
                $current = get_option(self::key($id));
                if (is_array($current) && $current['status'] === 'running') {
                    $current['status'] = 'failed';
                    $current['error'] = 'Backup creation was interrupted. Try again after checking the server logs.';
                    update_option(self::key($id), $current, false);
                }
            }
        });
        try {
            $backup_id = BackupEngine::run(false, 'device', $job['directory']);
            if (!$backup_id) {
                throw new \RuntimeException('Could not create the backup. A backup or restoration may already be running; check Execution Logs and try again.');
            }
            $package = self::package($job['directory'], $backup_id);
            if (is_wp_error($package)) {
                throw new \RuntimeException($package->get_error_message());
            }
            $job['status'] = 'ready';
            $job['filename'] = basename($package);
            $job['filesize'] = filesize($package);
            Logger::info('Device backup is ready for its owner to download.', ['backup_id' => $backup_id]);
        } catch (\Throwable $error) {
            $job['status'] = 'failed';
            $job['error'] = $error->getMessage();
            Logger::error('Device backup failed: ' . $error->getMessage());
        } finally {
            $finished = true;
            update_option(self::key($id), $job, false);
            delete_option(self::key($id) . '_lock');
        }
    }

    public static function package($directory, $backup_id)
    {
        if (!PrivateStorage::valid($directory, 'download') || !preg_match('/\Aspsync-backup-[0-9]{8}-[0-9]{6}\z/D', $backup_id)) {
            return new \WP_Error('invalid_backup', 'Invalid download backup.');
        }
        $root = $directory . '/artifacts/';
        $manifest = json_decode(file_get_contents($root . 'manifest.json'), true);
        if (!is_array($manifest) || empty($manifest['parts']) || $manifest['backup_id'] !== $backup_id) {
            return new \WP_Error('invalid_manifest', 'The backup manifest is incomplete.');
        }
        $files = [$root . 'manifest.json'];
        foreach ($manifest['parts'] as $part) {
            if (!\SPSync\Restore\LocalBackup::allowed_filename($part['filename']) || $part['filename'] === 'manifest.json' ||
                !is_file($root . $part['filename']) || is_link($root . $part['filename']) ||
                filesize($root . $part['filename']) != $part['filesize'] || hash_file('sha256', $root . $part['filename']) !== $part['checksum']) {
                return new \WP_Error('invalid_component', 'A backup component is missing or incomplete.');
            }
            $files[] = $root . $part['filename'];
        }
        $path = $directory . '/' . $backup_id . '.zip';
        if (class_exists('\\ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return new \WP_Error('package_failed', 'Could not create the download ZIP.');
            }
            foreach ($files as $file) {
                if (!$zip->addFile($file, $backup_id . '/' . basename($file))) {
                    $zip->close();
                    return new \WP_Error('package_failed', 'Could not add a backup component to the download.');
                }
            }
            if (!$zip->close()) {
                return new \WP_Error('package_failed', 'Could not finish the download ZIP. Check available disk space.');
            }
        } else {
            if (!class_exists('PclZip')) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            $zip = new \PclZip($path);
            $list = $zip->create($files, PCLZIP_OPT_REMOVE_PATH, rtrim($root, '/'), PCLZIP_OPT_ADD_PATH, $backup_id);
            if (!is_array($list) || count($list) !== count($files)) {
                return new \WP_Error('package_failed', 'Could not create the download ZIP.');
            }
        }
        return $path;
    }

    public static function download($id, $user_id)
    {
        $job = self::get($id, $user_id);
        if (!$job || $job['status'] !== 'ready' || $job['expires_at'] <= time() ||
            !PrivateStorage::valid($job['directory'], 'download') || empty($job['filename']) ||
            !preg_match('/\Aspsync-backup-[0-9]{8}-[0-9]{6}\.zip\z/D', $job['filename'])) {
            return new \WP_Error('not_ready', 'This backup is not ready, has expired, or belongs to another user.');
        }
        $path = $job['directory'] . '/' . $job['filename'];
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return new \WP_Error('missing_download', 'The backup download is no longer available. Create it again.');
        }
        return $path;
    }

    public static function discard($id, $user_id)
    {
        $job = self::get($id, $user_id);
        if (!$job || !add_option(self::key($id) . '_lock', time(), '', false)) {
            return false;
        }
        try {
            $job = self::get($id, $user_id);
            if (!$job || ($job['status'] === 'running' && $job['expires_at'] > time())) {
                return false;
            }
            PrivateStorage::remove($job['directory'], 'download');
            delete_option(self::key($id));
            wp_clear_scheduled_hook(self::ACTION_HOOK, [$id]);
            wp_clear_scheduled_hook(self::CLEANUP_HOOK, [$id]);
            if (get_user_meta($user_id, 'spsync_device_backup', true) === $id) {
                delete_user_meta($user_id, 'spsync_device_backup');
            }
            return true;
        } finally {
            delete_option(self::key($id) . '_lock');
        }
    }

    public static function expire($id)
    {
        $job = get_option(self::key($id));
        if (is_array($job) && $job['expires_at'] <= time()) {
            $lease = get_option(self::key($id) . '_lock');
            if ($lease && (int) $lease + self::LIFETIME < time()) {
                delete_option(self::key($id) . '_lock');
            }
            if (!self::discard($id, $job['user_id'])) {
                wp_schedule_single_event(time() + 300, self::CLEANUP_HOOK, [$id]);
            }
        }
    }
}
