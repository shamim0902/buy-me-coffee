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
    }

    public function maybeLoadFeedbackForm()
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'plugins') {
            return;
        }

        add_action('admin_footer', [$this, 'renderForm']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets()
    {
        wp_enqueue_style(
            'buymecoffee_deactivate',
            BUYMECOFFEE_URL . 'assets/css/deactivate-feedback.css',
            [],
            BUYMECOFFEE_VERSION
        );

        wp_enqueue_script(
            'buymecoffee_deactivate',
            BUYMECOFFEE_URL . 'assets/js/deactivate-feedback.js',
            ['jquery'],
            BUYMECOFFEE_VERSION,
            true
        );

        wp_localize_script('buymecoffee_deactivate', 'buymecoffee_deactivate', [
            'version'    => BUYMECOFFEE_VERSION,
            'wp_version' => get_bloginfo('version'),
            'site_url'   => site_url(),
        ]);
    }

    public function renderForm()
    {
        $logo = BUYMECOFFEE_URL . 'assets/images/coffee_logo.png';
        include BUYMECOFFEE_DIR . 'includes/views/admin/deactivate-feedback.php';
    }
}
