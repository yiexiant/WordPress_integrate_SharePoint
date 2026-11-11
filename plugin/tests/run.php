<?php
/** Isolated regression tests. Run: php plugin/tests/run.php. No real WordPress or cloud calls. */
namespace SPSync\Helpers {
    class Settings
    {
        public static $values = [];
        public static function get($key, $default = '') { return self::$values[$key] ?? $default; }
        public static function set($key, $value) { self::$values[$key] = $value; return true; }
        public static function delete($key) { unset(self::$values[$key]); return true; }
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
namespace {
    use SPSync\Backup\BackupSelection;
    use SPSync\Backup\BackupEngine;
    use SPSync\Backup\FileScanner;
    use SPSync\Helpers\Settings;
    use SPSync\Restore\LocalBackup;
    use SPSync\Restore\ManualRestore;
    use SPSync\Restore\RestoreEngine;

    $fixture = sys_get_temp_dir() . '/spsync-tests-' . bin2hex(random_bytes(8));
    mkdir($fixture, 0700);
    define('ABSPATH', $fixture . '/site/');
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
    define('MINUTE_IN_SECONDS', 60);
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

    class WP_Error
    {
        private $message;
        public function __construct($code, $message) { $this->message = $message; }
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
        public function query($sql) {
            $this->queries[] = $sql;
            return $this->fail && strpos($sql, $this->fail) !== false ? false : 1;
        }
        public function insert($table, $row, $formats = []) { $this->items[] = $row; return 1; }
        public function get_col($sql) { return ['wp_posts', 'wp_spsync_settings']; }
        public function get_row($sql, $mode = null) { return ['wp_posts', 'CREATE TABLE `wp_posts` (`id` int)']; }
        public function get_var($sql) { return strpos($sql, 'SHOW TABLES') === 0 ? null : 0; }
    }
    $wpdb = new TestDatabase();

    function is_wp_error($value) { return $value instanceof WP_Error; }
    function add_action() {}
    function register_activation_hook() {}
    function register_deactivation_hook() {}
    function plugin_dir_path($path) { return dirname($path) . '/'; }
    function apply_filters($name, $value) { return $value; }
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
    function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
    function wp_schedule_single_event($time, $hook, $args = []) {
        global $scheduled;
        $scheduled[] = [$hook, $args];
        return true;
    }
    function current_user_can($capability) { global $current_user_allowed; return $current_user_allowed; }
    function check_admin_referer($nonce) { global $nonce_allowed; if (!$nonce_allowed) { throw new \RuntimeException('invalid nonce'); } }
    function wp_die($message) { throw new \RuntimeException($message); }
    function get_current_user_id() { return 1; }
    function get_user_meta($id, $key, $single = true) { global $metadata; return $metadata[$key] ?? ''; }
    function admin_url($path) { return '/wp-admin/' . $path; }
    function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
    function esc_html($value) { return esc_attr($value); }
    function esc_url($value) { return esc_attr($value); }
    function __($value, $domain = null) { return $value; }
    function esc_html__($value, $domain = null) { return esc_html($value); }
    function esc_html_e($value, $domain = null) { echo esc_html($value); }
    function sanitize_text_field($value) { return strip_tags($value); }
    function wp_nonce_field($value) { echo '<input type="hidden" name="_wpnonce" value="test" />'; }
    function checked($value, $expected = true) { if ((string) $value === (string) $expected) { echo 'checked="checked"'; } }
    function wp_max_upload_size() { return 10485760; }
    function wp_unslash($value) { return $value; }
    function add_query_arg($values, $url) { return $url . '?' . http_build_query($values); }
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
        $base = wp_upload_dir()['basedir'] . '/spsync-backups/' . $id;
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
        $base = wp_upload_dir()['basedir'] . '/spsync-backups/' . $id;
        $manifest = json_decode(file_get_contents($base . '/manifest.json'), true);
        verify(count($manifest['parts']) === 1 && $manifest['parts'][0]['filename'] === 'database.sql', 'Database-only backup works without any ZIP files.');
        Settings::delete('backup_running');
        BackupSelection::save('full', [], false);
        $id = BackupEngine::run();
        $base = wp_upload_dir()['basedir'] . '/spsync-backups/' . $id;
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
        echo "PASS: $checks regression checks\n";
    } finally {
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
