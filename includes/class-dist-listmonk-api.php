<?php
/**
 * Listmonk API Wrapper
 *
 * Handles communication with the Listmonk API.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dist_Listmonk_API {

    private $base_url;
    private $username;
    private $password;

    public function __construct($base_url, $username, $password) {
        $this->base_url = rtrim($base_url, '/');
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Make an API request
     */
    private function request($endpoint, $method = 'GET', $body = null) {
        if (empty($this->base_url) || empty($this->username) || empty($this->password)) {
            return new WP_Error('config_missing', __('Listmonk configuration incomplete.', 'parish-dist-listmonk'));
        }

        $url = $this->base_url . '/api/' . ltrim($endpoint, '/');

        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $message = isset($data['message']) ? $data['message'] : __('Unknown error', 'parish-dist-listmonk');
            return new WP_Error('api_error', $message, array('status' => $code));
        }

        return $data;
    }

    /**
     * Test connection
     */
    public function test_connection() {
        $result = $this->request('health');

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Get all lists
     */
    public function get_lists() {
        $result = $this->request('lists?per_page=all');

        if (is_wp_error($result)) {
            return $result;
        }

        if (!isset($result['data']['results'])) {
            return array();
        }

        $lists = array();
        foreach ($result['data']['results'] as $list) {
            $lists[] = array(
                'id' => $list['id'],
                'uuid' => $list['uuid'],
                'name' => $list['name'],
                'type' => $list['type'],
                'subscriber_count' => $list['subscriber_count'],
            );
        }

        return $lists;
    }

    /**
     * Get templates
     */
    public function get_templates() {
        $result = $this->request('templates');

        if (is_wp_error($result)) {
            return $result;
        }

        if (!isset($result['data'])) {
            return array();
        }

        return $result['data'];
    }

    /**
     * Create a campaign
     */
    public function create_campaign($params) {
        $defaults = array(
            'name' => '',
            'subject' => '',
            'body' => '',
            'content_type' => 'richtext',
            'from_email' => '',
            'template_id' => 1,
            'list_ids' => array(),
            'send_at' => null,
            'tags' => array(),
        );

        $params = wp_parse_args($params, $defaults);

        $body = array(
            'name' => $params['name'],
            'subject' => $params['subject'],
            'body' => $params['body'],
            'content_type' => $params['content_type'],
            'from_email' => $params['from_email'],
            'lists' => $params['list_ids'],
            'template_id' => $params['template_id'],
            'tags' => $params['tags'],
        );

        if ($params['send_at']) {
            $body['send_at'] = $params['send_at'];
        }

        $result = $this->request('campaigns', 'POST', $body);

        if (is_wp_error($result)) {
            return $result;
        }

        if (!isset($result['data']['id'])) {
            return new WP_Error('create_failed', __('Failed to create campaign.', 'parish-dist-listmonk'));
        }

        return $result['data'];
    }

    /**
     * Start/send a campaign
     */
    public function start_campaign($campaign_id) {
        $result = $this->request("campaigns/{$campaign_id}/status", 'PUT', array(
            'status' => 'running',
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Create and send a campaign in one go
     */
    public function create_and_send_campaign($params) {
        // Create campaign
        $campaign = $this->create_campaign($params);

        if (is_wp_error($campaign)) {
            return $campaign;
        }

        // Start campaign
        $result = $this->start_campaign($campaign['id']);

        if (is_wp_error($result)) {
            return $result;
        }

        return $campaign;
    }

    /**
     * Get campaign status
     */
    public function get_campaign($campaign_id) {
        $result = $this->request("campaigns/{$campaign_id}");

        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['data']) ? $result['data'] : null;
    }
}
