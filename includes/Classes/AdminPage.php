<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit;

class AdminPage
{
    public function register()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Route detection only
        if (!isset($_GET['buymecoffee_admin'])) {
            return;
        }

        // Auth check — redirect to login if not authorized
        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url(site_url('?buymecoffee_admin')));
            exit;
        }

        $permission = AccessControl::hasTopLevelMenuPermission();
        if (!$permission) {
            wp_die(
                esc_html__('You do not have permission to access this page.', 'buy-me-coffee'),
                403
            );
        }

        $this->renderApp();
    }

    private function renderApp()
    {
        wp_enqueue_media();
        $this->enqueueAssets();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template is a controlled PHP file
        echo View::make('admin.AdminApp', [
            'title' => __('Buy Me Coffee', 'buy-me-coffee'),
        ]);

        exit;
    }

    private function enqueueAssets()
    {
        do_action('buymecoffee_render_admin_app');

        // Inter font
        wp_enqueue_style(
            'buy-me-coffee-inter-font',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            array(),
            null
        );

        Vite::enqueueScript(
            'buy-me-coffee_boot',
            'js/boot.js',
            array(),
            BUYMECOFFEE_VERSION,
            true
        );

        do_action('buymecoffee_booting_admin_app');

        Vite::enqueueScript(
            'buy-me-coffee_js',
            'js/main.js',
            array('jquery'),
            BUYMECOFFEE_VERSION,
            true
        );

        Vite::enqueueStyle(
            'buy-me-coffee_admin_css',
            'scss/admin/app.scss',
            array(),
            BUYMECOFFEE_VERSION
        );

        $adminVars = apply_filters('buymecoffee_admin_app_vars', array(
            'assets_url'        => Vite::staticPath(),
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'preview_url'       => site_url('?share_coffee'),
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'wp_admin_url'      => admin_url(),
            'admin_email'       => get_option('admin_email'),
        ));

        wp_localize_script('buy-me-coffee_boot', 'BuyMeCoffeeAdmin', $adminVars);
    }
}
