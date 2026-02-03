<?php
/**
 * Plugin Name: WONS Firewall
 * Plugin URI: https://wons.bt
 * Description: Comprehensive security solution that blocks automated attacks, brute force attempts, and suspicious behavior
 * Version: 1.1.2
 * Author: WONS
 * Author URI: https://wons.bt
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: adv-wp-security
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
if (!defined('FS_METHOD')) {
    define('FS_METHOD', 'direct');
}

class Wons_Firewall {
    // Plugin version
    const VERSION = '1.1.2';
    
    // Instance of this class
    protected static $instance = null;

    private $license_valid = false;
    private $license_expiry = '';
    
    // How long to ban IPs (in seconds)
    private $ban_duration = 86400; // 24 hours
    
    // Settings 
    private $settings;
    
    // Default settings
    private $default_settings = [
        'enabled' => true,
        'block_bot_user_agents' => true,
        'bot_user_agents' => [
            'python-requests',
            'python-httpx',
            'aiohttp',
            'requests/',
            'python',
            'curl',
            'wget',
            'go-http-client',
            'ruby',
            'perl',
            'mechanize',
            'nikto',
            'nmap',
            'sqlmap',
            'scanner',
            'semrush',
            'ahrefsbot',
        ],
        'enable_ip_banning' => true,
        'ban_duration' => 86400,
        'login_protection' => true,
        'max_login_attempts' => 5,
        'login_lockout_duration' => 1800,
        'admin_protection' => true,
        'whitelist_ips' => [],
        'block_plugin_install' => true,
        'allowed_plugin_install_ips' => [],
        'log_attacks' => true,
        'log_max_files' => 30,
        'log_max_lines' => 1000
    ];
    
    // IP address of current visitor
    private $visitor_ip;
    
    // User agent of current visitor
    private $user_agent;
    
    /**
     * Constructor for the main plugin class
     */
    public function __construct() {
        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Check license status
        $this->license_valid = get_option('wons_firewall_license_valid', false);
        $this->license_expiry = get_option('wons_firewall_license_expiry', '');
        $this->check_license();
        
        if (!$this->license_valid) {
            add_action('admin_notices', array($this, 'show_license_notice'));
            // return;
        }

        // Set up plugin updates
        $this->setup_plugin_updates();
        
        // Load settings
        $this->load_settings();

        if (!$this->license_valid) {
           $this->settings['enabled']=false;
        }

        
        
        // Get visitor information
        $this->visitor_ip = $this->get_real_ip();
        $this->user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        // Initialize security measures
        $this->initialize_security();
        
        // Admin settings
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_init', array($this, 'handle_manual_update'));
            add_action('admin_init', array($this, 'handle_log_actions'));
        }
        
        // Login protection
        add_action('wp_login_failed', array($this, 'track_failed_login'));
        add_filter('authenticate', array($this, 'check_login_attempts'), 30, 3);
        
        // Clean old log files periodically (5% chance to run on each request)
        if (mt_rand(1, 100) <= 5 && $this->settings['log_attacks']) {
            $this->clean_old_log_files();
        }
    }

    /**
     * Handle manual plugin update
     */
    public function handle_manual_update() {
        if (!isset($_GET['wons_action']) || $_GET['wons_action'] !== 'update_plugin') {
            return;
        }
        
        if (!current_user_can('update_plugins')) {
            wp_die('You do not have sufficient permissions to update plugins for this site.');
        }
        
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wons_update_plugin')) {
            wp_die('Security check failed.');
        }
        
        // Set up proper admin page header
        global $title, $hook_suffix;
        $title = 'Update WONS Firewall';
        $hook_suffix = '';
        
        require_once(ABSPATH . 'wp-admin/admin-header.php');
        
        // Get update info
        $update_info = $this->check_for_updates_debug();
        if (!$update_info || !isset($update_info->download_url)) {
            echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>';
            echo '<div class="notice notice-error"><p>Update information not available.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wons_firewall')) . '" class="button button-primary">Return to WONS Firewall</a></p>';
            echo '</div>';
            require_once(ABSPATH . 'wp-admin/admin-footer.php');
            exit;
        }
        
        // Start the update process with proper styling
        echo '<div class="wrap">';
        echo '<h1>' . esc_html($title) . '</h1>';
        
        echo '<div class="card" style="max-width: 800px; margin-top: 20px;">';
        echo '<h2 class="title">Update Information</h2>';
        echo '<div class="inside">';
        echo '<p><strong>Current version:</strong> ' . esc_html(self::VERSION) . '</p>';
        echo '<p><strong>New version:</strong> ' . esc_html($update_info->version) . '</p>';
        echo '</div></div>';
        
        echo '<div class="card" style="max-width: 800px; margin-top: 20px;">';
        echo '<h2 class="title">Update Progress</h2>';
        echo '<div class="inside" id="update-progress">';
        
        // Include necessary WordPress files for unzipping
        require_once ABSPATH . 'wp-admin/includes/file.php';
        
        // Function to update progress
        echo '<script>
        function updateProgress(message, type) {
            var progressDiv = document.getElementById("update-progress");
            var statusClass = type || "updated";
            var html = "<div class=\'notice notice-" + statusClass + "\' style=\'margin-left: 0;\'><p>" + message + "</p></div>";
            progressDiv.innerHTML += html;
            window.scrollTo(0, document.body.scrollHeight);
        }
        </script>';
        
        // Create temp directory
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Creating temporary directory...</p></div>';
        flush();

        if (!function_exists('WP_Filesystem')) {
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
        }
        global $wp_filesystem;
        WP_Filesystem();
        
        $temp_dir = WP_CONTENT_DIR . '/upgrade/wons-update-' . time();
        if (!wp_mkdir_p($temp_dir)) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>Could not create temporary directory for update: ' . esc_html($temp_dir) . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wons_firewall')) . '" class="button button-primary">Return to WONS Firewall</a></p>';
            echo '</div></div></div>';
            require_once(ABSPATH . 'wp-admin/admin-footer.php');
            exit;
        }
        
        // Download the ZIP file
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Downloading update package...</p></div>';
        flush();

        
        
        $zip_file = $temp_dir . '/update.zip';
        $download_result = $this->download_file($update_info->download_url, $zip_file);
        if (is_wp_error($download_result)) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>Download failed: ' . esc_html($download_result->get_error_message()) . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wons_firewall')) . '" class="button button-primary">Return to WONS Firewall</a></p>';
            echo '</div></div></div>';
            require_once(ABSPATH . 'wp-admin/admin-footer.php');
            exit;
        }
        
        if (!file_exists($zip_file)) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>Download seemed successful but ZIP file not found at: ' . esc_html($zip_file) . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wons_firewall')) . '" class="button button-primary">Return to WONS Firewall</a></p>';
            echo '</div></div></div>';
            require_once(ABSPATH . 'wp-admin/admin-footer.php');
            exit;
        }
        
        echo '<div class="notice notice-success" style="margin-left: 0;"><p>Download complete. File size: ' . esc_html(size_format(filesize($zip_file))) . '</p></div>';
        flush();
        
        // Extract the ZIP file
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Extracting ZIP file...</p></div>';
        flush();

        
        $unzip_result = unzip_file($zip_file, $temp_dir);
        if (is_wp_error($unzip_result)) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>Extraction failed: ' . esc_html($unzip_result->get_error_message()) . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wons_firewall')) . '" class="button button-primary">Return to WONS Firewall</a></p>';
            echo '</div></div></div>';
            require_once(ABSPATH . 'wp-admin/admin-footer.php');
            exit;
        }
        
        echo '<div class="notice notice-success" style="margin-left: 0;"><p>Extraction complete.</p></div>';
        flush();
        
        // Find the plugin directory in the extracted files
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Locating plugin files...</p></div>';
        flush();
        
        $plugin_dir = $this->find_plugin_directory($temp_dir);
        if (!$plugin_dir) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>Could not find plugin directory in the update package. Please check the ZIP file structure.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wons_firewall')) . '" class="button button-primary">Return to WONS Firewall</a></p>';
            echo '</div></div></div>';
            require_once(ABSPATH . 'wp-admin/admin-footer.php');
            exit;
        }
        
        echo '<div class="notice notice-success" style="margin-left: 0;"><p>Plugin files found at: ' . esc_html($plugin_dir) . '</p></div>';
        flush();
        
        // Get the current plugin path
        $current_plugin_file = plugin_basename(__FILE__);
        $current_plugin_dir = WP_PLUGIN_DIR . '/' . dirname($current_plugin_file);
        
        // Deactivate the plugin
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Deactivating plugin...</p></div>';
        flush();
        
        deactivate_plugins($current_plugin_file);
        
        // Remove the current plugin directory
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Removing old plugin files...</p></div>';
        flush();
        
        $this->recursively_delete_directory($current_plugin_dir);
        
        // Create the plugin directory if it doesn't exist
        if (!file_exists($current_plugin_dir)) {
            wp_mkdir_p($current_plugin_dir);
        }
        
        // Copy the new plugin files
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Installing new plugin files...</p></div>';
        flush();
        
        $this->recursively_copy_directory($plugin_dir, $current_plugin_dir);
        
        // Clean up
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Cleaning up temporary files...</p></div>';
        flush();
        
        $this->recursively_delete_directory($temp_dir);
        
        // Reactivate the plugin
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>Reactivating plugin...</p></div>';
        flush();
        
        activate_plugin($current_plugin_file);
        
        echo '<div class="notice notice-success" style="margin-left: 0;"><p><strong>Update complete!</strong> WONS Firewall has been updated to version ' . esc_html($update_info->version) . '.</p></div>';
        
        echo '</div></div>';  // Close the progress card
        
        // Final success message and button
        echo '<div style="margin-top: 20px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=wons_firewall')) . '" class="button button-primary button-hero">Return to WONS Firewall</a>';
        echo '</div>';
        
        echo '</div>'; // Close wrap
        
        // Include admin footer
        require_once(ABSPATH . 'wp-admin/admin-footer.php');
        exit;
    }

    /**
     * Handle log management actions
     */
    public function handle_log_actions() {
        if (!isset($_GET['wons_action']) || !current_user_can('manage_options')) {
            return;
        }
        
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wons_log_action')) {
            wp_die('Security check failed.');
        }
        
        $action = $_GET['wons_action'];
        $redirect_url = admin_url('admin.php?page=wons_firewall');
        
        if ($action === 'clear_logs') {
            $this->clear_all_logs();
            wp_redirect(add_query_arg('logs_cleared', 'true', $redirect_url));
            exit;
        } elseif ($action === 'trim_logs') {
            $this->trim_all_logs();
            wp_redirect(add_query_arg('logs_trimmed', 'true', $redirect_url));
            exit;
        }
    }

    /**
     * Clear all log files
     */
    private function clear_all_logs() {
        $log_dir = WP_CONTENT_DIR . '/security/logs';
        if (!file_exists($log_dir)) {
            return;
        }
        
        $log_files = glob($log_dir . '/*.log');
        foreach ($log_files as $log_file) {
            if (is_file($log_file)) {
                unlink($log_file);
            }
        }
    }

    /**
     * Trim all log files to the maximum number of lines
     */
    private function trim_all_logs() {
        $log_dir = WP_CONTENT_DIR . '/security/logs';
        if (!file_exists($log_dir)) {
            return;
        }
        
        $log_files = glob($log_dir . '/*.log');
        foreach ($log_files as $log_file) {
            if (is_file($log_file)) {
                $this->trim_log_file($log_file);
            }
        }
    }

    /**
     * Trim a log file to the maximum number of lines
     */
    private function trim_log_file($log_file) {
        $max_lines = absint($this->settings['log_max_lines']);
        if ($max_lines <= 0) {
            $max_lines = 1000; // Default
        }
        
        $lines = file($log_file, FILE_IGNORE_NEW_LINES);
        if (count($lines) <= $max_lines) {
            return; // No need to trim
        }
        
        // Keep only the most recent lines
        $lines = array_slice($lines, -$max_lines);
        
        // Write back to file
        file_put_contents($log_file, implode("\n", $lines) . "\n");
    }

    /**
     * Clean old log files based on max_files setting
     */
    private function clean_old_log_files() {
        $log_dir = WP_CONTENT_DIR . '/security/logs';
        if (!file_exists($log_dir)) {
            return;
        }
        
        $max_files = absint($this->settings['log_max_files']);
        if ($max_files <= 0) {
            $max_files = 30; // Default
        }
        
        $log_files = glob($log_dir . '/*.log');
        $file_count = count($log_files);
        
        if ($file_count <= $max_files) {
            return; // No need to clean
        }
        
        // Sort files by modification time (oldest first)
        usort($log_files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Delete oldest files
        $files_to_delete = array_slice($log_files, 0, $file_count - $max_files);
        foreach ($files_to_delete as $file) {
            unlink($file);
        }
    }

    /**
     * Check for updates (for debugging)
     */
    private function check_for_updates_debug() {
        $response = wp_remote_get('https://wons.bt/WONS_Firewall/update-info.json', array(
            'timeout' => 15,
            'sslverify' => false
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        return json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * Download a file from a URL
     */
    private function download_file($url, $destination) {
        $response = wp_remote_get($url, array(
            'timeout'     => 300,
            'sslverify'   => false,
            'stream'      => true,
            'filename'    => $destination
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('download_failed', 'Failed to download the file');
        }
        
        return true;
    }

    /**
     * Find the plugin directory in the extracted files
     */
    private function find_plugin_directory($directory) {
        // Debug
        error_log('Searching for plugin directory in: ' . $directory);
        $directories = glob($directory . '/*', GLOB_ONLYDIR);
        
        // First look for a directory that contains a PHP file with the plugin header
        foreach ($directories as $dir) {
            error_log('Checking directory: ' . $dir);
            $php_files = glob($dir . '/*.php');
            
            foreach ($php_files as $file) {
                error_log('Checking file: ' . $file);
                // Check if this file contains the plugin header
                $content = file_get_contents($file);
                if (strpos($content, 'Plugin Name: WONS Firewall') !== false) {
                    error_log('Found plugin directory: ' . $dir);
                    return $dir;
                }
            }
        }
        
        // If no plugin header found, check if the ZIP contains the plugin directly (no subdirectory)
        $php_files = glob($directory . '/*.php');
        foreach ($php_files as $file) {
            $content = file_get_contents($file);
            if (strpos($content, 'Plugin Name: WONS Firewall') !== false) {
                error_log('Found plugin files in the root directory: ' . $directory);
                return $directory;
            }
        }
        
        // Still not found, just look for any PHP files
        if (!empty($php_files)) {
            error_log('Found PHP files in the root directory, using it: ' . $directory);
            return $directory;
        }
        
        // Last resort - look for any directory with PHP files
        foreach ($directories as $dir) {
            $php_files = glob($dir . '/*.php');
            if (!empty($php_files)) {
                error_log('Using directory with PHP files: ' . $dir);
                return $dir;
            }
        }
        
        // Nothing found
        error_log('No suitable plugin directory found in: ' . $directory);
        return false;
    }

    /**
     * Recursively copy a directory
     */
    private function recursively_copy_directory($source, $destination) {
        // Create destination directory if it doesn't exist
        wp_mkdir_p($destination);
        
        // Open the source directory
        $dir = opendir($source);
        
        // Loop through the files in source directory
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                $src = $source . '/' . $file;
                $dst = $destination . '/' . $file;
                
                if (is_dir($src)) {
                    // Recursive call for directories
                    $this->recursively_copy_directory($src, $dst);
                } else {
                    // Copy file
                    copy($src, $dst);
                }
            }
        }
        
        closedir($dir);
    }

    /**
     * Recursively delete a directory
     */
    private function recursively_delete_directory($directory) {
        if (!is_dir($directory)) {
            return;
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($directory);
    }

    /**
     * Check license validity with WONS server
     */
    private function check_license() {
        $licenseServerUrl = "https://wons.bt/WONS_Firewall/license/";
        $last_check_time = get_option('wons_firewall_license_last_check', 0);
        $current_time = time();
        $cache_duration = 24 * 60 * 60; // 24 hours in seconds
        if ($this->license_valid && 
            !empty($this->license_expiry) && 
            strtotime($this->license_expiry) > $current_time &&
            ($current_time - $last_check_time) < $cache_duration) {
            return;
        }

        $ch = curl_init($licenseServerUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        if(curl_errno($ch)) {
            // Failed to connect to license server
            return;
        }
        curl_close($ch);
        
        $responseJson = base64_decode($response);
        $responseData = json_decode($responseJson, true);
        

        $sharedSecret = $this->get_license_key();
        $payload = isset($responseData['payload']) ? $responseData['payload'] : "";
        $providedSignature = isset($responseData['signature']) ? $responseData['signature'] : "";
        $computedSignature = hash('sha256', $sharedSecret . $payload . $sharedSecret);
        if ($computedSignature === $providedSignature) {
            $licenseData = json_decode($payload, true);
            if (isset($licenseData['status']) && $licenseData['status'] === 'valid') {
                $this->license_valid = true;
                $this->license_expiry = isset($licenseData['valid_until']) ? $licenseData['valid_until'] : '';
                update_option('wons_firewall_license_valid', $this->license_valid);
                update_option('wons_firewall_license_expiry', $this->license_expiry);
                update_option('wons_firewall_license_last_check', $current_time);
                return;
            }
        }
        $this->license_valid = false;
        update_option('wons_firewall_license_valid', false);
        update_option('wons_firewall_license_expiry', 'N/A');   
        update_option('wons_firewall_license_last_check', $current_time);
        
        // Store license info in options for persistence
        
    }
    
    /**
     * Get instance of this class
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create security directory for logs
        if (!file_exists(WP_CONTENT_DIR . '/security/logs')) {
            wp_mkdir_p(WP_CONTENT_DIR . '/security/logs');
            file_put_contents(WP_CONTENT_DIR . '/security/logs/.htaccess', 
                "Order Deny,Allow\nDeny from all");
        }
        
        // Migrate settings from old option name if it exists
        $old_settings = get_option('wons-firewall-settings', false);
        if ($old_settings !== false) {
            update_option('wons_firewall_settings', $old_settings);
            delete_option('wons-firewall-settings'); // Remove old option
        }
        // Store default settings if they don't exist
        else if (get_option('wons_firewall_settings') === false) {
            update_option('wons_firewall_settings', $this->default_settings);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Cleanup tasks if needed
    }

    /**
     * Set up automatic updates from private server
     */
    private function setup_plugin_updates() {
        // Only proceed if license is valid
        if (!$this->license_valid) {
            return;
        }

        // Include update checker library if not already included
        if (!class_exists('WONS_Plugin_Updater')) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-wons-plugin-updater.php';
        }

        // Set up the updater
        new WONS_Plugin_Updater(
            __FILE__,
            'https://wons.bt/WONS_Firewall/update-info.json',
            array(
                'version'     => self::VERSION,
                'license_key' => $this->get_license_key(),
                'item_name'   => 'WONS_Firewall'
            )
        );
    }

    /**
     * Get the license key
     */
    private function get_license_key() {
        return 'WONS_AMC_2024_to_2025';
    }
        
    
    /**
     * Initialize security measures
     */
    private function initialize_security() {
        // Skip security checks for whitelisted IPs
        if ($this->is_ip_whitelisted($this->visitor_ip)) {
            return;
        }
        
        // Check if firewall is enabled
        if (!$this->settings['enabled']) {
            return;
        }
        
        // Check if current IP is banned
        $this->check_if_banned();
        
        // Check for suspicious user agents
        if ($this->settings['block_bot_user_agents']) {
            $this->check_user_agent();
        }
        
        // Add protection for admin pages
        if ($this->settings['admin_protection']) {
            add_action('admin_init', array($this, 'protect_admin_pages'));
        }
        
        // Add protection for plugin installation
        if ($this->settings['block_plugin_install']) {
            add_action('admin_init', array($this, 'protect_plugin_installation'));
        }
        
        // Clean expired bans periodically (5% chance to run on each request)
        if (mt_rand(1, 100) <= 5) {
            $this->clean_expired_bans();
            $this->clean_expired_login_attempts();
        }
    }
    
    /**
     * Load settings from WordPress options
     */
    private function load_settings() {
        $saved_settings = get_option('wons_firewall_settings', array());
        $this->settings = wp_parse_args($saved_settings, $this->default_settings);
        
        // Update ban duration from settings
        $this->ban_duration = absint($this->settings['ban_duration']);
    }
    
    /**
     * Check if the current IP is banned
     */
    private function check_if_banned() {
        if (!$this->settings['enable_ip_banning']) {
            return;
        }
        
        $ban_list = get_transient('wons_firewall_ban_list');
        if (!is_array($ban_list)) {
            $ban_list = array();
        }
        
        if (isset($ban_list[$this->visitor_ip]) && $ban_list[$this->visitor_ip] > time()) {
            // Calculate remaining ban time
            $remaining = $ban_list[$this->visitor_ip] - time();
            $hours = floor($remaining / 3600);
            $minutes = floor(($remaining % 3600) / 60);
            
            // Log blocked access attempt
            if ($this->settings['log_attacks']) {
                $this->log_attack('blocked_banned_ip', array(
                    'ip' => $this->visitor_ip,
                    'user_agent' => $this->user_agent,
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'remaining_ban' => $hours . 'h ' . $minutes . 'm'
                ));
            }
            
            // Block access
            $this->block_access(
                'Security Notice',
                'Your IP address has been temporarily blocked due to suspicious activity.',
                'Remaining block time: ' . $hours . ' hours and ' . $minutes . ' minutes.',
                403
            );
        }
    }
    
    /**
     * Check user agent against blocked list
     */
    private function check_user_agent() {
        // If user agent is empty, or no bot user agents defined, skip check
        if (empty($this->user_agent) || empty($this->settings['bot_user_agents'])) {
            return;
        }
        
        foreach ($this->settings['bot_user_agents'] as $agent) {
            // Skip empty agent strings to prevent false positives
            if (empty($agent)) {
                continue;
            }
            
            if (stripos($this->user_agent, $agent) !== false) {
                // Ban the IP if enabled
                if ($this->settings['enable_ip_banning']) {
                    $this->ban_ip($this->visitor_ip);
                }
                
                // Log blocked bot
                if ($this->settings['log_attacks']) {
                    $this->log_attack('blocked_bot', array(
                        'ip' => $this->visitor_ip,
                        'user_agent' => $this->user_agent,
                        'matched_pattern' => $agent,
                        'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
                    ));
                }
                
                // Block access
                $this->block_access(
                    'Access Denied',
                    'Automated requests are not allowed.',
                    'This server does not allow automated tools to access this website.',
                    403
                );
            }
        }
    }
    
    /**
     * Protect admin pages from unauthorized access
     */
    public function protect_admin_pages() {
        // Skip this for AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        // Allow if user is authorized
        if (current_user_can('manage_options')) {
            return;
        }
        
        // Block access from bots
        if (empty($this->user_agent) || $this->is_bot_user_agent()) {
            if ($this->settings['enable_ip_banning']) {
                $this->ban_ip($this->visitor_ip);
            }
            
            if ($this->settings['log_attacks']) {
                $this->log_attack('unauthorized_admin_access', array(
                    'ip' => $this->visitor_ip,
                    'user_agent' => $this->user_agent,
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
                ));
            }
            
            $this->block_access(
                'Access Denied', 
                'You are not authorized to access this area.',
                'This action has been logged.',
                403
            );
        }
    }
    
    /**
     * Protect plugin installation pages
     */
    public function protect_plugin_installation() {
        global $pagenow;
        
        $plugin_pages = array(
            'plugin-install.php',
            'plugins.php', 
            'update.php'
        );
        
        if (in_array($pagenow, $plugin_pages) && isset($_GET['action']) && $_GET['action'] == 'upload-plugin') {
            // Check if IP is allowed to install plugins
            $allowed = false;
            
            // Allow if IP is in the allowed list
            if (!empty($this->settings['allowed_plugin_install_ips'])) {
                foreach ($this->settings['allowed_plugin_install_ips'] as $allowed_ip) {
                    if ($this->match_ip($this->visitor_ip, $allowed_ip)) {
                        $allowed = true;
                        break;
                    }
                }
            }
            
            // Block if not allowed
            if (!$allowed) {
                if ($this->settings['log_attacks']) {
                    $this->log_attack('unauthorized_plugin_install', array(
                        'ip' => $this->visitor_ip,
                        'user_agent' => $this->user_agent,
                        'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
                    ));
                }
                
                wp_die(
                    'Plugin installation is restricted to authorized IP addresses only. ' .
                    'This event has been logged.',
                    'Access Denied',
                    array('response' => 403, 'back_link' => true)
                );
            }
        }
    }
    
    /**
     * Track failed login attempts
     */
    public function track_failed_login($username) {
        if (!$this->settings['login_protection']) {
            return;
        }
        
        $login_attempts = get_transient('wons_firewall_login_attempts');
        if (!is_array($login_attempts)) {
            $login_attempts = array();
        }
        
        $time = time();
        
        if (!isset($login_attempts[$this->visitor_ip])) {
            $login_attempts[$this->visitor_ip] = array(
                'count' => 1,
                'last_attempt' => $time,
                'lockout_until' => 0
            );
        } else {
            // Increment attempt count
            $login_attempts[$this->visitor_ip]['count']++;
            $login_attempts[$this->visitor_ip]['last_attempt'] = $time;
            
            // Check if we should lock out this IP
            if ($login_attempts[$this->visitor_ip]['count'] >= $this->settings['max_login_attempts']) {
                $login_attempts[$this->visitor_ip]['lockout_until'] = $time + $this->settings['login_lockout_duration'];
                
                // Log lockout
                if ($this->settings['log_attacks']) {
                    $this->log_attack('login_lockout', array(
                        'ip' => $this->visitor_ip,
                        'user_agent' => $this->user_agent,
                        'username' => $username,
                        'attempts' => $login_attempts[$this->visitor_ip]['count']
                    ));
                }
                
                // Ban IP if it's a severe brute force attempt (3x the max attempts)
                if ($login_attempts[$this->visitor_ip]['count'] >= ($this->settings['max_login_attempts'] * 3)) {
                    if ($this->settings['enable_ip_banning']) {
                        $this->ban_ip($this->visitor_ip);
                    }
                }
            }
        }
        
        // Save updated login attempts
        set_transient('wons_firewall_login_attempts', $login_attempts, $this->settings['login_lockout_duration'] + 86400); // 24 hours buffer
    }
    
    /**
     * Check login attempts before authentication
     */
    public function check_login_attempts($user, $username, $password) {

        
        if (!$this->settings['login_protection']) {
            return $user;
        }
        
        // Don't block XML-RPC, it has its own authentication
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return $user;
        }
        
        $login_attempts = get_transient('wons_firewall_login_attempts');
        if (!is_array($login_attempts)) {
            return $user;
        }

        if ($this->is_ip_whitelisted($this->visitor_ip)) {
            return $user;
        }
        
        // If IP is locked out, block login
        if (isset($login_attempts[$this->visitor_ip]) && 
            $login_attempts[$this->visitor_ip]['lockout_until'] > time()) {
            
            // Calculate remaining lockout time
            $remaining = $login_attempts[$this->visitor_ip]['lockout_until'] - time();
            $minutes = ceil($remaining / 60);
            
            return new WP_Error(
                'too_many_attempts',
                sprintf(
                    'Too many failed login attempts. Please try again in %d %s.',
                    $minutes,
                    $minutes == 1 ? 'minute' : 'minutes'
                )
            );
        }
        
        return $user;
    }
    
    /**
     * Ban an IP address
     */
    private function ban_ip($ip) {
        $ban_list = get_transient('wons_firewall_ban_list');
        if (!is_array($ban_list)) {
            $ban_list = array();
        }
        
        $ban_list[$ip] = time() + $this->ban_duration;
        
        // Save the updated ban list
        set_transient('wons_firewall_ban_list', $ban_list, $this->ban_duration + 3600); // Add an hour buffer
    }
    
    /**
     * Clean expired bans
     */
    private function clean_expired_bans() {
        $ban_list = get_transient('wons_firewall_ban_list');
        if (!is_array($ban_list)) {
            return;
        }
        
        $current_time = time();
        $updated = false;
        
        foreach ($ban_list as $ip => $expire_time) {
            if ($expire_time <= $current_time) {
                unset($ban_list[$ip]);
                $updated = true;
            }
        }
        
        if ($updated) {
            set_transient('wons_firewall_ban_list', $ban_list, $this->ban_duration + 3600);
        }
    }
    
    /**
     * Clean expired login attempts
     */
    private function clean_expired_login_attempts() {
        $login_attempts = get_transient('wons_firewall_login_attempts');
        if (!is_array($login_attempts)) {
            return;
        }
        
        $current_time = time();
        $updated = false;
        
        foreach ($login_attempts as $ip => $data) {
            // Remove entries older than 24 hours with no lockout
            if ($data['lockout_until'] == 0 && ($current_time - $data['last_attempt']) > 86400) {
                unset($login_attempts[$ip]);
                $updated = true;
            }
            // Remove expired lockouts
            else if ($data['lockout_until'] > 0 && $data['lockout_until'] < $current_time) {
                unset($login_attempts[$ip]);
                $updated = true;
            }
        }
        
        if ($updated) {
            set_transient('wons_firewall_login_attempts', $login_attempts, $this->settings['login_lockout_duration'] + 86400);
        }
    }
    
    /**
     * Check if IP is whitelisted
     */
    private function is_ip_whitelisted($ip) {
        if (empty($this->settings['whitelist_ips'])) {
            return false;
        }
        
        foreach ($this->settings['whitelist_ips'] as $whitelisted_ip) {
            if ($this->match_ip($ip, $whitelisted_ip)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Match IP against pattern (including CIDR support)
     */
    private function match_ip($ip, $pattern) {
        // Exact match
        if ($ip === $pattern) {
            return true;
        }
        
        // CIDR notation
        if (strpos($pattern, '/') !== false) {
            list($subnet, $bits) = explode('/', $pattern);
            $ip_decimal = ip2long($ip);
            $subnet_decimal = ip2long($subnet);
            $mask_decimal = ~((1 << (32 - $bits)) - 1);
            return ($ip_decimal & $mask_decimal) === ($subnet_decimal & $mask_decimal);
        }
        
        // Wildcard notation
        if (strpos($pattern, '*') !== false) {
            $pattern = str_replace('*', '.*', $pattern);
            return (bool)preg_match('/^' . $pattern . '$/', $ip);
        }
        
        return false;
    }
    
    /**
     * Block access and display message
     */
    private function block_access($title, $message, $details = '', $status = 403) {
        $args = array(
            'response' => $status,
            'back_link' => false,
        );
        
        $message_html = '<h1>' . esc_html($message) . '</h1>';
        if (!empty($details)) {
            $message_html .= '<p>' . esc_html($details) . '</p>';
        }
        
        wp_die($message_html, esc_html($title), $args);
    }
    
    /**
     * Log security incidents
     */
    private function log_attack($type, $data) {
        $log_dir = WP_CONTENT_DIR . '/security/logs';
        
        // Create logs directory if it doesn't exist
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
            file_put_contents($log_dir . '/.htaccess', "Order Deny,Allow\nDeny from all");
        }
        
        $log_file = $log_dir . '/' . date('Y-m-d') . '.log';
        
        $log_entry = date('Y-m-d H:i:s') . ' | ' . $type . ' | ' . json_encode($data) . "\n";
        
        // Check if we need to trim the log file before adding new entry
        if (file_exists($log_file)) {
            $max_lines = absint($this->settings['log_max_lines']);
            if ($max_lines > 0) {
                $lines = file($log_file, FILE_IGNORE_NEW_LINES);
                $line_count = count($lines);
                
                // If we're approaching the limit, trim the file
                if ($line_count >= $max_lines) {
                    // Keep only the most recent lines (leaving room for the new entry)
                    $lines = array_slice($lines, -($max_lines - 1));
                    file_put_contents($log_file, implode("\n", $lines) . "\n");
                }
            }
        }
        
        // Append the new log entry
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
    
    /**
     * Check if current user agent is a bot
     */
    private function is_bot_user_agent() {
        if (empty($this->user_agent)) {
            return true;
        }
        
        foreach ($this->settings['bot_user_agents'] as $agent) {
            // Skip empty agent strings to prevent false positives
            if (empty($agent)) {
                continue;
            }
            
            if (stripos($this->user_agent, $agent) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get real IP address of visitor
     */
    private function get_real_ip() {
        // Check for proxy headers
        $headers = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        );
        
        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                // Validate IP format
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        // Add a top-level menu item
        add_menu_page(
            'WONS Firewall', // Page title
            'WONS Firewall', // Menu title
            'manage_options', // Capability
            'wons_firewall', // Menu slug
            array($this, 'render_admin_page'), // Callback function
            'dashicons-shield', // Icon URL or dashicon class
            30 // Position
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'wons_firewall_settings',
            'wons_firewall_settings',
            array($this, 'validate_settings')
        );
    }
    
    /**
     * Validate and sanitize settings
     */
    public function validate_settings($input) {
        $output = array();
        
        // Boolean settings
        $boolean_settings = array(
            'enabled',
            'block_bot_user_agents',
            'enable_ip_banning',
            'login_protection',
            'admin_protection',
            'block_plugin_install',
            'log_attacks'
        );
        
        foreach ($boolean_settings as $setting) {
            $output[$setting] = isset($input[$setting]) ? 1 : 0;
        }
        
        // Numeric settings
        $output['ban_duration'] = absint($input['ban_duration']);
        if ($output['ban_duration'] < 300) {
            $output['ban_duration'] = 300; // Minimum 5 minutes
        }
        
        $output['max_login_attempts'] = absint($input['max_login_attempts']);
        if ($output['max_login_attempts'] < 1) {
            $output['max_login_attempts'] = 1;
        } elseif ($output['max_login_attempts'] > 20) {
            $output['max_login_attempts'] = 20;
        }
        
        $output['login_lockout_duration'] = absint($input['login_lockout_duration']);
        if ($output['login_lockout_duration'] < 60) {
            $output['login_lockout_duration'] = 60; // Minimum 1 minute
        }
        
        $output['log_max_files'] = absint($input['log_max_files']);
        if ($output['log_max_files'] < 1) {
            $output['log_max_files'] = 1;
        } elseif ($output['log_max_files'] > 100) {
            $output['log_max_files'] = 100;
        }
        
        $output['log_max_lines'] = absint($input['log_max_lines']);
        if ($output['log_max_lines'] < 100) {
            $output['log_max_lines'] = 100;
        }
        
        // Array settings
        $array_settings = array(
            'bot_user_agents',
            'whitelist_ips',
            'allowed_plugin_install_ips'
        );
        
        foreach ($array_settings as $setting) {
            $output[$setting] = array();
            
            if (isset($input[$setting]) && is_array($input[$setting])) {
                foreach ($input[$setting] as $value) {
                    if (!empty($value)) {
                        // Split by newlines and add each line as a separate item
                        $lines = explode("\n", $value);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (!empty($line)) {
                                $output[$setting][] = sanitize_text_field($line);
                            }
                        }
                    }
                }
            }
        }
        
        return $output;
    }
    
    /**
     * Render admin settings page
     */
    public function render_admin_page() {
        if (!$this->license_valid) {
            ?>
            <div class="wrap">
                <h1>WONS Firewall</h1>
                <div class="notice notice-warning">
                    <p>
                        <img src="https://wons.bt/public/logo-color.png" alt="WONS Logo" style="max-height: 50px; vertical-align: middle; margin-right: 10px;">
                        <strong>This Plugin is currently not active. Your site is not protected. Contact <a href="https://wons.bt">WONS</a> if you want to use WONS Firewall Plugin</strong>
                        
                    </p>
                </div>
            </div>
            <?php
            // return;
        }

        

        $update_info = $this->check_for_updates_debug();
        $update_available = $update_info && version_compare(self::VERSION, $update_info->version, '<');
    
        // Get ban list count
        $ban_list = get_transient('wons_firewall_ban_list');
        if (!is_array($ban_list)) {
            $ban_list = array();
        }
        $banned_count = count($ban_list);
        
        // Get login attempts
        $login_attempts = get_transient('wons_firewall_login_attempts');
        if (!is_array($login_attempts)) {
            $login_attempts = array();
        }
        $lockout_count = 0;
        foreach ($login_attempts as $ip => $data) {
            if (isset($data['lockout_until']) && $data['lockout_until'] > time()) {
                $lockout_count++;
            }
        }
        echo '<div class="wrap">';
        if ($this->license_valid) {
            ?>
                <img src="https://wons.bt/public/logo-color.png" alt="WONS Logo" style="max-height: 50px; vertical-align: middle; margin-right: 10px;">
                <h1>WONS Firewall</h1>
                
                <?php if ($update_available): ?>
                <div class="notice notice-warning">
                    <h3>Update Available</h3>
                    <p>Version <?php echo esc_html($update_info->version); ?> is now available. You are currently using version <?php echo esc_html(self::VERSION); ?>.</p>
                    
                    <?php
                    // Create update URL with nonce
                    $update_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'page' => 'wons_firewall',
                                'wons_action' => 'update_plugin'
                            ),
                            admin_url('admin.php')
                        ),
                        'wons_update_plugin'
                    );
                    ?>
                    
                    <p><a href="<?php echo esc_url($update_url); ?>" class="button button-primary">Install Update Automatically</a></p>
                    
                    <?php if (isset($update_info->sections->changelog)): ?>
                    <div class="update-changelog">
                        <h4>What's New in Version <?php echo esc_html($update_info->version); ?></h4>
                        <?php echo wp_kses_post($update_info->sections->changelog); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true'): ?>
                <div class="notice notice-success">
                    <p><strong>Update successful!</strong> WONS Firewall has been updated to the latest version.</p>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['logs_cleared']) && $_GET['logs_cleared'] === 'true'): ?>
                <div class="notice notice-success">
                    <p><strong>Success!</strong> All log files have been cleared.</p>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['logs_trimmed']) && $_GET['logs_trimmed'] === 'true'): ?>
                <div class="notice notice-success">
                    <p><strong>Success!</strong> All log files have been trimmed to the maximum number of lines.</p>
                </div>
                <?php endif; ?>

                
                <div class="notice notice-info">
                    <p>
                        <strong>License Status:</strong> Valid until <?php echo esc_html($this->license_expiry); ?>
                        | <strong>Security Status:</strong> 
                        <?php echo $this->settings['enabled'] ? '<span style="color: green;">Active</span>' : '<span style="color: red;">Inactive</span>'; ?>
                        | <strong>Currently banned IPs:</strong> <?php echo $banned_count; ?>
                        | <strong>Active lockouts:</strong> <?php echo $lockout_count; ?>
                    </p>
                </div>
                
                <form method="post" action="options.php">
                    <?php settings_fields('wons_firewall_settings'); ?>
                    
                    <div class="metabox-holder">
                        <div class="postbox">
                            <h3 class="hndle"><span>General Settings</span></h3>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[enabled]">Enable Security Firewall</label>
                                        </th>
                                        <td>
                                            <input type="checkbox" id="wons_firewall_settings[enabled]" 
                                                name="wons_firewall_settings[enabled]" 
                                                value="1" <?php checked(1, $this->settings['enabled']); ?> />
                                            <p class="description">Enable or disable all security features</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[log_attacks]">Log Security Events</label>
                                        </th>
                                        <td>
                                            <input type="checkbox" id="wons_firewall_settings[log_attacks]" 
                                                name="wons_firewall_settings[log_attacks]" 
                                                value="1" <?php checked(1, $this->settings['log_attacks']); ?> />
                                            <p class="description">Log all security events to files in wp-content/security/logs/</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="postbox">
                            <h3 class="hndle"><span>Bot Protection</span></h3>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[block_bot_user_agents]">Block Bot User Agents</label>
                                        </th>
                                        <td>
                                            <input type="checkbox" id="wons_firewall_settings[block_bot_user_agents]" 
                                                name="wons_firewall_settings[block_bot_user_agents]" 
                                                value="1" <?php checked(1, $this->settings['block_bot_user_agents']); ?> />
                                            <p class="description">Block requests from known bot User-Agents (python-requests, curl, etc.)</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings_bot_user_agents">Bot User-Agents to Block</label>
                                        </th>
                                        <td>
                                            <textarea id="wons_firewall_settings_bot_user_agents" 
                                                    name="wons_firewall_settings[bot_user_agents][]" 
                                                    rows="5" cols="50" class="large-text code"><?php 
                                                if (!empty($this->settings['bot_user_agents'])) {
                                                    echo esc_textarea(implode("\n", $this->settings['bot_user_agents']));
                                                }
                                            ?></textarea>
                                            <p class="description">Enter one User-Agent string per line. Any request containing these strings will be blocked.</p>
                                            <input type="hidden" name="wons_firewall_settings[bot_user_agents][]" value="">
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="postbox">
                            <h3 class="hndle"><span>IP Management</span></h3>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[enable_ip_banning]">Enable IP Banning</label>
                                        </th>
                                        <td>
                                            <input type="checkbox" id="wons_firewall_settings[enable_ip_banning]" 
                                                name="wons_firewall_settings[enable_ip_banning]" 
                                                value="1" <?php checked(1, $this->settings['enable_ip_banning']); ?> />
                                            <p class="description">Automatically ban IP addresses that show suspicious behavior</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[ban_duration]">Ban Duration (seconds)</label>
                                        </th>
                                        <td>
                                            <input type="number" id="wons_firewall_settings[ban_duration]" 
                                                name="wons_firewall_settings[ban_duration]" 
                                                value="<?php echo esc_attr($this->settings['ban_duration']); ?>" 
                                                min="300" step="300" />
                                            <p class="description">How long to ban IP addresses (in seconds). Default: 86400 (24 hours)</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings_whitelist_ips">Whitelist IPs</label>
                                        </th>
                                        <td>
                                            <textarea id="wons_firewall_settings_whitelist_ips" 
                                                    name="wons_firewall_settings[whitelist_ips][]" 
                                                    rows="3" cols="50" class="large-text code"><?php 
                                                if (!empty($this->settings['whitelist_ips'])) {
                                                    echo esc_textarea(implode("\n", $this->settings['whitelist_ips']));
                                                }
                                            ?></textarea>
                                            <p class="description">
                                                Enter one IP address per line. These IPs will bypass all security checks.<br>
                                                You can use:<br>
                                                - Single IPs: 192.168.1.1<br>
                                                - CIDR notation: 192.168.1.0/24<br>
                                                - Wildcards: 192.168.1.*
                                            </p>
                                            <input type="hidden" name="wons_firewall_settings[whitelist_ips][]" value="">
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="postbox">
                            <h3 class="hndle"><span>Login Protection</span></h3>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[login_protection]">Enable Login Protection</label>
                                        </th>
                                        <td>
                                            <input type="checkbox" id="wons_firewall_settings[login_protection]" 
                                                name="wons_firewall_settings[login_protection]" 
                                                value="1" <?php checked(1, $this->settings['login_protection']); ?> />
                                            <p class="description">Limit login attempts to prevent brute force attacks</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[max_login_attempts]">Max Login Attempts</label>
                                        </th>
                                        <td>
                                            <input type="number" id="wons_firewall_settings[max_login_attempts]" 
                                                name="wons_firewall_settings[max_login_attempts]" 
                                                value="<?php echo esc_attr($this->settings['max_login_attempts']); ?>" 
                                                min="1" max="20" />
                                            <p class="description">Number of failed login attempts before lockout</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[login_lockout_duration]">Lockout Duration (seconds)</label>
                                        </th>
                                        <td>
                                            <input type="number" id="wons_firewall_settings[login_lockout_duration]" 
                                                name="wons_firewall_settings[login_lockout_duration]" 
                                                value="<?php echo esc_attr($this->settings['login_lockout_duration']); ?>" 
                                                min="60" step="60" />
                                            <p class="description">How long to lock out IP addresses after too many failed login attempts (in seconds)</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="postbox">
                            <h3 class="hndle"><span>Admin & Plugin Protection</span></h3>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[admin_protection]">Enable Admin Protection</label>
                                        </th>
                                        <td>
                                            <input type="checkbox" id="wons_firewall_settings[admin_protection]" 
                                                name="wons_firewall_settings[admin_protection]" 
                                                value="1" <?php checked(1, $this->settings['admin_protection']); ?> />
                                            <p class="description">Block suspicious access to admin pages</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[block_plugin_install]">Restrict Plugin Installation</label>
                                        </th>
                                        <td>
                                            <input type="checkbox" id="wons_firewall_settings[block_plugin_install]" 
                                                name="wons_firewall_settings[block_plugin_install]" 
                                                value="1" <?php checked(1, $this->settings['block_plugin_install']); ?> />
                                            <p class="description">Only allow plugin installation from specific IP addresses</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings_allowed_plugin_install_ips">Allowed Plugin Install IPs</label>
                                        </th>
                                        <td>
                                            <textarea id="wons_firewall_settings_allowed_plugin_install_ips" 
                                                    name="wons_firewall_settings[allowed_plugin_install_ips][]" 
                                                    rows="3" cols="50" class="large-text code"><?php 
                                                if (!empty($this->settings['allowed_plugin_install_ips'])) {
                                                    echo esc_textarea(implode("\n", $this->settings['allowed_plugin_install_ips']));
                                                }
                                            ?></textarea>
                                            <p class="description">
                                                Enter one IP address per line. Only these IPs will be allowed to install plugins.<br>
                                                If left empty, no one can install plugins when protection is enabled.
                                            </p>
                                            <input type="hidden" name="wons_firewall_settings[allowed_plugin_install_ips][]" value="">
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="postbox">
                            <h3 class="hndle"><span>Log Management</span></h3>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[log_max_files]">Maximum Log Files</label>
                                        </th>
                                        <td>
                                            <input type="number" id="wons_firewall_settings[log_max_files]" 
                                                name="wons_firewall_settings[log_max_files]" 
                                                value="<?php echo esc_attr($this->settings['log_max_files']); ?>" 
                                                min="1" max="100" />
                                            <p class="description">Maximum number of log files to keep. Oldest files will be deleted when this limit is reached.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="wons_firewall_settings[log_max_lines]">Maximum Lines Per Log</label>
                                        </th>
                                        <td>
                                            <input type="number" id="wons_firewall_settings[log_max_lines]" 
                                                name="wons_firewall_settings[log_max_lines]" 
                                                value="<?php echo esc_attr($this->settings['log_max_lines']); ?>" 
                                                min="100" step="100" />
                                            <p class="description">Maximum number of lines to keep in each log file. Oldest entries will be removed when this limit is reached.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label>Log Management Actions</label>
                                        </th>
                                        <td>
                                            <?php
                                            // Create action URLs with nonces
                                            $clear_logs_url = wp_nonce_url(
                                                add_query_arg(
                                                    array(
                                                        'page' => 'wons_firewall',
                                                        'wons_action' => 'clear_logs'
                                                    ),
                                                    admin_url('admin.php')
                                                ),
                                                'wons_log_action'
                                            );
                                            
                                            $trim_logs_url = wp_nonce_url(
                                                add_query_arg(
                                                    array(
                                                        'page' => 'wons_firewall',
                                                        'wons_action' => 'trim_logs'
                                                    ),
                                                    admin_url('admin.php')
                                                ),
                                                'wons_log_action'
                                            );
                                            ?>
                                            <a href="<?php echo esc_url($clear_logs_url); ?>" class="button" onclick="return confirm('Are you sure you want to delete all log files? This action cannot be undone.');">Clear All Logs</a>
                                            <a href="<?php echo esc_url($trim_logs_url); ?>" class="button">Trim Logs to Max Lines</a>
                                            <p class="description">Use these buttons to manage your log files. Clearing logs will delete all log files. Trimming logs will reduce each log file to the maximum number of lines specified above.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php submit_button('Save Settings'); ?>
                </form>
            <?php 
        } //Valid License
        ?>
            
            <div class="metabox-holder">
                <strong>Your public IP: </strong><?php echo esc_html($_SERVER['SERVER_ADDR']); ?>
                <div class="postbox">
                    <h3 class="hndle"><span>Security Logs</span></h3>
                    <div class="inside">
                        <?php
                        $log_dir = WP_CONTENT_DIR . '/security/logs';
                        if (file_exists($log_dir)) {
                            $log_files = glob($log_dir . '/*.log');
                            if (!empty($log_files)) {
                                echo '<p>Recent security logs:</p>';
                                echo '<ul>';
                                $log_files = array_slice(array_reverse($log_files), 0, 15);
                                foreach ($log_files as $log_file) {
                                    $filename = basename($log_file);
                                    $size = size_format(filesize($log_file));
                                    echo '<li>';
                                    echo esc_html($filename) . ' (' . esc_html($size) . ')';
                                    
                                    // Show most recent entries
                                    $entries = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                                    if (!empty($entries)) {
                                        $entries = array_slice($entries, -5);
                                        echo '<pre style="background:#f1f1f1;padding:10px;max-height:150px;overflow:auto;font-size:12px;">';
                                        foreach ($entries as $entry) {
                                            echo esc_html($entry) . "\n";
                                        }
                                        echo '</pre>';
                                    }
                                    
                                    echo '</li>';
                                }
                                echo '</ul>';
                            } else {
                                echo '<p>No security logs found.</p>';
                            }
                        } else {
                            echo '<p>Log directory does not exist yet. It will be created when security events occur.</p>';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="postbox">
                    <h3 class="hndle"><span>Current Bans</span></h3>
                    <div class="inside">
                        <?php
                        if (empty($ban_list)) {
                            echo '<p>No IP addresses are currently banned.</p>';
                        } else {
                            echo '<p>Currently banned IP addresses:</p>';
                            echo '<table class="widefat striped">';
                            echo '<thead><tr><th>IP Address</th><th>Ban Expires</th><th>Time Remaining</th></tr></thead>';
                            echo '<tbody>';
                            
                            $current_time = time();
                            foreach ($ban_list as $ip => $expire_time) {
                                $remaining = $expire_time - $current_time;
                                $hours = floor($remaining / 3600);
                                $minutes = floor(($remaining % 3600) / 60);
                                
                                echo '<tr>';
                                echo '<td>' . esc_html($ip) . '</td>';
                                echo '<td>' . esc_html(date('Y-m-d H:i:s', $expire_time)) . '</td>';
                                echo '<td>' . esc_html($hours . 'h ' . $minutes . 'm') . '</td>';
                                echo '</tr>';
                            }
                            
                            echo '</tbody></table>';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="postbox">
                    <h3 class="hndle"><span>Current Lockouts</span></h3>
                    <div class="inside">
                        <?php
                        $has_lockouts = false;
                        foreach ($login_attempts as $ip => $data) {
                            if (isset($data['lockout_until']) && $data['lockout_until'] > time()) {
                                $has_lockouts = true;
                                break;
                            }
                        }
                        
                        if (!$has_lockouts) {
                            echo '<p>No IP addresses are currently locked out.</p>';
                        } else {
                            echo '<p>Currently locked out IP addresses:</p>';
                            echo '<table class="widefat striped">';
                            echo '<thead><tr><th>IP Address</th><th>Attempts</th><th>Lockout Expires</th><th>Time Remaining</th></tr></thead>';
                            echo '<tbody>';
                            
                            $current_time = time();
                            foreach ($login_attempts as $ip => $data) {
                                if (isset($data['lockout_until']) && $data['lockout_until'] > $current_time) {
                                    $remaining = $data['lockout_until'] - $current_time;
                                    $minutes = ceil($remaining / 60);
                                    
                                    echo '<tr>';
                                    echo '<td>' . esc_html($ip) . '</td>';
                                    echo '<td>' . esc_html($data['count']) . '</td>';
                                    echo '<td>' . esc_html(date('Y-m-d H:i:s', $data['lockout_until'])) . '</td>';
                                    echo '<td>' . esc_html($minutes . ' minutes') . '</td>';
                                    echo '</tr>';
                                }
                            }
                            
                            echo '</tbody></table>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show license notice
     */
    public function show_license_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <img src="https://wons.bt/public/logo-color.png" alt="WONS Logo" style="max-height: 50px; vertical-align: middle; margin-right: 10px;">
                <strong>Contact WONS if you want to keep using WONS Firewall Plugin for protecting yourself from bots/attacks/hacks</strong>
            </p>
        </div>
        <?php
    }
}

// Initialize the plugin
function wons_firewall_init() {
    return Wons_Firewall::get_instance();
}

// Start the plugin
wons_firewall_init();
