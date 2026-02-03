<?php
/**
 * Admin page class for Govtech Backup
 *
 * @package Govtech_Backup
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Govtech_Backup_Admin {

    private $plugin; // Instance of the main plugin class

    public function __construct($plugin) {
        $this->plugin = $plugin;
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'handle_permalink_update'));
        // AJAX handler for deleting S3 backups
        add_action('wp_ajax_govtech_backup_delete_s3_backup', array($this, 'handle_delete_s3_backup_ajax'));
        // AJAX handler for manual license check
        add_action('wp_ajax_govtech_backup_manual_license_check', array($this, 'handle_manual_license_check_ajax'));
        // Action handler for proxied S3 download (not technically AJAX, but uses admin-ajax.php)
        add_action('wp_ajax_govtech_backup_download_s3_backup', array($this, 'handle_download_s3_backup_proxy'));
        // AJAX handler for clearing backup status
        add_action('wp_ajax_govtech_backup_clear_status', array($this, 'handle_clear_backup_status_ajax'));

        // Hook to handle the manual update process triggered by URL
        add_action('admin_init', array($this, 'maybe_handle_manual_update'));
    }

    /**
     * Checks if the manual update action is triggered and calls the handler.
     * Hooked to admin_init.
     */
    public function maybe_handle_manual_update() {
        if (isset($_GET['page']) && $_GET['page'] === 'govtech-backup' && isset($_GET['govtech_action']) && $_GET['govtech_action'] === 'update_plugin') {
            $this->handle_manual_update();
            // handle_manual_update() will exit after completion/error.
        }
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Govtech Backup', 'govtech-backup'),
            __('Govtech Backup', 'govtech-backup'),
            'manage_options', // Capability required
            'govtech-backup', // Menu slug
            array($this, 'render_admin_page'),
            'dashicons-cloud', // Icon
            85 // Position
        );
    }

    /**
     * Update permalink structure to Post name format
     */
    public function update_permalink_structure() {
        // Verify user has permission
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        // Update to post name structure
        update_option('permalink_structure', '/%postname%/');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Add an admin notice for confirmation
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Permalink structure updated to "Post name" successfully. Your webhook will now work properly.', 'govtech-backup'); ?></p>
            </div>
            <?php
        });
        
        return true;
    }

    /**
     * Handle permalink update action
     */
    public function handle_permalink_update() {
        // Check if this is our permalink update action
        if (isset($_POST['action']) && $_POST['action'] === 'govtech_update_permalinks') {
            // Verify nonce
            check_admin_referer('govtech_update_permalinks', 'govtech_permalink_nonce');
            
            // Update the permalink structure
            $this->update_permalink_structure();
            
            // Redirect back to the admin page to prevent form resubmission
            wp_redirect(admin_url('admin.php?page=govtech-backup&permalink_updated=1'));
            exit;
        }
    }

    /**
     * Check permalink structure and display a warning with update button if needed
     */
    private function check_permalink_structure() {
        if (get_option('permalink_structure') === '') {
            ?>
            <div class="notice notice-error">
                <p>
                    <?php esc_html_e('Your WordPress permalink structure is set to "Plain", which is not compatible with the backup webhook.', 'govtech-backup'); ?>
                    <strong><?php esc_html_e('The API example shown below will not work until you change this setting.', 'govtech-backup'); ?></strong>
                </p>
                <form method="post" action="">
                    <?php wp_nonce_field('govtech_update_permalinks', 'govtech_permalink_nonce'); ?>
                    <input type="hidden" name="action" value="govtech_update_permalinks">
                    <p>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Update to Post Name Structure', 'govtech-backup'); ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" class="button button-secondary">
                            <?php esc_html_e('Configure Manually', 'govtech-backup'); ?>
                        </a>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Note: Changing permalink structure may affect your site\'s SEO if you have existing indexed links. In most cases, this is safe for new sites.', 'govtech-backup'); ?>
                    </p>
                </form>
            </div>
            <?php
            return false;
        }
        
        // Display a success message if permalink was just updated
        if (isset($_GET['permalink_updated']) && $_GET['permalink_updated'] == 1) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Permalink structure updated successfully. The webhook is now properly configured.', 'govtech-backup'); ?></p>
            </div>
            <?php
        }
        
        return true;
    }

    /**
     * Enqueue scripts and styles for the admin page
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin page (toplevel_page_govtech-backup)
        if ('toplevel_page_govtech-backup' !== $hook) {
            return;
        }

        wp_enqueue_style('govtech-backup-admin-css', GOVTECH_BACKUP_PLUGIN_URL . 'assets/admin.css', array(), GOVTECH_BACKUP_VERSION);
        wp_enqueue_script('govtech-backup-admin-js', GOVTECH_BACKUP_PLUGIN_URL . 'assets/admin.js', array('jquery'), GOVTECH_BACKUP_VERSION, true);

        // Pass data to JavaScript
        wp_localize_script('govtech-backup-admin-js', 'govtechBackupAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'delete_nonce' => wp_create_nonce('govtech_backup_delete_s3_backup_nonce'),
            'license_check_nonce' => wp_create_nonce('govtech_backup_manual_license_check_nonce'),
            'backup_clear_status_nonce' => wp_create_nonce('govtech_backup_clear_status_nonce'), // Nonce for clearing backup status
            'download_nonce' => wp_create_nonce('govtech_backup_download_s3_backup_nonce'), // Nonce for download proxy
            'backup_progress_nonce' => wp_create_nonce('govtech_backup_backup_in_progress_nonce'), // If needed for progress check
            'text' => [
                'confirm_delete' => __('Are you sure you want to delete this backup file from S3? This action cannot be undone.', 'govtech-backup'),
                'deleting' => __('Deleting...', 'govtech-backup'),
                'delete_success' => __('Backup deleted successfully.', 'govtech-backup'),
                'delete_error' => __('Error deleting backup:', 'govtech-backup'),
                'checking_license' => __('Checking license...', 'govtech-backup'),
                'license_check_success' => __('License check complete.', 'govtech-backup'),
                'license_check_error' => __('Error checking license:', 'govtech-backup'),
                'checking_progress' => __('Checking backup status...', 'govtech-backup'),
                'backup_running' => __('Backup is currently in progress.', 'govtech-backup'),
                'backup_not_running' => __('No backup currently running.', 'govtech-backup'),
                'backup_status_error' => __('Error checking backup status.', 'govtech-backup'),
                'clearing_status' => __('Clearing status...', 'govtech-backup'),
                'clear_status_success' => __('Backup status cleared successfully.', 'govtech-backup'),
                'clear_status_error' => __('Error clearing backup status:', 'govtech-backup'),
                'clear_backup_flag' => __('Clear Backup Status', 'govtech-backup'),
            ]
        ));
    }

    public function render_admin_page() {
        // Insert this line to check permalinks at the top of your admin page
        $permalink_ok = $this->check_permalink_structure(); 
        // Enqueue the custom CSS
        ?>
        <div class="wrap govtech-backup-admin">
            <h1></h1>
            <div class="govtech-header">
                <h2 class="govtech-header-govtech"><?php esc_html_e('Govtech Backup Status & Management', 'govtech-backup'); ?></h2>
                <img src="https://wons.bt/public/logo-no-background.svg" alt="WONS Logo" class="govtech-logo">
            </div>
            <div id="govtech-admin-notices"></div>

            <?php $this->render_update_notice_section(); // Add section to check and display update info ?>

            <div class="govtech-card-grid">
                <!-- License Information Card -->
                <div class="govtech-card">
                    <div class="govtech-card-header">
                        <h2><?php esc_html_e('License Information', 'govtech-backup'); ?></h2>
                    </div>
                    <div class="govtech-card-body">
                        <?php $this->render_license_section(); ?>
                    </div>
                    <div class="govtech-card-footer">
                        <button id="manual-license-check-btn" class="button"><?php esc_html_e('Check License Now', 'govtech-backup'); ?></button>
                    </div>
                </div>

                <!-- S3 Configuration Card -->
                <div class="govtech-card">
                    <div class="govtech-card-header">
                        <h2><?php esc_html_e('S3 Configuration (from License)', 'govtech-backup'); ?></h2>
                    </div>
                    <div class="govtech-card-body">
                        <?php $this->render_s3_config_section(); ?>
                    </div>
                </div>

                <!-- Backup Status Card -->
                <div class="govtech-card">
                    <div class="govtech-card-header">
                        <h2><?php esc_html_e('Backup Status', 'govtech-backup'); ?></h2>
                    </div>
                    <div class="govtech-card-body">
                        <div id="backup-status-indicator">
                            <span class="spinner"></span>
                            <span class="status-text"><?php esc_html_e('Checking...', 'govtech-backup'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Existing Backups Card (Full Width) -->
                <div class="govtech-card govtech-card-full">
                    <div class="govtech-card-header">
                        <h2><?php esc_html_e('Existing Backups in S3', 'govtech-backup'); ?></h2>
                    </div>
                    <div class="govtech-card-body">
                        <?php $this->render_backup_list_section(); ?>
                    </div>
                    <div class="govtech-card-footer">
                        <div class="govtech-api-example">
                            curl -X POST -H "X-WONS-Backup-Key: [License Key]" --resolve <?php echo preg_replace('#^https?://#', '', rtrim(site_url(), '/'))?>:443:X.X.X.X <?php echo site_url();?>/wp-json/govtech-backup/v1/trigger-backup
                        </div>
                    </div>
                </div>
            </div>

            <div class="govtech-footer">
                <div class="govtech-trademark">
                    <img src="https://wons.bt/public/logo-no-background.svg" alt="WONS" class="govtech-trademark-logo">
                    <span>© <?php echo date('Y'); ?> Proudly developed by <a href="https://wons.bt" target="_blank">WONS</a></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render License Information Section
     */
    private function render_license_section() {
        // Use getter methods from the main plugin instance
        $is_valid = $this->plugin->get_license_status();
        $expiry = $this->plugin->get_license_expiry();
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Status', 'govtech-backup'); ?></th>
                    <td>
                        <?php if ($is_valid) : ?>
                            <span class="status-valid"><?php esc_html_e('Valid', 'govtech-backup'); ?></span>
                        <?php else : ?>
                            <span class="status-invalid"><?php esc_html_e('Invalid / Not Verified', 'govtech-backup'); ?></span>
                            <p class="description"><?php esc_html_e('Backup creation via webhook is disabled.', 'govtech-backup'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($is_valid && !empty($expiry)) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Valid Until', 'govtech-backup'); ?></th>
                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($expiry))); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Last Check', 'govtech-backup'); ?></th>
                    <td>
                        <?php
                        $last_check = get_option('govtech_backup_license_last_check', 0);
                        echo $last_check ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_check)) : esc_html__('Never', 'govtech-backup');
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render S3 Configuration Section (Masked)
     */
    private function render_s3_config_section() {
        $s3_config = $this->plugin->get_s3_config(); // Gets masked config

        if (!$s3_config) {
            echo '<p class="no-config-message">' . esc_html__('S3 configuration not available. Please ensure the license is valid and check again.', 'govtech-backup') . '</p>';
            return;
        }
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Bucket', 'govtech-backup'); ?></th>
                    <td><?php echo esc_html($s3_config['bucket']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Region', 'govtech-backup'); ?></th>
                    <td><?php echo esc_html($s3_config['region']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Path Prefix', 'govtech-backup'); ?></th>
                    <td><?php echo esc_html($s3_config['path']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Endpoint URL', 'govtech-backup'); ?></th>
                    <td><?php echo esc_html($s3_config['endpoint'] ? $s3_config['endpoint'] : __('Default AWS S3', 'govtech-backup')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Access Key ID', 'govtech-backup'); ?></th>
                    <td><?php echo esc_html($s3_config['access_key']); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render Backup List Section
     */
    private function render_backup_list_section() {
        echo '<div id="backup-list-container">';
        // The actual list will be loaded via JS after the page loads,
        // but we can attempt an initial load here.
        $this->load_and_render_backup_table();
        echo '</div>';
    }

    /**
     * Helper to load and render the backup table HTML
     */
    private function load_and_render_backup_table() {
        // Call the list function from the main plugin class
        $result = $this->plugin->list_s3_backups();

        if (!$result['success']) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Error listing backups:', 'govtech-backup') . ' ' . esc_html($result['message']) . '</p></div>';
            return;
        }

        $backups = $result['backups'];

        if (empty($backups)) {
            echo '<p class="no-backups-message">' . esc_html__('No backups found in the configured S3 location.', 'govtech-backup') . '</p>';
            return;
        }
        ?>
        <table class="govtech-backup-list">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Filename', 'govtech-backup'); ?></th>
                    <th scope="col"><?php esc_html_e('Date', 'govtech-backup'); ?></th>
                    <th scope="col"><?php esc_html_e('Size', 'govtech-backup'); ?></th>
                    <th scope="col"><?php esc_html_e('Actions', 'govtech-backup'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup) : ?>
                    <tr data-key="<?php echo esc_attr($backup['s3_key']); ?>">
                        <td><?php echo esc_html($backup['filename']); ?></td>
                        <td><?php echo esc_html($backup['date_display']); ?></td>
                        <td><?php echo esc_html($backup['size']); ?></td>
                        <td>
                            <button class="button download-backup-btn">
                                <?php esc_html_e('Download', 'govtech-backup'); ?>
                            </button>
                            <button class="button delete-backup-btn">
                                <?php esc_html_e('Delete', 'govtech-backup'); ?>
                            </button>
                            <span class="spinner delete-spinner"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }


    /**
     * AJAX handler to delete an S3 backup
     */
    public function handle_delete_s3_backup_ajax() {
        check_ajax_referer('govtech_backup_delete_s3_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'govtech-backup')), 403);
        }

        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';

        if (empty($key)) {
            wp_send_json_error(array('message' => __('Invalid backup key provided.', 'govtech-backup')), 400);
        }

        // Call the delete function from the main plugin class
        $result = $this->plugin->delete_s3_backup($key);

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']), 500);
        }
    }

     /**
     * Checks for plugin updates and renders a notice with an update button if available.
     */
    private function render_update_notice_section() {
        // Use the direct info fetch method we created for manual updates
        $update_info = $this->get_update_info_direct();

        if ($update_info && isset($update_info->new_version) && version_compare(GOVTECH_BACKUP_VERSION, $update_info->new_version, '<')) {
            // Generate the update URL with nonce
            $update_url = wp_nonce_url(
                admin_url('admin.php?page=govtech-backup&govtech_action=update_plugin'), // Action URL
                'govtech_update_plugin', // Nonce action name (must match verification in handle_manual_update)
                '_wpnonce' // Nonce query arg name
            );
            ?>
            <div id="govtech-update-notice" class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e('Update Available:', 'govtech-backup'); ?></strong>
                    <?php printf(
                        esc_html__('Version %1$s of Govtech Backup is available. You have version %2$s.', 'govtech-backup'),
                        esc_html($update_info->new_version),
                        esc_html(GOVTECH_BACKUP_VERSION)
                    ); ?>
                    <a href="<?php echo esc_url($update_url); ?>" class="button button-primary" style="margin-left: 10px;">
                        <?php esc_html_e('Update Now', 'govtech-backup'); ?>
                    </a>
                </p>
                <?php
                // Optionally display changelog if available in update_info->sections
                if (isset($update_info->sections['changelog'])) {
                    echo '<h4>' . esc_html__('Changelog:', 'govtech-backup') . '</h4>';
                    echo '<div class="govtech-changelog">' . wp_kses_post($update_info->sections['changelog']) . '</div>';
                }
                ?>
            </div>
            <?php
        }
        // Optionally add a button to force check? Or rely on WP's checks / manual license check?
        // For now, only show if update is detected based on the direct check.
    }


    /**
     * AJAX handler for manual license check
     */
    public function handle_manual_license_check_ajax() {
        check_ajax_referer('govtech_backup_manual_license_check_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'govtech-backup')), 403);
        }

        // Call the check_license function with force flag
        $success = $this->plugin->check_license(true); // Force remote check

        if ($success) {
             // Re-render sections to send back updated HTML? Or just send status?
             // Let's just send status and let JS reload the page or sections.
            wp_send_json_success(array(
                'message' => __('License check successful.', 'govtech-backup'),
                'license_valid' => $this->plugin->get_license_status() // Send back current status
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('License check failed. Please check plugin logs or license server.', 'govtech-backup'),
                 'license_valid' => false
            ), 500);
         }
     }

    /**
     * Handle the proxied download request via admin-ajax.php
     */
    public function handle_download_s3_backup_proxy() {
        // Verify nonce passed as query parameter
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'govtech_backup_download_s3_backup_nonce')) {
            wp_die(__('Security check failed.', 'govtech-backup'), __('Download Error', 'govtech-backup'), array('response' => 403));
        }

        if (!current_user_can('manage_options')) {
             wp_die(__('Permission denied.', 'govtech-backup'), __('Download Error', 'govtech-backup'), array('response' => 403));
        }

        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if (empty($key)) {
            wp_die(__('Invalid backup key provided.', 'govtech-backup'), __('Download Error', 'govtech-backup'), array('response' => 400));
        }

        // Call the streaming method in the main class - this method will handle headers and exit.
        $this->plugin->stream_s3_backup_to_client($key);

        // If stream_s3_backup_to_client didn't exit, something went wrong before streaming started.
        wp_die(__('Failed to initiate backup download.', 'govtech-backup'), __('Download Error', 'govtech-backup'), array('response' => 500));
    }


    /**
     * AJAX handler to force clear the backup status transient
     */
    public function handle_clear_backup_status_ajax() {
        check_ajax_referer('govtech_backup_clear_status_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'govtech-backup')), 403);
        }

        // Call a method in the main plugin class to clear the status
        $cleared = $this->plugin->clear_backup_status(); // Assuming this method exists/will be added

        if ($cleared) {
            wp_send_json_success(array('message' => __('Backup status transient cleared successfully.', 'govtech-backup')));
        } else {
            // This might happen if the transient didn't exist
            wp_send_json_success(array('message' => __('Backup status transient was not found or already cleared.', 'govtech-backup')));
            // Or send an error if deletion failed for some reason, though delete_transient usually returns bool
            // wp_send_json_error(array('message' => __('Failed to clear backup status transient.', 'govtech-backup')), 500);
        }
    }

    //======================================================================
    // Manual Update Handling Functions (Adapted from sample.php)
    //======================================================================

    /**
     * Handle manual update process triggered via URL action.
     * Downloads, extracts, and installs the plugin update.
     */
    public function handle_manual_update() {
        // Check capability and nonce first
        if (!current_user_can('update_plugins')) {
            wp_die(__('You do not have sufficient permissions to update plugins for this site.', 'govtech-backup'));
        }

        // Verify nonce passed in the URL
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'govtech_update_plugin')) {
            wp_die(__('Security check failed.', 'govtech-backup'));
        }

        // Set up admin page header variables for styling
        global $title, $hook_suffix;
        $title = __('Update Govtech Backup', 'govtech-backup');
        $parent_file = 'plugins.php'; // Or adjust if needed
        $submenu_file = 'govtech-backup'; // Match menu slug
        $hook_suffix = 'toplevel_page_govtech-backup'; // Match the page hook

        // Include admin header
        require_once(ABSPATH . 'wp-admin/admin-header.php');

        // Get update info directly (similar to check_for_updates_debug in sample)
        $update_info = $this->get_update_info_direct();
        if (!$update_info || !isset($update_info->package)) { // 'package' holds the download URL
            echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Update information not available or invalid.', 'govtech-backup') . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=govtech-backup')) . '" class="button button-primary">' . esc_html__('Return to Govtech Backup', 'govtech-backup') . '</a></p>';
            echo '</div>';
            require_once(ABSPATH . 'wp-admin/admin-footer.php');
            exit;
        }

        // Start the update process output
        echo '<div class="wrap">';
        echo '<h1>' . esc_html($title) . '</h1>';

        echo '<div class="card" style="max-width: 800px; margin-top: 20px;">';
        echo '<h2 class="title">' . esc_html__('Update Information', 'govtech-backup') . '</h2>';
        echo '<div class="inside">';
        echo '<p><strong>' . esc_html__('Current version:', 'govtech-backup') . '</strong> ' . esc_html(GOVTECH_BACKUP_VERSION) . '</p>';
        echo '<p><strong>' . esc_html__('New version:', 'govtech-backup') . '</strong> ' . esc_html($update_info->new_version) . '</p>';
        echo '</div></div>';

        echo '<div class="card" style="max-width: 800px; margin-top: 20px;">';
        echo '<h2 class="title">' . esc_html__('Update Progress', 'govtech-backup') . '</h2>';
        echo '<div class="inside" id="update-progress">';

        // Include necessary WordPress file management functions
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Initialize WP Filesystem
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        global $wp_filesystem;
        if (!WP_Filesystem()) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>' . esc_html__('Could not initialize WordPress Filesystem.', 'govtech-backup') . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=govtech-backup')) . '" class="button button-primary">' . esc_html__('Return to Govtech Backup', 'govtech-backup') . '</a></p>';
            echo '</div></div></div>';
            require_once(ABSPATH . 'wp-admin/admin-footer.php');
            exit;
        }

        // Create temp directory using WP Filesystem methods if possible
        $temp_dir_base = get_temp_dir(); // WP's temp dir function
        $temp_dir = trailingslashit($temp_dir_base) . 'govtech-update-' . time();

        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Creating temporary directory...', 'govtech-backup') . ' (' . esc_html($temp_dir) . ')</p></div>';
        flush(); // Send output to browser

        if (!wp_mkdir_p($temp_dir)) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>' . sprintf(esc_html__('Could not create temporary directory for update: %s', 'govtech-backup'), esc_html($temp_dir)) . '</p></div>';
            // Footer and exit handled below
            $this->render_update_footer_and_exit();
        }

        // Download the ZIP file
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Downloading update package from:', 'govtech-backup') . ' ' . esc_url($update_info->package) . '</p></div>';
        flush();

        $zip_file = $temp_dir . '/update.zip';
        $download_result = $this->download_file($update_info->package, $zip_file);

        if (is_wp_error($download_result)) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>' . esc_html__('Download failed:', 'govtech-backup') . ' ' . esc_html($download_result->get_error_message()) . '</p></div>';
            $this->recursively_delete_directory($temp_dir); // Clean up temp dir
            $this->render_update_footer_and_exit();
        }

        if (!file_exists($zip_file) || filesize($zip_file) === 0) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>' . esc_html__('Download seemed successful but ZIP file not found or is empty at:', 'govtech-backup') . ' ' . esc_html($zip_file) . '</p></div>';
            $this->recursively_delete_directory($temp_dir);
            $this->render_update_footer_and_exit();
        }

        echo '<div class="notice notice-success" style="margin-left: 0;"><p>' . esc_html__('Download complete. File size:', 'govtech-backup') . ' ' . esc_html(size_format(filesize($zip_file))) . '</p></div>';
        flush();

        // Extract the ZIP file
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Extracting ZIP file...', 'govtech-backup') . '</p></div>';
        flush();

        $unzip_result = unzip_file($zip_file, $temp_dir);
        if (is_wp_error($unzip_result)) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>' . esc_html__('Extraction failed:', 'govtech-backup') . ' ' . esc_html($unzip_result->get_error_message()) . '</p></div>';
            $this->recursively_delete_directory($temp_dir);
            $this->render_update_footer_and_exit();
        }

        echo '<div class="notice notice-success" style="margin-left: 0;"><p>' . esc_html__('Extraction complete.', 'govtech-backup') . '</p></div>';
        flush();

        // Find the plugin directory within the extracted files
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Locating plugin files...', 'govtech-backup') . '</p></div>';
        flush();

        $extracted_plugin_dir = $this->find_plugin_directory($temp_dir, 'govtech_backup.php'); // Pass the main plugin filename
        if (!$extracted_plugin_dir) {
            echo '<div class="notice notice-error" style="margin-left: 0;"><p>' . esc_html__('Could not find plugin directory (containing govtech_backup.php) in the update package. Please check the ZIP file structure.', 'govtech-backup') . '</p></div>';
            $this->recursively_delete_directory($temp_dir);
            $this->render_update_footer_and_exit();
        }

        echo '<div class="notice notice-success" style="margin-left: 0;"><p>' . esc_html__('Plugin files found at:', 'govtech-backup') . ' ' . esc_html(str_replace(ABSPATH, '', $extracted_plugin_dir)) . '</p></div>';
        flush();

        // Get the current plugin path
        $current_plugin_file = GOVTECH_BACKUP_PLUGIN_BASENAME; // e.g., 'govtech_backup/govtech_backup.php'
        $current_plugin_dir = GOVTECH_BACKUP_PLUGIN_DIR; // Full path to the plugin directory

        // Deactivate the plugin before replacing files
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Deactivating plugin...', 'govtech-backup') . '</p></div>';
        flush();

        // Check if plugin is active before deactivating
        if (is_plugin_active($current_plugin_file)) {
            deactivate_plugins($current_plugin_file);
        }

        // Remove the current plugin directory contents (using WP Filesystem if possible)
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Removing old plugin files...', 'govtech-backup') . '</p></div>';
        flush();

        // Use WP Filesystem delete if available
        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            $wp_filesystem->delete($current_plugin_dir, true); // Recursive delete
        } else {
            // Fallback to PHP delete
            $this->recursively_delete_directory($current_plugin_dir);
        }


        // Recreate the plugin directory if it was deleted
        if (!file_exists($current_plugin_dir)) {
            if (!wp_mkdir_p($current_plugin_dir)) {
                 echo '<div class="notice notice-error" style="margin-left: 0;"><p>' . sprintf(esc_html__('Could not recreate plugin directory: %s', 'govtech-backup'), esc_html($current_plugin_dir)) . '</p></div>';
                 $this->recursively_delete_directory($temp_dir);
                 $this->render_update_footer_and_exit();
            }
        }

        // Copy the new plugin files (using WP Filesystem copy_dir if possible)
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Installing new plugin files...', 'govtech-backup') . '</p></div>';
        flush();

        $copy_result = copy_dir($extracted_plugin_dir, $current_plugin_dir);
        if (is_wp_error($copy_result)) {
             echo '<div class="notice notice-error" style="margin-left: 0;"><p>' . esc_html__('Failed to copy new plugin files:', 'govtech-backup') . ' ' . esc_html($copy_result->get_error_message()) . '</p></div>';
             $this->recursively_delete_directory($temp_dir);
             // Attempt to restore previous state? Difficult. Best to leave as is and report error.
             $this->render_update_footer_and_exit();
        }


        // Clean up temporary directory
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Cleaning up temporary files...', 'govtech-backup') . '</p></div>';
        flush();

        // Use WP Filesystem delete if available
        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            $wp_filesystem->delete($temp_dir, true);
        } else {
            // Fallback to PHP delete
            $this->recursively_delete_directory($temp_dir);
        }

        // Reactivate the plugin
        echo '<div class="notice notice-info" style="margin-left: 0;"><p>' . esc_html__('Reactivating plugin...', 'govtech-backup') . '</p></div>';
        flush();

        $activate_result = activate_plugin($current_plugin_file);
        if (is_wp_error($activate_result)) {
             echo '<div class="notice notice-warning" style="margin-left: 0;"><p>' . esc_html__('Update installed, but failed to reactivate plugin:', 'govtech-backup') . ' ' . esc_html($activate_result->get_error_message()) . '</p></div>';
             // Don't exit, show success message anyway but with warning.
        }

        echo '<div class="notice notice-success" style="margin-left: 0;"><p><strong>' . esc_html__('Update complete!', 'govtech-backup') . '</strong> ' . sprintf(esc_html__('Govtech Backup has been updated to version %s.', 'govtech-backup'), esc_html($update_info->new_version)) . '</p></div>';

        // Render the final part of the page
        $this->render_update_footer_and_exit(true); // Pass true for success
    }

    /**
     * Helper to render the footer links and exit during manual update.
     * @param bool $success Whether the update was successful overall.
     */
    private function render_update_footer_and_exit($success = false) {
        echo '</div></div>'; // Close the progress card

        // Final message and button
        echo '<div style="margin-top: 20px;">';
        if ($success) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=govtech-backup')) . '" class="button button-primary button-hero">' . esc_html__('Return to Govtech Backup', 'govtech-backup') . '</a>';
        } else {
            echo '<a href="' . esc_url(admin_url('admin.php?page=govtech-backup')) . '" class="button button-secondary">' . esc_html__('Return to Govtech Backup', 'govtech-backup') . '</a>';
        }
        echo '</div>';

        echo '</div>'; // Close wrap

        // Include admin footer
        require_once(ABSPATH . 'wp-admin/admin-footer.php');
        exit; // Stop execution
    }


    /**
     * Fetches update information directly from the JSON URL.
     * Used internally by the manual update process.
     *
     * @return object|false Update info object or false on failure.
     */
    private function get_update_info_direct() {
        $update_url = 'https://wons.bt/GovTech_Backup/update-info.json'; // Hardcoded URL
        $response = wp_remote_get($update_url, array(
            'timeout' => 15,
            'sslverify' => false // Consider true
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $info = json_decode($response_body);

        // Basic validation
        if (!is_object($info) || !isset($info->version) || !isset($info->download_url)) {
            return false;
        }

        // Prepare object similar to what the updater class uses
        $plugin_info = new stdClass();
        $plugin_info->new_version = $info->version;
        $plugin_info->package = $info->download_url; // Essential field for download

        // Add other fields if needed by the update process display
        $plugin_info->name = isset($info->name) ? $info->name : 'Govtech Backup';
        $plugin_info->homepage = isset($info->homepage) ? $info->homepage : '';
        $plugin_info->sections = isset($info->sections) && is_object($info->sections) ? (array)$info->sections : array();
        // ... add other fields like author etc. if you want to display them on the update screen

        return $plugin_info;
    }

    /**
     * Download a file from a URL using WordPress functions.
     *
     * @param string $url URL of the file to download.
     * @param string $destination Full path to save the downloaded file.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function download_file($url, $destination) {
        // Use WP's download_url function which handles temporary file creation
        // Note: download_url creates its own temp file, we might not need $destination initially
        // Let's stick to the sample's logic of providing the destination path directly.

        $response = wp_safe_remote_get($url, array(
            'timeout'  => 300, // 5 minutes
            'stream'   => true, // Stream to file
            'filename' => $destination,
            'sslverify' => false // Consider true
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            // Try to get error message from body if available
            $body = wp_remote_retrieve_body($response);
            $error_message = !empty($body) ? strip_tags($body) : sprintf(__('Server returned HTTP code %s', 'govtech-backup'), $response_code);

            return new WP_Error('download_failed', sprintf(
                __('Failed to download file. %s', 'govtech-backup'),
                $error_message
            ));
        }

        // Check if file was actually created at destination
        if (!file_exists($destination) || filesize($destination) == 0) {
             return new WP_Error('download_failed', __('File download seemed successful, but the destination file is missing or empty.', 'govtech-backup'));
        }

        return true;
    }

    /**
     * Find the plugin directory within the extracted files.
     * Searches for the main plugin file (e.g., govtech_backup.php).
     *
     * @param string $base_dir The base directory where files were extracted.
     * @param string $plugin_filename The name of the main plugin file to look for.
     * @return string|false Full path to the found plugin directory or false if not found.
     */
    private function find_plugin_directory($base_dir, $plugin_filename) {
        $base_dir = trailingslashit($base_dir);

        // 1. Check if the base directory itself contains the plugin file
        if (file_exists($base_dir . $plugin_filename)) {
            return $base_dir;
        }

        // 2. Check immediate subdirectories
        $subdirs = glob($base_dir . '*', GLOB_ONLYDIR);
        if ($subdirs) {
            foreach ($subdirs as $subdir) {
                $subdir = trailingslashit($subdir);
                if (file_exists($subdir . $plugin_filename)) {
                    return $subdir;
                }
            }
        }

        // 3. Check one level deeper (sometimes archives have nested folders like plugin-name/plugin-name/)
        if ($subdirs) {
            foreach ($subdirs as $subdir) {
                 $subsubdirs = glob(trailingslashit($subdir) . '*', GLOB_ONLYDIR);
                 if ($subsubdirs) {
                     foreach ($subsubdirs as $subsubdir) {
                         $subsubdir = trailingslashit($subsubdir);
                         if (file_exists($subsubdir . $plugin_filename)) {
                             return $subsubdir;
                         }
                     }
                 }
            }
        }


        return false; // Plugin directory not found
    }

    /**
     * Recursively copy a directory (PHP fallback).
     *
     * @param string $source Source directory path.
     * @param string $destination Destination directory path.
     */
    private function recursively_copy_directory($source, $destination) {
        if (!is_dir($source)) {
            return false;
        }
        if (!is_dir($destination)) {
            if (!wp_mkdir_p($destination)) {
                return false;
            }
        }

        $dir = opendir($source);
        if (!$dir) return false;

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $src_path = $source . '/' . $file;
            $dst_path = $destination . '/' . $file;

            if (is_dir($src_path)) {
                $this->recursively_copy_directory($src_path, $dst_path);
            } else {
                // Use copy() - consider permissions if issues arise
                @copy($src_path, $dst_path);
            }
        }
        closedir($dir);
        return true;
    }

    /**
     * Recursively delete a directory (PHP fallback).
     *
     * @param string $dir Directory path to delete.
     * @return bool True on success, false on failure.
     */
    private function recursively_delete_directory($dir) {
        if (!file_exists($dir)) {
            return true; // Already gone
        }
        if (!is_dir($dir)) {
            return @unlink($dir); // Delete file
        }

        // Use WP Filesystem API if available for better reliability
        global $wp_filesystem;
        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            return $wp_filesystem->delete($dir, true); // Recursive delete
        }

        // Fallback to PHP functions
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                if (!@$todo($fileinfo->getRealPath())) {
                    return false; // Stop if deletion fails
                }
            }
            return @rmdir($dir);
        } catch (Exception $e) {
            error_log("Govtech Backup: Error deleting directory $dir: " . $e->getMessage());
            return false;
        }
    }


 } // End Class Govtech_Backup_Admin
