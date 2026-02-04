<?php
/**
 * Listmonk Distribution Destination
 *
 * Main class for Listmonk integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dist_Listmonk {

    private static $instance = null;
    private $api = null;
    private $option_name = 'parish_dist_listmonk_settings';
    private $lists_cache_key = 'parish_dist_listmonk_lists';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init() {
        // Register with core
        add_action('parish_dist_register_destinations', array($this, 'register_destination'));

        // Register post meta
        add_action('init', array($this, 'register_meta'));

        // Settings
        add_filter('parish_dist_settings_tabs', array($this, 'add_settings_tab'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('parish_dist_sanitize_settings', array($this, 'sanitize_settings'), 10, 2);

        // AJAX for testing connection
        add_action('wp_ajax_parish_dist_listmonk_test', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_parish_dist_listmonk_refresh_lists', array($this, 'ajax_refresh_lists'));
    }

    /**
     * Register as a distribution destination
     */
    public function register_destination($registry) {
        $registry->register('listmonk', array(
            'label' => __('Listmonk Newsletter', 'parish-dist-listmonk'),
            'settings_callback' => array($this, 'render_settings_tab'),
            'publish_callback' => array($this, 'publish'),
            'has_options' => true,
            'options_callback' => array($this, 'render_list_options'),
            'get_options_callback' => array($this, 'get_lists_for_editor'),
        ));
    }

    /**
     * Register post meta
     */
    public function register_meta() {
        register_post_meta('post', '_parish_dist_listmonk', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'boolean',
            'default' => false,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            },
        ));

        register_post_meta('post', '_parish_dist_listmonk_opts', array(
            'show_in_rest' => array(
                'schema' => array(
                    'type' => 'array',
                    'items' => array('type' => 'integer'),
                ),
            ),
            'single' => true,
            'type' => 'array',
            'default' => array(),
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            },
        ));

        register_post_meta('post', '_parish_dist_listmonk_id', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'integer',
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            },
        ));

        register_post_meta('post', '_parish_dist_listmonk_at', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            },
        ));
    }

    /**
     * Get settings
     */
    public function get_settings() {
        $settings = get_option($this->option_name, array());
        return wp_parse_args($settings, array(
            'url' => '',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'template_id' => 1,
            'default_lists' => array(),
        ));
    }

    /**
     * Get API instance
     */
    public function get_api() {
        if ($this->api === null) {
            $settings = $this->get_settings();
            $password = $settings['password'];

            // Decrypt if needed
            if (class_exists('Parish_Dist_Encryption') && Parish_Dist_Encryption::is_encrypted($password)) {
                $password = Parish_Dist_Encryption::decrypt($password);
            }

            $this->api = new Dist_Listmonk_API(
                $settings['url'],
                $settings['username'],
                $password
            );
        }
        return $this->api;
    }

    /**
     * Add settings tab
     */
    public function add_settings_tab($tabs) {
        $tabs['listmonk'] = array(
            'label' => __('Listmonk', 'parish-dist-listmonk'),
            'callback' => array($this, 'render_settings_tab'),
        );
        return $tabs;
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Register our own settings group for the Listmonk tab
        register_setting(
            'parish_dist_listmonk_group',
            $this->option_name,
            array($this, 'sanitize_own_settings')
        );

        add_settings_section(
            'parish_dist_listmonk',
            __('Listmonk Settings', 'parish-dist-listmonk'),
            array($this, 'render_section_description'),
            'parish-distribution-settings-listmonk'
        );

        add_settings_field(
            'listmonk_url',
            __('Listmonk URL', 'parish-dist-listmonk'),
            array($this, 'render_url_field'),
            'parish-distribution-settings-listmonk',
            'parish_dist_listmonk'
        );

        add_settings_field(
            'listmonk_credentials',
            __('API Credentials', 'parish-dist-listmonk'),
            array($this, 'render_credentials_fields'),
            'parish-distribution-settings-listmonk',
            'parish_dist_listmonk'
        );

        add_settings_field(
            'listmonk_from_email',
            __('From Email', 'parish-dist-listmonk'),
            array($this, 'render_from_email_field'),
            'parish-distribution-settings-listmonk',
            'parish_dist_listmonk'
        );

        add_settings_field(
            'listmonk_template',
            __('Email Template ID', 'parish-dist-listmonk'),
            array($this, 'render_template_field'),
            'parish-distribution-settings-listmonk',
            'parish_dist_listmonk'
        );

        add_settings_field(
            'listmonk_test',
            __('Connection Test', 'parish-dist-listmonk'),
            array($this, 'render_test_button'),
            'parish-distribution-settings-listmonk',
            'parish_dist_listmonk'
        );

        add_settings_field(
            'listmonk_lists',
            __('Available Lists', 'parish-dist-listmonk'),
            array($this, 'render_lists_field'),
            'parish-distribution-settings-listmonk',
            'parish_dist_listmonk'
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_own_settings($input) {
        $sanitized = array();

        $sanitized['url'] = isset($input['url']) ? esc_url_raw(rtrim($input['url'], '/')) : '';
        $sanitized['username'] = isset($input['username']) ? sanitize_text_field($input['username']) : '';
        $sanitized['from_email'] = isset($input['from_email']) ? sanitize_email($input['from_email']) : '';
        $sanitized['template_id'] = isset($input['template_id']) ? absint($input['template_id']) : 1;
        $sanitized['default_lists'] = isset($input['default_lists']) ? array_map('absint', (array) $input['default_lists']) : array();

        // Encrypt password
        if (isset($input['password']) && !empty($input['password'])) {
            if (class_exists('Parish_Dist_Encryption')) {
                $sanitized['password'] = Parish_Dist_Encryption::encrypt($input['password']);
            } else {
                $sanitized['password'] = $input['password'];
            }
        } else {
            // Keep existing password
            $existing = $this->get_settings();
            $sanitized['password'] = $existing['password'];
        }

        // Clear lists cache when settings change
        delete_transient($this->lists_cache_key);

        return $sanitized;
    }

    /**
     * Hook for core settings sanitization
     */
    public function sanitize_settings($sanitized, $input) {
        return $sanitized;
    }

    /**
     * Render section description
     */
    public function render_section_description() {
        echo '<p>' . esc_html__('Configure your Listmonk instance to send newsletters when posts are published.', 'parish-dist-listmonk') . '</p>';
    }

    /**
     * Render URL field
     */
    public function render_url_field() {
        $settings = $this->get_settings();
        printf(
            '<input type="url" name="%s[url]" value="%s" class="regular-text" placeholder="https://lists.example.com">',
            esc_attr($this->option_name),
            esc_attr($settings['url'])
        );
        echo '<p class="description">' . esc_html__('The URL of your Listmonk instance (without trailing slash).', 'parish-dist-listmonk') . '</p>';
    }

    /**
     * Render credentials fields
     */
    public function render_credentials_fields() {
        $settings = $this->get_settings();
        ?>
        <p>
            <label><?php esc_html_e('Username:', 'parish-dist-listmonk'); ?></label><br>
            <input type="text" name="<?php echo esc_attr($this->option_name); ?>[username]"
                   value="<?php echo esc_attr($settings['username']); ?>" class="regular-text">
        </p>
        <p>
            <label><?php esc_html_e('Password:', 'parish-dist-listmonk'); ?></label><br>
            <input type="password" name="<?php echo esc_attr($this->option_name); ?>[password]"
                   value="" class="regular-text" placeholder="<?php echo $settings['password'] ? '••••••••' : ''; ?>">
            <?php if ($settings['password']): ?>
                <span class="description"><?php esc_html_e('Leave blank to keep existing password.', 'parish-dist-listmonk'); ?></span>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Render from email field
     */
    public function render_from_email_field() {
        $settings = $this->get_settings();
        printf(
            '<input type="email" name="%s[from_email]" value="%s" class="regular-text" placeholder="newsletter@example.com">',
            esc_attr($this->option_name),
            esc_attr($settings['from_email'])
        );
        echo '<p class="description">' . esc_html__('The email address campaigns will be sent from.', 'parish-dist-listmonk') . '</p>';
    }

    /**
     * Render template field
     */
    public function render_template_field() {
        $settings = $this->get_settings();
        printf(
            '<input type="number" name="%s[template_id]" value="%s" class="small-text" min="1">',
            esc_attr($this->option_name),
            esc_attr($settings['template_id'])
        );
        echo '<p class="description">' . esc_html__('The Listmonk template ID to use for campaigns. Find this in Listmonk under Campaigns > Templates.', 'parish-dist-listmonk') . '</p>';
    }

    /**
     * Render test connection button
     */
    public function render_test_button() {
        ?>
        <button type="button" class="button" id="parish-dist-listmonk-test">
            <?php esc_html_e('Test Connection', 'parish-dist-listmonk'); ?>
        </button>
        <span id="parish-dist-listmonk-test-result"></span>
        <script>
        jQuery(function($) {
            $('#parish-dist-listmonk-test').on('click', function() {
                var $btn = $(this);
                var $result = $('#parish-dist-listmonk-test-result');
                $btn.prop('disabled', true);
                $result.text('Testing...');

                $.post(ajaxurl, {
                    action: 'parish_dist_listmonk_test',
                    _ajax_nonce: '<?php echo wp_create_nonce('parish_dist_listmonk_test'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color:green">✓ ' + response.data + '</span>');
                    } else {
                        $result.html('<span style="color:red">✗ ' + response.data + '</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render lists field
     */
    public function render_lists_field() {
        $lists = $this->get_cached_lists();
        $settings = $this->get_settings();
        ?>
        <div id="parish-dist-listmonk-lists">
            <?php if (empty($lists)): ?>
                <p><?php esc_html_e('No lists found. Save settings and click "Refresh Lists" below.', 'parish-dist-listmonk'); ?></p>
            <?php else: ?>
                <p><?php esc_html_e('Select default lists for new posts:', 'parish-dist-listmonk'); ?></p>
                <?php foreach ($lists as $list): ?>
                    <label style="display: block; margin-bottom: 4px;">
                        <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[default_lists][]"
                               value="<?php echo esc_attr($list['id']); ?>"
                               <?php checked(in_array($list['id'], $settings['default_lists'])); ?>>
                        <?php echo esc_html($list['name']); ?>
                        <span class="description">(<?php echo esc_html($list['subscriber_count']); ?> subscribers)</span>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <p>
            <button type="button" class="button" id="parish-dist-listmonk-refresh-lists">
                <?php esc_html_e('Refresh Lists', 'parish-dist-listmonk'); ?>
            </button>
        </p>
        <script>
        jQuery(function($) {
            $('#parish-dist-listmonk-refresh-lists').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Refreshing...');

                $.post(ajaxurl, {
                    action: 'parish_dist_listmonk_refresh_lists',
                    _ajax_nonce: '<?php echo wp_create_nonce('parish_dist_listmonk_refresh'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false).text('Refresh Lists');
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render settings tab
     */
    public function render_settings_tab() {
        // Use Listmonk's own settings group
        settings_fields('parish_dist_listmonk_group');
        do_settings_sections('parish-distribution-settings-listmonk');
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('parish_dist_listmonk_test');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'parish-dist-listmonk'));
        }

        $api = $this->get_api();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(__('Connected successfully!', 'parish-dist-listmonk'));
    }

    /**
     * AJAX: Refresh lists
     */
    public function ajax_refresh_lists() {
        check_ajax_referer('parish_dist_listmonk_refresh');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'parish-dist-listmonk'));
        }

        delete_transient($this->lists_cache_key);
        $lists = $this->get_cached_lists(true);

        if (is_wp_error($lists)) {
            wp_send_json_error($lists->get_error_message());
        }

        wp_send_json_success();
    }

    /**
     * Get cached lists
     */
    public function get_cached_lists($force_refresh = false) {
        if (!$force_refresh) {
            $cached = get_transient($this->lists_cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $api = $this->get_api();
        $lists = $api->get_lists();

        if (is_wp_error($lists)) {
            return array();
        }

        set_transient($this->lists_cache_key, $lists, DAY_IN_SECONDS);
        return $lists;
    }

    /**
     * Get lists for editor (REST API callback)
     */
    public function get_lists_for_editor() {
        $lists = $this->get_cached_lists();
        $formatted = array();

        foreach ($lists as $list) {
            $formatted[] = array(
                'id' => $list['id'],
                'label' => $list['name'],
            );
        }

        return $formatted;
    }

    /**
     * Publish to Listmonk
     */
    public function publish($post_id, $options) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', __('Post not found.', 'parish-dist-listmonk'));
        }

        $settings = $this->get_settings();
        $list_ids = !empty($options) ? $options : $settings['default_lists'];

        if (empty($list_ids)) {
            return new WP_Error('no_lists', __('No lists selected for distribution.', 'parish-dist-listmonk'));
        }

        // Prepare content
        $content = apply_filters('parish_dist_content', $post->post_content, $post_id, 'listmonk');
        $content = apply_filters('the_content', $content);

        // Add link to full post
        $content .= sprintf(
            '<p><a href="%s">%s</a></p>',
            get_permalink($post_id),
            __('Read more on our website', 'parish-dist-listmonk')
        );

        $api = $this->get_api();
        $result = $api->create_and_send_campaign(array(
            'name' => $post->post_title,
            'subject' => $post->post_title,
            'body' => $content,
            'from_email' => $settings['from_email'],
            'template_id' => $settings['template_id'],
            'list_ids' => array_map('intval', $list_ids),
        ));

        if (is_wp_error($result)) {
            // Log error
            error_log('Parish Distribution Listmonk Error: ' . $result->get_error_message());
            return $result;
        }

        return array(
            'external_id' => $result['id'],
        );
    }
}
