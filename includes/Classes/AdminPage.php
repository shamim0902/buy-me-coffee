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

        // Auth check - redirect to login if not authorized.
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(site_url('?buymecoffee_admin')));
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
        (new AdminAppAssets())->enqueue([
            'legacy_upgrade_only_whats_new' => true,
        ]);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template is a controlled PHP file
        echo View::make('admin.AdminApp', [
            'title' => esc_html__('Buy Me Coffee', 'buy-me-coffee'),
        ]);

        exit;
    }
}
