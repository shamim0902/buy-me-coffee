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

        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

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

        wp_enqueue_media();

        // Strip WP admin chrome padding so our full-width app has no gaps.
        // Scoped to body.bmc-admin-page so it never affects other admin pages.
        wp_add_inline_style('wp-admin', '
            body.bmc-admin-page #wpcontent {
                padding-left: 0 !important;
            }
            body.bmc-admin-page #wpbody-content {
                padding-bottom: 0 !important;
            }
            body.bmc-admin-page #wpbody {
                padding-top: 0 !important;
            }
            body.bmc-admin-page .wrap {
                margin: 0 !important;
                padding: 0 !important;
                max-width: none !important;
            }
            body.bmc-admin-page #buy-me-coffee_app {
                margin: 0 !important;
                padding: 0 !important;
            }
            body.bmc-admin-page #wpbody-content > .error {
                display: none !important;
            }
        ');

        do_action('buymecoffee_render_admin_app');

        wp_enqueue_style(
            'buy-me-coffee-inter-font',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            array(),
            BUYMECOFFEE_VERSION
        );

        Vite::enqueueScript(
            'buy-me-coffee_boot',
            'js/boot.js',
            array(),
            BUYMECOFFEE_VERSION,
            true
        );

        wp_add_inline_script('buy-me-coffee_boot', "
            (function () {
                var app = document.getElementById('buy-me-coffee_app');
                var wpBodyContent = document.getElementById('wpbody-content');

                if (!app || !wpBodyContent || !wpBodyContent.contains(app)) {
                    return;
                }

                Array.prototype.slice.call(wpBodyContent.children).forEach(function (child) {
                    if (child === app || child.contains(app)) {
                        return;
                    }

                    if (child.classList && child.classList.contains('error')) {
                        child.remove();
                    }
                });
            })();
        ", 'before');

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

        $seenVersion  = get_user_meta(get_current_user_id(), 'buymecoffee_whats_new_seen', true);
        $showWhatsNew = version_compare((string) $seenVersion, BUYMECOFFEE_VERSION, '<');

        $adminVars = apply_filters('buymecoffee_admin_app_vars', array_merge(array(
            'assets_url'        => Vite::staticPath(),
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'preview_url'       => site_url('?share_coffee'),
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'wp_admin_url'      => admin_url(),
            'is_wp_admin'       => true,
            'admin_email'       => get_option('admin_email'),
            'show_whats_new'    => $showWhatsNew,
            'plugin_version'    => BUYMECOFFEE_VERSION,
            'user_name'         => wp_get_current_user()->display_name,
            'setup_completed'   => $this->isSetupCompleted(),
        ), \BuyMeCoffee\Helpers\PaymentHelper::getFrontendFormattingConfig()));

        wp_localize_script('buy-me-coffee_boot', 'BuyMeCoffeeAdmin', $adminVars);
    }

    private function isSetupCompleted()
    {
        $templateSettings = get_option('buymecoffee_payment_setting', []);
        if (is_array($templateSettings) && !empty($templateSettings)) {
            return true;
        }

        $stripe = get_option('buymecoffee_payment_settings_stripe', []);
        if (is_array($stripe) && ($stripe['enable'] ?? '') === 'yes') {
            return !empty($stripe['test_secret_key']) || !empty($stripe['live_secret_key']);
        }

        $paypal = get_option('buymecoffee_payment_settings_paypal', []);
        if (is_array($paypal) && ($paypal['enable'] ?? '') === 'yes') {
            return !empty($paypal['paypal_email'])
                || !empty($paypal['test_secret_key'])
                || !empty($paypal['live_secret_key']);
        }

        return false;
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
