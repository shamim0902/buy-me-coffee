<?php

namespace buyMeCoffee\Builder\Methods\Stripe;

use BuyMeCoffee\Builder\Methods\Stripe\StripeSettings;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class IPN
{
    public function IPNData()
    {
        $postData = '';
        if (ini_get('allow_url_fopen')) {
            $postData = file_get_contents('php://input');
        } elseif (!empty($_POST)) {
            $postData = wp_json_encode(stripslashes_deep($_POST));
        }

        if (!$postData) {
            return new \WP_Error('invalid_payload', __('Stripe payload is empty', 'buy-me-coffee'));
        }

        $data = json_decode($postData);
        if (!$data || empty($data->id)) {
            return new \WP_Error('invalid_payload', __('Invalid Stripe webhook payload', 'buy-me-coffee'));
        }

        $signatureHeader = $this->getStripeSignatureHeader();
        $webhookSecret = $this->getWebhookSecret($data);

        // Fail closed: never process Stripe webhooks without a configured secret.
        if (!$webhookSecret) {
            return new \WP_Error('missing_webhook_secret', __('Stripe webhook secret is not configured', 'buy-me-coffee'));
        }

        if (!$signatureHeader) {
            return new \WP_Error('missing_signature', __('Missing Stripe webhook signature header', 'buy-me-coffee'));
        }

        if (!$this->isValidSignature($postData, $signatureHeader, $webhookSecret)) {
            return new \WP_Error('invalid_signature', __('Invalid Stripe webhook signature', 'buy-me-coffee'));
        }

        return $data;
    }

    private function getStripeSignatureHeader()
    {
        if (!empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_STRIPE_SIGNATURE']));
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (!empty($headers['Stripe-Signature'])) {
                return sanitize_text_field($headers['Stripe-Signature']);
            }
            if (!empty($headers['stripe-signature'])) {
                return sanitize_text_field($headers['stripe-signature']);
            }
        }

        return '';
    }

    private function getWebhookSecret($payload)
    {
        $settings = StripeSettings::getSettings();
        $isLive = !empty($payload->livemode);
        $key = $isLive ? 'live_webhook_secret' : 'test_webhook_secret';

        return isset($settings[$key]) ? sanitize_text_field($settings[$key]) : '';
    }

    private function isValidSignature($payload, $signatureHeader, $secret)
    {
        $parts = explode(',', $signatureHeader);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            $pair = array_map('trim', explode('=', $part, 2));
            if (count($pair) !== 2) {
                continue;
            }

            if ($pair[0] === 't') {
                $timestamp = intval($pair[1]);
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if (!$timestamp || empty($signatures)) {
            return false;
        }

        // Stripe recommends a tolerance check to reduce replay window.
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

}