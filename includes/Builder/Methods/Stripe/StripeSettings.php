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

        return self::getKeysForMode($settings['payment_mode'], $key);
    }

    /**
     * Get the keys for an explicit payment mode instead of the mode currently
     * selected in the settings UI.
     *
     * Stored records (subscriptions, transactions) keep the mode they were
     * created in, so operations against an existing remote object must use the
     * credentials of that mode — the admin may have switched the UI to the
     * other mode since.
     *
     * @param string      $mode 'live' or 'test'; anything else falls back to the configured mode.
     * @param string|null $key  'secret' or 'public'; null returns both.
     * @return array|string
     */
    public static function getKeysForMode($mode, $key = null)
    {
        $settings = self::getSettings();

        $mode = is_string($mode) ? strtolower(trim($mode)) : '';
        if (!in_array($mode, array('live', 'test'), true)) {
            $mode = $settings['payment_mode'] === 'live' ? 'live' : 'test';
        }

        if ($mode === 'live') {
            $data = array(
                'secret' => $settings['live_secret_key'],
                'public' => $settings['live_pub_key']
            );
        } else {
            $data = array(
                'secret' => $settings['test_secret_key'],
                'public' => $settings['test_pub_key']
            );
        }

        return $key && isset($data[$key]) ? $data[$key] : $data;
    }

    /**
     * Resolve the mode a stored record should be operated in.
     *
     * @param string $storedMode Value of a payment_mode column.
     * @return string 'live' or 'test'
     */
    public static function resolveMode($storedMode)
    {
        $storedMode = is_string($storedMode) ? strtolower(trim($storedMode)) : '';
        if (in_array($storedMode, array('live', 'test'), true)) {
            return $storedMode;
        }

        $settings = self::getSettings();

        return $settings['payment_mode'] === 'live' ? 'live' : 'test';
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