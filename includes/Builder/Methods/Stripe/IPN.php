<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class IPN
{
    /**
     * Parse the incoming Stripe webhook payload.
     *
     * Verification is handled by re-fetching the event from Stripe API
     * in the calling code (same approach as fluent-cart).
     *
     * @return object|\WP_Error  Parsed webhook payload or WP_Error on failure.
     */
    public function IPNData()
    {
        $postData = @file_get_contents('php://input');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Stripe webhook fallback; authenticity is verified by re-fetching the event from Stripe.
        if (!$postData && !empty($_POST)) {
            $postData = wp_json_encode(stripslashes_deep($_POST));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (!$postData) {
            self::log('Payload is empty — no php://input data and no $_POST fallback');
            return new \WP_Error('invalid_payload', __('Stripe payload is empty', 'buy-me-coffee'));
        }

        $data = json_decode($postData);
        if (!$data || empty($data->id)) {
            self::log('Payload JSON decode failed or missing id field. Raw length: ' . strlen($postData));
            return new \WP_Error('invalid_payload', __('Invalid Stripe webhook payload', 'buy-me-coffee'));
        }

        $eventType = isset($data->type) ? $data->type : 'unknown';
        self::log('Payload received — event_id: ' . $data->id . ', type: ' . $eventType);

        return $data;
    }

    private static function log($message)
    {
        if (defined('BUYMECOFFEE_DEBUG') && BUYMECOFFEE_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging
            error_log('[BuyMeCoffee][Stripe IPN] ' . $message);
        }
    }
}
