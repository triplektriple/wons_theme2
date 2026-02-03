<?php
/**
 * Main plugin class for Govtech Backup
 *
 * @package Govtech_Backup
 */

if (! defined('WPINC')) {
    die;
}

require_once GOVTECH_BACKUP_PLUGIN_DIR . 'includes/class-govtech-backup-updater.php';

class Govtech_Backup
{

    const VERSION = '1.0.5';

    protected static $instance = null;

    private $license_valid         = false;
    private $license_expiry        = '';
    private $s3_config             = null;private $webhook_secret             = null;private $backups             = [];
    private $total_backups_to_keep = 1000;

    private $backup_in_progress = false;

    /**
     * Constructor for the main plugin class
     */
    public function __construct()
    {

        register_activation_hook(GOVTECH_BACKUP_PLUGIN_BASENAME, [$this, 'activate']);
        register_deactivation_hook(GOVTECH_BACKUP_PLUGIN_BASENAME, [$this, 'deactivate']);

        $this->check_license();

        $this->initialize_plugin();

        $this->setup_plugin_updates();

        if (is_admin()) {
            require_once GOVTECH_BACKUP_PLUGIN_DIR . 'includes/class-govtech-backup-admin.php';
            new Govtech_Backup_Admin($this);
        }
    }

    /**
     * Get instance of this class
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Plugin activation
     */
    public function activate()
    {

        $backup_dir = WP_CONTENT_DIR . '/backups';
        if (! file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);

            file_put_contents($backup_dir . '/.htaccess', "Order Deny,Allow\nDeny from all");
            file_put_contents($backup_dir . '/index.php', "<?php\n// Silence is golden.");
        }

        if (get_option('govtech_backup_license_last_check') === false) {
            update_option('govtech_backup_license_last_check', 0);
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {

    }

    /**
     * Initialize plugin hooks
     */
    private function initialize_plugin()
    {

        load_plugin_textdomain('govtech-backup', false, dirname(GOVTECH_BACKUP_PLUGIN_BASENAME) . '/languages');

        add_action('rest_api_init', function () {
            register_rest_route('govtech-backup/v1', '/trigger-backup', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_webhook'],
                'permission_callback' => [$this, 'verify_webhook'],
            ]);
        });

        add_action('wp_ajax_govtech_backup_backup_in_progress', [$this, 'is_backup_in_progress_ajax']);
    }

    /**
     * Set up automatic updates from the custom server.
     */
    private function setup_plugin_updates()
    {

        new Govtech_Backup_Updater(
            GOVTECH_BACKUP_PLUGIN_DIR . 'govtech_backup.php', 'https://wons.bt/GovTech_Backup/update-info.json', GOVTECH_BACKUP_VERSION, $this->license_valid);
    }

    /**
     * Check license validity with the license server
     * Fetches license status, S3 config, and webhook secret.
     * Stores fetched data in class properties.
     * Uses caching to avoid checking on every page load.
     *
     * *** IMPROVEMENT 1: FIXED CACHE CHECK LOGIC ***
     */
    public function check_license($force_check = false)
    {
        $licenseServerUrl = "https://wons.bt/GovTech_Backup/license/";
        $last_check_time  = get_option('govtech_backup_license_last_check', 0);
        $current_time     = time();
        $cache_duration   = 12 * 60 * 60;

        $cached_license_valid  = get_option('govtech_backup_license_valid', false);
        $cached_license_expiry = get_option('govtech_backup_license_expiry', '');
        $cached_s3_config      = get_option('govtech_backup_s3_config', null);
        $cached_webhook_secret = get_option('govtech_backup_webhook_secret', null);

        if (! $force_check &&
            $cached_license_valid &&
            ! empty($cached_license_expiry) &&
            strtotime($cached_license_expiry) > $current_time &&
            ($current_time - $last_check_time) < $cache_duration &&
            ! empty($cached_s3_config) &&
            ! empty($cached_webhook_secret)) {

            $this->license_valid  = true;
            $this->license_expiry = $cached_license_expiry;
            $this->s3_config      = $cached_s3_config;

            $this->s3_config['path']     = $this->s3_config['bucket'] . '/' . preg_replace('#^https?://#', '', rtrim(site_url(), '/')) . '/';
            $this->webhook_secret        = $cached_webhook_secret;
            $this->total_backups_to_keep = get_option('govtech_backup_total_rotation_backups', 1000);
            return true;
        }
        $response = wp_remote_get($licenseServerUrl, [
            'timeout'   => 15,
            'sslverify' => false]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $error_message = is_wp_error($response) ? $response->get_error_message() : 'HTTP Error: ' . wp_remote_retrieve_response_code($response);
            error_log('Govtech Backup: Failed to connect to license server: ' . $error_message);

            if ($cached_license_valid && ! empty($cached_s3_config) && ! empty($cached_webhook_secret)) {
                $this->license_valid         = true;
                $this->license_expiry        = $cached_license_expiry;
                $this->s3_config             = $cached_s3_config;
                $this->s3_config['path']     = $this->s3_config['bucket'] . '/' . preg_replace('#^https?://#', '', rtrim(site_url(), '/')) . '/';
                $this->webhook_secret        = $cached_webhook_secret;
                $this->total_backups_to_keep = get_option('govtech_backup_total_rotation_backups', 1000);
                error_log('Govtech Backup: Using stale cached license data due to server error.');
                return true;
            }

            $this->invalidate_license_data();
            return false;
        }

        $encodedResponse = wp_remote_retrieve_body($response);
        $responseJson    = base64_decode($encodedResponse);
        $responseData    = json_decode($responseJson, true);

        if (! $responseData || ! isset($responseData['payload']) || ! isset($responseData['signature'])) {
            error_log('Govtech Backup: Invalid response format from license server.');
            $this->invalidate_license_data();
            return false;
        }

        $sharedSecret      = "WONS_AMC_2024_to_2025";
        $payload           = $responseData['payload'];
        $providedSignature = $responseData['signature'];
        $computedSignature = hash('sha256', $sharedSecret . $payload . $sharedSecret);

        if ($computedSignature === $providedSignature) {
            $licenseData = json_decode($payload, true);if ($licenseData && isset($licenseData['status'])) {
                if ($licenseData['status'] === 'valid' && isset($licenseData['s3_config']) && isset($licenseData['webhook_secret'])) {
                    $this->license_valid     = true;
                    $this->license_expiry    = isset($licenseData['valid_until']) ? $licenseData['valid_until'] : '';
                    $this->s3_config         = $licenseData['s3_config'];
                    $this->s3_config['path'] = $this->s3_config['bucket'] . '/' . preg_replace('#^https?://#', '', rtrim(site_url(), '/')) . '/';

                    $this->webhook_secret        = $licenseData['webhook_secret'];
                    $this->total_backups_to_keep = isset($licenseData['total_backups_to_keep']) ? $licenseData['total_backups_to_keep'] : 1000;

                    update_option('govtech_backup_license_valid', true);
                    update_option('govtech_backup_license_expiry', $this->license_expiry);
                    update_option('govtech_backup_s3_config', $this->s3_config);
                    update_option('govtech_backup_webhook_secret', $this->webhook_secret);
                    update_option('govtech_backup_license_last_check', $current_time);
                    update_option('govtech_backup_total_rotation_backups', $this->total_backups_to_keep);
                    error_log('Govtech Backup: License valid. S3 and Webhook details updated.');
                    return true;
                } else {
                    error_log('Govtech Backup: License status is invalid or missing required data (S3/Webhook). Status: ' . $licenseData['status']);
                    $this->invalidate_license_data();
                    return false;
                }
            } else {
                error_log('Govtech Backup: Invalid payload data structure after decoding.');
                $this->invalidate_license_data();
                return false;
            }
        } else {
            error_log('Govtech Backup: License signature verification failed.');
            $this->invalidate_license_data();
            return false;
        }
    }

    /**
     * Helper to clear stored license data on failure/invalidation.
     */
    private function invalidate_license_data()
    {
        $this->license_valid  = false;
        $this->license_expiry = '';
        $this->s3_config      = null;
        $this->webhook_secret = null;
        update_option('govtech_backup_license_valid', false);
        update_option('govtech_backup_license_expiry', '');

        delete_option('govtech_backup_s3_config');
        delete_option('govtech_backup_webhook_secret');

        update_option('govtech_backup_license_last_check', time());
    }

    /**
     * Verify webhook request using the secret key from license data
     */
    public function verify_webhook($request)
    {

        $provided_key = $request->get_header('X-WONS-Backup-Key');
        if (empty($this->webhook_secret)) {
            error_log('Govtech Backup: Webhook verification failed - Secret key not loaded from license.');
            return false;
        }

        if ($provided_key !== $this->webhook_secret) {
            error_log('Govtech Backup: Webhook authentication failed - Incorrect key provided.');
            return false;
        }

        return true;
    }

    /**
     * Handle webhook request and trigger backup
     */
    public function handle_webhook($request)
    {
        error_log('Govtech Backup: Webhook triggered at ' . date('Y-m-d H:i:s'));

        $backup_list_result = $this->list_s3_backups();
        if ($backup_list_result['success'] && ! empty($backup_list_result['backups'])) {
            $current_backups = $backup_list_result['backups'];

            if (count($current_backups) >= $this->total_backups_to_keep) {

                usort($current_backups, function ($a, $b) {
                    return $a['date_raw'] - $b['date_raw'];
                });

                $backups_to_delete_count = count($current_backups) - $this->total_backups_to_keep + 1;

                if ($backups_to_delete_count > 0) {
                    $to_be_deleted_backups = array_slice($current_backups, 0, $backups_to_delete_count);

                    foreach ($to_be_deleted_backups as $backup) {
                        $delete_result = $this->delete_s3_backup_with_retry($backup['s3_key']);
                        if ($delete_result['success']) {
                            error_log('Govtech Backup: Successfully deleted old backup: ' . $backup['filename']);
                        } else {
                            error_log('Govtech Backup: Failed to delete old backup: ' . $backup['filename'] . ' - ' . $delete_result['message']);
                        }
                    }
                }
            }
        } else {
            error_log('Govtech Backup: Could not retrieve backup list for rotation: ' . $backup_list_result['message']);
        }

        if (! $this->check_license(true)) {error_log('Govtech Backup: Backup aborted - License check failed or invalid.');
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Backup failed: License invalid or could not be verified.',
                'details' => 'License check failed',
            ], 403);}

        if (empty($this->s3_config)) {
            error_log('Govtech Backup: Backup aborted - S3 configuration not loaded from license.');
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Backup failed: S3 configuration missing.',
                'details' => 'S3 config missing',
            ], 500);
        }

        $result = $this->create_backup();

        if ($result['success']) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Backup initiated successfully',
                'details' => $result['details'],
            ], 200);
        } else {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Backup failed',
                'details' => $result['details'],
            ], 500);
        }
    }

    /**
     * Get the total size of a directory
     *
     * @param string $dir Directory path
     * @return int Size in bytes
     */
    private function get_directory_size($dir)
    {
        $size = 0;

        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (Exception $e) {
            error_log("Govtech Backup: Error calculating directory size: " . $e->getMessage());
        }

        return $size;
    }

    public function clear_backup_status()
    {

        delete_transient('govtech_backup_in_progress');
        $this->backup_in_progress = false;

        return true;
    }

    /**
     * *** IMPROVEMENT 3: ENHANCED CLEANUP ON FAILURE ***
     * Clean up all backup-related files
     */
    private function cleanup_backup_files($temp_dir = null, $final_zip_file = null)
    {

        if (! empty($temp_dir) && is_dir($temp_dir)) {
            $this->cleanup_temp_dir($temp_dir);
        }

        if (! empty($final_zip_file) && file_exists($final_zip_file)) {
            @unlink($final_zip_file);
            error_log("Govtech Backup: Cleaned up ZIP file: " . $final_zip_file);
        }

        delete_transient('govtech_backup_in_progress');
        $this->backup_in_progress = false;

        error_log('Govtech Backup: All backup files cleaned up');
    }

    /**
     * Create a backup (wp-content + database) and upload to S3
     * *** IMPROVEMENT 3: ENHANCED ERROR HANDLING AND CLEANUP ***
     */
    public function create_backup()
    {

        if (! $this->license_valid || empty($this->s3_config)) {
            $this->check_license(true);if (! $this->license_valid || empty($this->s3_config)) {
                return [
                    'success' => false,
                    'details' => __('Backup failed: License invalid or S3 configuration missing.', 'govtech-backup'),
                ];
            }
        }

        if (get_transient('govtech_backup_in_progress')) {
            error_log('Govtech Backup: Backup requested but another process is already running (transient exists).');
            return [
                'success' => false,
                'details' => __('Backup already in progress.', 'govtech-backup'),
            ];
        }

        @ini_set('max_execution_time', 0);@ini_set('memory_limit', '1024M');@set_time_limit(0);

        set_transient('govtech_backup_in_progress', 'true', HOUR_IN_SECONDS);
        $this->backup_in_progress = true;
        $result                   = ['success' => false, 'details' => __('Backup process did not complete.', 'govtech-backup')];

        $temp_dir       = null;
        $final_zip_file = null;

        try {

            $site_url             = get_site_url();
            $domain               = preg_replace('/^www\./', '', parse_url($site_url, PHP_URL_HOST));
            $timestamp            = date('Y-m-d_H-i-s');
            $backup_filename_base = $domain . '_' . $timestamp;

            $temp_dir        = trailingslashit(WP_CONTENT_DIR) . 'backups/temp_' . $backup_filename_base;
            $final_zip_file  = trailingslashit(WP_CONTENT_DIR) . 'backups/' . $backup_filename_base . '.zip';
            $backup_base_dir = trailingslashit(WP_CONTENT_DIR) . 'backups';

            $free_space      = disk_free_space(dirname($final_zip_file));
            $wp_content_size = $this->get_directory_size(WP_CONTENT_DIR);
            error_log("Govtech Backup: Free space: " . size_format($free_space) . ", WP Content size: " . size_format($wp_content_size));

            if ($free_space < ($wp_content_size * 1.2)) {
                throw new Exception(__('Not enough disk space for backup. Need at least ', 'govtech-backup') .
                    size_format($wp_content_size * 1.2) . ', ' .
                    __('but only', 'govtech-backup') . ' ' . size_format($free_space) . ' ' .
                    __('available.', 'govtech-backup'));
            }

            $backup_base_dir = trailingslashit(WP_CONTENT_DIR) . 'backups';
            if (! file_exists($backup_base_dir)) {
                if (! wp_mkdir_p($backup_base_dir)) {
                    throw new Exception(sprintf(__('Failed to create base backup directory: %s', 'govtech-backup'), $backup_base_dir));
                }

                file_put_contents($backup_base_dir . '/.htaccess', "Order Deny,Allow\nDeny from all");
                file_put_contents($backup_base_dir . '/index.php', "<?php\n// Silence is golden.");
            } else if (file_exists($backup_base_dir)) {
                error_log('Govtech Backup: Cleaning up remnant files in backups directory');

                try {
                    $old_backup_files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($backup_base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );

                    $cleaned_count = 0;
                    foreach ($old_backup_files as $file) {

                        if (in_array(basename($file->getPathname()), ['.htaccess', 'index.php'])) {
                            continue;
                        }

                        if ($file->isDir()) {
                            if (@rmdir($file->getPathname())) {
                                $cleaned_count++;
                            }
                        } else {
                            if (@unlink($file->getPathname())) {
                                $cleaned_count++;
                            }
                        }
                    }

                    if ($cleaned_count > 0) {
                        error_log("Govtech Backup: Cleaned up {$cleaned_count} remnant files/directories from backups folder");
                    }

                } catch (Exception $e) {
                    error_log('Govtech Backup: Error cleaning backups directory: ' . $e->getMessage());

                }
            }
            if (! is_writable($backup_base_dir)) {
                throw new Exception(sprintf(__('Base backup directory is not writable: %s', 'govtech-backup'), $backup_base_dir));
            }

            if (! wp_mkdir_p($temp_dir)) {
                throw new Exception(__('Failed to create temporary directory.', 'govtech-backup') . ' ' . $temp_dir);
            }

            $db_backup_path   = $temp_dir . '/init.sql';
            $db_backup_result = $this->backup_database($db_backup_path);
            if (! $db_backup_result['success']) {
                throw new Exception(__('Database backup failed.', 'govtech-backup') . ' ' . $db_backup_result['message']);
            }

            if (! class_exists('PclZip')) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }

            $archive = new PclZip($final_zip_file);

            $db_add_result = $archive->add($db_backup_path, PCLZIP_OPT_REMOVE_PATH, $temp_dir);
            if ($db_add_result === 0) {
                throw new Exception(__('Failed to add database to ZIP: ', 'govtech-backup') . $archive->errorInfo(true));
            }

            $wp_content_dir = WP_CONTENT_DIR;
            $exclude_dirs   = [
                trailingslashit(WP_CONTENT_DIR) . 'backups',
                trailingslashit(WP_CONTENT_DIR) . 'uploads/uag-plugin/assets',
                trailingslashit(WP_CONTENT_DIR) . 'cache',
                trailingslashit(WP_CONTENT_DIR) . 'uploads/cache',
                trailingslashit(WP_CONTENT_DIR) . 'debug.log',
                trailingslashit(WP_CONTENT_DIR) . 'temp',
                trailingslashit(WP_CONTENT_DIR) . 'tmp',
            ];

            $file_list = [];

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($wp_content_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                $file_path      = $file->getPathname();
                $should_exclude = false;

                foreach ($exclude_dirs as $exclude_dir) {
                    if (strpos($file_path, $exclude_dir) === 0) {
                        $should_exclude = true;
                        break;
                    }
                }

                if (! $should_exclude && strpos($file_path, 'cache') !== false) {
                    $should_exclude = true;
                }

                if (! $should_exclude) {
                    $file_list[] = $file_path;
                }
            }

            if (! empty($file_list)) {

                $existing_files = array_filter($file_list, function ($file_path) {
                    $exists = file_exists($file_path);
                    if (! $exists) {
                        error_log("Govtech Backup: Skipping non-existent file during ZIP creation: " . $file_path);
                    }
                    return $exists;
                });

                if (! empty($existing_files)) {
                    $content_add_result = $archive->add(
                        $existing_files,
                        PCLZIP_OPT_REMOVE_PATH, dirname($wp_content_dir),
                        PCLZIP_OPT_ADD_PATH, basename($wp_content_dir),
                        PCLZIP_OPT_TEMP_FILE_THRESHOLD, 100 * 1024 * 1024);

                    if ($content_add_result === 0) {
                        throw new Exception(__('Failed to add wp-content to ZIP: ', 'govtech-backup') . $archive->errorInfo(true));
                    }

                    $skipped_count = count($file_list) - count($existing_files);
                    if ($skipped_count > 0) {
                        error_log("Govtech Backup: Skipped {$skipped_count} non-existent files during ZIP creation");
                    }
                } else {
                    error_log("Govtech Backup: No files to add to ZIP after filtering non-existent files");
                }
            }

            $this->cleanup_temp_dir($temp_dir);
            $temp_dir = null;

            if (! file_exists($final_zip_file) || filesize($final_zip_file) === 0) {
                throw new Exception(__('ZIP file was not created or is empty.', 'govtech-backup'));
            }

            $upload_result = $this->upload_to_s3($final_zip_file);

            if (! $upload_result['success']) {
                throw new Exception(__('Backup upload failed: ', 'govtech-backup') . $upload_result['message']);
            }

            @unlink($final_zip_file);
            $final_zip_file = null;

            $result = [
                'success' => true,
                'details' => $upload_result['message'],
            ];

        } catch (Exception $e) {
            error_log('Govtech Backup: Backup failed with exception: ' . $e->getMessage());

            $this->cleanup_backup_files($temp_dir, $final_zip_file);

            $result = [
                'success' => false,
                'details' => $e->getMessage(),
            ];

        } finally {

            delete_transient('govtech_backup_in_progress');
            $this->backup_in_progress = false;
            error_log('Govtech Backup: Backup process finished. Transient cleared.');
        }

        return $result;
    }

    /**
     * AJAX handler for checking backup progress using transient
     */
    public function is_backup_in_progress_ajax()
    {

        $in_progress = (bool) get_transient('govtech_backup_in_progress');
        wp_send_json_success([
            'in_progress' => $in_progress,
        ]);
    }

    /**
     * Backup database using PHP fallback method
     */
    private function backup_database($backup_file)
    {
        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');
        if (empty($tables)) {
            return ['success' => false, 'message' => __('No database tables found.', 'govtech-backup')];
        }

        $handle = @fopen($backup_file, 'w');
        if (! $handle) {
            return ['success' => false, 'message' => sprintf(__('Could not open file for writing database backup: %s', 'govtech-backup'), $backup_file)];
        }

        fwrite($handle, "-- WordPress SQL Backup (Govtech Backup Plugin)\n");
        fwrite($handle, "-- Site URL: " . get_site_url() . "\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($handle, "SET time_zone = \"+00:00\";\n\n");

        foreach ($tables as $table) {

            $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            if (! $create) {
                continue;
            }

            fwrite($handle, "-- --------------------------------------------------------\n\n");
            fwrite($handle, "--\n-- Table structure for table `$table`\n--\n");
            fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
            fwrite($handle, $create[1] . ";\n\n");

            $row_count  = $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            $chunk_size = 500;
            $offset     = 0;

            if ($row_count > 0) {
                fwrite($handle, "--\n-- Dumping data for table `$table`\n--\n");
                $columns_sql = '';
                $first_chunk = true;

                while ($offset < $row_count) {
                    $rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT $chunk_size OFFSET $offset", ARRAY_A);
                    if (! $rows) {
                        break;
                    }

                    if ($first_chunk) {
                        $columns = array_map(function ($col) {return "`" . $col . "`";}, array_keys($rows[0]));
                        $columns_sql = implode(',', $columns);
                        fwrite($handle, "INSERT INTO `$table` ($columns_sql) VALUES\n");
                        $first_chunk = false;
                    }

                    $values = [];
                    foreach ($rows as $row) {
                        $escaped = array_map(function ($value) use ($wpdb) {
                            if (is_null($value)) {
                                return 'NULL';
                            }

                            return "'" . $wpdb->_real_escape((string) $value) . "'";
                        }, $row);
                        $values[] = '(' . implode(',', $escaped) . ')';
                    }

                    fwrite($handle, implode(",\n", $values));

                    $offset += count($rows);

                    if ($offset < $row_count) {
                        fwrite($handle, ",\n");
                    } else {
                        fwrite($handle, ";\n");
                    }
                    unset($rows);unset($values);
                }
            }
            fwrite($handle, "\n");
        }

        fclose($handle);

        if (! file_exists($backup_file) || filesize($backup_file) === 0) {
            return ['success' => false, 'message' => __('Database backup file was created but is empty.', 'govtech-backup')];
        }

        return ['success' => true, 'message' => __('Database backup successful.', 'govtech-backup')];
    }

    /**
     * Clean up temporary directory
     */
    private function cleanup_temp_dir($temp_dir)
    {
        if (! is_dir($temp_dir)) {
            return;
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            $wp_filesystem->delete($temp_dir, true);
        } else {

            try {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($files as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    @$todo($fileinfo->getRealPath());
                }
                @rmdir($temp_dir);
            } catch (Exception $e) {
                error_log("Govtech Backup: Error cleaning up temp directory $temp_dir: " . $e->getMessage());
            }
        }
    }

    /**
     * *** IMPROVEMENT 2: MULTIPART UPLOAD FOR LARGE FILES ***
     * Upload backup file to S3 using details from license
     * Automatically uses multipart upload for files larger than 100MB
     */
    private function upload_to_s3($file, $max_retries = 3)
    {

        if (empty($this->s3_config)) {
            return ['success' => false, 'message' => __('S3 configuration not available.', 'govtech-backup')];
        }

        $access_key = $this->s3_config['access_key'] ?? '';
        $secret_key = $this->s3_config['secret_key'] ?? '';
        $bucket     = $this->s3_config['bucket'] ?? '';
        $region     = $this->s3_config['region'] ?? 'us-east-1';
        $endpoint   = $this->s3_config['endpoint'] ?? '';
        $path       = $bucket . '/' . preg_replace('#^https?://#', '', rtrim(site_url(), '/')) . '/';

        if (empty($access_key) || empty($secret_key) || empty($bucket)) {
            return ['success' => false, 'message' => __('Incomplete S3 configuration received from license server.', 'govtech-backup')];
        }

        $path = trailingslashit($path);

        $base_url = ! empty($endpoint) ? rtrim($endpoint, '/') : "https://{$bucket}.s3.{$region}.amazonaws.com";

        if (strpos($base_url, 'http') !== 0) {
            $base_url = 'https://' . $base_url;
        }

        $filesize = @filesize($file);
        if ($filesize === false) {
            return ['success' => false, 'message' => sprintf(__('Could not get size of file: %s', 'govtech-backup'), basename($file))];
        }
        error_log("Govtech Backup: Starting S3 upload for file: " . basename($file) . " Size: " . size_format($filesize));

        $key = $path . basename($file);

        $multipart_threshold = 1024 * 1024 * 1024; //1GB threshold
        if ($filesize > $multipart_threshold) {
            error_log("Govtech Backup: File size (" . size_format($filesize) . ") exceeds threshold, using multipart upload");
            return $this->upload_to_s3_multipart($file, $key, $max_retries);
        } else {
            error_log("Govtech Backup: File size (" . size_format($filesize) . ") below threshold, using single upload");
            return $this->upload_to_s3_single($file, $key, $max_retries);
        }
    }

    /**
     * *** IMPROVEMENT 2: SINGLE UPLOAD METHOD ***
     * Single upload method for smaller files (extracted from original method)
     */
    private function upload_to_s3_single($file, $key, $max_retries = 3)
    {

        $s3_settings_for_helpers = [
            'access_key' => $this->s3_config['access_key'],
            'secret_key' => $this->s3_config['secret_key'],
            'bucket'     => $this->s3_config['bucket'],
            'region'     => $this->s3_config['region'] ?? 'us-east-1',
            'endpoint'   => $this->s3_config['endpoint'] ?? '',
            'path'       => $this->s3_config['path'],
            'base_url'   => ! empty($this->s3_config['endpoint']) ? rtrim($this->s3_config['endpoint'], '/') : "https://{$this->s3_config['bucket']}.s3.{$this->s3_config['region']}.amazonaws.com",
        ];

        if (strpos($s3_settings_for_helpers['base_url'], 'http') !== 0) {
            $s3_settings_for_helpers['base_url'] = 'https://' . $s3_settings_for_helpers['base_url'];
        }

        $filesize = filesize($file);
        $url      = "{$s3_settings_for_helpers['base_url']}/{$key}";

        $attempt        = 0;
        $success        = false;
        $last_error     = '';
        $last_http_code = 0;
        $initial_delay  = ($filesize > 50 * 1024 * 1024) ? 2000000 : 500000;
        while ($attempt < $max_retries && ! $success) {
            if ($attempt > 0) {
                $delay         = pow(2, $attempt) * $initial_delay;
                $delay_seconds = $delay / 1000000;
                error_log("Govtech Backup: S3 upload attempt {$attempt} failed, retrying after {$delay_seconds} seconds");
                usleep($delay);
            }

            $payload_hash = hash_file('sha256', $file);
            if ($payload_hash === false) {
                $last_error = "Failed to calculate SHA256 hash for file.";
                $attempt++;
                continue;
            }

            error_log("Govtech Backup: === SINGLE UPLOAD DEBUG ===");
            error_log("Govtech Backup: About to create signature with:");
            error_log("  Method: PUT");
            error_log("  Canonical URI: /" . $key);
            error_log("  Query: (empty)");
            error_log("  Key: " . $key);
            error_log("  URL: " . $url);

            $auth_data = $this->create_s3_signature($s3_settings_for_helpers, 'PUT', '/' . $key, '', $payload_hash);

            error_log("Govtech Backup: Single upload signature created: " . substr($auth_data['signature'], 0, 20) . "...");
            error_log("Govtech Backup: Single upload authorization: " . $auth_data['authorization']);

            $auth_data = $this->create_s3_signature($s3_settings_for_helpers, 'PUT', '/' . $key, '', $payload_hash);

            $file_handle = @fopen($file, 'r');
            if (! $file_handle) {
                return ['success' => false, 'message' => sprintf(__('Could not open file for upload: %s', 'govtech-backup'), basename($file))];
            }

            $connect_timeout = min(120, max(30, ceil($filesize / (5 * 1024 * 1024))));
            $exec_timeout    = min(3600, max(600, ceil($filesize / (1024 * 1024))));
            error_log("Govtech Backup: Setting timeouts: connect={$connect_timeout}s, exec={$exec_timeout}s for file size " . size_format($filesize));

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: {$auth_data['authorization']}",
                "x-amz-date: {$auth_data['timestamp']}",
                "x-amz-content-sha256: {$auth_data['payload_hash']}",
                "Content-Type: application/octet-stream",
                "Content-Length: {$filesize}",
                "Expect: 100-continue",
            ]);
            curl_setopt($ch, CURLOPT_INFILE, $file_handle);
            curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $exec_timeout);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 128 * 1024);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($download_size, $downloaded, $upload_size, $uploaded) use ($file) {
                static $last_log = 0;
                $now             = time();
                if ($now - $last_log >= 30) {
                    $percent  = ($upload_size > 0) ? round(($uploaded / $upload_size) * 100, 2) : 0;
                    $last_log = $now;
                }
                return 0;
            });

            $response   = curl_exec($ch);
            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error      = curl_error($ch);
            $curl_errno = curl_errno($ch);
            curl_close($ch);
            fclose($file_handle);

            if ($http_code >= 200 && $http_code < 300) {
                $success = true;
                error_log("Govtech Backup: S3 single upload successful for " . basename($file));
            } else {
                $error_message = $this->handle_s3_error($response, $http_code, $error);
                if (strpos($error_message, 'timeout') !== false || $curl_errno == CURLE_OPERATION_TIMEOUTED) {
                    error_log("S3 upload timeout on attempt {$attempt}: {$error_message}");
                    sleep(pow(2, $attempt + 1));
                } elseif (strpos($error_message, 'rate') !== false || $http_code == 429 ||
                    strpos($error_message, 'SlowDown') !== false || strpos($error_message, 'Throttling') !== false) {
                    error_log("S3 rate limit hit on attempt {$attempt}: {$error_message}");
                    sleep(pow(3, $attempt + 1));
                } else {
                    error_log("S3 upload error on attempt {$attempt}: {$error_message}");
                }
                $last_error     = $error_message;
                $last_http_code = $http_code;
                $attempt++;
            }
        }

        if ($success) {
            return [
                'success' => true,
                'message' => sprintf(__('Backup uploaded to S3: %s', 'govtech-backup'), basename($file)),
            ];
        } else {
            return [
                'success' => false,
                'message' => sprintf(__('S3 upload failed after %d attempts: %s', 'govtech-backup'), $attempt, $last_error),
            ];
        }
    }

    /**
     * *** IMPROVEMENT 2: MULTIPART UPLOAD METHOD ***
     * Multipart upload method for large files
     */
    private function upload_to_s3_multipart($file, $key, $max_retries = 3)
    {

        $s3_settings_for_helpers = [
            'access_key' => $this->s3_config['access_key'],
            'secret_key' => $this->s3_config['secret_key'],
            'bucket'     => $this->s3_config['bucket'],
            'region'     => $this->s3_config['region'] ?? 'us-east-1',
            'endpoint'   => $this->s3_config['endpoint'] ?? '',
            'path'       => $this->s3_config['path'],
            'base_url'   => ! empty($this->s3_config['endpoint']) ? rtrim($this->s3_config['endpoint'], '/') : "https://{$this->s3_config['bucket']}.s3.{$this->s3_config['region']}.amazonaws.com",
        ];

        if (strpos($s3_settings_for_helpers['base_url'], 'http') !== 0) {
            $s3_settings_for_helpers['base_url'] = 'https://' . $s3_settings_for_helpers['base_url'];
        }

        $filesize     = filesize($file);
        $chunk_size   = 200 * 1024 * 1024; // 200MB chunks (minimum for S3 multipart is 5MB, except last part)
        $total_chunks = ceil($filesize / $chunk_size);

        error_log("Govtech Backup: Starting multipart upload. File size: " . size_format($filesize) . ", Chunks: {$total_chunks}, Chunk size: " . size_format($chunk_size));

        try {

            $upload_id = $this->initiate_multipart_upload($s3_settings_for_helpers, $key);
            if (! $upload_id) {
                throw new Exception('Failed to initiate multipart upload');
            }

            error_log("Govtech Backup: Multipart upload initiated with ID: {$upload_id}");

            $parts       = [];
            $file_handle = fopen($file, 'r');
            if (! $file_handle) {
                throw new Exception('Could not open file for multipart upload');
            }

            for ($part_number = 1; $part_number <= $total_chunks; $part_number++) {
                $is_last_part       = ($part_number == $total_chunks);
                $current_chunk_size = $is_last_part ? ($filesize - (($part_number - 1) * $chunk_size)) : $chunk_size;

                fseek($file_handle, ($part_number - 1) * $chunk_size);
                $chunk_data = fread($file_handle, $current_chunk_size);

                if ($chunk_data === false || strlen($chunk_data) == 0) {
                    fclose($file_handle);
                    $this->abort_multipart_upload($s3_settings_for_helpers, $key, $upload_id);
                    throw new Exception("Failed to read chunk {$part_number}");
                }

                $etag    = null;
                $attempt = 0;

                while ($attempt < $max_retries && ! $etag) {
                    if ($attempt > 0) {
                        $delay = pow(2, $attempt) * 1000000;
                        error_log("Govtech Backup: Retrying part {$part_number} after " . ($delay / 1000000) . " seconds");
                        usleep($delay);
                    }

                    $etag = $this->upload_multipart_chunk($s3_settings_for_helpers, $key, $upload_id, $part_number, $chunk_data);
                    $attempt++;
                }

                if (! $etag) {
                    fclose($file_handle);
                    $this->abort_multipart_upload($s3_settings_for_helpers, $key, $upload_id);
                    throw new Exception("Failed to upload part {$part_number} after {$max_retries} attempts");
                }

                $parts[] = [
                    'PartNumber' => $part_number,
                    'ETag'       => $etag,
                ];

                $progress = round(($part_number / $total_chunks) * 100, 1);
                error_log("Govtech Backup: Uploaded part {$part_number}/{$total_chunks} ({$progress}%) - ETag: {$etag}");
            }

            fclose($file_handle);

            $complete_result = $this->complete_multipart_upload($s3_settings_for_helpers, $key, $upload_id, $parts);
            if (! $complete_result) {
                $this->abort_multipart_upload($s3_settings_for_helpers, $key, $upload_id);
                throw new Exception('Failed to complete multipart upload');
            }

            error_log("Govtech Backup: Multipart upload completed successfully for " . basename($file));

            return [
                'success' => true,
                'message' => sprintf(__('Backup uploaded to S3 via multipart: %s (%d parts)', 'govtech-backup'), basename($file), $total_chunks),
            ];

        } catch (Exception $e) {
            error_log("Govtech Backup: Multipart upload failed: " . $e->getMessage());

            if (! empty($upload_id)) {
                $this->abort_multipart_upload($s3_settings_for_helpers, $key, $upload_id);
            }

            return [
                'success' => false,
                'message' => sprintf(__('S3 multipart upload failed: %s', 'govtech-backup'), $e->getMessage()),
            ];
        }
    }

    /**
     * Fixed multipart upload methods - prevents CURL from adding unwanted headers
     * Replace these methods in your Govtech_Backup class
     */

    private function initiate_multipart_upload($s3_settings_for_helpers, $key)
    {
        // The key already contains the full path: websites/domain/file.zip
        $url           = "{$s3_settings_for_helpers['base_url']}/{$key}?uploads=";
        $canonical_uri = "/{$key}";

        error_log("Govtech Backup: Initiating multipart upload");
        error_log("  Full Key: " . $key);
        error_log("  URL: " . $url);
        error_log("  Canonical URI: " . $canonical_uri);

        $payload_hash = hash('sha256', '');

        // Use the same signature method that works for single upload
        $auth_data = $this->create_multipart_s3_signature($s3_settings_for_helpers, 'POST', $canonical_uri, 'uploads', $payload_hash);

        error_log("Govtech Backup: Authorization header: " . $auth_data['authorization']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        // Use CUSTOMREQUEST instead of POST to avoid chunked encoding
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$auth_data['authorization']}",
            "x-amz-date: {$auth_data['timestamp']}",
            "x-amz-content-sha256: {$auth_data['payload_hash']}",
            "Content-Type: application/octet-stream",
            "Content-Length: 0",
            "Expect:", // Explicitly set empty Expect header to prevent 100-continue
        ]);
        // Set empty post fields to ensure Content-Length: 0 is respected
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        error_log("Govtech Backup: Multipart init response code: " . $http_code);
        if (! empty($response)) {
            error_log("Govtech Backup: Multipart init response: " . substr($response, 0, 500));
        }

        if ($http_code >= 200 && $http_code < 300 && ! empty($response)) {
            $xml = simplexml_load_string($response);
            if ($xml && isset($xml->UploadId)) {
                $upload_id = (string) $xml->UploadId;
                error_log("Govtech Backup: Successfully initiated multipart upload with ID: " . $upload_id);
                return $upload_id;
            }
        }

        return false;
    }

    private function upload_multipart_chunk($s3_settings_for_helpers, $key, $upload_id, $part_number, $chunk_data)
    {
        // Use the full key as-is, matching the single upload pattern
        $query_string  = "partNumber={$part_number}&uploadId=" . urlencode($upload_id);
        $url           = "{$s3_settings_for_helpers['base_url']}/{$key}?{$query_string}";
        $canonical_uri = "/{$key}";

        $payload_hash = hash('sha256', $chunk_data);
        $auth_data    = $this->create_multipart_s3_signature($s3_settings_for_helpers, 'PUT', $canonical_uri, $query_string, $payload_hash);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$auth_data['authorization']}",
            "x-amz-date: {$auth_data['timestamp']}",
            "x-amz-content-sha256: {$auth_data['payload_hash']}",
            "Content-Type: application/octet-stream",
            "Content-Length: " . strlen($chunk_data),
            "Expect:", // Prevent 100-continue
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $response, $matches)) {
                $etag = trim($matches[1], '"');
                error_log("Govtech Backup: Successfully uploaded part {$part_number} with ETag: " . $etag);
                return $etag;
            }
        }

        error_log("Govtech Backup: Failed to upload part {$part_number}. HTTP: {$http_code}");
        if (! empty($response)) {
            error_log("Govtech Backup: Response: " . substr($response, 0, 500));
        }
        return false;
    }

    private function complete_multipart_upload($s3_settings_for_helpers, $key, $upload_id, $parts)
    {
        // Use the full key as-is, matching the single upload pattern
        $query_string  = "uploadId=" . urlencode($upload_id);
        $url           = "{$s3_settings_for_helpers['base_url']}/{$key}?{$query_string}";
        $canonical_uri = "/{$key}";

        $xml_parts = '';
        foreach ($parts as $part) {
            // Ensure ETag is properly quoted
            $etag = trim($part['ETag'], '"');
            $xml_parts .= "<Part><PartNumber>{$part['PartNumber']}</PartNumber><ETag>\"{$etag}\"</ETag></Part>";
        }
        $xml_payload = "<CompleteMultipartUpload>{$xml_parts}</CompleteMultipartUpload>";

        error_log("Govtech Backup: Completing multipart upload with " . count($parts) . " parts");

        $payload_hash = hash('sha256', $xml_payload);
        $auth_data    = $this->create_multipart_s3_signature($s3_settings_for_helpers, 'POST', $canonical_uri, $query_string, $payload_hash);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$auth_data['authorization']}",
            "x-amz-date: {$auth_data['timestamp']}",
            "x-amz-content-sha256: {$auth_data['payload_hash']}",
            "Content-Type: application/xml",
            "Content-Length: " . strlen($xml_payload),
            "Expect:", // Prevent 100-continue
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            error_log("Govtech Backup: Successfully completed multipart upload");
            return true;
        } else {
            error_log("Govtech Backup: Failed to complete multipart upload. HTTP: {$http_code}");
            if (! empty($response)) {
                error_log("Govtech Backup: Response: " . substr($response, 0, 500));
            }
            return false;
        }
    }

    private function abort_multipart_upload($s3_settings_for_helpers, $key, $upload_id)
    {
        // Use the full key as-is, matching the single upload pattern
        $query_string  = "uploadId=" . urlencode($upload_id);
        $url           = "{$s3_settings_for_helpers['base_url']}/{$key}?{$query_string}";
        $canonical_uri = "/{$key}";

        error_log("Govtech Backup: Aborting multipart upload ID: " . $upload_id);

        $payload_hash = hash('sha256', '');
        $auth_data    = $this->create_multipart_s3_signature($s3_settings_for_helpers, 'DELETE', $canonical_uri, $query_string, $payload_hash);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$auth_data['authorization']}",
            "x-amz-date: {$auth_data['timestamp']}",
            "x-amz-content-sha256: {$auth_data['payload_hash']}",
            "Content-Length: 0",
            "Expect:", // Prevent 100-continue
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ''); // Empty body for DELETE
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            error_log("Govtech Backup: Successfully aborted multipart upload");
        } else {
            error_log("Govtech Backup: Failed to abort multipart upload. HTTP: {$http_code}");
        }
    }

    /**
     * List backups from S3 using details from license
     */
    public function list_s3_backups()
    {
        error_log("Govtech Backup: S3 listing function called");

        if (empty($this->s3_config)) {
            $this->check_license(true);if (empty($this->s3_config)) {
                return ['success' => false, 'message' => __('S3 configuration not available.', 'govtech-backup'), 'backups' => []];
            }
        }

        $access_key = $this->s3_config['access_key'] ?? '';
        $secret_key = $this->s3_config['secret_key'] ?? '';
        $bucket     = $this->s3_config['bucket'] ?? '';
        $region     = $this->s3_config['region'] ?? 'us-east-1';
        $endpoint   = $this->s3_config['endpoint'] ?? '';
        $path       = $bucket . '/' . preg_replace('#^https?://#', '', rtrim(site_url(), '/')) . '/';
        $path       = trailingslashit($path);

        if (empty($access_key) || empty($secret_key) || empty($bucket)) {
            return ['success' => false, 'message' => __('Incomplete S3 configuration.', 'govtech-backup'), 'backups' => []];
        }

        $base_url = ! empty($endpoint) ? rtrim($endpoint, '/') : "https://{$bucket}.s3.{$region}.amazonaws.com";
        if (strpos($base_url, 'http') !== 0) {
            $base_url = 'https://' . $base_url;
        }

        $s3_settings_for_helpers = [
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'bucket'     => $bucket,
            'region'     => $region,
            'endpoint'   => $endpoint,
            'path'       => $path,
            'base_url'   => $base_url,
        ];

        $directorypath         = preg_replace('#^https?://#', '', rtrim(site_url(), '/')) . '/';
        $canonical_querystring = 'list-type=2&prefix=' . urlencode($directorypath);

        error_log("Govtech Backup: S3 List Query String: " . $canonical_querystring);
        $canonical_uri = "/{$bucket}/";
        $auth_data     = $this->create_s3_signature($s3_settings_for_helpers, 'GET', $canonical_uri, $canonical_querystring);

        $url = "{$base_url}/{$bucket}/?{$canonical_querystring}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$auth_data['authorization']}",
            "x-amz-date: {$auth_data['timestamp']}",
            "x-amz-content-sha256: {$auth_data['payload_hash']}"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        error_log("Govtech Backup: S3 List Response HTTP code: " . $http_code);
        if (! empty($error)) {
            error_log("Govtech Backup: S3 List cURL error: " . $error);
        }

        if ($http_code >= 200 && $http_code < 300) {

            $previous_entity_loader_state = libxml_disable_entity_loader(true);
            $xml                          = simplexml_load_string($response);
            libxml_disable_entity_loader($previous_entity_loader_state);

            if (! $xml) {
                error_log("Govtech Backup: Failed to parse S3 list XML response.");
                return ['success' => false, 'message' => __('Error parsing S3 response.', 'govtech-backup'), 'backups' => []];
            }

            $backups = [];
            if (isset($xml->Contents)) {
                error_log("Govtech Backup: S3 returned " . count($xml->Contents) . " items in Contents.");
                foreach ($xml->Contents as $content) {
                    $key = (string) $content->Key;

                    if ($key === $path || substr($key, -1) === '/' || ! preg_match('/\.zip$/i', $key)) {
                        continue;
                    }

                    $filename      = basename($key);
                    $size          = (int) $content->Size;
                    $last_modified = (string) $content->LastModified;

                    $backups[] = [
                        'filename' => $filename,
                        'size'     => size_format($size),
                        'date_raw' => strtotime($last_modified), 'date_display' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_modified)),

                        's3_key'   => $key];
                }

                usort($backups, function ($a, $b) {
                    return $b['date_raw'] - $a['date_raw'];
                });

                $this->backups = $backups;
                error_log("Govtech Backup: Found " . count($backups) . " valid backup files in S3.");
            } else {
                error_log("Govtech Backup: No 'Contents' element found in S3 list response.");
            }

            return [
                'success' => true,
                'message' => '',
                'backups' => $backups,
            ];
        } else {
            $error_message = $this->handle_s3_error($response, $http_code, $error);
            error_log("Govtech Backup: S3 List error: " . $error_message);
            return [
                'success' => false,
                'message' => $error_message,
                'backups' => [],
            ];
        }
    }

    /**
     * Delete backup from S3
     */
    public function delete_s3_backup($key)
    {

        if (empty($this->s3_config)) {
            $this->check_license(true);
            if (empty($this->s3_config)) {
                return ['success' => false, 'message' => __('S3 configuration not available.', 'govtech-backup')];
            }
        }

        $access_key = $this->s3_config['access_key'] ?? '';
        $secret_key = $this->s3_config['secret_key'] ?? '';
        $bucket     = $this->s3_config['bucket'] ?? '';
        $region     = $this->s3_config['region'] ?? 'us-east-1';
        $endpoint   = $this->s3_config['endpoint'] ?? '';

        if (empty($access_key) || empty($secret_key) || empty($bucket) || empty($key)) {
            return ['success' => false, 'message' => __('Missing required parameters for S3 deletion.', 'govtech-backup')];
        }

        $base_url = ! empty($endpoint) ? rtrim($endpoint, '/') : "https://{$bucket}.s3.{$region}.amazonaws.com";
        if (strpos($base_url, 'http') !== 0) {
            $base_url = 'https://' . $base_url;
        }

        $s3_settings_for_helpers = [
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'bucket'     => $bucket,
            'region'     => $region,
            'endpoint'   => $endpoint,
            'base_url'   => $base_url,
        ];

        $url           = "{$base_url}/{$bucket}/{$key}";
        $canonical_uri = "/{$bucket}/{$key}";

        error_log("Govtech Backup: Delete URL: {$url}");
        error_log("Govtech Backup: Delete Canonical URI: {$canonical_uri}");
        error_log("Govtech Backup: Delete Key: {$key}");
        error_log("Govtech Backup: Delete Bucket: {$bucket}");
        error_log("Govtech Backup: Delete Endpoint: {$endpoint}");
        error_log("Govtech Backup: Delete Base URL: {$base_url}");

        $auth_data = $this->create_s3_signature($s3_settings_for_helpers, 'DELETE', $canonical_uri, '', '');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$auth_data['authorization']}",
            "x-amz-date: {$auth_data['timestamp']}",
            "x-amz-content-sha256: {$auth_data['payload_hash']}",
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            error_log("Govtech Backup: Successfully deleted S3 object: " . $key);
            return [
                'success' => true,
                'message' => sprintf(__('Backup deleted from S3: %s', 'govtech-backup'), basename($key)),
            ];
        } else {
            $error_message = $this->handle_s3_error($response, $http_code, $error, sprintf(__('Failed to delete %s from S3: ', 'govtech-backup'), basename($key)));
            error_log("Govtech Backup: S3 Delete error: " . $error_message . " (HTTP: " . $http_code . ")");
            error_log("Govtech Backup: S3 Delete response body: " . substr($response, 0, 500));
            return [
                'success' => false,
                'message' => $error_message,
            ];
        }
    }

    /**
     * Enhanced delete with better error handling
     */
    private function delete_s3_backup_with_retry($key, $max_retries = 2)
    {
        $attempt = 0;
        while ($attempt < $max_retries) {
            $result = $this->delete_s3_backup($key);
            if ($result['success']) {
                return $result;
            }

            $attempt++;
            if ($attempt < $max_retries) {
                error_log("Govtech Backup: Delete attempt {$attempt} failed for {$key}, retrying...");
                sleep(1);
            }
        }

        return $result;
    }

    /**
     * Create AWS signature v4 for S3 requests (Helper) - UPDATED
     */
    private function create_s3_signature($s3_settings, $method, $canonical_uri, $canonical_querystring = '', $payload_hash = '')
    {
        $access_key = $s3_settings['access_key'];
        $secret_key = $s3_settings['secret_key'];
        $region     = $s3_settings['region'];
        $base_url   = $s3_settings['base_url'];

        $timestamp = gmdate('Ymd\THis\Z');
        $date      = gmdate('Ymd');

        if (empty($payload_hash)) {
            $payload_hash = hash('sha256', '');
        }

        $host = parse_url($base_url, PHP_URL_HOST);

        $canonical_headers_str = "host:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$timestamp}\n";
        $signed_headers_str    = 'host;x-amz-content-sha256;x-amz-date';

        $canonical_request = "{$method}\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers_str}\n{$signed_headers_str}\n{$payload_hash}";

        $algorithm        = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$date}/{$region}/s3/aws4_request";
        $string_to_sign   = "{$algorithm}\n{$timestamp}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

        $k_date    = hash_hmac('sha256', $date, "AWS4{$secret_key}", true);
        $k_region  = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $authorization = "{$algorithm} Credential={$access_key}/{$credential_scope}, SignedHeaders={$signed_headers_str}, Signature={$signature}";
        return [
            'authorization'    => $authorization,
            'timestamp'        => $timestamp,
            'date'             => $date,
            'signed_headers'   => $signed_headers_str,
            'credential_scope' => $credential_scope,
            'algorithm'        => $algorithm,
            'signature'        => $signature,
            'payload_hash'     => $payload_hash,
        ];
    }

    private function create_multipart_s3_signature(
        array $cfg,
        string $method,
        string $uri,
        string $qs = '',
        string $payload = '',
        array $extraHeaders = []
    ) {
        // ── Canonicalise the query-string exactly as AWS expects ──
        if ($qs !== '') {
            parse_str($qs, $p);
            ksort($p); // alphabetic sort
            $enc = [];
            foreach ($p as $k => $v) {
                $enc[] = rawurlencode($k) . '=' . rawurlencode($v);
            }
            $qs = implode('&', $enc); // canonical form
        } else {
            $qs = '';
        }

        // ── Timestamps ──
        $amzTime = gmdate('Ymd\THis\Z');
        $date    = gmdate('Ymd');

        // ── Payload hash ──
        if ($payload === '') {
            $payloadHash = hash('sha256', '');
        } elseif ($payload === 'UNSIGNED-PAYLOAD') {
            $payloadHash = 'UNSIGNED-PAYLOAD';
        } else {
            // If caller already sent a 64-char SHA-256 hash, use it as-is
            $payloadHash = (strlen($payload) === 64 && ctype_xdigit($payload))
            ? $payload
            : hash('sha256', $payload);
        }

        // ── Canonical / signed headers ──
        $host         = parse_url($cfg['base_url'], PHP_URL_HOST);
        $canonHeaders = "host:{$host}\n"
            . "x-amz-content-sha256:{$payloadHash}\n"
            . "x-amz-date:{$amzTime}\n";

        $signedHeaders = ['host', 'x-amz-content-sha256', 'x-amz-date'];

        // include any extra x-amz-* headers that were passed in
        foreach ($extraHeaders as $k => $v) {
            $lk = strtolower($k);
            if (strpos($lk, 'x-amz-') === 0 &&
                $lk !== 'x-amz-date' &&
                $lk !== 'x-amz-content-sha256') {

                $canonHeaders .= "{$lk}:{$v}\n";
                $signedHeaders[] = $lk;
            }
        }
        sort($signedHeaders);
        $signedHeadersStr = implode(';', $signedHeaders);

        // ── Canonical request & string-to-sign ──
        $canonicalRequest = "{$method}\n{$uri}\n{$qs}\n"
            . "{$canonHeaders}\n{$signedHeadersStr}\n{$payloadHash}";

        $scope        = "{$date}/{$cfg['region']}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzTime}\n{$scope}\n"
        . hash('sha256', $canonicalRequest);

        // ── Signing key ──
        $kDate    = hash_hmac('sha256', $date, "AWS4{$cfg['secret_key']}", true);
        $kRegion  = hash_hmac('sha256', $cfg['region'], $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // ── Finished auth header ──
        $authorization =
            "AWS4-HMAC-SHA256 Credential={$cfg['access_key']}/{$scope}, "
            . "SignedHeaders={$signedHeadersStr}, Signature={$signature}";

        return [
            'authorization' => $authorization,
            'timestamp'     => $amzTime,
            'payload_hash'  => $payloadHash,
            'signature'     => $signature,
        ];
    }

    /**
     * Handle S3 API error response (Helper)
     */
    private function handle_s3_error($response, $http_code, $error, $error_prefix = '')
    {
        $error_message = ! empty($error) ? $error : "HTTP Error: {$http_code}";

        if (! empty($response) && strpos($response, '<?xml') !== false) {

            $previous_entity_loader_state = libxml_disable_entity_loader(true);
            $xml                          = @simplexml_load_string($response);
            libxml_disable_entity_loader($previous_entity_loader_state);

            if ($xml && isset($xml->Message)) {
                $error_message = (string) $xml->Message;
            } elseif ($xml && isset($xml->Code)) {

                $error_message = (string) $xml->Code;
            }
        }

        return $error_prefix . $error_message;
    }

    public function get_license_status()
    {
        return $this->license_valid;
    }
    public function get_license_expiry()
    {
        return $this->license_expiry;
    }
    public function get_s3_config()
    {

        if ($this->s3_config) {
            return [
                'bucket'     => $this->s3_config['bucket'] ?? 'N/A',
                'region'     => $this->s3_config['region'] ?? 'N/A',
                'endpoint'   => $this->s3_config['endpoint'] ?? 'N/A',
                'path'       => $this->s3_config['path'] ?? 'N/A',
                'access_key' => isset($this->s3_config['access_key']) ? substr($this->s3_config['access_key'], 0, 4) . '...' : 'N/A',
            ];
        }
        return null;
    }

    /**
     * Downloads an S3 object to a temporary file, streams it to the client, and cleans up.
     * Handles authentication and download headers.
     *
     * @param string $key The S3 object key to download.
     */
    public function stream_s3_backup_to_client($key)
    {

        if (empty($this->s3_config)) {
            $this->check_license(true);if (empty($this->s3_config)) {
                wp_die(__('S3 configuration not available for download.', 'govtech-backup'), __('Download Error', 'govtech-backup'), 404);
            }
        }

        $access_key = $this->s3_config['access_key'] ?? '';
        $secret_key = $this->s3_config['secret_key'] ?? '';
        $bucket     = $this->s3_config['bucket'] ?? '';
        $region     = $this->s3_config['region'] ?? 'us-east-1';
        $endpoint   = $this->s3_config['endpoint'] ?? '';

        if (empty($access_key) || empty($secret_key) || empty($bucket) || empty($key)) {
            wp_die(__('Incomplete S3 configuration or missing key for download.', 'govtech-backup'), __('Download Error', 'govtech-backup'), 400);
        }

        $base_url = ! empty($endpoint) ? rtrim($endpoint, '/') : "https://{$bucket}.s3.{$region}.amazonaws.com";
        if (strpos($base_url, 'http') !== 0) {
            $base_url = 'https://' . $base_url;
        }

        $url = "{$base_url}/{$bucket}/{$key}";

        $s3_settings_for_helpers = [
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'bucket'     => $bucket,
            'region'     => $region,
            'endpoint'   => $endpoint,
            'base_url'   => $base_url,
        ];

        $canonical_uri = "/{$bucket}/{$key}";
        error_log("Govtech Backup: S3 Download Canonical URI: {$canonical_uri}");

        $auth_data = $this->create_s3_signature($s3_settings_for_helpers, 'GET', $canonical_uri);

        error_log("Govtech Backup: S3 Download URL: " . $url);
        error_log("Govtech Backup: S3 Download Auth Headers: " . print_r([
            'Authorization' => $auth_data['authorization'],
            'x-amz-date'    => $auth_data['timestamp'],
        ], true));
        error_log("Govtech Backup: S3 Download S3 Settings: " . print_r($s3_settings_for_helpers, true));

        $temp_file_path = wp_tempnam($key);
        if (! $temp_file_path) {
            error_log("Govtech Backup: Failed to create temporary file for download.");
            wp_die(__('Could not create temporary file for download.', 'govtech-backup'), __('Download Error', 'govtech-backup'), 500);
        }

        register_shutdown_function('unlink', $temp_file_path);

        error_log("Govtech Backup: Attempting to download S3 object '{$key}' to '{$temp_file_path}'");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$auth_data['authorization']}",
            "x-amz-date: {$auth_data['timestamp']}",
            "x-amz-content-sha256: {$auth_data['payload_hash']}",
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $fp = fopen($temp_file_path, 'w+');
        if (! $fp) {
            curl_close($ch);
            wp_die(__('Could not open temporary file for writing.', 'govtech-backup'), __('Download Error', 'govtech-backup'), 500);
        }

        curl_setopt($ch, CURLOPT_FILE, $fp);

        $result    = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);

        fclose($fp);
        curl_close($ch);

        if ($result === false) {
            error_log("Govtech Backup: Error downloading S3 file '{$key}': " . $error);
            @unlink($temp_file_path);
            wp_die(sprintf(__('Error downloading backup from S3: %s', 'govtech-backup'), $error), __('Download Error', 'govtech-backup'), 500);
        }

        if ($http_code < 200 || $http_code >= 300) {
            error_log("Govtech Backup: Error downloading S3 file '{$key}'. HTTP Code: {$http_code}");
            @unlink($temp_file_path);
            wp_die(sprintf(__('Error downloading backup from S3 (HTTP %s)', 'govtech-backup'), $http_code), __('Download Error', 'govtech-backup'), 500);
        }

        if (! file_exists($temp_file_path) || filesize($temp_file_path) === 0) {
            error_log("Govtech Backup: S3 download seemed successful (HTTP {$http_code}) but temporary file '{$temp_file_path}' is missing or empty.");
            @unlink($temp_file_path);
            wp_die(__('Downloaded backup file is missing or empty on the server.', 'govtech-backup'), __('Download Error', 'govtech-backup'), 500);
        }

        $filename = basename($key);
        $filesize = filesize($temp_file_path);

        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Pragma: public');
        header('Content-Length: ' . $filesize);

        if (ob_get_level()) {
            ob_end_clean();
        }

        $readfile_result = readfile($temp_file_path);

        if ($readfile_result === false) {
            error_log("Govtech Backup: readfile() failed for temporary file: " . $temp_file_path);
            @unlink($temp_file_path);
        }

        exit;
    }
}
