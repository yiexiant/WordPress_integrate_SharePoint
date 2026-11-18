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
        add_action('admin_post_spsync_cancel_backup', [__CLASS__, 'handle_cancel_backup']);
        add_action('admin_post_spsync_save_contents', [__CLASS__, 'handle_save_contents']);
        add_action('admin_post_spsync_upload_backup', [__CLASS__, 'handle_upload_backup']);
        add_action('wp_ajax_spsync_restore_upload', [__CLASS__, 'handle_chunked_upload']);
        add_action('admin_post_spsync_restore_uploaded', [__CLASS__, 'handle_restore_uploaded']);
        add_action('admin_post_spsync_discard_upload', [__CLASS__, 'handle_discard_upload']);
        add_action('admin_post_spsync_create_device_backup', [__CLASS__, 'handle_device_backup']);
        add_action('admin_post_spsync_download_backup', [__CLASS__, 'handle_download_backup']);
        add_action('admin_post_spsync_discard_device_backup', [__CLASS__, 'handle_discard_device_backup']);
        add_action('admin_post_spsync_restore_remote', [__CLASS__, 'handle_restore_remote']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /** Top-level screens, in navigation order. */
    const PAGES = [
        'dashboard' => 'Dashboard',
        'backups'   => 'Backups',
        'restore'   => 'Restore',
        'settings'  => 'Settings',
        'activity'  => 'Activity',
    ];

    /** Settings sub-sections, in navigation order. */
    const SECTIONS = [
        'schedule'   => 'Schedule',
        'contents'   => 'Backup Contents',
        'sharepoint' => 'SharePoint',
        'dashboard'  => 'Central Dashboard',
    ];

    /** Pre-2.0 tab names keep working for redirects and bookmarks: old => [page, section]. */
    const ALIASES = [
        'overview'    => ['dashboard', null],
        'download'    => ['backups', null],
        'logs'        => ['activity', null],
        'automation'  => ['settings', 'schedule'],
        'contents'    => ['settings', 'contents'],
        'credentials' => ['settings', 'sharepoint'],
    ];

    /** Admin URL of a screen of this plugin. */
    public static function url($page = 'dashboard', array $args = [])
    {
        return add_query_arg(array_merge(['page' => self::MENU_SLUG, 'tab' => $page], $args), admin_url('admin.php'));
    }

    /** @return array{0:string,1:string} Resolved [page, section] from the request. */
    private static function current_screen()
    {
        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'dashboard';
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : null;
        if (isset(self::ALIASES[$tab])) {
            [$tab, $alias_section] = self::ALIASES[$tab];
            $section = $section ?: $alias_section;
        }
        if (!isset(self::PAGES[$tab])) {
            $tab = 'dashboard';
        }
        if (!isset(self::SECTIONS[(string) $section])) {
            $section = 'schedule';
        }
        return [$tab, $section];
    }

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }
        $base = plugins_url('admin/assets/', SPSYNC_PLUGIN_FILE);
        wp_enqueue_style('spsync-admin', $base . 'admin.css', [], SPSYNC_VERSION);
        wp_enqueue_script('spsync-admin', $base . 'admin.js', [], SPSYNC_VERSION, true);
    }

    /** Restore one of this site's SharePoint backups from the Backups screen. */
    public static function handle_restore_remote()
    {
        self::authorize('spsync_restore_remote');
        $backup_id = isset($_POST['backup_id']) && is_string($_POST['backup_id']) ? wp_unslash($_POST['backup_id']) : '';
        if (empty($_POST['confirm_restore']) || !preg_match('/\Aspsync-backup-[A-Za-z0-9_-]{1,80}\z/D', $backup_id)) {
            self::redirect_notice('backups', false, 'Confirm that you want to overwrite this website before restoring.');
        }
        $job = \SPSync\Restore\RestoreManager::start_restore($backup_id);
        self::redirect_notice($job ? 'restore' : 'backups', (bool) $job, $job
            ? 'Restore started. A safety snapshot was taken first; this page shows the progress.'
            : 'The restore could not start. Another backup or restore may be running, or the backup is no longer on SharePoint. See Activity for details.');
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
        wp_safe_redirect(self::notice_url($tab, $success, $message));
        exit;
    }

    private static function notice_url($tab, $success, $message)
    {
        return add_query_arg([
            'page' => self::MENU_SLUG,
            'tab' => $tab,
            'spsync_notice' => $success ? 'success' : 'error',
            'spsync_msg' => $message,
        ], admin_url('admin.php'));
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
        [$success, $message] = self::start_verified_restore($result['directory']);
        self::redirect_notice('restore', $success, $message);
    }

    /**
     * A verified upload is waiting in $directory: keep it as the user's pending import and start restoring.
     *
     * @return array{0:bool,1:string} [started, message for the administrator]
     */
    private static function start_verified_restore($directory)
    {
        $old = get_user_meta(get_current_user_id(), 'spsync_pending_import', true);
        if ($old && $old !== $directory && $old !== Settings::get('manual_restore_directory')) {
            \SPSync\Restore\LocalBackup::remove($old);
        }
        update_user_meta(get_current_user_id(), 'spsync_pending_import', $directory);
        $started = \SPSync\Restore\ManualRestore::start($directory);
        if (is_wp_error($started)) {
            return [false, 'Backup verified, but restoration could not start: ' . $started->get_error_message() . ' The verified upload is retained below so you can retry without uploading again.'];
        }
        delete_user_meta(get_current_user_id(), 'spsync_pending_import');
        \SPSync\Restore\ManualRestore::dispatch_after_response($started);
        return [true, 'Backup verified. Restoration is starting automatically; no second confirmation is needed. Refresh this page for status.'];
    }

    /**
     * Chunked restore upload (admin-ajax): begin | chunk | finish | status | cancel.
     * Lets backups of any size through web servers that reject large requests (HTTP 413).
     */
    public static function handle_chunked_upload()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to manage backups.'], 403);
        }
        if (!check_ajax_referer('spsync_upload_backup', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Your session expired. Reload the page and choose the file again; the upload continues where it stopped.'], 403);
        }
        $user = get_current_user_id();
        $step = isset($_POST['step']) && is_string($_POST['step']) ? $_POST['step'] : '';
        $upload = '\SPSync\Restore\ChunkedUpload';

        switch ($step) {
            case 'begin':
                if (empty($_POST['confirm_restore'])) {
                    wp_send_json_error(['message' => 'Confirm that you want to overwrite the website before uploading and restoring the backup.'], 400);
                }
                $files = isset($_POST['files']) && is_string($_POST['files']) ? json_decode(wp_unslash($_POST['files']), true) : null;
                $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? $_POST['mode'] : '';
                $result = $upload::begin($user, $mode, $files);
                break;

            case 'chunk':
                $chunk = isset($_FILES['chunk']) && is_array($_FILES['chunk']) ? $_FILES['chunk'] : null;
                if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
                    wp_send_json_error(['message' => 'A piece of the upload did not arrive completely.', 'retry' => true], 400);
                }
                $name = isset($_POST['name']) && is_string($_POST['name']) ? wp_unslash($_POST['name']) : '';
                $offset = isset($_POST['offset']) && is_numeric($_POST['offset']) ? (int) $_POST['offset'] : -1;
                $received = $upload::append($user, $name, $offset, $chunk['tmp_name']);
                $result = is_wp_error($received) ? $received : ['received' => $received];
                break;

            case 'finish':
                ignore_user_abort(true);
                if (function_exists('set_time_limit')) {
                    @set_time_limit(0);
                }
                $verified = $upload::finish($user);
                if (is_wp_error($verified)) {
                    $result = $verified;
                    break;
                }
                [$success, $message] = self::start_verified_restore($verified['directory']);
                $redirect = self::notice_url('restore', $success, $message);
                $upload::complete($user, $success, $message, $redirect);
                $result = ['redirect' => $redirect];
                break;

            case 'status':
                $result = $upload::status($user);
                break;

            case 'cancel':
                $upload::discard($user);
                $result = ['cancelled' => true];
                break;

            default:
                $result = new \WP_Error('invalid_step', 'Unknown upload step.');
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()], 400);
        }
        wp_send_json_success($result);
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
        StatusSummary::record_connection_test($result);

        self::redirect_notice('credentials', !empty($result['success']), $result['message']);
    }

    /**
     * "Back up now": hand the backup to a background worker and return at once. Running it inside
     * this request kept the browser waiting for many minutes on large sites, which invited repeat clicks.
     */
    public static function handle_manual_backup()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'schedule-backup-plugin'));
        }

        check_admin_referer('spsync_manual_backup_action');

        $backup_id = \SPSync\Backup\BackupEngine::request();
        if (is_wp_error($backup_id)) {
            self::redirect_notice('backups', false, $backup_id->get_error_message());
        }
        self::redirect_notice('backups', true, 'Backup started. It is created and uploaded to SharePoint in the background, so you can leave this page; the list below shows the progress.');
    }

    /**
     * Cancel an unfinished backup from the Backups list (e.g. one started by an accidental extra click).
     */
    public static function handle_cancel_backup()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'schedule-backup-plugin'));
        }
        check_admin_referer('spsync_cancel_backup');

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        $result = \SPSync\Backup\BackupEngine::cancel($backup_id);
        if (is_wp_error($result)) {
            self::redirect_notice('backups', false, $result->get_error_message());
        }
        self::redirect_notice('backups', true, 'The backup was cancelled and its files were removed from this server and from SharePoint.');
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

        \SPSync\Helpers\BackupLock::release();
        $reason = \SPSync\Helpers\BackupLock::busy_reason();
        self::redirect_notice('dashboard', true, $reason === null ? 'The stuck backup was cleared. You can start a new backup now.' : 'The stuck backup was cleared. ' . $reason);
    }

    /**
     * Render the plugin's admin screens.
     */
    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        [$page, $section] = self::current_screen();
        $notices = [];

        if (isset($_POST['spsync_save_settings']) && $page === 'settings') {
            check_admin_referer('spsync_save_settings_action');
            $notices[] = self::save_settings($section);
        }
        if (isset($_GET['spsync_notice'], $_GET['spsync_msg'])) {
            $notices[] = [
                'type'    => $_GET['spsync_notice'] === 'success' ? 'success' : 'error',
                'message' => sanitize_text_field(wp_unslash(urldecode($_GET['spsync_msg']))),
            ];
        }

        $backups = StatusSummary::recent_backups(30);
        $overall = StatusSummary::overall($backups);
        $activity = StatusSummary::activity();
        ?>
        <div class="wrap spsync" <?php if ($activity && !$activity['stale']) : ?>data-autorefresh="15"<?php endif; ?>>
            <header class="spsync-header">
                <div>
                    <h1 class="spsync-header__title">SharePoint Backup</h1>
                    <p class="spsync-header__subtitle">Back up this website to Microsoft SharePoint and restore it when you need to.</p>
                </div>
                <span class="spsync-pill spsync-pill--<?php echo esc_attr($overall['level']); ?>"><?php echo esc_html(self::level_label($overall['level'])); ?></span>
            </header>

            <nav class="spsync-nav" aria-label="SharePoint Backup">
                <?php foreach (self::PAGES as $key => $label) : ?>
                    <a href="<?php echo esc_url(self::url($key)); ?>" class="spsync-nav__item<?php echo $page === $key ? ' is-active' : ''; ?>" <?php echo $page === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php foreach ($notices as $notice) : ?>
                <div class="spsync-alert spsync-alert--<?php echo esc_attr($notice['type']); ?>" role="<?php echo $notice['type'] === 'error' ? 'alert' : 'status'; ?>">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endforeach; ?>

            <?php if ($activity) : ?>
                <div class="spsync-alert spsync-alert--<?php echo $activity['stale'] ? 'warning' : 'active'; ?>" role="status">
                    <?php if ($activity['stale']) : ?>
                        <p><strong>A backup seems to be stuck.</strong> It has shown no progress for <?php echo esc_html(human_time_diff($activity['since'])); ?>. If you are sure nothing is running, clear it. Unfinished uploads can be cancelled on the Backups screen.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-spsync-confirm="Clear the stuck backup? Only do this if no backup is really running.">
                            <input type="hidden" name="action" value="spsync_reset_lock" />
                            <?php wp_nonce_field('spsync_reset_lock_action'); ?>
                            <button type="submit" class="button">Clear stuck backup</button>
                        </form>
                    <?php else : ?>
                        <p><span class="spsync-spinner" aria-hidden="true"></span><strong><?php echo esc_html($activity['label']); ?></strong> This page refreshes by itself.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="spsync-main">
                <?php
                self::view($page, [
                    'section'  => $section,
                    'backups'  => $backups,
                    'overall'  => $overall,
                    'activity' => $activity,
                ]);
                ?>
            </div>
        </div>
        <?php
    }

    /** Short label for the header status pill. */
    public static function level_label($level)
    {
        $labels = [
            'ok'       => 'Protected',
            'active'   => 'Working…',
            'warning'  => 'Needs attention',
            'critical' => 'Action needed',
            'setup'    => 'Setup needed',
        ];
        return $labels[$level] ?? 'Unknown';
    }

    /** Render admin/views/{name}.php with the given variables. */
    private static function view($name, array $data)
    {
        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/' . $name . '.php';
    }

    /**
     * Persist a Settings section form. Each section saves only its own fields, so saving SharePoint
     * credentials never resets the schedule and vice versa.
     *
     * @return array{type:string, message:string}
     */
    private static function save_settings($section)
    {
        if ($section === 'sharepoint') {
            Settings::set('tenant_id', sanitize_text_field(wp_unslash($_POST['tenant_id'] ?? '')));
            Settings::set('client_id', sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')));

            // The saved secret is never sent to the browser; an empty field keeps it.
            $posted_secret = wp_unslash($_POST['client_secret'] ?? '');
            $new_secret = $posted_secret === '' ? Settings::get('client_secret') : $posted_secret;
            Settings::set('encrypt_secret', !empty($_POST['encrypt_secret']) ? '1' : '0');
            Settings::set('client_secret', $new_secret);

            $location_before = [Settings::get('site_url'), Settings::get('document_library'), Settings::get('root_folder')];
            Settings::set('site_url', esc_url_raw(wp_unslash($_POST['site_url'] ?? '')));
            Settings::set('document_library', sanitize_text_field(wp_unslash($_POST['document_library'] ?? '')));
            Settings::set('root_folder', sanitize_text_field(wp_unslash($_POST['root_folder'] ?? '')));
            // Cached Graph IDs (and the last test result) must follow a changed SharePoint location.
            if ($location_before !== [Settings::get('site_url'), Settings::get('document_library'), Settings::get('root_folder')]) {
                Settings::delete('site_id');
                Settings::delete('drive_id');
                Settings::delete('root_folder_id');
                Settings::delete('connection_test');
            }

            $site_folder = \SPSync\Storage\RemoteInventory::sanitize_folder_name(wp_unslash($_POST['site_folder'] ?? ''));
            if ($site_folder !== '' && $site_folder !== \SPSync\Storage\RemoteInventory::site_folder_name()) {
                Settings::set('site_folder', $site_folder);
                \SPSync\Storage\RemoteInventory::forget();
            }

            return ['type' => 'success', 'message' => 'SharePoint settings saved. Click "Test connection" to check them.'];
        }

        if ($section === 'schedule') {
            $method = sanitize_text_field(wp_unslash($_POST['backup_method'] ?? 'manual'));
            $new_frequency = $method === 'automatic' ? sanitize_text_field(wp_unslash($_POST['backup_frequency'] ?? 'daily')) : 'manual';
            if (!in_array($new_frequency, ['manual', 'daily', 'weekly', 'monthly', 'once'], true)) {
                $new_frequency = 'manual';
            }
            $new_time = sanitize_text_field(wp_unslash($_POST['backup_time'] ?? Settings::get('backup_time', '02:00')));
            $new_dow  = sanitize_text_field(wp_unslash($_POST['backup_day_of_week'] ?? Settings::get('backup_day_of_week', '0')));
            $new_dom  = sanitize_text_field(wp_unslash($_POST['backup_day_of_month'] ?? Settings::get('backup_day_of_month', '1')));
            $new_date = sanitize_text_field(wp_unslash($_POST['backup_specific_date'] ?? Settings::get('backup_specific_date', '')));

            Settings::set('backup_frequency', $new_frequency);
            Settings::set('backup_time', $new_time);
            Settings::set('backup_day_of_week', $new_dow);
            Settings::set('backup_day_of_month', $new_dom);
            Settings::set('backup_specific_date', $new_date);
            Settings::set('retention_limit', sanitize_text_field(wp_unslash($_POST['retention_limit'] ?? Settings::get('retention_limit', '30'))));

            \SPSync\Scheduler\Scheduler::update_schedule($new_frequency, $new_time, $new_dow, $new_dom, $new_date);

            return ['type' => 'success', 'message' => $new_frequency === 'manual'
                ? 'Saved. Automatic backups are off.'
                : 'Saved. ' . StatusSummary::schedule()['label'] . '.'];
        }

        return ['type' => 'error', 'message' => 'Nothing to save on this screen.'];
    }
}
