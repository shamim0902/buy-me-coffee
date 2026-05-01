<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) {
    exit;
}

class DeactivationFeedback
{
    public function register()
    {
        add_action('current_screen', [$this, 'maybeLoadFeedbackForm'], 20);
        add_action('wp_ajax_buymecoffee_deactivation_feedback', [$this, 'submitFeedback']);
    }

    public function maybeLoadFeedbackForm()
    {
        $screen = get_current_screen();

        if (!$screen || !in_array($screen->id, ['plugins', 'plugins-network'], true)) {
            return;
        }

        add_action('admin_footer', [$this, 'renderForm']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets()
    {
        Vite::enqueueStyle(
            'buymecoffee_deactivate',
            'scss/admin/deactivate-feedback.scss',
            [],
            BUYMECOFFEE_VERSION
        );

        Vite::enqueueScript(
            'buymecoffee_deactivate',
            'js/deactivate-feedback.js',
            ['jquery'],
            BUYMECOFFEE_VERSION,
            true
        );

        wp_localize_script('buymecoffee_deactivate', 'buymecoffee_deactivate', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('buymecoffee_deactivation_feedback'),
            'plugin_basename' => plugin_basename(BUYMECOFFEE_MAIN_FILE),
            'version'         => BUYMECOFFEE_VERSION,
            'wp_version'      => get_bloginfo('version'),
            'site_url'        => site_url(),
        ]);
    }

    public function submitFeedback()
    {
        check_ajax_referer('buymecoffee_deactivation_feedback', 'nonce');

        if (!current_user_can('activate_plugins') && !current_user_can('manage_network_plugins')) {
            wp_send_json_error(['message' => __('You do not have permission to submit feedback.', 'buy-me-coffee')], 403);
        }

        $allowedReasons = [
            'temporary',
            'missing_feature',
            'no_longer_needed',
            'stopped_working',
            'other',
        ];

        $rawReasons = isset($_POST['reasons']) ? wp_unslash($_POST['reasons']) : [];
        if (!is_array($rawReasons)) {
            $rawReasons = [];
        }

        $reasons = [];
        foreach ($rawReasons as $reason) {
            $reason = sanitize_text_field($reason);
            if (in_array($reason, $allowedReasons, true)) {
                $reasons[] = $reason;
            }
        }

        if (empty($reasons)) {
            wp_send_json_error(['message' => __('Please select at least one reason.', 'buy-me-coffee')], 400);
        }

        $body = [
            'plugin'          => 'buy-me-coffee',
            'reasons'         => array_values(array_unique($reasons)),
            'feature_missing' => isset($_POST['feature_missing']) ? sanitize_text_field(wp_unslash($_POST['feature_missing'])) : '',
            'other_details'   => isset($_POST['other_details']) ? sanitize_textarea_field(wp_unslash($_POST['other_details'])) : '',
            'plugin_version'  => BUYMECOFFEE_VERSION,
            'wp_version'      => get_bloginfo('version'),
            'site_url'        => site_url(),
        ];

        $response = wp_safe_remote_post(
            'https://wpminers.com/?buymecoffee_deactivation_feedback=1',
            [
                'timeout' => 5,
                'body'    => $body,
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            wp_send_json_error(['message' => __('Feedback receiver rejected the request.', 'buy-me-coffee')], 502);
        }

        wp_send_json_success(['message' => __('Feedback submitted.', 'buy-me-coffee')]);
    }

    public function renderForm()
    {
        $logo = BUYMECOFFEE_URL . 'assets/images/coffee_logo.png';
        include BUYMECOFFEE_DIR . 'includes/views/admin/deactivate-feedback.php';
    }
}
