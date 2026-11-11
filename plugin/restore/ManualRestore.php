<?php
namespace SPSync\Restore;

use SPSync\Helpers\Settings;
use SPSync\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class ManualRestore
{
    const ACTION_HOOK = 'spsync_manual_restore';

    public static function init()
    {
        add_action(self::ACTION_HOOK, [__CLASS__, 'run'], 10, 1);
    }

    public static function start($directory)
    {
        if (!add_option('spsync_restore_start_lock', time(), '', false)) {
            return new \WP_Error('busy', 'Another restoration is being prepared. Try again shortly.');
        }
        try {
            return self::queue($directory);
        } finally {
            delete_option('spsync_restore_start_lock');
        }
    }

    private static function queue($directory)
    {
        $pending = get_option('spsync_api_backup_request');
        if (Settings::get('backup_running') || (is_array($pending) && $pending['created_at'] > time() - 600) || in_array(Settings::get('restore_status'), ['downloading', 'extracting', 'restoring_database', 'cleanup'], true)) {
            return new \WP_Error('busy', 'Wait for the current backup or restoration to finish.');
        }
        $manifest = LocalBackup::validate($directory);
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        $id = 'spsync-local-' . wp_generate_uuid4();
        Settings::set('restore_job_active', $id);
        Settings::set('manual_restore_directory', $directory);
        Settings::set('restore_status', 'downloading');
        Settings::delete('restore_db_offset');
        Settings::delete('restore_error');
        if (function_exists('as_enqueue_async_action')) {
            $scheduled = as_enqueue_async_action(self::ACTION_HOOK, [$id]);
        } else {
            $scheduled = wp_schedule_single_event(time(), self::ACTION_HOOK, [$id]);
        }
        if (!$scheduled || is_wp_error($scheduled)) {
            Settings::set('restore_status', 'failed');
            return new \WP_Error('schedule_failed', 'Could not schedule restoration. Check that WordPress cron is working.');
        }
        Logger::info('Manual restore queued after administrator confirmation.', ['restore_job_id' => $id, 'backup_id' => $manifest['backup_id']]);
        return $id;
    }

    /** Start after the upload response; the scheduled action remains a fallback. */
    public static function dispatch_after_response($id)
    {
        add_action('shutdown', function () use ($id) {
            ignore_user_abort(true);
            @set_time_limit(0);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            self::run($id);
        }, 0);
    }

    /** One worker keeps the loaded restore code alive even if the plugin itself is restored. */
    public static function run($id)
    {
        if (Settings::get('restore_job_active') !== $id || Settings::get('restore_status') !== 'downloading') {
            return;
        }
        if (!add_option('spsync_manual_worker_' . $id, time(), '', false)) {
            return;
        }
        $finished = false;
        register_shutdown_function(function () use (&$finished, $id) {
            if (!$finished && Settings::get('restore_job_active') === $id) {
                self::fail('The restoration worker was interrupted. Recovery files were retained; ask your administrator to inspect them before retrying.');
            }
        });
        try {
            self::restore($id);
        } catch (\Throwable $error) {
            self::fail('Restoration stopped unexpectedly. Recovery files were retained. ' . $error->getMessage());
        } finally {
            $finished = true;
            delete_option('spsync_manual_worker_' . $id);
        }
    }

    private static function restore($id)
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $directory = Settings::get('manual_restore_directory');
        $manifest = LocalBackup::validate($directory);
        if (is_wp_error($manifest)) {
            self::fail($manifest->get_error_message());
            return;
        }
        $archives = [];
        $targets = [];
        $new_files = [];
        $has_database = false;
        $destinations = [];
        foreach ($manifest['parts'] as $part) {
            if ($part['filename'] === 'database.sql') {
                $has_database = true;
                continue;
            }
            $archive = $directory . '/' . $part['filename'];
            $entries = RestoreEngine::archive_entries($archive);
            if (is_wp_error($entries)) {
                self::fail($entries->get_error_message());
                return;
            }
            foreach ($entries as $entry) {
                if (substr($entry['filename'], -1) !== '/') {
                    $path = rtrim(ABSPATH, '/\\') . '/' . str_replace('\\', '/', $entry['filename']);
                    if (isset($destinations[strtolower($path)])) {
                        self::fail('More than one ZIP part contains the same destination file.');
                        return;
                    }
                    $destinations[strtolower($path)] = true;
                    $targets[] = $path;
                    if (!file_exists($path)) {
                        $new_files[] = $path;
                    }
                }
            }
            $archives[] = $archive;
        }
        $rollback = $directory . '/rollback';
        if (!RestoreEngine::create_rollback_snapshot($rollback, array_unique($targets), $has_database)) {
            self::fail('Could not create the recovery snapshot. No backup files were restored.');
            return;
        }
        // Keep the list on disk too, so an interrupted worker leaves recovery evidence.
        file_put_contents($rollback . '/new-files.json', wp_json_encode($new_files));
        try {
            Settings::set('restore_status', 'extracting');
            if ($archives && !RestoreEngine::restore_files($archives)) {
                throw new \RuntimeException('Could not restore the backup files.');
            }
            if ($has_database) {
                Settings::set('restore_status', 'restoring_database');
                do {
                    $done = RestoreEngine::restore_database($directory . '/database.sql');
                    if (is_wp_error($done)) {
                        throw new \RuntimeException($done->get_error_message());
                    }
                } while ($done === false);
            }
            wp_cache_flush();
            Settings::set('restore_status', 'completed');
            Settings::delete('manual_restore_directory');
            Logger::info('Manual restoration completed.', ['restore_job_id' => $id, 'backup_id' => $manifest['backup_id']]);
            LocalBackup::remove($directory);
        } catch (\Throwable $error) {
            foreach ($new_files as $file) {
                if (is_file($file) && !is_link($file)) {
                    @unlink($file);
                }
            }
            $recovered = RestoreEngine::trigger_rollback($rollback);
            wp_cache_flush();
            self::fail($error->getMessage() . ($recovered ? ' A recovery snapshot was restored.' : ' Recovery was incomplete; retain the recovery files and contact your administrator.'));
        }
    }

    private static function fail($message)
    {
        Settings::set('restore_status', 'failed');
        Settings::set('restore_error', $message);
        Logger::error('Manual restoration failed: ' . $message);
    }
}
