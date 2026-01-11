<?php

namespace BuyMeCoffee\Builder\Methods\PayPal;

use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class IPN
{
    public function ipnVerificationProcess()
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below
        if (isset($_SERVER['REQUEST_METHOD']) && sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) != 'POST') {
            return;
        }

        header("HTTP/1.1 200 OK");

        $post_data = '';
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for PayPal IPN to handle large POST data
            ini_set('post_max_size', '12M');
        }
        $encoded_data = 'cmd=_notify-validate';
        $arg_separator = ini_get('arg_separator.output');
        if ($post_data || strlen($post_data) > 0) {
            $encoded_data .= $arg_separator . $post_data;
        } else {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- PayPal IPN webhook callback
            if (empty($_POST)) {
                return;
            } else {
                foreach ($_POST as $key => $value) {
                    $encoded_data .= $arg_separator . "$key=" . urlencode($value);
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification.Missing
        }

        parse_str($encoded_data, $encoded_data_array);

        foreach ($encoded_data_array as $key => $value) {
            if (false !== strpos($key, 'amp;')) {
                $new_key = str_replace('&amp;', '&', $key);
                $new_key = str_replace('amp;', '&', $new_key);
                unset($encoded_data_array[$key]);
                $encoded_data_array[$new_key] = $value;
            }
        }
        $defaults = array(
            'txn_type' => '',
            'payment_status' => '',
            'custom' => ''
        );

        $encoded_data_array = wp_parse_args($encoded_data_array, $defaults);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PayPal IPN callback, verified by PayPal
        if (!is_array($encoded_data_array) && !empty($encoded_data_array)) {
            return;
        }
        
        $payment_id = 0;
        $transactions = new Transactions();

        if (!empty($encoded_data_array['parent_txn_id'])) {
            $payment_id = $transactions->getByPaymentId($encoded_data_array['parent_txn_id'],  'paypal');
        } elseif (!empty($encoded_data_array['txn_id'])) {
            $payment_id = $transactions->getByPaymentId($encoded_data_array['txn_id'], 'paypal');
        }

        if (empty($payment_id)) {
            $payment_id = !empty($encoded_data_array['custom']) ? absint($encoded_data_array['custom']) : 0;
        }

        do_action('buymecoffee_paypal_action_web_accept', $encoded_data_array, $payment_id);
        exit;
    }

    private function getSettings()
    {
        $settings = get_option('buymecoffee_payment_settings_paypal', []);
        return $settings;
    }

    private function isTestMode()
    {
        $settings = get_option('buymecoffee_payment_settings_paypal', []);
        if (isset($settings['payment_mode']) && $settings['payment_mode'] == 'live') {
            return false;
        }
        return true;
    }

    private function getPaypalRedirect($ssl_check = false, $ipn = false)
    {
        $protocol = 'http://';
        if (is_ssl() || !$ssl_check) {
            $protocol = 'https://';
        }

        // Check the current payment mode
        if ($this->isTestMode()) {
            // Test mode
            if ($ipn) {
                $paypal_uri = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';
            } else {
                $paypal_uri = $protocol . 'www.sandbox.paypal.com/cgi-bin/webscr';
            }
        } else {
            // Live mode
            if ($ipn) {
                $paypal_uri = 'https://ipnpb.paypal.com/cgi-bin/webscr';
            } else {
                $paypal_uri = $protocol . 'www.paypal.com/cgi-bin/webscr';
            }
        }
        return apply_filters('buymecoffee_ipn/paypal_url', $paypal_uri, $ssl_check, $ipn);
    }
}

