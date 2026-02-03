<?php
/**
 * Plugin updater class for WONS plugins
 */
class WONS_Plugin_Updater {
    private $plugin_file;      // Path to the plugin file
    private $api_url;          // URL to check for updates
    private $plugin_data;      // Plugin data
    private $license_key;      // License key
    private $item_name;        // Plugin name
    private $slug;             // Plugin slug
    private $version;          // Current plugin version

    /**
     * Class constructor
     */
    public function __construct($plugin_file, $api_url, $args = array()) {
        $this->plugin_file = $plugin_file;
        $this->api_url = $api_url;
        
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data($plugin_file);
        
        $this->version = $plugin_data['Version'];
        $this->slug = plugin_basename($plugin_file);
        $this->item_name = isset($args['item_name']) ? $args['item_name'] : $plugin_data['Name'];
        $this->license_key = isset($args['license_key']) ? $args['license_key'] : '';

        // Set up hooks
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    /**
     * Check for plugin updates
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get update info from API
        $info = $this->get_update_info();
        
        // If there's a newer version, add it to the transient
        if (isset($info->version) && version_compare($this->version, $info->version, '<')) {
            $plugin_data = new stdClass();
            $plugin_data->slug = $this->slug;
            $plugin_data->plugin = $this->slug;
            $plugin_data->new_version = $info->version;
            $plugin_data->url = isset($info->url) ? $info->url : '';
            $plugin_data->package = isset($info->download_url) ? $info->download_url : '';
            $plugin_data->tested = isset($info->tested) ? $info->tested : '';
            $plugin_data->icons = isset($info->icons) ? (array) $info->icons : array();
            
            $transient->response[$this->slug] = $plugin_data;
        }
        
        return $transient;
    }

    /**
     * Get plugin information for the updates panel
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        
        if (isset($args->slug) && $args->slug === $this->slug) {
            $info = $this->get_update_info();
            
            if ($info) {
                $result = new stdClass();
                $result->name = $this->item_name;
                $result->slug = $this->slug;
                $result->version = $info->version;
                $result->tested = isset($info->tested) ? $info->tested : '';
                $result->requires = isset($info->requires) ? $info->requires : '';
                $result->author = isset($info->author) ? $info->author : '';
                $result->author_profile = isset($info->author_profile) ? $info->author_profile : '';
                $result->download_link = isset($info->package) ? $info->package : '';
                $result->trunk = isset($info->package) ? $info->package : '';
                $result->requires_php = isset($info->requires_php) ? $info->requires_php : '';
                $result->last_updated = isset($info->last_updated) ? $info->last_updated : '';
                
                if (isset($info->sections)) {
                    $result->sections = (array) $info->sections;
                } else {
                    $result->sections = array(
                        'description' => isset($info->description) ? $info->description : '',
                        'changelog' => isset($info->changelog) ? $info->changelog : ''
                    );
                }
                
                if (isset($info->banners)) {
                    $result->banners = (array) $info->banners;
                }
                
                return $result;
            }
        }
        
        return $result;
    }

    /**
     * After installation is complete, ensure plugin files retain original name
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->slug) {
            return $response;
        }
        
        // Get the plugin directory name from the plugin file
        $plugin_dir = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $plugin_dir);
        $result['destination'] = $plugin_dir;
        
        return $result;
    }

    /**
     * Get update information from the API server
     */
    private function get_update_info() {
        // Prepare the data
        $api_params = array(
            'action'      => 'get_plugin_info',
            'plugin_name' => $this->item_name,
            'version'     => $this->version,
            'license_key' => $this->license_key,
            'site_url'    => home_url()
        );
        
        // Make the API call
        $response = wp_remote_post($this->api_url, array(
            'timeout'   => 15,
            'sslverify' => false,
            'body'      => $api_params
        ));
        
        // Check for error
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        // Parse the response
        $info = json_decode(wp_remote_retrieve_body($response));
        
        return $info;
    }
}