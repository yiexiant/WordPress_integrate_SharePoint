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
    }

    /**
     * Register settings submenu under Settings menu.
     */
    public static function add_menu_page()
    {
        add_options_page(
            __('SharePoint Backup Settings', 'schedule-backup-plugin'),
            __('SharePoint Backup', 'schedule-backup-plugin'),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'render_settings_page' ]
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

        wp_safe_redirect(admin_url('options-general.php?page=' . self::MENU_SLUG . '&spsync_notice=' . $type . '&spsync_msg=' . $message));
        exit;
    }

    /**
     * Render the admin Settings HTML form.
     */
    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle Form Submission
        if (isset($_POST['spsync_save_settings'])) {
            check_admin_referer('spsync_save_settings_action');

            Settings::set('tenant_id', sanitize_text_field($_POST['tenant_id']));
            Settings::set('client_id', sanitize_text_field($_POST['client_id']));
            
            // Only update secret if it has been explicitly changed by the user
            $new_secret = $_POST['client_secret'];
            if ($new_secret !== '••••••••••••••••••••••••••••••••') {
                Settings::set('client_secret', $new_secret);
            }

            Settings::set('site_url', esc_url_raw($_POST['site_url']));
            Settings::set('document_library', sanitize_text_field($_POST['document_library']));
            Settings::set('root_folder', sanitize_text_field($_POST['root_folder']));
            Settings::set('encrypt_secret', isset($_POST['encrypt_secret']) ? '1' : '0');

            // Force regenerate API Key if requested
            if (isset($_POST['regenerate_api_key'])) {
                if (function_exists('random_bytes')) {
                    try {
                        Settings::set('api_key', bin2hex(random_bytes(32)));
                    } catch (\Exception $e) {
                        // Keep current if random_bytes fails
                    }
                } else {
                    Settings::set('api_key', wp_generate_password(64, true, true));
                }
            }

            echo '<div class="updated notice is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'schedule-backup-plugin') . '</p></div>';
        }

        // Display redirected notice banners
        if (isset($_GET['spsync_notice']) && isset($_GET['spsync_msg'])) {
            $class = ($_GET['spsync_notice'] === 'success') ? 'updated notice is-dismissible' : 'error notice';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html(urldecode($_GET['spsync_msg'])) . '</p></div>';
        }

        $tenant_id        = Settings::get('tenant_id');
        $client_id        = Settings::get('client_id');
        $client_secret    = Settings::get('client_secret');
        $site_url         = Settings::get('site_url');
        $document_library = Settings::get('document_library');
        $root_folder      = Settings::get('root_folder');
        $encrypt_secret   = Settings::get('encrypt_secret', '1');
        $api_key          = Settings::get('api_key');

        $masked_secret = empty($client_secret) ? '' : '••••••••••••••••••••••••••••••••';
        $is_https = is_ssl();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SharePoint Backup Settings', 'schedule-backup-plugin'); ?></h1>
            
            <?php if (!$is_https) : ?>
                <div class="notice notice-warning"><p><strong><?php esc_html_e('Warning:', 'schedule-backup-plugin'); ?></strong> <?php esc_html_e('HTTPS is not active. It is highly recommended to configure SSL to secure API communication.', 'schedule-backup-plugin'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('spsync_save_settings_action'); ?>
                
                <h2><?php esc_html_e('Microsoft Entra ID OAuth Credentials', 'schedule-backup-plugin'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tenant_id"><?php esc_html_e('Tenant ID', 'schedule-backup-plugin'); ?></label></th>
                        <td>
                            <input name="tenant_id" type="text" id="tenant_id" value="<?php echo esc_attr($tenant_id); ?>" class="regular-text" placeholder="e.g. 00000000-0000-0000-0000-000000000000" />
                            <p class="description"><?php esc_html_e('Your Microsoft Entra (Azure AD) Directory Tenant ID.', 'schedule-backup-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_id"><?php esc_html_e('Client ID', 'schedule-backup-plugin'); ?></label></th>
                        <td>
                            <input name="client_id" type="text" id="client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" placeholder="e.g. 00000000-0000-0000-0000-000000000000" />
                            <p class="description"><?php esc_html_e('Your registered Azure Application Client ID.', 'schedule-backup-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_secret"><?php esc_html_e('Client Secret', 'schedule-backup-plugin'); ?></label></th>
                        <td>
                            <input name="client_secret" type="password" id="client_secret" value="<?php echo esc_attr($masked_secret); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('The Application Client Secret value.', 'schedule-backup-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Security Options', 'schedule-backup-plugin'); ?></th>
                        <td>
                            <fieldset>
                                <label for="encrypt_secret">
                                    <input name="encrypt_secret" type="checkbox" id="encrypt_secret" value="1" <?php checked($encrypt_secret, '1'); ?> />
                                    <?php esc_html_e('Encrypt Client Secret in Database', 'schedule-backup-plugin'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Store encrypted client secret using AES-256 encryption based on native WP security salts.', 'schedule-backup-plugin'); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('SharePoint Target Configuration', 'schedule-backup-plugin'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="site_url"><?php esc_html_e('SharePoint Site URL', 'schedule-backup-plugin'); ?></label></th>
                        <td>
                            <input name="site_url" type="url" id="site_url" value="<?php echo esc_attr($site_url); ?>" class="regular-text" placeholder="https://tenant.sharepoint.com/sites/sitename" />
                            <p class="description"><?php esc_html_e('Full SharePoint Online site URL where backups will be stored.', 'schedule-backup-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="document_library"><?php esc_html_e('Document Library', 'schedule-backup-plugin'); ?></label></th>
                        <td>
                            <input name="document_library" type="text" id="document_library" value="<?php echo esc_attr($document_library); ?>" class="regular-text" placeholder="e.g. Documents" />
                            <p class="description"><?php esc_html_e('Name of the document library (drive) under the SharePoint site (defaults to "Documents").', 'schedule-backup-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="root_folder"><?php esc_html_e('Root Backup Folder', 'schedule-backup-plugin'); ?></label></th>
                        <td>
                            <input name="root_folder" type="text" id="root_folder" value="<?php echo esc_attr($root_folder); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Name of the folder inside the document library where backups will go.', 'schedule-backup-plugin'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('API Authentication', 'schedule-backup-plugin'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_key"><?php esc_html_e('Secret API Key', 'schedule-backup-plugin'); ?></label></th>
                        <td>
                            <input type="text" id="api_key" value="<?php echo esc_attr($api_key); ?>" class="large-text code" readonly style="font-family: monospace; background: #eee;" onclick="this.select();" />
                            <p class="description">
                                <?php esc_html_e('Key used by the Central Dashboard for authorization. Pass in the HTTP header: ', 'schedule-backup-plugin'); ?>
                                <code>X-SPSYNC-KEY: <?php echo esc_html($api_key); ?></code>
                            </p>
                            <fieldset style="margin-top: 10px;">
                                <label for="regenerate_api_key">
                                    <input name="regenerate_api_key" type="checkbox" id="regenerate_api_key" value="1" />
                                    <strong><?php esc_html_e('Regenerate API Key on save', 'schedule-backup-plugin'); ?></strong>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="spsync_save_settings" id="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'schedule-backup-plugin'); ?>" />
                </form>

                <?php if (!empty($tenant_id) && !empty($client_id) && !empty($site_url)) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="spsync_test_connection" />
                        <?php wp_nonce_field('spsync_test_conn_action'); ?>
                        <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Test SharePoint Connection', 'schedule-backup-plugin'); ?>" />
                    </form>
                <?php endif; ?>
                </p>
        </div>
        <?php
    }
}
