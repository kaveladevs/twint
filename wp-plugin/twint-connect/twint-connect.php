<?php
/**
 * Plugin Name: Twint Connect
 * Plugin URI: https://github.com/twintproject/twint
 * Description: Connect to a remote Twint API server to search and display tweets in WordPress.
 * Version: 1.0.0
 * Author: Twint Project
 * License: MIT
 * Text Domain: twint-connect
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TWINT_CONNECT_VERSION', '1.0.0');
define('TWINT_CONNECT_PATH', plugin_dir_path(__FILE__));
define('TWINT_CONNECT_URL', plugin_dir_url(__FILE__));

class Twint_Connect {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_ajax_twint_search', [$this, 'ajax_search']);
        add_action('wp_ajax_twint_lookup', [$this, 'ajax_lookup']);
        add_action('wp_ajax_twint_poll_job', [$this, 'ajax_poll_job']);
        add_shortcode('twint_feed', [$this, 'shortcode_feed']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
    }

    /**
     * Admin menu
     */
    public function admin_menu() {
        add_menu_page(
            __('Twint Connect', 'twint-connect'),
            __('Twint', 'twint-connect'),
            'manage_options',
            'twint-connect',
            [$this, 'admin_page'],
            'dashicons-twitter',
            30
        );

        add_submenu_page(
            'twint-connect',
            __('Search Tweets', 'twint-connect'),
            __('Search', 'twint-connect'),
            'manage_options',
            'twint-connect',
            [$this, 'admin_page']
        );

        add_submenu_page(
            'twint-connect',
            __('Settings', 'twint-connect'),
            __('Settings', 'twint-connect'),
            'manage_options',
            'twint-connect-settings',
            [$this, 'settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('twint_connect_settings', 'twint_api_url', [
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);
        register_setting('twint_connect_settings', 'twint_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
    }

    /**
     * Enqueue admin assets
     */
    public function admin_assets($hook) {
        if (strpos($hook, 'twint-connect') === false) {
            return;
        }
        wp_enqueue_style('twint-admin', TWINT_CONNECT_URL . 'assets/admin.css', [], TWINT_CONNECT_VERSION);
        wp_enqueue_script('twint-admin', TWINT_CONNECT_URL . 'assets/admin.js', ['jquery'], TWINT_CONNECT_VERSION, true);
        wp_localize_script('twint-admin', 'twintConnect', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('twint_connect_nonce'),
        ]);
    }

    /**
     * Frontend assets for shortcode
     */
    public function frontend_assets() {
        wp_enqueue_style('twint-front', TWINT_CONNECT_URL . 'assets/front.css', [], TWINT_CONNECT_VERSION);
    }

    /**
     * Make API request to remote Twint server
     */
    private function api_request($endpoint, $method = 'GET', $body = null) {
        $api_url = rtrim(get_option('twint_api_url', ''), '/');
        $api_key = get_option('twint_api_key', '');

        if (empty($api_url)) {
            return new WP_Error('no_api_url', __('Twint API URL is not configured.', 'twint-connect'));
        }

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'X-API-Key'   => $api_key,
                'Content-Type' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($api_url . $endpoint, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            return new WP_Error('auth_failed', __('API authentication failed. Check your API key.', 'twint-connect'));
        }

        if ($code >= 400) {
            $msg = isset($data['error']) ? $data['error'] : __('API request failed.', 'twint-connect');
            return new WP_Error('api_error', $msg);
        }

        return $data;
    }

    /**
     * AJAX: Search tweets
     */
    public function ajax_search() {
        check_ajax_referer('twint_connect_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'twint-connect'));
        }

        $params = [
            'search'       => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'username'     => sanitize_text_field(wp_unslash($_POST['username'] ?? '')),
            'limit'        => absint($_POST['limit'] ?? 20),
            'lang'         => sanitize_text_field(wp_unslash($_POST['lang'] ?? '')),
            'since'        => sanitize_text_field(wp_unslash($_POST['since'] ?? '')),
            'until'        => sanitize_text_field(wp_unslash($_POST['until'] ?? '')),
        ];

        // Remove empty params
        $params = array_filter($params, function($v) { return $v !== '' && $v !== 0; });

        $result = $this->api_request('/api/search', 'POST', $params);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Lookup user
     */
    public function ajax_lookup() {
        check_ajax_referer('twint_connect_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'twint-connect'));
        }

        $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
        if (empty($username)) {
            wp_send_json_error(__('Username is required.', 'twint-connect'));
        }

        $result = $this->api_request('/api/lookup', 'POST', ['username' => $username]);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Poll job status
     */
    public function ajax_poll_job() {
        check_ajax_referer('twint_connect_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'twint-connect'));
        }

        $job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
        if (empty($job_id)) {
            wp_send_json_error(__('Job ID is required.', 'twint-connect'));
        }

        $result = $this->api_request('/api/job/' . $job_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Twint Connect Settings', 'twint-connect'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('twint_connect_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="twint_api_url"><?php esc_html_e('API Server URL', 'twint-connect'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="twint_api_url" name="twint_api_url"
                                   value="<?php echo esc_attr(get_option('twint_api_url')); ?>"
                                   class="regular-text" placeholder="https://your-vps-ip:5000" />
                            <p class="description"><?php esc_html_e('The URL of your Twint API server running on VPS.', 'twint-connect'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="twint_api_key"><?php esc_html_e('API Key', 'twint-connect'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="twint_api_key" name="twint_api_key"
                                   value="<?php echo esc_attr(get_option('twint_api_key')); ?>"
                                   class="regular-text" />
                            <p class="description"><?php esc_html_e('The API key set on your VPS (TWINT_API_KEY env var).', 'twint-connect'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Connection Test', 'twint-connect'); ?></h2>
            <button id="twint-test-connection" class="button button-secondary">
                <?php esc_html_e('Test Connection', 'twint-connect'); ?>
            </button>
            <span id="twint-test-result"></span>
        </div>
        <?php
    }

    /**
     * Admin search page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Twint - Tweet Search', 'twint-connect'); ?></h1>

            <div class="twint-search-form">
                <table class="form-table">
                    <tr>
                        <th><label for="twint-search"><?php esc_html_e('Search Query', 'twint-connect'); ?></label></th>
                        <td><input type="text" id="twint-search" class="regular-text" placeholder="<?php esc_attr_e('keyword or hashtag', 'twint-connect'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="twint-username"><?php esc_html_e('Username', 'twint-connect'); ?></label></th>
                        <td><input type="text" id="twint-username" class="regular-text" placeholder="<?php esc_attr_e('@username (without @)', 'twint-connect'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="twint-limit"><?php esc_html_e('Limit', 'twint-connect'); ?></label></th>
                        <td><input type="number" id="twint-limit" value="20" min="1" max="1000" /></td>
                    </tr>
                    <tr>
                        <th><label for="twint-lang"><?php esc_html_e('Language', 'twint-connect'); ?></label></th>
                        <td><input type="text" id="twint-lang" class="small-text" placeholder="tr, en, ..." /></td>
                    </tr>
                    <tr>
                        <th><label for="twint-since"><?php esc_html_e('Since', 'twint-connect'); ?></label></th>
                        <td><input type="date" id="twint-since" /></td>
                    </tr>
                    <tr>
                        <th><label for="twint-until"><?php esc_html_e('Until', 'twint-connect'); ?></label></th>
                        <td><input type="date" id="twint-until" /></td>
                    </tr>
                </table>
                <p>
                    <button id="twint-do-search" class="button button-primary"><?php esc_html_e('Search Tweets', 'twint-connect'); ?></button>
                    <button id="twint-do-lookup" class="button button-secondary"><?php esc_html_e('Lookup User', 'twint-connect'); ?></button>
                    <span id="twint-status" class="twint-status"></span>
                </p>
            </div>

            <div id="twint-results" class="twint-results"></div>
        </div>
        <?php
    }

    /**
     * Shortcode: [twint_feed username="example" limit="10"]
     */
    public function shortcode_feed($atts) {
        $atts = shortcode_atts([
            'username' => '',
            'search'   => '',
            'limit'    => 10,
            'lang'     => '',
        ], $atts, 'twint_feed');

        // Build cache key
        $cache_key = 'twint_feed_' . md5(wp_json_encode($atts));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $params = array_filter([
            'search'   => $atts['search'],
            'username' => $atts['username'],
            'limit'    => (int) $atts['limit'],
            'lang'     => $atts['lang'],
        ], function($v) { return $v !== '' && $v !== 0; });

        // Start the search job
        $result = $this->api_request('/api/search', 'POST', $params);
        if (is_wp_error($result) || empty($result['job_id'])) {
            return '<p class="twint-error">' . esc_html__('Could not fetch tweets.', 'twint-connect') . '</p>';
        }

        // Poll for results (max 30 seconds)
        $job_id = $result['job_id'];
        $data = null;
        for ($i = 0; $i < 15; $i++) {
            sleep(2);
            $data = $this->api_request('/api/job/' . $job_id);
            if (!is_wp_error($data) && isset($data['status']) && $data['status'] !== 'running') {
                break;
            }
        }

        if (is_wp_error($data) || !isset($data['tweets'])) {
            return '<p class="twint-error">' . esc_html__('Tweet fetch timed out or failed.', 'twint-connect') . '</p>';
        }

        // Render tweets
        $html = '<div class="twint-feed">';
        foreach ($data['tweets'] as $tweet) {
            $html .= '<div class="twint-tweet">';
            $html .= '<div class="twint-tweet-header">';
            $html .= '<strong class="twint-name">' . esc_html($tweet['name']) . '</strong> ';
            $html .= '<span class="twint-username">@' . esc_html($tweet['username']) . '</span> ';
            $html .= '<span class="twint-date">' . esc_html($tweet['date']) . '</span>';
            $html .= '</div>';
            $html .= '<div class="twint-tweet-body">' . esc_html($tweet['tweet']) . '</div>';
            $html .= '<div class="twint-tweet-stats">';
            $html .= '<span>' . esc_html($tweet['likes_count']) . ' likes</span> &middot; ';
            $html .= '<span>' . esc_html($tweet['retweets_count']) . ' RT</span> &middot; ';
            $html .= '<span>' . esc_html($tweet['replies_count']) . ' replies</span>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        // Cache for 15 minutes
        set_transient($cache_key, $html, 15 * MINUTE_IN_SECONDS);

        // Clean up job
        $this->api_request('/api/job/' . $job_id, 'DELETE');

        return $html;
    }
}

// Initialize
Twint_Connect::instance();
