<?php
/**
 * Handles checking for updates for the Govtech Backup plugin from a custom URL.
 *
 * @package Govtech_Backup
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Govtech_Backup_Updater {

    private $plugin_file;     // Full path to the main plugin file
    private $plugin_slug;     // Plugin slug (e.g., govtech_backup/govtech_backup.php)
    private $current_version; // Current installed version
    private $update_url;      // URL to the update-info.json file
    private $license_valid;   // Boolean indicating if the license is currently valid
    private $transient_key;   // Unique key for caching update checks

    /**
     * Constructor.
     *
     * @param string $plugin_file     Path to the main plugin file.
     * @param string $update_url      URL for the update JSON file.
     * @param string $version         Current plugin version.
     * @param bool   $license_valid   Current license status.
     */
    public function __construct($plugin_file, $update_url, $version, $license_valid) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->update_url = $update_url;
        $this->current_version = $version;
        $this->license_valid = $license_valid;
        // Generate a unique transient key based on the slug
        $this->transient_key = 'govtech_update_' . md5($this->plugin_slug);

        // Hook into the necessary WordPress filters
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_api_info'), 10, 3);

        // Optional: Clear transient on demand via admin action? (Not implemented here)
    }

    /**
     * Fetches update information from the remote URL and caches it.
     *
     * @return object|false Plugin update information object or false on failure.
     */
    private function get_remote_info() {
        // Check cache first
        $remote_info = get_site_transient($this->transient_key);
        if (false !== $remote_info) {
            // Ensure cached data is an object before returning
            return is_object($remote_info) ? $remote_info : false;
        }

        // Fetch from remote URL
        $response = wp_remote_get($this->update_url, array(
            'timeout'   => 15,
            'sslverify' => false // Set to true in production if possible
        ));

        // Handle fetch errors
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $error_message = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
            error_log('Govtech Backup Updater: Failed to fetch update info from ' . $this->update_url . ' - ' . $error_message);
            set_site_transient($this->transient_key, (object)['error' => true], MINUTE_IN_SECONDS * 5); // Cache failure briefly
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $info = json_decode($body);

        // Validate received data
        if (!is_object($info) || !isset($info->version) || !isset($info->download_url)) {
            error_log('Govtech Backup Updater: Invalid update info structure received from ' . $this->update_url);
            set_site_transient($this->transient_key, (object)['error' => true], MINUTE_IN_SECONDS * 5);
            return false;
        }
        // Basic validation of download URL format
        if (filter_var($info->download_url, FILTER_VALIDATE_URL) === false) {
             error_log('Govtech Backup Updater: Invalid download_url format received: ' . $info->download_url);
             set_site_transient($this->transient_key, (object)['error' => true], MINUTE_IN_SECONDS * 5);
             return false;
        }


        // Prepare info in the format WordPress expects for the transient/API
        // This structure matches the object WP puts in $transient->response
        $plugin_info = new stdClass();
        $plugin_info->slug = dirname($this->plugin_slug); // e.g., 'govtech_backup'
        $plugin_info->plugin = $this->plugin_slug;       // e.g., 'govtech_backup/govtech_backup.php'
        $plugin_info->new_version = $info->version;
        $plugin_info->url = isset($info->homepage) ? esc_url($info->homepage) : ''; // Plugin homepage URL
        $plugin_info->package = esc_url($info->download_url); // The ZIP file URL

        // Optional fields from JSON
        $plugin_info->icons = isset($info->icons) && is_object($info->icons) ? (array)$info->icons : array();
        $plugin_info->banners = isset($info->banners) && is_object($info->banners) ? (array)$info->banners : array();
        $plugin_info->banners_rtl = isset($info->banners_rtl) && is_object($info->banners_rtl) ? (array)$info->banners_rtl : array();
        $plugin_info->tested = isset($info->tested) ? sanitize_text_field($info->tested) : ''; // Tested up to WP version
        $plugin_info->requires = isset($info->requires) ? sanitize_text_field($info->requires) : ''; // Requires WP version
        $plugin_info->requires_php = isset($info->requires_php) ? sanitize_text_field($info->requires_php) : ''; // Requires PHP version
        $plugin_info->author = isset($info->author) ? wp_kses_post($info->author) : ''; // Allow basic HTML in author field
        $plugin_info->author_profile = isset($info->author_profile) ? esc_url($info->author_profile) : '';
        $plugin_info->last_updated = isset($info->last_updated) ? sanitize_text_field($info->last_updated) : '';
        $plugin_info->added = isset($info->added) ? sanitize_text_field($info->added) : '';

        // Sections (description, installation, changelog etc.)
        $plugin_info->sections = array();
        if (isset($info->sections) && is_object($info->sections)) {
            foreach (get_object_vars($info->sections) as $key => $value) {
                // Sanitize section content allowing basic HTML
                $plugin_info->sections[sanitize_key($key)] = wp_kses_post($value);
            }
        }
        // Ensure essential sections exist even if empty
        if (!isset($plugin_info->sections['description'])) $plugin_info->sections['description'] = '';
        if (!isset($plugin_info->sections['changelog'])) $plugin_info->sections['changelog'] = '';


        // Cache the successfully fetched and formatted info (e.g., for 12 hours)
        set_site_transient($this->transient_key, $plugin_info, HOUR_IN_SECONDS * 12);

        return $plugin_info;
    }

    /**
     * Hooks into the 'pre_set_site_transient_update_plugins' filter.
     * Checks if a new version of our plugin is available and injects it into the transient.
     *
     * @param object $transient The update transient object.
     * @return object The modified transient object.
     */
    public function check_for_update($transient) {
        // Check if the transient has the 'checked' property, indicating WP is checking
        if (!is_object($transient) || !isset($transient->checked)) {
            return $transient;
        }

        // Optionally, skip check if license is invalid
        // if (!$this->license_valid) {
        //     return $transient;
        // }

        $remote_info = $this->get_remote_info();

        // Check if remote info was fetched successfully and if a newer version exists
        if ($remote_info && !isset($remote_info->error) && version_compare($this->current_version, $remote_info->new_version, '<')) {
            // Inject our update information into the transient's response array
            // The key must be the plugin slug (e.g., 'my-plugin/my-plugin.php')
            $transient->response[$this->plugin_slug] = $remote_info;
        } else {
            // Ensure our plugin isn't listed if no update is available or check failed
            // This prevents stale data from causing issues
            if (isset($transient->response[$this->plugin_slug])) {
                unset($transient->response[$this->plugin_slug]);
            }
        }

        return $transient;
    }

    /**
     * Hooks into the 'plugins_api' filter.
     * Provides plugin details for the "View Details" popup.
     *
     * @param false|object|array $result The result object or array. Default false.
     * @param string             $action The API action being requested.
     * @param object             $args   Arguments passed to the plugins_api function.
     * @return false|object      The plugin information object or false on failure.
     */
    public function plugin_api_info($result, $action, $args) {
        // Check if this request is for plugin information and for our plugin slug
        if ($action !== 'plugin_information' || !isset($args->slug) || dirname($this->plugin_slug) !== $args->slug) {
            return $result; // Return original result (likely false)
        }

        $remote_info = $this->get_remote_info();

        if ($remote_info && !isset($remote_info->error)) {
            // Prepare the response object in the format required by plugins_api filter
            // Most fields are already prepared in get_remote_info()
            $res = new stdClass();
            $res->name = isset($remote_info->name) ? $remote_info->name : 'Govtech Backup'; // Use name from info or default
            $res->slug = $remote_info->slug;
            $res->version = $remote_info->new_version;
            $res->author = $remote_info->author;
            $res->author_profile = $remote_info->author_profile;
            $res->requires = $remote_info->requires;
            $res->tested = $remote_info->tested;
            $res->requires_php = $remote_info->requires_php;
            $res->rating = null; // Not applicable usually
            $res->num_ratings = 0;
            $res->support_threads = 0;
            $res->support_threads_resolved = 0;
            $res->active_installs = null; // Not applicable
            $res->downloaded = null; // Not applicable
            $res->last_updated = $remote_info->last_updated;
            $res->added = $remote_info->added;
            $res->homepage = $remote_info->homepage;
            $res->sections = (array)$remote_info->sections; // Ensure sections is an array
            $res->download_link = $remote_info->package;
            $res->banners = (array)$remote_info->banners; // Ensure banners is an array
            $res->icons = (array)$remote_info->icons;     // Ensure icons is an array

            return $res;
        }

        // Return false if unable to get remote info
        return false;
    }
}
