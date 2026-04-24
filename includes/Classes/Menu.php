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
            'manage_options',
            'buy-me-coffee.php',
            [$this, 'render'],
            'dashicons-coffee',
            68
        );

        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Collapse WP admin sidebar on our page
        add_filter('admin_body_class', function ($classes) {
            global $pagenow;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'buy-me-coffee.php') {
                $classes .= ' folded';
            }
            return $classes;
        });
    }

    public function enqueueAssets($hook)
    {
        if ($hook !== $this->pageHook) {
            return;
        }

        wp_enqueue_media();

        do_action('buymecoffee_render_admin_app');

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
            'is_wp_admin'       => true,
        ));

        wp_localize_script('buy-me-coffee_boot', 'BuyMeCoffeeAdmin', $adminVars);
    }

    public function render()
    {
        ?>
        <div class="wrap" style="margin:0; padding:0;">
            <div id="buy-me-coffee_app">
                <router-view></router-view>
            </div>
        </div>
        <?php
    }
}
