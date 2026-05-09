<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Menu
{
    private $pageHook = '';

    public function register()
    {
        add_action('admin_menu', array($this, 'addMenus'));
    }

    public function addMenus()
    {
        $menuPermission = AccessControl::hasTopLevelMenuPermission();
        if (!$menuPermission) {
            return;
        }

        $title = __('Buy Me Coffee', 'buy-me-coffee');

        $this->pageHook = add_menu_page(
            $title,
            $title,
            'read',
            'buy-me-coffee.php',
            [$this, 'render'],
            'dashicons-coffee',
            68
        );

        add_submenu_page(
            'buy-me-coffee.php',
            __('App View', 'buy-me-coffee'),
            __('App View', 'buy-me-coffee'),
            'read',
            'buymecoffee-app-view',
            '__return_null'
        );

        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Redirect the "App View" submenu to the full-page frontend app.
        add_action('admin_init', function () {
            global $pagenow;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'buymecoffee-app-view') {
                wp_safe_redirect(site_url('?buymecoffee_admin#/'));
                exit;
            }
        });

        // Tag the Buy Me Coffee admin page without changing the WordPress menu state.
        add_filter('admin_body_class', function ($classes) {
            global $pagenow;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'buy-me-coffee.php') {
                $classes .= ' bmc-admin-page';
            }
            return $classes;
        });
    }

    public function enqueueAssets($hook)
    {
        if ($hook !== $this->pageHook) {
            return;
        }

        (new AdminAppAssets())->enqueue([
            'cleanup_admin_chrome' => true,
            'legacy_upgrade_only_whats_new' => false,
        ]);
    }

    public function render()
    {
        if (!AccessControl::hasTopLevelMenuPermission()) {
            wp_die(
                esc_html__('You do not have permission to access this page.', 'buy-me-coffee'),
                403
            );
        }

        ?>
        <div class="wrap" style="margin:0; padding:0;">
            <div id="buy-me-coffee_app">
                <router-view></router-view>
            </div>
        </div>
        <?php
    }
}
