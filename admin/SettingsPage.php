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

        // Clear stale lock before initiating manual test run
        Settings::delete('backup_running');

        $backup_id = \SPSync\Backup\BackupEngine::run(true);
        
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

            Settings::set('tenant_id', sanitize_text_field($_POST['tenant_id']));
            Settings::set('client_id', sanitize_text_field($_POST['client_id']));
            
            $new_secret = $_POST['client_secret'];
            if ($new_secret !== '••••••••••••••••••••••••••••••••') {
                Settings::set('client_secret', $new_secret);
            }

            Settings::set('site_url', esc_url_raw($_POST['site_url']));
            Settings::set('document_library', sanitize_text_field($_POST['document_library']));
            Settings::set('root_folder', sanitize_text_field($_POST['root_folder']));
            Settings::set('encrypt_secret', isset($_POST['encrypt_secret']) ? '1' : '0');

            $new_frequency = sanitize_text_field($_POST['backup_frequency'] ?? 'manual');
            $new_time      = sanitize_text_field($_POST['backup_time'] ?? '02:00');
            $new_dow       = sanitize_text_field($_POST['backup_day_of_week'] ?? '0');
            $new_dom       = sanitize_text_field($_POST['backup_day_of_month'] ?? '1');
            $new_date      = sanitize_text_field($_POST['backup_specific_date'] ?? '');

            Settings::set('backup_frequency', $new_frequency);
            Settings::set('backup_time', $new_time);
            Settings::set('backup_day_of_week', $new_dow);
            Settings::set('backup_day_of_month', $new_dom);
            Settings::set('backup_specific_date', $new_date);
            Settings::set('retention_limit', sanitize_text_field($_POST['retention_limit']));

            \SPSync\Scheduler\Scheduler::update_schedule($new_frequency, $new_time, $new_dow, $new_dom, $new_date);

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
                        <span class="spsync-badge spsync-badge-warning">● Credentials Needed</span>
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
                    <span class="dashicons dashicons-calendar-alt"></span> Automation & Retention
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
                        <h3 class="spsync-card-title"><span class="dashicons dashicons-backup" style="color: #dc2626;"></span> Manual Backup Test</h3>
                        <p style="color: #64748b; font-size: 0.9rem; line-height: 1.5; margin-bottom: 20px;">
                            Trigger a full manual backup execution to verify that database dumping, file packaging, and SharePoint upload workflows complete cleanly without errors.
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="spsync_run_manual_backup" />
                            <?php wp_nonce_field('spsync_manual_backup_action'); ?>
                            <button type="submit" class="spsync-btn-primary" style="width: 100%; justify-content: center;">
                                <span class="dashicons dashicons-controls-play"></span> Run Backup Now (Test Workflow)
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
                    
                    <!-- Hidden credentials to preserve when saving automation tab -->
                    <input type="hidden" name="tenant_id" value="<?php echo esc_attr($tenant_id); ?>" />
                    <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>" />
                    <input type="hidden" name="client_secret" value="••••••••••••••••••••••••••••••••" />
                    <input type="hidden" name="site_url" value="<?php echo esc_attr($site_url); ?>" />
                    <input type="hidden" name="document_library" value="<?php echo esc_attr($document_library); ?>" />
                    <input type="hidden" name="root_folder" value="<?php echo esc_attr($root_folder); ?>" />
                    <input type="hidden" name="encrypt_secret" value="<?php echo esc_attr($encrypt_secret); ?>" />

                    <div class="spsync-card" style="margin-bottom: 24px;">
                        <h3 class="spsync-card-title"><span class="dashicons dashicons-clock"></span> Automated Schedule & Specific Time Rules</h3>
                        
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
                                        <option value="manual" <?php selected($backup_frequency, 'manual'); ?>><?php esc_html_e('Manual Only (Disabled)', 'schedule-backup-plugin'); ?></option>
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

                    <button type="submit" name="spsync_save_settings" class="spsync-btn-primary">Save Automation Settings</button>
                </form>

                <script>
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
