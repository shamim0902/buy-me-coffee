<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Menu
{
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
        global $submenu;

        $capability = 'manage_options';

        add_menu_page(
            $title,
            $title,
            $capability,
            'buy-me-coffee.php',
            [$this, 'render'],
            'dashicons-coffee',
            68
        );

        $submenu['buy-me-coffee.php']['my_profile'] = array(
            __('Dashboard', 'buy-me-coffee'),
            $menuPermission,
            'admin.php?page=buy-me-coffee.php#/',
        );

        $submenu['buy-me-coffee.php']['supporters'] = array(
            __('Supporters', 'buy-me-coffee'),
            $menuPermission,
            'admin.php?page=buy-me-coffee.php#/supporters',
        );

        $submenu['buy-me-coffee.php']['settings'] = array(
            __('Settings', 'buy-me-coffee'),
            $menuPermission,
            'admin.php?page=buy-me-coffee.php#/settings',
        );
        $submenu['buy-me-coffee.php']['gateways'] = array(
            __('Gateways', 'buy-me-coffee'),
            $menuPermission,
            'admin.php?page=buy-me-coffee.php#/stripe',
        );
//        $submenu['buy-me-coffee.php']['notifications'] = array(
//            __('Notifications', 'buy-me-coffee'),
//            $menuPermission,
//            'admin.php?page=buy-me-coffee.php#/notifications',
//        );
    }

    public function render()
    {
        wp_enqueue_media();
        $this->enqueueAssets();

        ob_start();
        ?>
        <div id="buy-me-coffee_app" class="buy-me-coffee_app">
            <div class="buymecoffee_body">
                <div class="bmc_route_wrapper">
                    <router-view></router-view>
                </div>
            </div>
            <div class="buymecoffee_app_menu">
                <ul>
                    <li class="buymecoffee_menu_dashboard">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=buy-me-coffee.php#/')); ?>" >
                            <img width="30" src="<?php echo esc_url(Vite::staticPath() . 'images/dashboard.svg'); ?>" />
                            Report
                        </a>
                    </li>
                    <li class="buymecoffee_menu_supporters">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=buy-me-coffee.php#/supporters')); ?>" >
                            <img width="30" src="<?php echo esc_url(Vite::staticPath() . 'images/handshake.svg'); ?>" />
                            Donor
                        </a>
                    </li>
                    <li class="buymecoffee_menu_settings">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=buy-me-coffee.php#/settings')); ?>" >
                            <img width="30" src="<?php echo esc_url(Vite::staticPath() . 'images/settings.svg'); ?>" />
                            Settings
                        </a>
                    </li>
                    <li class="buymecoffee_menu_gateway">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=buy-me-coffee.php#/stripe')); ?>" >
                            <img width="30" src="<?php echo esc_url(Vite::staticPath() . 'images/bank.svg'); ?>" />
                            Gateways
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo ob_get_clean();
    }

    public function enqueueAssets()
    {
        do_action('buymecoffee_render_admin_app');

        Vite::enqueueScript(
            'buy-me-coffee_boot',
            'js/boot.js',
            array(),
            BUYMECOFFEE_VERSION,
            true
        );

        // 3rd party developers can now add their scripts here
        do_action('buymecoffee_booting_admin_app');

        Vite::enqueueScript(
            'buy-me-coffee_js',
            'js/main.js',
            array('jquery'),
            BUYMECOFFEE_VERSION,
            true
        );

        //enqueue css file using wp_enqueue (already compiled with vite)
        Vite::enqueueStyle('buy-me-coffee_admin_css', 'scss/admin/app.scss', array(), BUYMECOFFEE_VERSION, true);

        $BuyMeCoffeeAdminVars = apply_filters('buymecoffee_admin_app_vars', array(
            'assets_url' => Vite::staticPath(),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'preview_url' => site_url('?share_coffee'),
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
        ));


        wp_localize_script('buy-me-coffee_boot', 'BuyMeCoffeeAdmin', $BuyMeCoffeeAdminVars);
    }
}
