<?php
/** Isolated regression tests. Run: php plugin/tests/run.php. No real WordPress or cloud calls. */
namespace SPSync\Helpers {
    class Settings
    {
        public static $values = [];
        public static function get($key, $default = '') { return self::$values[$key] ?? $default; }
        public static function set($key, $value) { self::$values[$key] = $value; return true; }
        public static function delete($key) { unset(self::$values[$key]); return true; }
        public static function add($key, $value) {
            if (isset(self::$values[$key])) { return false; }
            self::$values[$key] = (string) $value;
            return true;
        }
        public static function replace_if($key, $expected, $value) {
            if (!isset(self::$values[$key]) || (string) self::$values[$key] !== (string) $expected) { return false; }
            self::$values[$key] = (string) $value;
            return true;
        }
    }
}
namespace SPSync\Logger {
    class Logger
    {
        public static function __callStatic($name, $arguments) {}
    }
}
namespace SPSync\Queue {
    class QueueManager
    {
        public static $triggered = false;
        public static function trigger() { self::$triggered = true; }
    }
}
namespace SPSync\Auth {
    class GraphClient
    {
        public static $responses = [];
        public static function get_drive_id() { return 'test-drive'; }
        public static function get_root_folder_id() { return 'test-root'; }
        public static function request($endpoint, $method = 'GET', $body = null) { return array_shift(self::$responses); }
    }
}
namespace {
    use SPSync\Backup\BackupSelection;
    use SPSync\Backup\BackupEngine;
    use SPSync\Backup\FileScanner;
    use SPSync\Backup\DeviceBackup;
    use SPSync\Helpers\PrivateStorage;
    use SPSync\Helpers\Settings;
    use SPSync\Restore\LocalBackup;
    use SPSync\Restore\ManualRestore;
    use SPSync\Restore\RestoreEngine;

    $fixture = sys_get_temp_dir() . '/spsync-tests-' . bin2hex(random_bytes(8));
    mkdir($fixture, 0700);
    define('ABSPATH', $fixture . '/site/');
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
    define('MINUTE_IN_SECONDS', 60);
    define('DAY_IN_SECONDS', 86400);
    define('WEEK_IN_SECONDS', 604800);
    define('HOUR_IN_SECONDS', 3600);
    define('ARRAY_N', 'ARRAY_N');
    define('ARRAY_A', 'ARRAY_A');
    define('DB_HOST', 'test-host');
    define('DB_NAME', 'test-db');
    $wp_version = '6.0';
    $options = [];
    $scheduled = [];
    $current_user_allowed = true;
    $nonce_allowed = true;
    $metadata = [];
    $schedule_fail = false;
    $actions = [];
    $filter_values = [];

    class WP_Error
    {
        private $code;
        private $message;
        public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
    class WP_REST_Controller {}
    class WP_REST_Response
    {
        public $data;
        public function __construct($data, $status = 200) { $this->data = $data; }
    }
    class TestDatabase
    {
        public $prefix = 'wp_';
        public $queries = [];
        public $items = [];
        public $fail = '';
        public $has_tables = false;
        public $backup_ids = [];
        public $results = [];
        public $updates = [];
        public $deletes = [];
        public function get_results($sql, $mode = null) { return $this->results; }
        public function update($table, $data, $where) { $this->updates[] = [$data, $where]; return 1; }
        public function delete($table, $where, $formats = []) { $this->deletes[] = $where; return 1; }
        public function query($sql) {
            $this->queries[] = $sql;
            return $this->fail && strpos($sql, $this->fail) !== false ? false : 1;
        }
        public function insert($table, $row, $formats = []) { $this->items[] = $row; return 1; }
        public function get_col($sql) { return strpos($sql, 'DISTINCT backup_id') !== false ? $this->backup_ids : ['wp_posts', 'wp_spsync_settings']; }
        public function get_row($sql, $mode = null) { return ['wp_posts', 'CREATE TABLE `wp_posts` (`id` int)']; }
        public $pending_uploads = 0;
        public function get_var($sql) {
            if (strpos($sql, "status IN ('queued', 'uploading') AND retries < 3") !== false && strpos($sql, 'COUNT(*)') !== false) { return $this->pending_uploads; }
            return strpos($sql, 'SHOW TABLES') === 0 ? ($this->has_tables ? 'wp_fixture' : null) : 0;
        }
    }
    $wpdb = new TestDatabase();

    function is_wp_error($value) { return $value instanceof WP_Error; }
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { global $actions; $actions[$hook][] = $callback; return true; }
    function register_activation_hook() {}
    function register_deactivation_hook() {}
    function plugin_dir_path($path) { return dirname($path) . '/'; }
    function apply_filters($name, $value) { global $filter_values; return $filter_values[$name] ?? $value; }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
    function wp_generate_uuid4() {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
    function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0700, true); }
    function wp_upload_dir() { return ['basedir' => WP_CONTENT_DIR . '/uploads']; }
    function current_time($format, $gmt = false) {
        static $counter = 0;
        return date($format === 'mysql' ? 'Y-m-d H:i:s' : $format, time() + $counter++);
    }
    function esc_sql($value) { return addslashes($value); }
    function size_format($bytes) { return $bytes . ' bytes'; }
    function wp_cache_flush() {}
    function add_option($key, $value, $deprecated = '', $autoload = false) {
        global $options;
        if (isset($options[$key])) { return false; }
        $options[$key] = $value;
        return true;
    }
    function delete_option($key) { global $options; unset($options[$key]); }
    function update_option($key, $value, $autoload = null) { global $options; $options[$key] = $value; return true; }
    function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
    function wp_schedule_single_event($time, $hook, $args = []) {
        global $scheduled, $schedule_fail;
        if ($schedule_fail) { return false; }
        $scheduled[] = [$hook, $args];
        return true;
    }
    function wp_schedule_event($time, $frequency, $hook) { return wp_schedule_single_event($time, $hook, [$frequency]); }
    function wp_clear_scheduled_hook($hook, $args = []) {
        global $scheduled;
        $scheduled = array_values(array_filter($scheduled, function ($event) use ($hook, $args) { return $event[0] !== $hook || ($args && $event[1] !== $args); }));
        return true;
    }
    function wp_next_scheduled($hook) {
        global $scheduled;
        foreach ($scheduled as $event) { if ($event[0] === $hook) { return time() + 3600; } }
        return false;
    }
    function current_user_can($capability) { global $current_user_allowed; return $current_user_allowed; }
    function check_admin_referer($nonce) { global $nonce_allowed; if (!$nonce_allowed) { throw new \RuntimeException('invalid nonce'); } }
    function wp_die($message) { throw new \RuntimeException($message); }
    function get_current_user_id() { return 1; }
    function get_user_meta($id, $key, $single = true) { global $metadata; return $metadata[$id][$key] ?? ''; }
    function update_user_meta($id, $key, $value) { global $metadata; $metadata[$id][$key] = $value; return true; }
    function delete_user_meta($id, $key) { global $metadata; unset($metadata[$id][$key]); return true; }
    function admin_url($path) { return '/wp-admin/' . $path; }
    function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
    function esc_html($value) { return esc_attr($value); }
    function esc_url($value) { return esc_attr($value); }
    function __($value, $domain = null) { return $value; }
    function esc_html__($value, $domain = null) { return esc_html($value); }
    function esc_html_e($value, $domain = null) { echo esc_html($value); }
    function sanitize_text_field($value) { return strip_tags($value); }
    function wp_nonce_field($value) { echo '<input type="hidden" name="_wpnonce" value="test" />'; }
    function disabled($value, $expected = true) { if ((string) $value === (string) $expected) { echo 'disabled="disabled"'; } }
    function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
    function checked($value, $expected = true) { if ((string) $value === (string) $expected) { echo 'checked="checked"'; } }
    function selected($value, $expected = true) { if ((string) $value === (string) $expected) { echo 'selected="selected"'; } }
    function wp_date($format, $timestamp = null) { return date($format, $timestamp ?? time()); }
    function human_time_diff($timestamp) { return 'one hour'; }
    function esc_url_raw($value) { return $value; }
    function wp_nonce_url($url, $action) { return $url . '&_wpnonce=test'; }
    function wp_max_upload_size() { return 10485760; }
    function wp_unslash($value) { return $value; }
    function add_query_arg($values, $url) { return $url . '?' . http_build_query($values); }
    function home_url($path = '') { return 'https://shop.example.test/store' . $path; }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function wp_safe_redirect($url) { throw new \RuntimeException('redirect:' . $url); }

    require dirname(__DIR__) . '/schedule-backup-plugin.php';

    $checks = 0;
    function verify($condition, $message) {
        global $checks;
        if (!$condition) { throw new \RuntimeException($message); }
        $checks++;
    }
    function write_fixture($relative, $content) {
        $path = ABSPATH . $relative;
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content);
        return realpath($path);
    }
    function archive($path, $files) {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) { $zip->addFromString($name, $contents); }
        $zip->close();
    }
    function staging($files, $with_sql = false) {
        $path = sys_get_temp_dir() . '/spsync-import-' . wp_generate_uuid4();
        mkdir($path, 0700);
        $parts = [];
        if ($files) { archive($path . '/backup_part001.zip', $files); $parts[] = 'backup_part001.zip'; }
        if ($with_sql !== false) { file_put_contents($path . '/database.sql', $with_sql); $parts[] = 'database.sql'; }
        $manifest = ['backup_id' => 'spsync-backup-test', 'backup_type' => 'selected', 'parts' => []];
        foreach ($parts as $part) {
            $manifest['parts'][] = ['filename' => $part, 'filesize' => filesize($path . '/' . $part), 'checksum' => hash_file('sha256', $path . '/' . $part)];
        }
        file_put_contents($path . '/manifest.json', json_encode($manifest));
        return $path;
    }

    $staging_directories = [];
    $device_directories = [];
    try {
        write_fixture('index.php', 'core');
        $plugin = write_fixture('wp-content/plugins/example/plugin.php', 'plugin');
        write_fixture('wp-content/themes/example/style.css', 'theme');
        $upload = write_fixture('wp-content/uploads/photo.txt', 'original photo');
        $archive_file = write_fixture('wp-content/uploads/download.zip', 'existing archive');
        write_fixture('wp-content/custom-assets/asset.txt', 'custom');
        write_fixture('wp-content/cache/cache.txt', 'cache');
        write_fixture('wp-content/uploads/spsync-backups/private.sql', 'exclude');
        write_fixture('wp-content/spsync-backups/private.sql', 'exclude');

        verify(class_exists('SPSync\\Backup\\BackupSelection'), 'Autoloader must resolve lowercase source directories.');
        verify(BackupSelection::get()['mode'] === 'full' && BackupSelection::get()['database'], 'Fresh installations must default to full site plus database.');
        $available = BackupSelection::directories();
        verify(isset($available['plugins'], $available['themes'], $available['uploads'], $available['custom-assets'], $available['cache']), 'Discover standard and additional content directories: ' . json_encode($available));
        verify(!isset($available['spsync-backups']), 'Do not offer generated backup storage.');
        verify(is_wp_error(BackupSelection::save('selected', [], false)), 'Reject empty selections.');
        verify(is_wp_error(BackupSelection::save('selected', ['../plugins'], false)), 'Reject traversal selections.');
        verify(is_wp_error(BackupSelection::save('selected', ['not-present'], false)), 'Reject unavailable selections.');
        verify(is_wp_error(BackupSelection::save('selected', [['plugins']], false)), 'Reject malformed selections.');
        verify(BackupSelection::save('selected', ['plugins', 'uploads'], false) === true, 'Save explicit directories.');
        $scanned = FileScanner::scan([], [$available['plugins'], $available['uploads']]);
        verify(in_array($plugin, $scanned, true) && in_array($upload, $scanned, true), 'Selected roots must be scanned.');
        verify(in_array($archive_file, $scanned, true), 'Explicit directory selection must retain archive files.');
        verify(count($scanned) === 3, 'Do not scan unselected directories or generated backups.');
        verify(count(FileScanner::scan([], [$available['cache']])) === 1, 'Explicit custom/cache directory must be honored.');
        verify(FileScanner::scan([], [sys_get_temp_dir()]) === [], 'Reject scan roots outside WordPress.');

        $id = BackupEngine::run();
        $base = \SPSync\Helpers\LocalPaths::backup_dir($id);
        $manifest = json_decode(file_get_contents($base . '/manifest.json'), true);
        verify($manifest['backup_type'] === 'selected' && !$manifest['selection']['database'], 'Manifest records selected scope without database.');
        verify(!file_exists($base . '/database.sql'), 'File-only backup must not export SQL.');
        verify(count($manifest['parts']) === 1 && \SPSync\Queue\QueueManager::$triggered, 'Queue archive and manifest for upload.');
        Settings::delete('backup_running');
        $options['spsync_restore_start_lock'] = time();
        verify(BackupEngine::run(true) === false && is_wp_error(ManualRestore::start($base)), 'Restore preparation lock blocks backup and duplicate local restore starts.');
        unset($options['spsync_restore_start_lock']);
        BackupSelection::save('selected', [], true);
        $id = BackupEngine::run();
        $base = \SPSync\Helpers\LocalPaths::backup_dir($id);
        $manifest = json_decode(file_get_contents($base . '/manifest.json'), true);
        verify(count($manifest['parts']) === 1 && $manifest['parts'][0]['filename'] === 'database.sql', 'Database-only backup works without any ZIP files.');
        Settings::delete('backup_running');
        BackupSelection::save('full', [], false);
        $id = BackupEngine::run();
        $base = \SPSync\Helpers\LocalPaths::backup_dir($id);
        $manifest = json_decode(file_get_contents($base . '/manifest.json'), true);
        verify($manifest['backup_type'] === 'full' && file_exists($base . '/database.sql') && count($manifest['parts']) === 2, 'Full backup preserves default SQL and archive behavior.');
        Settings::delete('backup_running');

        $path = staging(['wp-content/uploads/photo.txt' => 'restored photo']);
        $staging_directories[] = $path;
        verify(!is_wp_error(LocalBackup::validate($path)), 'Accept complete old/new plugin manifests and matching hashes.');
        file_put_contents($path . '/backup_part001.zip', 'corrupt');
        verify(is_wp_error(LocalBackup::validate($path)), 'Reject a corrupt or truncated part.');
        verify(is_wp_error(LocalBackup::validate($fixture)), 'Reject arbitrary directories as staging.');
        verify(is_wp_error(LocalBackup::upload([])), 'Reject empty upload.');
        verify(!LocalBackup::allowed_filename('../database.sql') && !LocalBackup::allowed_filename('shell.php'), 'Restrict uploaded component filenames.');
        verify(is_wp_error(LocalBackup::upload(['name' => ['database.sql'], 'error' => [UPLOAD_ERR_OK], 'tmp_name' => [$upload]])), 'Reject a path that is not a real HTTP upload.');
        $path = staging(['wp-content/uploads/photo.txt' => 'valid']);
        $staging_directories[] = $path;
        $modified = json_decode(file_get_contents($path . '/manifest.json'), true);
        $modified['parts'][] = $modified['parts'][0];
        file_put_contents($path . '/manifest.json', json_encode($modified));
        verify(is_wp_error(LocalBackup::validate($path)), 'Reject duplicate manifest parts.');
        array_pop($modified['parts']);
        $modified['parts'][0]['filename'] = '../escape.zip';
        file_put_contents($path . '/manifest.json', json_encode($modified));
        verify(is_wp_error(LocalBackup::validate($path)), 'Reject manifest path traversal.');
        $path = staging(['wp-content/uploads/photo.txt' => 'valid']);
        $staging_directories[] = $path;
        unlink($path . '/backup_part001.zip');
        verify(is_wp_error(LocalBackup::validate($path)), 'Reject missing manifest components.');
        $path = staging(['wp-content/uploads/photo.txt' => 'valid']);
        $staging_directories[] = $path;
        file_put_contents($path . '/database.sql', 'extra file');
        verify(is_wp_error(LocalBackup::validate($path)), 'Reject files outside the manifest.');

        $bad = $fixture . '/unsafe.zip';
        foreach (['../escape.php', '/absolute.php', 'C:/absolute.php', 'wp-content/../escape.php', 'wp-content/spsync-backups/leak.sql', 'wp-content/uploads/.. /escape.php'] as $name) {
            archive($bad, [$name => 'unsafe']);
            verify(is_wp_error(RestoreEngine::validate_archive($bad)), 'Reject unsafe ZIP entry: ' . $name);
        }
        archive($bad, ['wp-content/uploads/link' => 'target']);
        $zip = new \ZipArchive(); $zip->open($bad); $zip->setExternalAttributesName('wp-content/uploads/link', \ZipArchive::OPSYS_UNIX, 0120777 << 16); $zip->close();
        verify(is_wp_error(RestoreEngine::validate_archive($bad)), 'Reject archive symlinks.');
        archive($bad, ['wp-content/uploads/A.txt' => 'first', 'wp-content/uploads/a.txt' => 'second']);
        verify(is_wp_error(RestoreEngine::validate_archive($bad)), 'Reject case-colliding archive entries.');
        $path = staging(['wp-content/uploads/photo.txt' => 'restored photo', 'wp-content/uploads/new.txt' => 'new']);
        $staging_directories[] = $path;
        $job = ManualRestore::start($path);
        verify(is_string($job) && end($scheduled)[0] === ManualRestore::ACTION_HOOK, 'Local restore schedules without any SharePoint configuration.');
        verify(is_wp_error(ManualRestore::start($path)), 'Reject another restore while one is active.');
        ManualRestore::run($job);
        verify(file_get_contents($upload) === 'restored photo' && file_exists(ABSPATH . 'wp-content/uploads/new.txt'), 'Manual file-only restoration overwrites included files.');
        verify(Settings::get('restore_status') === 'completed' && !file_exists($path), 'Successful restore cleans private staging and records completion.');
        verify(file_exists(ABSPATH . 'wp-content/themes/example/style.css'), 'Unrelated files remain intact.');
        file_put_contents($upload, 'after restore');
        ManualRestore::run($job);
        verify(file_get_contents($upload) === 'after restore', 'Completed worker events must not replay restoration.');
        $path = staging([], "INSERT INTO `wp_posts` VALUES (42);\n");
        $staging_directories[] = $path;
        $job = ManualRestore::start($path);
        ManualRestore::run($job);
        verify(Settings::get('restore_status') === 'completed', 'Database-only manual restore completes without ZIP parts.');
        verify(file_get_contents($upload) === 'after restore', 'Database-only restoration must leave files unchanged.');
        Settings::set('backup_running', 'busy');
        $path = staging(['wp-content/uploads/photo.txt' => 'valid']);
        $staging_directories[] = $path;
        verify(is_wp_error(ManualRestore::start($path)), 'Do not start local restore during a backup.');
        Settings::delete('backup_running');

        $wpdb->queries = [];
        $sql = $fixture . '/batch.sql';
        file_put_contents($sql, "DROP TABLE IF EXISTS `wp_spsync_settings`;\n" . str_repeat("INSERT INTO `wp_posts` VALUES (1);\n", 501));
        Settings::delete('restore_db_offset');
        verify(RestoreEngine::restore_database($sql) === false, 'Database import yields after 500 statements.');
        verify(RestoreEngine::restore_database($sql) === true, 'Database import resumes to completion.');
        verify(!in_array('DROP TABLE IF EXISTS `wp_spsync_settings`;' . "\n", $wpdb->queries, true), 'Legacy SQL must not overwrite live restore control tables.');
        verify(count(array_filter($wpdb->queries, function ($query) { return strpos($query, 'INSERT INTO') === 0; })) === 501, 'Resuming SQL neither loses nor duplicates rows.');
        verify(is_wp_error(RestoreEngine::restore_database($fixture . '/missing.sql')), 'Missing SQL must fail rather than report success.');
        file_put_contents($sql, 'INSERT INTO `wp_posts` VALUES (1)');
        verify(is_wp_error(RestoreEngine::restore_database($sql)), 'Incomplete SQL statement must fail.');

        file_put_contents($upload, 'before failed restore');
        $path = staging(['wp-content/uploads/photo.txt' => 'will be rolled back', 'wp-content/uploads/created-by-failure.txt' => 'remove me'], "TRIGGER_FAILURE;\n");
        $staging_directories[] = $path;
        $job = ManualRestore::start($path);
        $wpdb->fail = 'TRIGGER_FAILURE';
        ManualRestore::run($job);
        $wpdb->fail = '';
        verify(Settings::get('restore_status') === 'failed', 'SQL failure must not mark restoration complete.');
        verify(file_get_contents($upload) === 'before failed restore', 'Rollback includes overwritten uploads, not only plugins/themes.');
        verify(!file_exists(ABSPATH . 'wp-content/uploads/created-by-failure.txt'), 'Rollback removes newly introduced files.');
        verify(is_file($path . '/rollback/rollback_database.sql'), 'Failure retains private recovery artifacts.');
        $response = (new \SPSync\Api\RestController())->get_backup_status(null);
        verify(isset($response->data['restore']['restore_job_id']) && $response->data['restore']['status'] === 'failed', 'Expose restore state on fresh sites with no backup table.');

        $_GET['tab'] = 'contents';
        ob_start(); \SPSync\Admin\SettingsPage::render_settings_page(); $html = ob_get_clean();
        verify(strpos($html, 'name="backup_scope" value="full" checked') !== false, 'Backup Contents displays full-site default.');
        verify(strpos($html, 'value="custom-assets"') !== false && strpos($html, 'Backup Contents') !== false, 'Admin shows discovered directories and new navigation.');
        $_GET['tab'] = 'restore';
        ob_start(); \SPSync\Admin\SettingsPage::render_settings_page(); $html = ob_get_clean();
        verify(strpos($html, 'webkitdirectory') !== false && strpos($html, 'multipart/form-data') !== false, 'Manual restore provides folder and file upload form.');
        $current_user_allowed = false;
        try { \SPSync\Admin\SettingsPage::handle_upload_backup(); verify(false, 'Unauthorized upload was allowed.'); }
        catch (\RuntimeException $error) { verify(strpos($error->getMessage(), 'permission') !== false, 'Upload requires administrator capability.'); }
        $current_user_allowed = true;
        $nonce_allowed = false;
        try { \SPSync\Admin\SettingsPage::handle_restore_uploaded(); verify(false, 'Invalid nonce was allowed.'); }
        catch (\RuntimeException $error) { verify($error->getMessage() === 'invalid nonce', 'Restore requires a valid nonce.'); }
        $nonce_allowed = true;
        $_POST = [];
        try { \SPSync\Admin\SettingsPage::handle_restore_uploaded(); verify(false, 'Missing confirmation was allowed.'); }
        catch (\RuntimeException $error) { verify(strpos($error->getMessage(), 'Confirm') !== false, 'Restore requires explicit overwrite confirmation.'); }
        verify(Settings::get('restore_status') === 'failed', 'Missing confirmation does not launch or alter a restore.');

        Settings::set('restore_status', 'completed');
        Settings::set('backup_frequency', 'weekly');
        Settings::set('backup_time', '03:45');
        BackupSelection::save('selected', ['uploads'], false);
        \SPSync\Queue\QueueManager::$triggered = false;
        $queue_count = count($wpdb->items);
        $device_id = DeviceBackup::start(1);
        verify(is_string($device_id), 'Device backup can be started without SharePoint credentials.');
        $device_job = DeviceBackup::get($device_id, 1);
        $device_directories[] = $device_job['directory'];
        verify($device_job['status'] === 'queued' && PrivateStorage::valid($device_job['directory'], 'download'), 'Device jobs use private storage outside the website.');
        verify(!DeviceBackup::get($device_id, 2) && is_wp_error(DeviceBackup::download($device_id, 2)), 'Another user cannot inspect or download the backup.');
        verify(is_wp_error(DeviceBackup::start(1)), 'Reject duplicate queued device backups.');
        verify(is_wp_error(DeviceBackup::download($device_id, 1)), 'A queued backup cannot be downloaded.');
        DeviceBackup::run($device_id);
        $device_job = DeviceBackup::get($device_id, 1);
        verify($device_job['status'] === 'ready', 'Background device backup becomes ready.');
        verify(!\SPSync\Queue\QueueManager::$triggered && count($wpdb->items) === $queue_count, 'Device backup never enqueues SharePoint uploads.');
        verify(!Settings::get('backup_running'), 'Device backup releases its creation lock.');
        verify(Settings::get('backup_frequency') === 'weekly', 'A manual device backup preserves the automatic schedule.');
        $download_path = DeviceBackup::download($device_id, 1);
        verify(is_string($download_path) && is_file($download_path), 'Ready backup has an owner-only download path.');
        $zip = new \ZipArchive(); $zip->open($download_path);
        $source_manifest = json_decode(file_get_contents($device_job['directory'] . '/artifacts/manifest.json'), true);
        $folder = $source_manifest['backup_id'];
        verify($zip->numFiles === count($source_manifest['parts']) + 1, 'Download ZIP includes the manifest and every backup part.');
        $roundtrip = PrivateStorage::create('import');
        $staging_directories[] = $roundtrip;
        file_put_contents($roundtrip . '/manifest.json', $zip->getFromName($folder . '/manifest.json'));
        foreach ($source_manifest['parts'] as $part) { file_put_contents($roundtrip . '/' . $part['filename'], $zip->getFromName($folder . '/' . $part['filename'])); }
        $zip->close();
        verify(!is_wp_error(LocalBackup::validate($roundtrip)), 'Extracted device ZIP is compatible with manual restore.');
        $_POST = [];
        $_GET['tab'] = 'download';
        ob_start(); \SPSync\Admin\SettingsPage::render_settings_page(); $html = ob_get_clean();
        verify(strpos($html, 'Download ZIP to My Device') !== false && strpos($html, 'backup_job=') !== false, 'Admin renders an authenticated device download link.');
        verify(!DeviceBackup::discard($device_id, 2), 'Another user cannot remove the server copy.');
        $modified = $device_job; $modified['expires_at'] = time() - 1;
        update_option('spsync_device_job_' . $device_id, $modified);
        verify(is_wp_error(DeviceBackup::download($device_id, 1)), 'Expired backups cannot be downloaded.');
        DeviceBackup::expire($device_id);
        verify(!file_exists($device_job['directory']) && !DeviceBackup::get($device_id, 1), 'Expiry removes the private server copy and job.');

        $_GET['tab'] = 'automation';
        $_POST = ['spsync_save_settings' => '1', 'backup_method' => 'manual'];
        ob_start(); \SPSync\Admin\SettingsPage::render_settings_page(); $html = ob_get_clean();
        verify(Settings::get('backup_frequency') === 'manual' && !wp_next_scheduled(\SPSync\Scheduler\Scheduler::EVENT_HOOK), 'Choosing manual turns off automatic backup events.');
        verify(Settings::get('backup_time') === '03:45', 'Switching to manual preserves previous automatic execution time.');
        verify(strpos($html, 'name="backup_method" value="manual" checked') !== false, 'Manual method is visibly selected after saving.');
        $_POST = ['spsync_save_settings' => '1', 'backup_method' => 'automatic', 'backup_frequency' => 'daily', 'backup_time' => '04:30'];
        ob_start(); \SPSync\Admin\SettingsPage::render_settings_page(); $html = ob_get_clean();
        verify(Settings::get('backup_frequency') === 'daily' && wp_next_scheduled(\SPSync\Scheduler\Scheduler::EVENT_HOOK), 'Choosing automatic creates the selected schedule.');
        verify(strpos($html, 'name="backup_method" value="automatic" checked') !== false, 'Automatic method is visibly selected after saving.');
        $_GET['tab'] = 'credentials';
        $_POST = ['spsync_save_settings' => '1', 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'site_url' => '', 'document_library' => '', 'root_folder' => '', 'encrypt_secret' => '1'];
        ob_start(); \SPSync\Admin\SettingsPage::render_settings_page(); ob_end_clean();
        verify(Settings::get('backup_frequency') === 'daily' && Settings::get('backup_time') === '04:30', 'Saving credentials does not reset the chosen method or schedule.');
        $_POST = [];
        $schedule_fail = true;
        verify(is_wp_error(DeviceBackup::start(1)), 'Scheduling failure is surfaced without a usable device job.');
        $schedule_fail = false;
        $device_id = DeviceBackup::start(1);
        $device_job = DeviceBackup::get($device_id, 1);
        $device_directories[] = $device_job['directory'];
        verify(DeviceBackup::discard($device_id, 1) && !file_exists($device_job['directory']), 'Owner can cancel a queued backup and remove its server storage.');
        $current_user_allowed = false;
        try { \SPSync\Admin\SettingsPage::handle_device_backup(); verify(false, 'Unauthorized device backup was allowed.'); }
        catch (\RuntimeException $error) { verify(strpos($error->getMessage(), 'permission') !== false, 'Creating device backups requires administrator capability.'); }
        $current_user_allowed = true;
        $nonce_allowed = false;
        try { \SPSync\Admin\SettingsPage::handle_download_backup(); verify(false, 'Invalid download nonce was allowed.'); }
        catch (\RuntimeException $error) { verify($error->getMessage() === 'invalid nonce', 'Device download requires a valid nonce.'); }

        Settings::set('restore_status', 'completed');
        Settings::set('backup_frequency', 'once');
        $controller = new \SPSync\Api\RestController();
        $accepted = $controller->start_backup(null);
        verify($accepted instanceof WP_REST_Response && !empty($accepted->data['backup_id']), 'Remote backup accepts an identifiable queued job.');
        $remote_id = $accepted->data['backup_id'];
        verify(end($scheduled)[1] === [$remote_id], 'Remote job ID is passed to the scheduled worker.');
        verify(is_wp_error($controller->start_backup(null)), 'Do not accept duplicate queued API backup commands.');
        verify($controller->get_backup_status(null)->data['backup_id'] === $remote_id, 'Polling reports the accepted ID before archive creation starts.');
        verify(BackupEngine::run() === false, 'A separate backup cannot overtake an accepted API job.');
        $result = \SPSync\Scheduler\Scheduler::run_scheduled_backup($remote_id);
        verify($result === $remote_id, 'Worker creates artifacts using the original API job ID.');
        $remote_manifest = \SPSync\Helpers\LocalPaths::backup_dir($remote_id) . "/manifest.json";
        verify(json_decode(file_get_contents($remote_manifest), true)['backup_id'] === $remote_id, 'Manifest and API identifiers remain identical.');
        verify(!get_option('spsync_api_backup_request'), 'Worker releases its pending API request reservation.');
        verify(Settings::get('backup_frequency') === 'once', 'A manually requested API backup does not cancel an independent one-time schedule.');
        Settings::delete('backup_running');
        $schedule_fail = true;
        verify(is_wp_error($controller->start_backup(null)) && !get_option('spsync_api_backup_request'), 'Remote scheduling failure is returned and its reservation is removed.');
        $schedule_fail = false;
        $accepted = $controller->start_backup(null);
        Settings::set('restore_status', 'extracting');
        \SPSync\Scheduler\Scheduler::run_scheduled_backup($accepted->data['backup_id']);
        $wpdb->has_tables = true;
        $failed = $controller->get_backup_status(null)->data;
        verify($failed['backup_id'] === $accepted->data['backup_id'] && $failed['status'] === 'failed', 'A worker failure before queue insertion is monitorable with the accepted ID.');
        $wpdb->has_tables = false;
        Settings::set('restore_status', 'completed');

        // --- One backup at a time (1.5.1) ---
        $lock = 'SPSync\\Helpers\\BackupLock';
        Settings::delete('backup_running');
        delete_option('spsync_api_backup_request');
        verify($lock::acquire('spsync-backup-a') && !$lock::acquire('spsync-backup-b'), 'Only one backup can hold the lock.');
        Settings::set('backup_running', (string) (time() - 20 * 60));
        verify($lock::is_held() && !$lock::acquire('spsync-backup-b'), 'A long backup 20 minutes after its last progress still counts as running.');
        Settings::set('backup_running', (string) (time() - 2 * 3600));
        verify($lock::is_stale() && $lock::acquire('spsync-backup-b') && $lock::current_id() === 'spsync-backup-b', 'A lock is taken over only after 30 minutes without progress.');
        $lock::release();
        $wpdb->pending_uploads = 40;
        verify(BackupEngine::run() === false && !Settings::get('backup_running'), 'No new backup starts while an earlier one still has files to upload.');
        verify(strpos((string) $lock::busy_reason(), '40 files left') !== false, 'The busy reason names the remaining uploads.');
        $wpdb->pending_uploads = 0;
        $before = count($scheduled);
        $first = $second = '';
        $nonce_allowed = true;
        $current_user_allowed = true;
        try { \SPSync\Admin\SettingsPage::handle_manual_backup(); } catch (\RuntimeException $redirect) { $first = $redirect->getMessage(); }
        try { \SPSync\Admin\SettingsPage::handle_manual_backup(); } catch (\RuntimeException $redirect) { $second = $redirect->getMessage(); }
        verify(strpos($first, 'spsync_notice=success') !== false && strpos($second, 'spsync_notice=error') !== false, 'A double click on Back up now starts one backup and refuses the second: ' . $second);
        verify(count($scheduled) === $before + 1 && !Settings::get('backup_starting'), 'Back up now schedules exactly one background worker and returns at once.');
        $manual_id = get_option('spsync_api_backup_request')['backup_id'];
        $lock::acquire($manual_id);
        $redelivered = \SPSync\Scheduler\Scheduler::run_scheduled_backup($manual_id);
        verify($redelivered === $manual_id && get_option('spsync_api_backup_request') && (get_option('spsync_api_backup_failed')['backup_id'] ?? '') !== $manual_id,
            'A re-delivered worker for a running backup exits without restarting or failing it.');
        $lock::release();
        delete_option('spsync_api_backup_request');

        // --- Per-site SharePoint isolation (1.4.0) ---
        $inventory = 'SPSync\\Storage\\RemoteInventory';
        verify($inventory::site_folder_name() === 'shop.example.test-store', 'Site folder defaults to the WordPress host and path.');
        verify(Settings::get('site_folder') === 'shop.example.test-store', 'Derived site folder is persisted so a later URL change keeps the same folder.');
        verify($inventory::sanitize_folder_name('../evil/..') === 'evil', 'Folder names cannot traverse.');
        verify(strpos($inventory::sanitize_folder_name('spsync-backup-20260101-000000'), 'site-') === 0, 'A site folder can never look like a backup folder.');
        $folder = function ($id, $name, $count = null) { return ['id' => $id, 'name' => $name, 'folder' => $count === null ? [] : ['childCount' => $count]]; };
        $site_folder = $folder('site-1', 'shop.example.test-store');
        Settings::set('site_folder_id', 'site-1');
        Settings::set('site_folder_binding', 'test-root|shop.example.test-store');

        \SPSync\Auth\GraphClient::$responses = [false];
        verify(is_wp_error($controller->verify_remote_backups(null)), 'A failed SharePoint listing must not be interpreted as missing backups.');
        $wpdb->backup_ids = ['spsync-backup-own-legacy'];
        \SPSync\Auth\GraphClient::$responses = [
            ['value' => [$folder('l1', 'spsync-backup-own-legacy'), $folder('l2', 'spsync-backup-other-site')], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next-page'],
            ['value' => [$site_folder, $folder('o1', 'other-site.example')]],
            ['value' => [$folder('b1', 'spsync-backup-first'), $folder('b2', 'spsync-backup-second')]],
        ];
        $verified = $controller->verify_remote_backups(null)->data;
        verify($verified['remote_backups'] === ['spsync-backup-first', 'spsync-backup-own-legacy', 'spsync-backup-second'], 'Verification follows pagination and lists only this site\'s backups: ' . json_encode($verified['remote_backups']));
        verify(!in_array('spsync-backup-other-site', $verified['remote_backups'], true), 'Another site\'s legacy root-level backup is never claimed.');
        verify($verified['site_folder'] === 'shop.example.test-store', 'Verification reports the site folder.');
        \SPSync\Auth\GraphClient::$responses = [['value' => [], '@odata.nextLink' => 'https://untrusted.example/next-page']];
        verify(is_wp_error($controller->verify_remote_backups(null)), 'Do not follow Graph pagination to another host.');

        // Missing / incomplete / still-uploading classification.
        $wpdb->has_tables = true;
        $wpdb->backup_ids = [];
        $wpdb->updates = [];
        $wpdb->results = [
            ['backup_id' => 'spsync-backup-complete', 'total' => 3, 'done' => 3, 'missing' => 0],
            ['backup_id' => 'spsync-backup-partial', 'total' => 3, 'done' => 3, 'missing' => 0],
            ['backup_id' => 'spsync-backup-gone', 'total' => 2, 'done' => 2, 'missing' => 0],
            ['backup_id' => 'spsync-backup-uploading', 'total' => 3, 'done' => 1, 'missing' => 0],
            ['backup_id' => 'spsync-backup-back', 'total' => 2, 'done' => 0, 'missing' => 2],
        ];
        \SPSync\Auth\GraphClient::$responses = [
            ['value' => [$site_folder]],
            ['value' => [$folder('c', 'spsync-backup-complete', 3), $folder('p', 'spsync-backup-partial', 1), $folder('r', 'spsync-backup-back', 2)]],
        ];
        $verified = $controller->verify_remote_backups(null)->data;
        verify($verified['missing_backups'] === ['spsync-backup-gone'], 'Only fully uploaded backups absent from SharePoint are missing: ' . json_encode($verified));
        verify($verified['incomplete_backups'] === ['spsync-backup-partial'], 'A folder with fewer files than uploaded is incomplete.');
        verify($verified['pending_backups'] === ['spsync-backup-uploading'], 'A backup still uploading is pending, not missing.');
        verify(in_array([['status' => 'done'], ['backup_id' => 'spsync-backup-back', 'status' => 'missing']], $wpdb->updates, true), 'A reappearing backup is marked present again.');
        verify(in_array([['status' => 'missing'], ['backup_id' => 'spsync-backup-gone', 'status' => 'done']], $wpdb->updates, true), 'A vanished backup is marked missing.');
        $wpdb->has_tables = false;
        $wpdb->results = [];

        // The site folder is recreated when it no longer exists.
        \SPSync\Auth\GraphClient::$responses = [['value' => []], ['id' => 'site-2'], ['value' => []]];
        verify($controller->verify_remote_backups(null)->data['total_remote'] === 0 && Settings::get('site_folder_id') === 'site-2', 'A deleted site folder is recreated and re-cached.');
        $site_folder = $folder('site-2', 'shop.example.test-store');

        // Retention counts only this site's backups and keeps tracking when a delete fails.
        Settings::set('retention_limit', '1');
        $wpdb->deletes = [];
        \SPSync\Auth\GraphClient::$responses = [
            ['value' => [$site_folder, $folder('x', 'spsync-backup-20200101-000000')]],
            ['value' => [$folder('a', 'spsync-backup-20260101-000000'), $folder('b', 'spsync-backup-20260102-000000'), $folder('c', 'spsync-backup-20260103-000000')]],
            false,
            null,
        ];
        verify(\SPSync\Backup\Retention::enforce() === 1, 'Retention deletes only confirmed folders.');
        verify($wpdb->deletes === [['backup_id' => 'spsync-backup-20260102-000000']], 'Tracking rows are removed only after SharePoint confirms deletion: ' . json_encode($wpdb->deletes));
        Settings::set('retention_limit', '30');
        \SPSync\Auth\GraphClient::$responses = [];

        $source = staging(['wp-content/uploads/zip-restored.txt' => 'ZIP restoration fixture']);
        $staging_directories[] = $source;
        $manifest_text = file_get_contents($source . '/manifest.json');
        $part_bytes = file_get_contents($source . '/backup_part001.zip');
        $outer = $fixture . '/uploaded-package.zip';
        foreach (['', 'spsync-backup-folder/', 'downloads/spsync-backups/one-backup/'] as $prefix) {
            archive($outer, [$prefix . 'manifest.json' => $manifest_text, $prefix . 'backup_part001.zip' => $part_bytes]);
            $import = PrivateStorage::create('import');
            $staging_directories[] = $import;
            $verified = LocalBackup::unpack_archive($outer, $import);
            verify(!is_wp_error($verified), 'Accept a flat or nested single-backup ZIP: ' . $prefix);
            verify(file_get_contents($import . '/backup_part001.zip') === $part_bytes, 'Outer ZIP extraction preserves the original part bytes.');
        }
        $invalid_packages = [
            ['one/manifest.json' => $manifest_text, 'two/manifest.json' => $manifest_text],
            ['backup_part001.zip' => $part_bytes],
            ['manifest.json' => $manifest_text],
            ['manifest.json' => $manifest_text, 'backup_part001.zip' => 'corrupt'],
            ['manifest.json' => $manifest_text, 'backup_part001.zip' => $part_bytes, 'shell.php' => '<?php unsafe'],
            ['manifest.json' => $manifest_text, '../escape.php' => 'unsafe'],
            ['manifest.json' => str_repeat('x', 1048577)],
            ['one/manifest.json' => $manifest_text, 'elsewhere/backup_part001.zip' => $part_bytes],
        ];
        foreach ($invalid_packages as $package) {
            archive($outer, $package);
            $import = PrivateStorage::create('import');
            $staging_directories[] = $import;
            verify(is_wp_error(LocalBackup::unpack_archive($outer, $import)), 'Reject missing/corrupt/multiple/unsafe backup packages.');
        }
        archive($outer, ['manifest.json' => $manifest_text, 'backup_part001.zip' => $part_bytes]);
        $import = PrivateStorage::create('import');
        $staging_directories[] = $import;
        $filter_values['spsync_restore_max_expanded_bytes'] = 20;
        verify(is_wp_error(LocalBackup::unpack_archive($outer, $import)), 'Enforce expanded-size limit on the uploaded outer ZIP.');
        unset($filter_values['spsync_restore_max_expanded_bytes']);
        verify(is_wp_error(LocalBackup::upload_zip(['name' => 'backup.zip', 'error' => UPLOAD_ERR_OK, 'tmp_name' => $outer])), 'ZIP upload must originate from an actual HTTP upload.');
        verify(is_wp_error(LocalBackup::unpack_archive($outer, $fixture)), 'Do not unpack to an arbitrary directory.');

        $automatic = staging(['wp-content/uploads/automatically-restored.txt' => 'Started without cron']);
        $staging_directories[] = $automatic;
        $id = ManualRestore::start($automatic);
        ManualRestore::dispatch_after_response($id);
        verify(!file_exists(ABSPATH . 'wp-content/uploads/automatically-restored.txt'), 'Register dispatch before applying the restore.');
        $dispatch = end($actions['shutdown']);
        $dispatch();
        verify(file_get_contents(ABSPATH . 'wp-content/uploads/automatically-restored.txt') === 'Started without cron', 'Response-shutdown dispatch restores files without a cron visit.');
        verify(Settings::get('restore_status') === 'completed' && !file_exists($automatic), 'Immediate restore reaches completion and cleans staging.');

        $nonce_allowed = true;
        $current_user_allowed = true;
        $_POST = [];
        $_FILES = [];
        $before_dispatch = count($actions['shutdown']);
        try { \SPSync\Admin\SettingsPage::handle_upload_backup(); verify(false, 'Unconfirmed upload was allowed.'); }
        catch (\RuntimeException $error) { verify(strpos($error->getMessage(), 'Confirm') !== false, 'Require overwrite confirmation on the initial upload form.'); }
        verify(count($actions['shutdown']) === $before_dispatch, 'Missing confirmation never schedules immediate restoration.');
        $_POST = ['confirm_restore' => '1'];
        $_FILES = ['backup_zip' => ['name' => 'backup.zip'], 'backup_folder' => ['name' => ['manifest.json']]];
        try { \SPSync\Admin\SettingsPage::handle_upload_backup(); verify(false, 'Mixed upload sources were allowed.'); }
        catch (\RuntimeException $error) { verify(strpos($error->getMessage(), 'one+backup+ZIP') !== false, 'Require exactly one upload source.'); }
        $_FILES = [];
        $automatic = staging(['wp-content/uploads/retry-restored.txt' => 'Previously uploaded backup']);
        $staging_directories[] = $automatic;
        update_user_meta(1, 'spsync_pending_import', $automatic);
        try { \SPSync\Admin\SettingsPage::handle_restore_uploaded(); verify(false, 'Expected restoration redirect.'); }
        catch (\RuntimeException $error) { verify(strpos($error->getMessage(), 'starting+now') !== false, 'Previously verified upload can be restored immediately without reuploading.'); }
        $dispatch = end($actions['shutdown']); $dispatch();
        verify(file_get_contents(ABSPATH . 'wp-content/uploads/retry-restored.txt') === 'Previously uploaded backup', 'Retry action dispatches the retained verified backup.');
        $_POST = [];
        $_GET = ['tab' => 'restore'];
        ob_start(); \SPSync\Admin\SettingsPage::render_settings_page(); $html = ob_get_clean();
        verify(strpos($html, 'name="backup_zip"') !== false && strpos($html, 'Upload, Verify &amp; Restore') !== false, 'Manual Restore offers ZIP input and a single upload/verify/restore button.');
        verify(strpos($html, 'name="confirm_restore" value="1" required') !== false, 'Initial form includes a required overwrite confirmation.');
        // ---- Admin screens (2.0 layout): every page/section renders cleanly and says the right thing.
        $render = function (array $get) {
            $_GET = $get;
            $_POST = [];
            set_error_handler(function ($severity, $message, $file, $line) { throw new \ErrorException($message, 0, $severity, $file, $line); });
            try {
                ob_start();
                \SPSync\Admin\SettingsPage::render_settings_page();
                return ob_get_clean();
            } catch (\Throwable $error) {
                ob_end_clean();
                throw $error;
            } finally {
                restore_error_handler();
            }
        };
        $saved_settings = Settings::$values;
        $wpdb->has_tables = false;
        foreach (['tenant_id', 'client_id', 'client_secret', 'site_url', 'connection_test', 'backup_running'] as $key) { Settings::delete($key); }
        Settings::set('backup_frequency', 'manual');
        Settings::set('restore_status', 'completed');

        $html = $render([]);
        verify(strpos($html, 'Finish setting up your backups') !== false && strpos($html, 'Get set up') !== false, 'A fresh site sees a setup checklist instead of jargon.');
        verify(strpos($html, 'Connect SharePoint') !== false && strpos($html, 'Download a copy') !== false, 'Dashboard offers the next step and a device download.');
        foreach (['dashboard', 'backups', 'restore', 'settings', 'activity'] as $page) {
            verify(strpos($html, 'tab=' . $page) !== false, "Navigation links to $page.");
        }
        foreach (['schedule', 'contents', 'sharepoint', 'dashboard'] as $section) {
            $page_html = $render(['tab' => 'settings', 'section' => $section]);
            verify(strpos($page_html, 'aria-current="page">' . \SPSync\Admin\SettingsPage::SECTIONS[$section]) !== false, "Settings section $section renders and is marked current.");
        }
        verify(strpos($render(['tab' => 'credentials']), 'Microsoft 365 sign-in') !== false, 'Old "credentials" links open the SharePoint section.');
        verify(strpos($render(['tab' => 'logs']), 'aria-label="Filter activity"') !== false, 'Old "logs" links open Activity.');
        verify(strpos($render(['tab' => 'nonsense']), 'Get set up') !== false, 'Unknown screens fall back to the dashboard.');
        $sharepoint_html = $render(['tab' => 'settings', 'section' => 'sharepoint']);
        verify(strpos($sharepoint_html, 'name="client_secret" type="password" id="client_secret" value=""') !== false, 'The saved client secret is never sent back to the browser.');
        verify(strpos($render(['tab' => 'settings', 'section' => 'dashboard']), 'data-spsync-copy="spsync-api-key-input"') !== false, 'Central dashboard key has a copy button.');
        verify(strpos($render(['tab' => 'backups']), 'No backups yet') !== false, 'Backups screen has an empty state.');

        // Status wording follows the real state.
        Settings::set('tenant_id', 't'); Settings::set('client_id', 'c'); Settings::set('client_secret', 's'); Settings::set('site_url', 'https://contoso.sharepoint.com/sites/b');
        \SPSync\Admin\StatusSummary::record_connection_test(['success' => false, 'message' => 'AADSTS7000215: Invalid client secret.']);
        verify(\SPSync\Admin\StatusSummary::overall([])['title'] === "SharePoint can't be reached", 'A failed connection test is the headline.');
        \SPSync\Admin\StatusSummary::record_connection_test(['success' => true, 'message' => 'ok']);
        verify(\SPSync\Admin\StatusSummary::overall([])['title'] === 'Automatic backups are off', 'Manual-only sites are told automatic backups are off.');
        verify(strpos($render(['tab' => 'backups']), 'spsync_run_manual_backup') !== false, 'Back up now is offered when nothing is running.');
        $wpdb->pending_uploads = 12;
        $busy_html = $render(['tab' => 'backups']);
        verify(strpos($busy_html, 'Backup in progress') !== false && strpos($busy_html, 'spsync_run_manual_backup') === false && strpos($busy_html, '12 files left') !== false,
            'Back up now is replaced by a disabled button and the reason while a backup is still uploading.');
        verify(strpos($render([]), 'spsync_run_manual_backup') === false, 'The dashboard button is disabled too.');
        $wpdb->pending_uploads = 0;
        Settings::set('backup_frequency', 'daily');
        $recent = ['id' => 'spsync-backup-x', 'time' => time() - 3600, 'files' => 3, 'uploaded' => 3, 'size' => 2048, 'state' => 'completed'];
        verify(\SPSync\Admin\StatusSummary::overall([$recent])['level'] === 'ok', 'A recent successful backup on a schedule is "protected".');
        verify(\SPSync\Admin\StatusSummary::overall([['time' => time() - 3 * DAY_IN_SECONDS] + $recent])['title'] === 'Backups are overdue', 'An old last backup on a daily schedule is overdue.');
        verify(\SPSync\Admin\StatusSummary::overall([['state' => 'failed'] + $recent, $recent])['title'] === 'The last backup failed', 'A failed latest backup is reported.');
        verify(\SPSync\Admin\StatusSummary::backup_time('spsync-backup-20261114-020000') === (new \DateTimeImmutable('2026-11-14 02:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 'Backup time is read from its ID.');
        Settings::set('backup_running', (string) (time() - 3600));
        $html = $render([]);
        verify(strpos($html, 'A backup seems to be stuck') !== false && strpos($html, 'spsync_reset_lock') !== false, 'The stuck-lock reset is offered only when a backup looks stuck.');
        Settings::set('backup_running', (string) time());
        $html = $render([]);
        verify(strpos($html, 'data-autorefresh') !== false && strpos($html, 'spsync_reset_lock') === false, 'A running backup refreshes the page and hides the reset.');
        Settings::delete('backup_running');
        verify(strpos($render([]), 'spsync_reset_lock') === false, 'No reset button when nothing is running.');

        // Restoring a SharePoint backup from the Backups screen requires confirmation and a valid ID.
        $_POST = ['backup_id' => 'spsync-backup-20261114-020000'];
        try { \SPSync\Admin\SettingsPage::handle_restore_remote(); verify(false, 'Unconfirmed remote restore was accepted.'); }
        catch (\RuntimeException $error) { verify(strpos($error->getMessage(), 'tab=backups') !== false && strpos($error->getMessage(), 'Confirm') !== false, 'Remote restore requires explicit confirmation.'); }
        $_POST = ['backup_id' => '../../etc', 'confirm_restore' => '1'];
        try { \SPSync\Admin\SettingsPage::handle_restore_remote(); verify(false, 'Invalid backup ID was accepted.'); }
        catch (\RuntimeException $error) { verify(strpos($error->getMessage(), 'spsync_notice=error') !== false, 'Remote restore rejects malformed backup IDs.'); }
        $nonce_allowed = false;
        $_POST = ['backup_id' => 'spsync-backup-20261114-020000', 'confirm_restore' => '1'];
        try { \SPSync\Admin\SettingsPage::handle_restore_remote(); verify(false, 'Remote restore without a nonce was accepted.'); }
        catch (\RuntimeException $error) { verify($error->getMessage() === 'invalid nonce', 'Remote restore requires a valid nonce.'); }
        $nonce_allowed = true;
        $_POST = [];
        $_GET = [];
        Settings::$values = $saved_settings;

        // Monthly schedules are calendar-based single events that re-arm after each run.
        $event = \SPSync\Scheduler\Scheduler::EVENT_HOOK;
        \SPSync\Scheduler\Scheduler::update_schedule('monthly', '03:00', 0, 15);
        $monthly = array_values(array_filter($scheduled, function ($e) use ($event) { return $e[0] === $event; }));
        verify(count($monthly) === 1 && $monthly[0][1] === [], 'Monthly backups use a single calendar event, not a drifting 30-day recurrence: ' . json_encode($monthly));
        $next = \SPSync\Scheduler\Scheduler::calculate_next_timestamp('monthly', '03:00', 0, 15);
        verify((int) wp_date('j', $next) === 15, 'The next monthly run lands on the chosen day of the month.');
        Settings::set('backup_frequency', 'monthly');
        Settings::set('backup_day_of_month', '15');
        $scheduled = array_values(array_filter($scheduled, function ($e) use ($event) { return $e[0] !== $event; }));
        \SPSync\Scheduler\Scheduler::run_scheduled_backup();
        Settings::delete('backup_running');
        verify((bool) wp_next_scheduled($event), 'A monthly run re-arms the next calendar month.');
        \SPSync\Scheduler\Scheduler::update_schedule('manual');
        Settings::set('backup_frequency', 'manual');

        // Status responses carry monitoring data and surface restore errors.
        Settings::set('restore_status', 'failed');
        Settings::set('restore_error', 'Checksum mismatch');
        Settings::set('backup_frequency', 'daily');
        $status = $controller->get_backup_status(null)->data;
        verify(array_key_exists('last_successful_backup', $status) && $status['schedule']['frequency'] === 'daily' && $status['site_folder'] === 'shop.example.test-store', 'Status includes last successful backup, schedule and site folder: ' . json_encode($status));
        verify($status['restore']['error'] === 'Checksum mismatch', 'Status exposes the restore failure reason.');
        Settings::set('restore_status', 'completed');
        Settings::delete('restore_error');
        verify($controller->get_backup_status(null)->data['restore']['error'] === null, 'No restore error is reported for a healthy site.');

        // Local SharePoint staging lives under an unguessable, protected directory.
        $private_root = \SPSync\Helpers\LocalPaths::private_root();
        verify(preg_match('#/spsync-backups/[a-f0-9]{32}\z#', $private_root) === 1, 'Local backups are staged under a random token directory.');
        verify($private_root === \SPSync\Helpers\LocalPaths::private_root(), 'The token directory is stable across calls.');
        foreach ([\SPSync\Helpers\LocalPaths::base(), $private_root] as $protected) {
            verify(strpos((string) @file_get_contents($protected . '/.htaccess'), 'Require all denied') !== false && is_file($protected . '/web.config') && is_file($protected . '/index.php'), 'Staging directories carry Apache 2.4/2.2 and IIS deny rules: ' . $protected);
        }

        // Remote (SharePoint) manifests are validated before their filenames become local paths.
        $hash = str_repeat('a', 64);
        $manifest_error = ['SPSync\\Restore\\RestoreManager', 'manifest_error'];
        verify($manifest_error(['backup_id' => 'spsync-backup-x', 'parts' => [['filename' => 'database.sql', 'checksum' => $hash]]], 'spsync-backup-x') === '', 'Accept a well-formed remote manifest.');
        verify($manifest_error(['parts' => [['filename' => '../../wp-config.php', 'checksum' => $hash]]], 'spsync-backup-x') !== '', 'Reject traversal filenames in remote manifests.');
        verify($manifest_error(['parts' => [['filename' => 'backup_part001.zip', 'checksum' => '']]], 'spsync-backup-x') !== '', 'Reject remote parts without a verifiable checksum.');
        verify($manifest_error(['parts' => [['filename' => 'database.sql', 'checksum' => $hash], ['filename' => 'database.sql', 'checksum' => $hash]]], 'spsync-backup-x') !== '', 'Reject duplicate remote parts.');
        verify($manifest_error(['backup_id' => 'spsync-backup-other', 'parts' => [['filename' => 'database.sql', 'checksum' => $hash]]], 'spsync-backup-x') !== '', 'Reject a manifest from another backup.');

        // URL migration binds the search value instead of concatenating it into SQL.
        $real_wpdb = $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $selects = [];
            public function get_col($sql) { return ['wp_options']; }
            public function get_row($sql) { return (object) ['Column_name' => 'option_id']; }
            public function get_results($sql, $mode = null) {
                if (strpos($sql, 'SHOW COLUMNS') === 0) { return [(object) ['Field' => 'option_value', 'Type' => 'longtext']]; }
                $this->selects[] = $sql;
                return [];
            }
            public function esc_like($value) { return addcslashes($value, '_%\\'); }
            public function prepare($query, ...$args) {
                return str_replace('%s', "'" . addslashes($args[0]) . "'", $query);
            }
        };
        $injection = "https://old.example/x' OR 1=1 -- ";
        verify(\SPSync\Database\DatabaseMigrator::search_and_replace($injection, 'https://new.example')['success'] === false, 'URL migration rejects malformed URLs.');
        \SPSync\Database\DatabaseMigrator::search_and_replace("https://old.example/a'b", 'https://new.example', true);
        verify(isset($wpdb->selects[0]) && strpos($wpdb->selects[0], "LIKE '%https://old.example/a\\'b%'") !== false, 'URL migration escapes quotes in the LIKE value: ' . json_encode($wpdb->selects));
        verify(\SPSync\Database\DatabaseMigrator::search_and_replace('javascript:alert(1)', 'https://new.example')['success'] === false, 'URL migration accepts only http(s) URLs.');
        $wpdb = $real_wpdb;

        echo "PASS: $checks regression checks\n";
    } finally {
        foreach ($device_directories as $path) { PrivateStorage::remove($path, 'download'); }
        foreach ($staging_directories as $path) { LocalBackup::remove($path); }
        // The fixture was created here with a random name; never recurse outside it.
        if (realpath(dirname($fixture)) === realpath(sys_get_temp_dir()) && strpos(basename($fixture), 'spsync-tests-') === 0) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixture, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
                else { unlink($entry->getPathname()); }
            }
            rmdir($fixture);
        }
    }
}
