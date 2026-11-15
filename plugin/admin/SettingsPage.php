<?php
namespace SPSync\Admin;

use SPSync\Helpers\Settings;
use SPSync\Auth\GraphClient;

if (!defined('ABSPATH')) {
    exit;
}

class SettingsPage
{
    const MENU_SLUG = 'spsync-settings';

    /**
     * Initialize Settings Page hooks.
     */
    public static function init()
    {
        add_action('admin_menu', [ __CLASS__, 'add_menu_page' ]);
        add_action('admin_post_spsync_test_connection', [ __CLASS__, 'handle_connection_test' ]);
        add_action('admin_post_spsync_run_manual_backup', [ __CLASS__, 'handle_manual_backup' ]);
        add_action('admin_post_spsync_reset_lock', [ __CLASS__, 'handle_reset_lock' ]);
        add_action('admin_post_spsync_save_contents', [__CLASS__, 'handle_save_contents']);
        add_action('admin_post_spsync_upload_backup', [__CLASS__, 'handle_upload_backup']);
        add_action('admin_post_spsync_restore_uploaded', [__CLASS__, 'handle_restore_uploaded']);
        add_action('admin_post_spsync_discard_upload', [__CLASS__, 'handle_discard_upload']);
        add_action('admin_post_spsync_create_device_backup', [__CLASS__, 'handle_device_backup']);
        add_action('admin_post_spsync_download_backup', [__CLASS__, 'handle_download_backup']);
        add_action('admin_post_spsync_discard_device_backup', [__CLASS__, 'handle_discard_device_backup']);
    }

    private static function authorize($nonce)
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to manage backups.');
        }
        check_admin_referer($nonce);
    }

    private static function redirect_notice($tab, $success, $message)
    {
        wp_safe_redirect(add_query_arg([
            'page' => self::MENU_SLUG,
            'tab' => $tab,
            'spsync_notice' => $success ? 'success' : 'error',
            'spsync_msg' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_save_contents()
    {
        self::authorize('spsync_save_contents');
        $mode = isset($_POST['backup_scope']) ? wp_unslash($_POST['backup_scope']) : '';
        $directories = isset($_POST['backup_directories']) ? wp_unslash($_POST['backup_directories']) : [];
        $result = \SPSync\Backup\BackupSelection::save($mode, $directories, !empty($_POST['backup_include_database']));
        self::redirect_notice('contents', !is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'Backup contents saved. These choices apply to manual, scheduled, and dashboard-triggered backups.');
    }

    public static function handle_upload_backup()
    {
        self::authorize('spsync_upload_backup');
        if (empty($_POST['confirm_restore'])) {
            self::redirect_notice('restore', false, 'Confirm that you want to overwrite the website before uploading and restoring the backup.');
        }
        $folder = !empty($_FILES['backup_folder']['name'][0]);
        $files = !empty($_FILES['backup_files']['name'][0]);
        $zip = !empty($_FILES['backup_zip']['name']);
        if ((int) $folder + (int) $files + (int) $zip !== 1) {
            self::redirect_notice('restore', false, 'Choose one backup ZIP, a backup folder, or its individual files. Use only one upload option.');
        }
        $result = $zip ? \SPSync\Restore\LocalBackup::upload_zip($_FILES['backup_zip']) : \SPSync\Restore\LocalBackup::upload($folder ? $_FILES['backup_folder'] : $_FILES['backup_files']);
        if (is_wp_error($result)) {
            self::redirect_notice('restore', false, $result->get_error_message());
        }
        $old = get_user_meta(get_current_user_id(), 'spsync_pending_import', true);
        if ($old && $old !== \SPSync\Helpers\Settings::get('manual_restore_directory')) {
            \SPSync\Restore\LocalBackup::remove($old);
        }
        update_user_meta(get_current_user_id(), 'spsync_pending_import', $result['directory']);
        $started = \SPSync\Restore\ManualRestore::start($result['directory']);
        if (is_wp_error($started)) {
            self::redirect_notice('restore', false, 'Backup verified, but restoration could not start: ' . $started->get_error_message() . ' The verified upload is retained below so you can retry without uploading again.');
        }
        delete_user_meta(get_current_user_id(), 'spsync_pending_import');
        \SPSync\Restore\ManualRestore::dispatch_after_response($started);
        self::redirect_notice('restore', true, 'Backup verified. Restoration is starting automatically; no second confirmation is needed. Refresh this page for status.');
    }

    public static function handle_restore_uploaded()
    {
        self::authorize('spsync_restore_uploaded');
        if (empty($_POST['confirm_restore'])) {
            self::redirect_notice('restore', false, 'Confirm that you want to overwrite the website with this backup.');
        }
        $directory = get_user_meta(get_current_user_id(), 'spsync_pending_import', true);
        $result = \SPSync\Restore\ManualRestore::start($directory);
        if (!is_wp_error($result)) {
            delete_user_meta(get_current_user_id(), 'spsync_pending_import');
            \SPSync\Restore\ManualRestore::dispatch_after_response($result);
        }
        self::redirect_notice('restore', !is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'Restoration is starting now. WordPress will create a recovery snapshot and restore the verified backup. Refresh this page for progress.');
    }

    public static function handle_discard_upload()
    {
        self::authorize('spsync_discard_upload');
        $directory = get_user_meta(get_current_user_id(), 'spsync_pending_import', true);
        if ($directory && $directory !== Settings::get('manual_restore_directory')) {
            \SPSync\Restore\LocalBackup::remove($directory);
            delete_user_meta(get_current_user_id(), 'spsync_pending_import');
        }
        self::redirect_notice('restore', true, 'Pending upload discarded.');
    }

    public static function handle_device_backup()
    {
        self::authorize('spsync_create_device_backup');
        $result = \SPSync\Backup\DeviceBackup::start(get_current_user_id());
        self::redirect_notice('download', !is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'Your device backup is queued. Refresh this page, then click Download ZIP when it is ready.');
    }

    public static function handle_discard_device_backup()
    {
        self::authorize('spsync_discard_device_backup');
        $id = isset($_POST['backup_job']) && is_string($_POST['backup_job']) ? wp_unslash($_POST['backup_job']) : '';
        $removed = \SPSync\Backup\DeviceBackup::discard($id, get_current_user_id());
        self::redirect_notice('download', $removed, $removed ? 'The temporary server copy was removed. Files already downloaded to your device were not changed.' : 'This backup cannot be removed while it is running, or is no longer available.');
    }

    public static function handle_download_backup()
    {
        $id = isset($_GET['backup_job']) && is_string($_GET['backup_job']) ? wp_unslash($_GET['backup_job']) : '';
        self::authorize('spsync_download_backup_' . $id);
        $path = \SPSync\Backup\DeviceBackup::download($id, get_current_user_id());
        if (is_wp_error($path)) {
            wp_die(esc_html($path->get_error_message()));
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            wp_die('The backup could not be opened. Please try again.');
        }
        @set_time_limit(0);
        while (ob_get_level()) {
            ob_end_clean();
        }
        nocache_headers();
        send_nosniff_header();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        while (!feof($handle) && !connection_aborted()) {
            echo fread($handle, 1048576);
            flush();
        }
        fclose($handle);
        exit;
    }

    /**
     * Register main sidebar menu item in WordPress Admin side panel.
     */
    public static function add_menu_page()
    {
        add_menu_page(
            __('SharePoint Backup', 'schedule-backup-plugin'),
            __('SharePoint Backup', 'schedule-backup-plugin'),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-cloud-upload',
            80
        );
    }

    /**
     * Handle connection test trigger from the button.
     */
    public static function handle_connection_test()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'schedule-backup-plugin'));
        }

        check_admin_referer('spsync_test_conn_action');

        $result = GraphClient::test_connection();
        
        $type = $result['success'] ? 'success' : 'error';
        $message = urlencode($result['message']);

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=overview&spsync_notice=' . $type . '&spsync_msg=' . $message));
        exit;
    }

    /**
     * Handle manual backup execution trigger for instant testing.
     */
    public static function handle_manual_backup()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'schedule-backup-plugin'));
        }

        check_admin_referer('spsync_manual_backup_action');

        $backup_id = \SPSync\Backup\BackupEngine::run();
        
        if ($backup_id) {
            $message = urlencode("Manual backup successfully initiated! (Backup ID: $backup_id). Live progress is logged in System Logs.");
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=logs&spsync_notice=success&spsync_msg=' . $message));
        } else {
            $message = urlencode("Could not start manual backup. Another backup operation may already be in progress.");
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=overview&spsync_notice=error&spsync_msg=' . $message));
        }
        exit;
    }

    /**
     * Handle manual clearing of stale backup lock.
     */
    public static function handle_reset_lock()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'schedule-backup-plugin'));
        }

        check_admin_referer('spsync_reset_lock_action');

        Settings::delete('backup_running');
        $message = urlencode("Stale backup lock successfully cleared! You can now run a new backup.");
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=overview&spsync_notice=success&spsync_msg=' . $message));
        exit;
    }

    /**
     * Render the human-readable admin Settings UI.
     */
    public static function render_settings_page()
    {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            return;
        }

        // Active tab determination
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';

        // Handle Form Submission
        if (isset($_POST['spsync_save_settings'])) {
            check_admin_referer('spsync_save_settings_action');

            if ($active_tab === 'credentials') {
                Settings::set('tenant_id', sanitize_text_field($_POST['tenant_id']));
                Settings::set('client_id', sanitize_text_field($_POST['client_id']));
            
                $new_secret = $_POST['client_secret'] === '••••••••••••••••••••••••••••••••' ? Settings::get('client_secret') : wp_unslash($_POST['client_secret']);
                Settings::set('encrypt_secret', !empty($_POST['encrypt_secret']) ? '1' : '0');
                Settings::set('client_secret', $new_secret);

                $location_before = [Settings::get('site_url'), Settings::get('document_library'), Settings::get('root_folder')];
                Settings::set('site_url', esc_url_raw($_POST['site_url']));
                Settings::set('document_library', sanitize_text_field($_POST['document_library']));
                Settings::set('root_folder', sanitize_text_field($_POST['root_folder']));
                // Cached Graph IDs must follow a changed SharePoint location.
                if ($location_before !== [Settings::get('site_url'), Settings::get('document_library'), Settings::get('root_folder')]) {
                    Settings::delete('site_id');
                    Settings::delete('drive_id');
                    Settings::delete('root_folder_id');
                }

                $site_folder = \SPSync\Storage\RemoteInventory::sanitize_folder_name(wp_unslash($_POST['site_folder'] ?? ''));
                if ($site_folder !== '' && $site_folder !== \SPSync\Storage\RemoteInventory::site_folder_name()) {
                    Settings::set('site_folder', $site_folder);
                    \SPSync\Storage\RemoteInventory::forget();
                }

            }

            if ($active_tab === 'automation') {
                $method = sanitize_text_field($_POST['backup_method'] ?? 'manual');
                $new_frequency = $method === 'automatic' ? sanitize_text_field($_POST['backup_frequency'] ?? 'daily') : 'manual';
                if (!in_array($new_frequency, ['manual', 'daily', 'weekly', 'monthly', 'once'], true)) {
                    $new_frequency = 'manual';
                }
                $new_time      = sanitize_text_field($_POST['backup_time'] ?? Settings::get('backup_time', '02:00'));
                $new_dow       = sanitize_text_field($_POST['backup_day_of_week'] ?? Settings::get('backup_day_of_week', '0'));
                $new_dom       = sanitize_text_field($_POST['backup_day_of_month'] ?? Settings::get('backup_day_of_month', '1'));
                $new_date      = sanitize_text_field($_POST['backup_specific_date'] ?? Settings::get('backup_specific_date', ''));

                Settings::set('backup_frequency', $new_frequency);
                Settings::set('backup_time', $new_time);
                Settings::set('backup_day_of_week', $new_dow);
                Settings::set('backup_day_of_month', $new_dom);
                Settings::set('backup_specific_date', $new_date);
                Settings::set('retention_limit', sanitize_text_field($_POST['retention_limit'] ?? Settings::get('retention_limit', '30')));

                \SPSync\Scheduler\Scheduler::update_schedule($new_frequency, $new_time, $new_dow, $new_dom, $new_date);
            }

            if (isset($_POST['regenerate_api_key'])) {
                if (function_exists('random_bytes')) {
                    try {
                        Settings::set('api_key', bin2hex(random_bytes(32)));
                    } catch (\Exception $e) {}
                } else {
                    Settings::set('api_key', wp_generate_password(64, true, true));
                }
            }

            echo '<div class="updated notice is-dismissible" style="margin: 20px 0;"><p>' . esc_html__('Settings saved successfully.', 'schedule-backup-plugin') . '</p></div>';
        }

        if (isset($_GET['spsync_notice']) && isset($_GET['spsync_msg'])) {
            $class = ($_GET['spsync_notice'] === 'success') ? 'updated notice is-dismissible' : 'error notice';
            echo '<div class="' . esc_attr($class) . '" style="margin: 20px 0;"><p>' . esc_html(urldecode($_GET['spsync_msg'])) . '</p></div>';
        }

        $tenant_id        = Settings::get('tenant_id');
        $client_id        = Settings::get('client_id');
        $client_secret    = Settings::get('client_secret');
        $site_url         = Settings::get('site_url');
        $document_library = Settings::get('document_library');
        $root_folder      = Settings::get('root_folder');
        $encrypt_secret   = Settings::get('encrypt_secret', '1');
        $api_key          = Settings::get('api_key');
        
        $backup_frequency     = Settings::get('backup_frequency', 'manual');
        $backup_time          = Settings::get('backup_time', '02:00');
        $backup_day_of_week   = Settings::get('backup_day_of_week', '0');
        $backup_day_of_month  = Settings::get('backup_day_of_month', '1');
        $backup_specific_date = Settings::get('backup_specific_date', '');
        $retention_limit      = Settings::get('retention_limit', '30');

        $masked_secret = empty($client_secret) ? '' : '••••••••••••••••••••••••••••••••';
        $is_configured = !empty($tenant_id) && !empty($client_id) && !empty($site_url);
        ?>

        <style>
            .spsync-wrap {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                margin: 20px 20px 0 0;
                color: #1e293b;
            }
            .spsync-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: #ffffff;
                padding: 24px 30px;
                border-radius: 16px;
                border: 1px solid #e2e8f0;
                box-shadow: 0 4px 12px rgba(0,0,0,0.03);
                margin-bottom: 24px;
            }
            .spsync-title-area h1 {
                font-size: 1.6rem;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 6px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .spsync-title-area p {
                margin: 0;
                color: #64748b;
                font-size: 0.95rem;
            }
            .spsync-badge {
                display: inline-block;
                padding: 6px 14px;
                border-radius: 20px;
                font-size: 0.8rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .spsync-badge-success { background: #d1fae5; color: #047857; }
            .spsync-badge-warning { background: #fef3c7; color: #b45309; }

            /* Navigation Tabs */
            .spsync-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                border-bottom: 2px solid #e2e8f0;
                margin-bottom: 24px;
            }
            .spsync-tab-item {
                padding: 12px 20px;
                font-size: 0.95rem;
                font-weight: 600;
                color: #64748b;
                text-decoration: none;
                border-bottom: 3px solid transparent;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .spsync-tab-item:hover { color: #dc2626; }
            .spsync-tab-item.active {
                color: #dc2626;
                border-bottom-color: #dc2626;
                background: #ffffff;
                border-radius: 8px 8px 0 0;
            }

            /* Cards & Grid Layout */
            .spsync-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 24px;
            }
            .spsync-card {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                padding: 28px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            }
            .spsync-card-title {
                font-size: 1.15rem;
                font-weight: 700;
                color: #0f172a;
                margin-top: 0;
                margin-bottom: 16px;
                padding-bottom: 12px;
                border-bottom: 1px solid #f1f5f9;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            /* Buttons & Actions */
            .spsync-btn-primary {
                background: linear-gradient(135deg, #e63946, #dc2626) !important;
                border: none !important;
                color: #ffffff !important;
                font-weight: 600 !important;
                padding: 10px 20px !important;
                border-radius: 8px !important;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                box-shadow: 0 4px 12px rgba(220,38,38,0.2);
            }
            .spsync-btn-primary:hover {
                background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
                color: #ffffff !important;
            }
            .spsync-btn-secondary {
                background: #f8fafc !important;
                border: 1px solid #cbd5e1 !important;
                color: #334155 !important;
                font-weight: 600 !important;
                padding: 10px 20px !important;
                border-radius: 8px !important;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .spsync-btn-secondary:hover {
                background: #f1f5f9 !important;
                color: #0f172a !important;
            }

            /* Custom Tables */
            .spsync-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 12px;
            }
            .spsync-table th {
                background: #f8fafc;
                color: #64748b;
                font-weight: 600;
                font-size: 0.8rem;
                text-transform: uppercase;
                padding: 12px 16px;
                border-bottom: 1px solid #e2e8f0;
                text-align: left;
            }
            .spsync-table td {
                padding: 14px 16px;
                border-bottom: 1px solid #f1f5f9;
                font-size: 0.9rem;
            }
            .spsync-table tr:last-child td { border-bottom: none; }
        </style>

        <div class="spsync-wrap">
            <!-- Top Header Banner -->
            <div class="spsync-header">
                <div class="spsync-title-area">
                    <h1><span class="dashicons dashicons-cloud-upload" style="font-size: 1.8rem; height: auto;"></span> SharePoint Backup & Restore</h1>
                    <p><?php esc_html_e('Automated site backups & cloud integration using Microsoft Graph API.', 'schedule-backup-plugin'); ?></p>
                </div>
                <div>
                    <?php if ($is_configured) : ?>
                        <span class="spsync-badge spsync-badge-success">● Connected & Configured</span>
                    <?php else : ?>
                        <span class="spsync-badge spsync-badge-warning">● Device backup available · SharePoint not configured</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabbed Navigation -->
            <div class="spsync-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=overview')); ?>" class="spsync-tab-item <?php echo $active_tab === 'overview' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-dashboard"></span> Overview & Testing
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=credentials')); ?>" class="spsync-tab-item <?php echo $active_tab === 'credentials' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-lock"></span> SharePoint & OAuth Config
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=automation')); ?>" class="spsync-tab-item <?php echo $active_tab === 'automation' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-calendar-alt"></span> Backup Method & Schedule
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=download')); ?>" class="spsync-tab-item <?php echo $active_tab === 'download' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-download"></span> Manual Backup & Download
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=contents')); ?>" class="spsync-tab-item <?php echo $active_tab === 'contents' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-portfolio"></span> Backup Contents
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=restore')); ?>" class="spsync-tab-item <?php echo $active_tab === 'restore' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-upload"></span> Manual Restore
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=logs')); ?>" class="spsync-tab-item <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> Execution Logs
                </a>
            </div>

            <!-- TAB 1: OVERVIEW & TESTING -->
            <?php if ($active_tab === 'overview') : ?>
                <div class="spsync-grid">
                    <!-- Manual Backup Execution Card -->
                    <div class="spsync-card">
                        <h3 class="spsync-card-title"><span class="dashicons dashicons-backup" style="color: #dc2626;"></span> Back Up Your Website</h3>
                        <p style="color: #64748b; font-size: 0.9rem; line-height: 1.5; margin-bottom: 20px;">
                            Choose manual backups saved to your device or automatic backups to SharePoint in Backup Method & Schedule. Both use your saved Backup Contents selection; the default is the entire website and database.
                        </p>
                        <p><a class="spsync-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=download')); ?>">Create Backup for My Device</a></p>
                        <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=automation')); ?>">Choose Backup Method & Schedule</a></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="spsync_run_manual_backup" />
                            <?php wp_nonce_field('spsync_manual_backup_action'); ?>
                            <button type="submit" class="spsync-btn-primary" style="width: 100%; justify-content: center;">
                                <span class="dashicons dashicons-controls-play"></span> Back Up to SharePoint Now
                            </button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;">
                            <input type="hidden" name="action" value="spsync_reset_lock" />
                            <?php wp_nonce_field('spsync_reset_lock_action'); ?>
                            <button type="submit" class="spsync-btn-secondary" style="width: 100%; justify-content: center; font-size: 0.85rem;">
                                <span class="dashicons dashicons-unlock"></span> Clear / Reset Stale Lock
                            </button>
                        </form>
                    </div>

                    <!-- Connection Diagnostics Card -->
                    <div class="spsync-card">
                        <h3 class="spsync-card-title"><span class="dashicons dashicons-networking" style="color: #dc2626;"></span> SharePoint Health Diagnostics</h3>
                        <p style="color: #64748b; font-size: 0.9rem; line-height: 1.5; margin-bottom: 20px;">
                            Test communication with Microsoft Graph API, verify OAuth access tokens, and check document library drive access.
                        </p>
                        <?php if ($is_configured) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="spsync_test_connection" />
                                <?php wp_nonce_field('spsync_test_conn_action'); ?>
                                <button type="submit" class="spsync-btn-secondary" style="width: 100%; justify-content: center;">
                                    <span class="dashicons dashicons-update"></span> Test SharePoint Connection
                                </button>
                            </form>
                        <?php else : ?>
                            <div style="background: #fffbebfb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; color: #92400e; font-size: 0.85rem;">
                                Please configure your Tenant ID, Client ID, & Site URL under the <strong>SharePoint & OAuth Config</strong> tab first.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Central Dashboard API Key Card -->
                    <div class="spsync-card" style="grid-column: span 2;">
                        <h3 class="spsync-card-title"><span class="dashicons dashicons-key" style="color: #dc2626;"></span> Central Dashboard Secret API Key</h3>
                        <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 14px;">
                            Use this key to pair this website with your central <strong>Laravel Backup Dashboard</strong>. Include it in the HTTP header:
                        </p>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="spsync-api-key-input" value="<?php echo esc_attr($api_key); ?>" readonly class="regular-text" style="font-family: monospace; background: #f8fafc; font-size: 0.9rem; padding: 10px 14px; width: 100%; border: 1px solid #cbd5e1; border-radius: 8px;" />
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('spsync-api-key-input').value); alert('API Key copied to clipboard!');" class="spsync-btn-secondary">
                                Copy Key
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'download') : ?>
                <?php
                $device_job_id = get_user_meta(get_current_user_id(), 'spsync_device_backup', true);
                $device_job = \SPSync\Backup\DeviceBackup::get($device_job_id, get_current_user_id());
                ?>
                <div class="spsync-card" style="margin-bottom: 24px;">
                    <h3 class="spsync-card-title">Manual Backup to My Device</h3>
                    <p>Create a backup whenever you want, then download its ZIP to your computer or phone. No SharePoint account or credentials are needed.</p>
                    <p>Your saved <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=contents')); ?>">Backup Contents</a> selection is used. This does not change your automatic backup schedule.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="spsync_create_device_backup" />
                        <?php wp_nonce_field('spsync_create_device_backup'); ?>
                        <button type="submit" class="spsync-btn-primary">Create Backup for My Device</button>
                    </form>
                    <p class="description">The backup is prepared in the background. Refresh this page for status. The temporary server copy expires after 24 hours; starting another backup replaces your previous completed server copy. WordPress cron or Action Scheduler must be running.</p>
                </div>
                <?php if ($device_job) : ?>
                    <div class="spsync-card">
                        <h3 class="spsync-card-title">Your Latest Device Backup</h3>
                        <p><strong>Status:</strong> <?php echo esc_html($device_job['expires_at'] <= time() ? 'Expired' : ucfirst($device_job['status'])); ?></p>
                        <?php if ($device_job['error']) : ?><p><?php echo esc_html($device_job['error']); ?></p><?php endif; ?>
                        <?php if ($device_job['status'] === 'ready' && $device_job['expires_at'] > time()) : ?>
                            <p><?php echo esc_html($device_job['filename'] . ' — ' . size_format($device_job['filesize'])); ?></p>
                            <?php $download_url = wp_nonce_url(add_query_arg(['action' => 'spsync_download_backup', 'backup_job' => $device_job_id], admin_url('admin-post.php')), 'spsync_download_backup_' . $device_job_id); ?>
                            <p><a class="spsync-btn-primary" href="<?php echo esc_url($download_url); ?>">Download ZIP to My Device</a></p>
                            <p>Your browser controls where the file is saved. For manual restoration later, extract this ZIP and upload the backup folder inside it in Manual Restore.</p>
                        <?php endif; ?>
                        <p><a class="spsync-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=download')); ?>">Refresh Status</a></p>
                        <?php if ($device_job['status'] !== 'running') : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="spsync_discard_device_backup" />
                                <input type="hidden" name="backup_job" value="<?php echo esc_attr($device_job_id); ?>" />
                                <?php wp_nonce_field('spsync_discard_device_backup'); ?>
                                <button type="submit" class="spsync-btn-secondary">Remove Temporary Server Copy</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($active_tab === 'contents') : ?>
                <?php $selection = \SPSync\Backup\BackupSelection::get(); $directories = \SPSync\Backup\BackupSelection::directories(); ?>
                <div class="spsync-card">
                    <h3 class="spsync-card-title">Choose what to back up</h3>
                    <p>These choices apply to every backup, including scheduled and dashboard-triggered backups.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="spsync_save_contents" />
                        <?php wp_nonce_field('spsync_save_contents'); ?>
                        <p><label><input type="radio" name="backup_scope" value="full" <?php checked($selection['mode'], 'full'); ?> /> <strong>Entire website and database (default)</strong></label></p>
                        <p class="description">Includes WordPress core, configuration, wp-content, and the database. Generated backups and the existing cache, temporary-file, log and archive exclusions still apply.</p>
                        <p><label><input type="radio" name="backup_scope" value="selected" <?php checked($selection['mode'], 'selected'); ?> /> <strong>Only selected wp-content directories</strong></label></p>
                        <fieldset id="spsync-selected-contents" style="padding: 12px 20px; border: 1px solid #e2e8f0; margin-bottom: 16px;">
                            <legend>Available directories</legend>
                            <?php foreach ($directories as $name => $path) : ?>
                                <p><label><input type="checkbox" name="backup_directories[]" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name, $selection['directories'], true)); ?> /> <?php echo esc_html(ucfirst($name)); ?> <code><?php echo esc_html('wp-content/' . $name); ?></code></label></p>
                            <?php endforeach; ?>
                            <?php if (!$directories) : ?><p>No readable directories were found inside wp-content.</p><?php endif; ?>
                            <hr />
                            <p><label><input type="checkbox" name="backup_include_database" value="1" <?php checked(Settings::get('backup_include_database', '0'), '1'); ?> /> Include the WordPress database too</label></p>
                            <p class="description">Selected-directory backups include every regular file inside those directories, except the plugin's own backup storage. Symlinks are excluded. Database-only backups are also supported.</p>
                        </fieldset>
                        <button type="submit" class="spsync-btn-primary">Save Backup Contents</button>
                    </form>
                </div>
                <script>
                (function () {
                    const fieldset = document.getElementById('spsync-selected-contents');
                    const radios = document.querySelectorAll('input[name="backup_scope"]');
                    function updateSelection() { fieldset.disabled = document.querySelector('input[name="backup_scope"]:checked').value === 'full'; }
                    radios.forEach(function (radio) { radio.addEventListener('change', updateSelection); });
                    updateSelection();
                }());
                </script>
            <?php endif; ?>

            <?php if ($active_tab === 'restore') : ?>
                <?php
                $pending_directory = get_user_meta(get_current_user_id(), 'spsync_pending_import', true);
                $pending = $pending_directory ? \SPSync\Restore\LocalBackup::validate($pending_directory) : null;
                $restore_status = Settings::get('restore_status', 'idle');
                ?>
                <div class="spsync-card" style="margin-bottom: 24px;">
                    <h3 class="spsync-card-title">Upload and restore a backup</h3>
                    <p>No SharePoint connection is needed. Upload the ZIP downloaded by this plugin, a ZIP containing one plugin backup folder, or choose the folder/files directly.</p>
                    <p>The plugin verifies <code>manifest.json</code>, all ZIP parts and <code>database.sql</code> (if included), then immediately starts restoration. There is no second Start button after a successful upload. Backups from other plugins are not supported.</p>
                    <form id="spsync-upload-restore-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="spsync_upload_backup" />
                        <?php wp_nonce_field('spsync_upload_backup'); ?>
                        <p><label for="spsync-backup-zip"><strong>Choose backup ZIP</strong></label><br /><input id="spsync-backup-zip" type="file" name="backup_zip" accept=".zip,application/zip" /></p>
                        <p><label for="spsync-backup-folder"><strong>Or choose backup folder</strong></label><br /><input id="spsync-backup-folder" type="file" name="backup_folder[]" webkitdirectory directory multiple /></p>
                        <p><label for="spsync-backup-files"><strong>Or choose individual backup files</strong></label><br /><input id="spsync-backup-files" type="file" name="backup_files[]" accept=".json,.sql,.zip" multiple /></p>
                        <p class="description">Server limits: each file <?php echo esc_html(size_format(wp_max_upload_size())); ?>; total request <?php echo esc_html(ini_get('post_max_size')); ?>; up to <?php echo esc_html(ini_get('max_file_uploads')); ?> files. A missing or truncated part will fail verification.</p>
                        <p><label><input type="checkbox" name="confirm_restore" value="1" required /> I confirm that restoration will overwrite the website files and database included in this backup.</label></p>
                        <p id="spsync-upload-error" role="alert" style="display:none; color:#b91c1c;"></p>
                        <p><button id="spsync-upload-restore-button" type="submit" class="spsync-btn-primary">Upload, Verify &amp; Restore</button></p>
                    </form>
                    <script>
                    document.getElementById('spsync-upload-restore-form').addEventListener('submit', function (event) {
                        const count = ['spsync-backup-zip', 'spsync-backup-folder', 'spsync-backup-files'].filter(function (id) { return document.getElementById(id).files.length > 0; }).length;
                        if (count !== 1) {
                            event.preventDefault();
                            const error = document.getElementById('spsync-upload-error');
                            error.textContent = 'Choose one ZIP, a folder, or individual files. Use only one upload option.';
                            error.style.display = '';
                            return;
                        }
                        const button = document.getElementById('spsync-upload-restore-button');
                        button.disabled = true;
                        button.textContent = 'Uploading, verifying and starting restoration…';
                    });
                    </script>
                </div>
                <?php if ($pending && !is_wp_error($pending)) : ?>
                    <div class="spsync-card" style="margin-bottom: 24px;">
                        <h3 class="spsync-card-title">Previously verified backup</h3>
                        <p>This upload was retained from an earlier attempt. You can restore it now without uploading it again. New uploads start automatically after verification.</p>
                        <p><strong>Backup:</strong> <?php echo esc_html($pending['backup_id']); ?></p>
                        <p><strong>Files to restore:</strong></p>
                        <ul style="list-style: disc; padding-left: 24px;">
                            <?php foreach ($pending['parts'] as $part) : ?>
                                <li><?php echo esc_html($part['filename'] . ' — ' . size_format($part['filesize'])); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p><strong>Only the files and database included in this backup will be restored.</strong> A database restore can replace users, passwords and site settings. Existing files outside the backup remain in place.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="spsync_restore_uploaded" />
                            <?php wp_nonce_field('spsync_restore_uploaded'); ?>
                            <p><label><input type="checkbox" name="confirm_restore" value="1" required /> I understand that restoration overwrites the included website files and database.</label></p>
                            <button type="submit" class="spsync-btn-primary">Restore Verified Backup Now</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px;">
                            <input type="hidden" name="action" value="spsync_discard_upload" />
                            <?php wp_nonce_field('spsync_discard_upload'); ?>
                            <button type="submit" class="spsync-btn-secondary">Discard Upload</button>
                        </form>
                    </div>
                <?php elseif (is_wp_error($pending)) : ?>
                    <p><?php echo esc_html($pending->get_error_message()); ?></p>
                <?php endif; ?>
                <div class="spsync-card">
                    <h3 class="spsync-card-title">Restoration status</h3>
                    <p><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $restore_status))); ?></strong></p>
                    <?php if (Settings::get('restore_error')) : ?><p><?php echo esc_html(Settings::get('restore_error')); ?></p><?php endif; ?>
                    <?php if ($restore_status === 'failed' && Settings::get('manual_restore_directory')) : ?><p>Private recovery location for your server administrator: <code><?php echo esc_html(Settings::get('manual_restore_directory')); ?></code></p><?php endif; ?>
                    <p>Restoration starts automatically after successful verification. Refresh this page for the latest status. WordPress cron or Action Scheduler is retained as a fallback. Recovery files are retained if restoration fails.</p>
                    <a class="spsync-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=restore')); ?>">Refresh Status</a>
                </div>
            <?php endif; ?>

            <!-- TAB 2: CREDENTIALS & SHAREPOINT CONFIG -->
            <?php if ($active_tab === 'credentials') : ?>
                <form method="post" action="">
                    <?php wp_nonce_field('spsync_save_settings_action'); ?>
                    
                    <div class="spsync-card" style="margin-bottom: 24px;">
                        <h3 class="spsync-card-title"><span class="dashicons dashicons-shield"></span> Microsoft Entra ID (Azure AD) Credentials</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="tenant_id"><?php esc_html_e('Tenant ID', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <input name="tenant_id" type="text" id="tenant_id" value="<?php echo esc_attr($tenant_id); ?>" class="regular-text" style="width: 100%; max-width: 480px; padding: 8px 12px;" placeholder="00000000-0000-0000-0000-000000000000" />
                                    <p class="description"><?php esc_html_e('Your Azure AD Directory Tenant ID.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="client_id"><?php esc_html_e('Client ID', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <input name="client_id" type="text" id="client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" style="width: 100%; max-width: 480px; padding: 8px 12px;" placeholder="00000000-0000-0000-0000-000000000000" />
                                    <p class="description"><?php esc_html_e('Your registered Azure Application Client ID.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="client_secret"><?php esc_html_e('Client Secret', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <div style="display: flex; gap: 8px; max-width: 480px;">
                                        <input name="client_secret" type="password" id="client_secret" value="<?php echo esc_attr($masked_secret); ?>" class="regular-text" style="width: 100%; padding: 8px 12px;" />
                                        <button type="button" class="button" onclick="const input = document.getElementById('client_secret'); input.type = input.type === 'password' ? 'text' : 'password';">Show/Hide</button>
                                    </div>
                                    <p class="description"><?php esc_html_e('The Application Client Secret value.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Encryption', 'schedule-backup-plugin'); ?></th>
                                <td>
                                    <label for="encrypt_secret">
                                        <input name="encrypt_secret" type="checkbox" id="encrypt_secret" value="1" <?php checked($encrypt_secret, '1'); ?> />
                                        <?php esc_html_e('Encrypt Client Secret in Database using AES-256', 'schedule-backup-plugin'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="spsync-card" style="margin-bottom: 24px;">
                        <h3 class="spsync-card-title"><span class="dashicons dashicons-admin-site"></span> SharePoint Target Path</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="site_url"><?php esc_html_e('SharePoint Site URL', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <input name="site_url" type="url" id="site_url" value="<?php echo esc_attr($site_url); ?>" class="regular-text" style="width: 100%; max-width: 480px; padding: 8px 12px;" placeholder="https://tenant.sharepoint.com/sites/sitename" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="document_library"><?php esc_html_e('Document Library', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <input name="document_library" type="text" id="document_library" value="<?php echo esc_attr($document_library); ?>" class="regular-text" style="width: 100%; max-width: 480px; padding: 8px 12px;" placeholder="Documents" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="root_folder"><?php esc_html_e('Root Backup Folder', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <input name="root_folder" type="text" id="root_folder" value="<?php echo esc_attr($root_folder); ?>" class="regular-text" style="width: 100%; max-width: 480px; padding: 8px 12px;" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="site_folder"><?php esc_html_e('Site Folder', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <input name="site_folder" type="text" id="site_folder" value="<?php echo esc_attr(\SPSync\Storage\RemoteInventory::site_folder_name()); ?>" class="regular-text" style="width: 100%; max-width: 480px; padding: 8px 12px;" />
                                    <p class="description"><?php esc_html_e('This website\'s own folder inside the Root Backup Folder, so several websites can share one SharePoint library without seeing or pruning each other\'s backups. Letters, numbers, dots, dashes and underscores only. Renaming it later hides older backups from retention and verification.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <button type="submit" name="spsync_save_settings" class="spsync-btn-primary">Save Credentials & Configuration</button>
                </form>
            <?php endif; ?>

            <!-- TAB 3: AUTOMATION & RETENTION -->
            <?php if ($active_tab === 'automation') : ?>
                <?php
                $next_event = wp_next_scheduled(\SPSync\Scheduler\Scheduler::EVENT_HOOK);
                ?>
                <form method="post" action="">
                    <?php wp_nonce_field('spsync_save_settings_action'); ?>
                    
                    <div class="spsync-card" style="margin-bottom: 24px;">
                        <h3 class="spsync-card-title"><span class="dashicons dashicons-clock"></span> Choose How to Back Up</h3>
                        <p><label><input type="radio" name="backup_method" value="manual" <?php checked($backup_frequency, 'manual'); ?> onchange="toggleBackupMethod()" /> <strong>Manual backups to my device</strong></label></p>
                        <p class="description">You decide when to create and download a backup. Saving this choice turns off automatic backups.</p>
                        <p><label><input type="radio" name="backup_method" value="automatic" <?php checked($backup_frequency !== 'manual'); ?> onchange="toggleBackupMethod()" /> <strong>Automatic backups to SharePoint</strong></label></p>
                        <p class="description">Choose a schedule below and configure SharePoint credentials. Your device cannot receive unattended backups while its browser is closed.</p>
                        <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=download')); ?>">Create a manual device download now</a></p>

                        <div id="spsync-automatic-options">
                        
                        <?php if ($next_event) : ?>
                            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; color: #065f46; font-size: 0.85rem; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-calendar-alt" style="color: #059669;"></span>
                                <span><strong>Next Scheduled Backup:</strong> <?php echo esc_html(wp_date('Y-m-d H:i:s T', $next_event)); ?> (in <?php echo esc_html(human_time_diff($next_event)); ?>)</span>
                            </div>
                        <?php else : ?>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; color: #64748b; font-size: 0.85rem;">
                                No automated backup schedule is currently active. Select a frequency below.
                            </div>
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="backup_frequency"><?php esc_html_e('Backup Frequency', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <select name="backup_frequency" id="backup_frequency" style="padding: 8px 12px; min-width: 260px;" onchange="toggleScheduleFields(this.value)">
                                        <option value="daily" <?php selected($backup_frequency, 'daily'); ?>><?php esc_html_e('Daily at Specific Time', 'schedule-backup-plugin'); ?></option>
                                        <option value="weekly" <?php selected($backup_frequency, 'weekly'); ?>><?php esc_html_e('Weekly on Specific Day & Time', 'schedule-backup-plugin'); ?></option>
                                        <option value="monthly" <?php selected($backup_frequency, 'monthly'); ?>><?php esc_html_e('Monthly on Specific Date & Time', 'schedule-backup-plugin'); ?></option>
                                        <option value="once" <?php selected($backup_frequency, 'once'); ?>><?php esc_html_e('One-Time on Specific Date & Time', 'schedule-backup-plugin'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Choose how often and when to trigger automated site exports.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>

                            <!-- Specific Time Selection (Daily, Weekly, Monthly, Once) -->
                            <tr id="row_backup_time" style="<?php echo $backup_frequency === 'manual' ? 'display:none;' : ''; ?>">
                                <th scope="row"><label for="backup_time"><?php esc_html_e('Execution Time', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <input type="time" name="backup_time" id="backup_time" value="<?php echo esc_attr($backup_time); ?>" style="padding: 6px 12px; font-size: 0.95rem;" />
                                    <p class="description"><?php esc_html_e('Site local time to run the backup (24-hour format e.g. 02:00 for 2:00 AM).', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>

                            <!-- Day of the Week (Weekly) -->
                            <tr id="row_day_of_week" style="<?php echo $backup_frequency === 'weekly' ? '' : 'display:none;'; ?>">
                                <th scope="row"><label for="backup_day_of_week"><?php esc_html_e('Day of the Week', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <select name="backup_day_of_week" id="backup_day_of_week" style="padding: 8px 12px; min-width: 200px;">
                                        <option value="0" <?php selected($backup_day_of_week, '0'); ?>>Sunday</option>
                                        <option value="1" <?php selected($backup_day_of_week, '1'); ?>>Monday</option>
                                        <option value="2" <?php selected($backup_day_of_week, '2'); ?>>Tuesday</option>
                                        <option value="3" <?php selected($backup_day_of_week, '3'); ?>>Wednesday</option>
                                        <option value="4" <?php selected($backup_day_of_week, '4'); ?>>Thursday</option>
                                        <option value="5" <?php selected($backup_day_of_week, '5'); ?>>Friday</option>
                                        <option value="6" <?php selected($backup_day_of_week, '6'); ?>>Saturday</option>
                                    </select>
                                    <p class="description"><?php esc_html_e('The day of each week when the backup should run.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>

                            <!-- Day of the Month (Monthly) -->
                            <tr id="row_day_of_month" style="<?php echo $backup_frequency === 'monthly' ? '' : 'display:none;'; ?>">
                                <th scope="row"><label for="backup_day_of_month"><?php esc_html_e('Day of the Month', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <select name="backup_day_of_month" id="backup_day_of_month" style="padding: 8px 12px; min-width: 200px;">
                                        <?php for ($d = 1; $d <= 28; $d++) : ?>
                                            <option value="<?php echo $d; ?>" <?php selected((int)$backup_day_of_month, $d); ?>>
                                                Day <?php echo $d; ?> of each month
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e('The day number of the month (1-28) to trigger the monthly backup.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>

                            <!-- One-Time Specific Date (Once) -->
                            <tr id="row_specific_date" style="<?php echo $backup_frequency === 'once' ? '' : 'display:none;'; ?>">
                                <th scope="row"><label for="backup_specific_date"><?php esc_html_e('Specific Execution Date', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <input type="date" name="backup_specific_date" id="backup_specific_date" value="<?php echo esc_attr($backup_specific_date); ?>" min="<?php echo wp_date('Y-m-d'); ?>" style="padding: 6px 12px; font-size: 0.95rem;" />
                                    <p class="description"><?php esc_html_e('Exact calendar date on which this single one-time backup will execute.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="retention_limit"><?php esc_html_e('SharePoint Retention Policy', 'schedule-backup-plugin'); ?></label></th>
                                <td>
                                    <select name="retention_limit" id="retention_limit" style="padding: 8px 12px; min-width: 240px;">
                                        <option value="5" <?php selected($retention_limit, '5'); ?>><?php esc_html_e('Keep last 5 backups', 'schedule-backup-plugin'); ?></option>
                                        <option value="10" <?php selected($retention_limit, '10'); ?>><?php esc_html_e('Keep last 10 backups', 'schedule-backup-plugin'); ?></option>
                                        <option value="30" <?php selected($retention_limit, '30'); ?>><?php esc_html_e('Keep last 30 backups (Default)', 'schedule-backup-plugin'); ?></option>
                                        <option value="50" <?php selected($retention_limit, '50'); ?>><?php esc_html_e('Keep last 50 backups', 'schedule-backup-plugin'); ?></option>
                                        <option value="100" <?php selected($retention_limit, '100'); ?>><?php esc_html_e('Keep last 100 backups', 'schedule-backup-plugin'); ?></option>
                                        <option value="0" <?php selected($retention_limit, '0'); ?>><?php esc_html_e('Unlimited (Never delete)', 'schedule-backup-plugin'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Automatically removes oldest backups from SharePoint when count exceeds this limit to save cloud space.', 'schedule-backup-plugin'); ?></p>
                                </td>
                            </tr>
                        </table>
                        </div>
                    </div>

                    <button type="submit" name="spsync_save_settings" class="spsync-btn-primary">Save Backup Method & Schedule</button>
                </form>

                <script>
                    function toggleBackupMethod() {
                        const automatic = document.querySelector('input[name="backup_method"]:checked').value === 'automatic';
                        const options = document.getElementById('spsync-automatic-options');
                        options.style.display = automatic ? '' : 'none';
                        options.querySelectorAll('input,select').forEach(function (input) { input.disabled = !automatic; });
                        if (automatic) toggleScheduleFields(document.getElementById('backup_frequency').value);
                    }
                    function toggleScheduleFields(freq) {
                        const rowTime = document.getElementById('row_backup_time');
                        const rowDow = document.getElementById('row_day_of_week');
                        const rowDom = document.getElementById('row_day_of_month');
                        const rowDate = document.getElementById('row_specific_date');

                        if (rowTime) rowTime.style.display = (freq === 'manual') ? 'none' : '';
                        if (rowDow) rowDow.style.display = (freq === 'weekly') ? '' : 'none';
                        if (rowDom) rowDom.style.display = (freq === 'monthly') ? '' : 'none';
                        if (rowDate) rowDate.style.display = (freq === 'once') ? '' : 'none';
                    }
                    toggleBackupMethod();
                </script>
            <?php endif; ?>

            <!-- TAB 4: SYSTEM LOGS -->
            <?php if ($active_tab === 'logs') : ?>
                <div class="spsync-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 class="spsync-card-title" style="margin: 0; padding: 0; border: none;"><span class="dashicons dashicons-list-view"></span> Execution & Activity Logs</h3>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=logs')); ?>" class="spsync-btn-secondary">
                            <span class="dashicons dashicons-update"></span> Refresh Logs
                        </a>
                    </div>

                    <?php
                    $log_table = $wpdb->prefix . 'spsync_logs';
                    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$log_table'");

                    if ($table_exists) {
                        $logs = $wpdb->get_results("SELECT * FROM $log_table ORDER BY id DESC LIMIT 50", ARRAY_A);
                    } else {
                        $logs = [];
                    }
                    ?>

                    <?php if (empty($logs)) : ?>
                        <div style="text-align: center; padding: 40px 0; color: #64748b;">
                            No system logs recorded yet.
                        </div>
                    <?php else : ?>
                        <div style="overflow-x: auto;">
                            <table class="spsync-table">
                                <thead>
                                    <tr>
                                        <th style="width: 160px;">Timestamp</th>
                                        <th style="width: 100px;">Level</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log) : ?>
                                        <tr>
                                            <td style="font-family: monospace; color: #64748b; font-size: 0.85rem;">
                                                <?php echo esc_html($log['created_at'] ?? $log['timestamp'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $level = strtoupper($log['level'] ?? 'INFO'); 
                                                $color = '#2563eb'; $bg = '#eff6ff';
                                                if ($level === 'ERROR' || $level === 'CRITICAL') { $color = '#dc2626'; $bg = '#fef2f2'; }
                                                elseif ($level === 'WARNING') { $color = '#b45309'; $bg = '#fffbeba'; }
                                                ?>
                                                <span class="spsync-badge" style="background: <?php echo $bg; ?>; color: <?php echo $color; ?>; font-size: 0.7rem;">
                                                    <?php echo esc_html($level); ?>
                                                </span>
                                            </td>
                                            <td style="font-family: monospace; font-size: 0.85rem; color: #1e293b;">
                                                <?php echo esc_html($log['message']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
