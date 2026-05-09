<?php

namespace BuyMeCoffee\Controllers;

use BuyMeCoffee\Helpers\ArrayHelper;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class PaymentHandler
{
    public static function getAllMethods()
    {
        $methods = apply_filters('buymecoffee_get_all_methods', []);
        return $methods;
    }

    public static function isMethodEnabled($method)
    {
        if (empty($method) || !is_array($method) || !array_key_exists('status', $method)) {
            return false;
        }

        return $method['status'] === true || $method['status'] === 'yes' || $method['status'] === 1 || $method['status'] === '1';
    }

    public static function normalizeRegisteredMethod($method)
    {
        $method = sanitize_key($method);
        $methods = self::getAllMethods();

        if (!$method || empty($methods[$method])) {
            return '';
        }

        return $method;
    }

    public function saveSettings($method, $settings)
    {
        $method = self::normalizeRegisteredMethod($method);
        if (!$method) {
            wp_send_json_error([
                'message' => __('Invalid payment method.', 'buy-me-coffee'),
            ], 400);
        }

        $settings = apply_filters('buymecoffee_before_save_' . $method, $settings);

        update_option('buymecoffee_payment_settings_' . $method, $settings, false);

        do_action('buymecoffee_after_save_' . $method, $settings);

        wp_send_json_success(array(
            'message' => "Settings $method successfully updated"
        ), 200);

    }

}
