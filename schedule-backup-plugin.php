<?php
/**
 * Plugin Name: WordPress SharePoint Backup Plugin
 * Description: Schedule WordPress backups and upload them to SharePoint using Microsoft Graph.
 * Version: 1.0.0
 * Author: Antigravity AI
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: schedule-backup-plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('SPSYNC_VERSION', '1.0.0');
define('SPSYNC_PLUGIN_FILE', __FILE__);
define('SPSYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Register Autoloader for SPSync namespace
spl_autoload_register(function ($class) {
    $prefix = 'SPSync\\';
    $base_dir = SPSYNC_PLUGIN_DIR;
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    // Replace namespace separator with directory separator, maintaining casing (PSR-4)
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// Activation and Deactivation Hooks
register_activation_hook(__FILE__, [ 'SPSync\\Database\\Installer', 'activate' ]);
register_deactivation_hook(__FILE__, [ 'SPSync\\Database\\Installer', 'deactivate' ]);

// Initialize Plugin Components on plugins_loaded
add_action('plugins_loaded', function () {
    // Initialize Database hooks if any
    SPSync\Database\Installer::init();
    
    // Initialize Logging system
    SPSync\Logger\Logger::init();

    // Initialize Background Queue Manager
    SPSync\Queue\QueueManager::init();
    
    // Initialize Admin Settings Page
    if (is_admin()) {
        SPSync\Admin\SettingsPage::init();
    }
    
    // Initialize REST API Endpoints
    SPSync\Api\RestController::init();
    
    // Initialize Backup Scheduler
    SPSync\Scheduler\Scheduler::init();
});
