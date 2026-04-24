<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class StripeSettings
{
    public static function getSettings($key = null)
    {
        $settings = get_option('buymecoffee_payment_settings_stripe', []);

        $defaults = array(
            'enable' => 'no',
            'payment_mode' => 'test',
            'live_pub_key' => '',
            'live_secret_key' => '',
            'test_pub_key' => '',
            'test_secret_key' => '',
            'live_webhook_secret' => '',
            'test_webhook_secret' => ''
        );

        $data = wp_parse_args($settings, $defaults);
        return $key && isset($data[$key]) ? $data[$key] : $data;
    }

    public static function getKeys($key = null)
    {
        $settings = self::getSettings();

        if ($settings['payment_mode'] == 'test') {
            $data = array(
                'secret' => $settings['test_secret_key'],
                'public' => $settings['test_pub_key']
            );
        } else {
            $data = array(
                'secret' => $settings['live_secret_key'],
                'public' =>$settings['live_pub_key']
            );
        }

        return $key && isset($data[$key]) ? $data[$key] : $data;

    }

    public static function getWebhookSecret()
    {
        $settings = self::getSettings();
        if (($settings['payment_mode'] ?? 'test') === 'live') {
            return $settings['live_webhook_secret'] ?? '';
        }
        return $settings['test_webhook_secret'] ?? '';
    }



}