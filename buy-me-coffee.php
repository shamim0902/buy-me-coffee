<?php

/*
Plugin Name: Buy Me a Coffee button & widgets - Fundraise with Stripe and PayPal
Plugin URI: https://wpminers.com/buymecoffee/
Description: Easy way to collect donations like "buy me a coffee" directly your own Stripe and PayPal for free
Version: 1.0.5
Author: wpminers
Author URI: http://www.wpminers.com/
License: GPLv2 or later
Text Domain: buy-me-coffee
*/


/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 *
 * Copyright 2019 Plugin Name LLC. All rights reserved.
 */

if (!defined('ABSPATH')) {
    exit;
}
if (!defined('BUYMECOFFEE_VERSION')) {
    define('BUYMECOFFEE_VERSION_LITE', true);
    define('BUYMECOFFEE_VERSION', '1.0.5');
    define('BUYMECOFFEE_MAIN_FILE', __FILE__);
    define('BUYMECOFFEE_URL', plugin_dir_url(__FILE__));
    define('BUYMECOFFEE_DIR', plugin_dir_path(__FILE__));
    define('BUYMECOFFEE_UPLOAD_DIR', '/buy-me-coffee');
    define('BUYMECOFFEE_DEVELOPMENT', 'yes');

    class BuyMeCoffee
    {
        public function boot()
        {
            if (is_admin()) {
                $this->adminHooks();
            }
            $this->commonActions();
            $this->textDomain();
            $this->LoadEditorBlocks();
            $this->loadFiles();
            $this->registerShortcode();
            $this->registerIpnHooks();
        }

        public function loadFiles()
        {
            require BUYMECOFFEE_DIR . 'includes/autoload.php';
            require BUYMECOFFEE_DIR . 'includes/Models/Buttons.php';
            require BUYMECOFFEE_DIR . 'includes/Helpers/ArrayHelper.php';
        }

        public function adminHooks()
        {
            require BUYMECOFFEE_DIR . 'includes/autoload.php';

            //Register Admin menu
            $menu = new \BuyMeCoffee\Classes\Menu();
            $menu->register();

            // Top Level Ajax Handlers
            $ajaxHandler = new \BuyMeCoffee\Classes\AdminAjaxHandler();
            $ajaxHandler->registerEndpoints();

        }

        public function registerShortcode()
        {
            add_shortcode('buymecoffee_button', function ($args) {
                $args = shortcode_atts(array(
                    'type' => '',
                ), $args);

                $this->addAssets();

                $builder = new \BuyMeCoffee\Builder\Render();
                return $builder->render($args);
            });

            $demoPage = new \BuyMeCoffee\Classes\DemoPage();
            add_shortcode('buymecoffee_form', [$demoPage, 'renderFormOnly']);

            add_shortcode('buymecoffee_basic', [$demoPage, 'renderBasicTemplate']);

        }

        public function addAssets()
        {
            $vite = new \BuyMeCoffee\Classes\Vite();

            // $vite::enqueueStyle('buymecoffee_style', 'scss/public/public-style.scss', array(), BUYMECOFFEE_VERSION);
            $vite::enqueueScript('buymecoffee_script',  'js/BmcPublic.js', array('jquery'), BUYMECOFFEE_VERSION, true);
            wp_localize_script('buymecoffee_script', 'buymecoffee', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            ));
        }

        public function commonActions()
        {
            require_once BUYMECOFFEE_DIR . 'includes/Classes/Vite.php';
            require BUYMECOFFEE_DIR . 'includes/Controllers/SubmissionHandler.php';

            //payment methods init
            require BUYMECOFFEE_DIR . 'includes/Builder/Methods/BaseMethods.php';
            require BUYMECOFFEE_DIR . 'includes/Builder/Methods/Stripe/Stripe.php';
            require BUYMECOFFEE_DIR . 'includes/Builder/Methods/PayPal/PayPal.php';
            new \BuyMeCoffee\Builder\Methods\PayPal\PayPal();
            new \BuyMeCoffee\Builder\Methods\Stripe\Stripe();

            // Submission Handler
            $submissionHandler = new \BuyMeCoffee\Controllers\SubmissionHandler();
            add_action('wp_ajax_buymecoffee_submit', array($submissionHandler, 'handleSubmission'));
            add_action('wp_ajax_nopriv_buymecoffee_submit', array($submissionHandler, 'handleSubmission'));
        }

        public function registerIpnHooks()
        {
            if (isset($_REQUEST['buymecoffee_ipn_listener']) && isset($_REQUEST['method'])) {
                add_action('wp', function () {
                    $paymentMethod = sanitize_text_field($_REQUEST['method']);
                    do_action('buymecoffee_ipn_endpoint_' . $paymentMethod);
                });
            }
        }

        public function textDomain()
        {
            load_plugin_textdomain('buy-me-coffee', false, basename(dirname(__FILE__)) . '/languages');
        }

        public function LoadEditorBlocks()
        {
            require BUYMECOFFEE_DIR . 'includes/Builder/EditorBlocks/EditorBlocks.php';
            $pages_with_editor_button = array('post.php', 'post-new.php');
            foreach ($pages_with_editor_button as $editor_page) {
                add_action("load-{$editor_page}", array(new \BuyMeCoffee\Builder\EditorBlocks\EditorBlocks(), 'register'));
            }
        }
    }

    add_action('plugins_loaded', function () {
        (new BuyMeCoffee())->boot();
    });

    register_activation_hook(__FILE__, function ($newWorkWide) {
        require_once(BUYMECOFFEE_DIR . 'includes/Classes/Activator.php');
        $activator = new \BuyMeCoffee\Classes\Activator();
        $activator->migrateDatabases($newWorkWide);
    });

    // disabled admin-notice on dashboard
    add_action('admin_init', function () {
        if (isset($_GET['page']) && $_GET['page'] === 'buy-me-coffee.php') {
            remove_all_actions('admin_notices');
        }
    });

    // Handle Exterior Pages
    add_action('wp', function () {
        $demoPage = new \BuyMeCoffee\Classes\DemoPage();
        $demoPage->register();
    });

    add_filter('plugin_row_meta', function($links, $file) {
        if ('buy-me-coffee/buy-me-coffee.php' == $file) {
            $row_meta = [
                'preview' => '<a rel="noopener" href="'. esc_url(site_url('?share_coffee')) . '" style="color: #8BC34A;font-weight: 600;" aria-label="' . esc_attr__('Preview', 'buy-me-coffee') . '" target="_blank">' . esc_html__('Preview', 'buy-me-coffee') . '</a>',
                'docs' => '<a rel="noopener" href="https://wpminers.com/buymecoffee/docs/installation/install-buy-me-coffee-plugin/" style="color: #8BC34A;font-weight: 600;" aria-label="' . esc_attr__('View Documentation', 'buy-me-coffee') . '" target="_blank">' . esc_html__('Docs', 'buy-me-coffee') . '</a>',
                'demo' => '<a rel="noopener" href="https://wpminers.com/buymecoffee-demo/" style="color: #8BC34A;font-weight: 600;" aria-label="' . esc_attr__('Demo', 'buy-me-coffee') . '" target="_blank">' . esc_html__('Demo', 'buy-me-coffee') . '</a>',
            ];
            return array_merge($links, $row_meta);
        }
        return (array)$links;
    }, 10, 2);


} else {
    add_action('admin_init', function () {
        deactivate_plugins(plugin_basename(__FILE__));
    });
}
