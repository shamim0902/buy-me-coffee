<?php

namespace BuyMeCoffee\Services;

use BuyMeCoffee\Builder\Methods\Stripe\API as StripeAPI;
use BuyMeCoffee\Builder\Methods\Stripe\StripeSettings;
use BuyMeCoffee\Classes\ActivityLogger;
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
use BuyMeCoffee\Models\Subscriptions;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Single place where a recurring agreement is cancelled at the provider and
 * locally, so the admin route, the donor account route and supporter deletion
 * all enforce the same verification rules.
 */
class SubscriptionCancellationService
{
    /**
     * Local statuses that can no longer bill the donor, so no provider call is
     * required before the row is removed or re-cancelled.
     *
     * @var string[]
     */
    const TERMINAL_STATUSES = ['cancelled', 'canceled', 'expired', 'incomplete_expired'];

    /**
     * Is this local subscription already in a state that cannot bill again?
     *
     * @param object $subscription Local subscription row.
     * @return bool
     */
    public static function isTerminal($subscription)
    {
        $status = isset($subscription->status) ? strtolower((string) $subscription->status) : '';

        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * A remote agreement must be cancelled first when the row carries a provider
     * ID and is not already terminal. Everything else (local-only rows, already
     * cancelled rows) can be handled locally.
     *
     * @param object $subscription Local subscription row.
     * @return bool
     */
    public static function needsRemoteCancellation($subscription)
    {
        if (empty($subscription->stripe_subscription_id)) {
            return false;
        }

        return !self::isTerminal($subscription);
    }

    /**
     * Cancel the remote agreement and require the provider to confirm it.
     *
     * @param object $subscription Local subscription row.
     * @return true|\WP_Error True when nothing is billing remotely any more.
     */
    public function cancelRemote($subscription)
    {
        if (!self::needsRemoteCancellation($subscription)) {
            return true;
        }

        $remoteId = sanitize_text_field($subscription->stripe_subscription_id);
        $mode     = StripeSettings::resolveMode($subscription->payment_mode ?? '');
        $apiKey   = StripeSettings::getKeysForMode($mode, 'secret');

        if (empty($apiKey)) {
            return new \WP_Error(
                'bmc_stripe_key_missing',
                sprintf(
                    /* translators: 1: payment mode (live/test), 2: Stripe subscription ID. */
                    __('The %1$s mode Stripe secret key is missing, so subscription %2$s could not be cancelled. Add the key under Payment Settings and try again.', 'buy-me-coffee'),
                    $mode,
                    $remoteId
                ),
                ['subscription_id' => (int) $subscription->id, 'payment_mode' => $mode]
            );
        }

        $response = (new StripeAPI())->makeRequest('subscriptions/' . $remoteId, [], $apiKey, 'DELETE');

        if (is_wp_error($response)) {
            // A retry after a partially completed run (or a cancellation made in
            // the Stripe dashboard) hits an agreement that is already cancelled,
            // which Stripe rejects. Confirm the real state before blocking.
            if ($this->isCancelledAtStripe($remoteId, $apiKey)) {
                return true;
            }

            return new \WP_Error(
                'bmc_stripe_cancel_failed',
                sprintf(
                    /* translators: 1: Stripe subscription ID, 2: error message returned by Stripe. */
                    __('Stripe did not cancel subscription %1$s: %2$s', 'buy-me-coffee'),
                    $remoteId,
                    $response->get_error_message()
                ),
                ['subscription_id' => (int) $subscription->id, 'payment_mode' => $mode]
            );
        }

        $remoteStatus = sanitize_text_field(Arr::get($response, 'status', ''));

        if ($remoteStatus !== 'canceled') {
            return new \WP_Error(
                'bmc_stripe_cancel_unconfirmed',
                sprintf(
                    /* translators: 1: Stripe subscription ID, 2: status Stripe reported. */
                    __('Stripe did not confirm the cancellation of subscription %1$s (it reported "%2$s"). Please try again.', 'buy-me-coffee'),
                    $remoteId,
                    $remoteStatus ?: __('no status', 'buy-me-coffee')
                ),
                [
                    'subscription_id' => (int) $subscription->id,
                    'payment_mode'    => $mode,
                    'remote_status'   => $remoteStatus,
                ]
            );
        }

        return true;
    }

    /**
     * Read-only check that an agreement is already cancelled at Stripe.
     *
     * @param string $remoteId Stripe subscription ID.
     * @param string $apiKey   Secret key for the subscription's mode.
     * @return bool
     */
    private function isCancelledAtStripe($remoteId, $apiKey)
    {
        $current = (new StripeAPI())->makeRequest('subscriptions/' . $remoteId, [], $apiKey, 'GET');

        if (is_wp_error($current)) {
            return false;
        }

        return sanitize_text_field(Arr::get($current, 'status', '')) === 'canceled';
    }

    /**
     * Cancel remotely (when needed) and then record the cancellation locally.
     *
     * The local row is only written once the provider has confirmed, so a
     * failure never leaves a "cancelled" record that is still billing.
     *
     * @param object $subscription Local subscription row.
     * @param array  $args         {
     *     @type string $log_title  Activity log title.
     *     @type string $created_by Activity log actor.
     *     @type array  $context    Extra activity log context.
     * }
     * @return true|\WP_Error
     */
    public function cancel($subscription, array $args = [])
    {
        $remote = $this->cancelRemote($subscription);

        if (is_wp_error($remote)) {
            return $remote;
        }

        $persisted = $this->markCancelledLocally((int) $subscription->id);

        if (is_wp_error($persisted)) {
            return $persisted;
        }

        do_action('buymecoffee_subscription_cancelled', (int) $subscription->id);

        $context = isset($args['context']) && is_array($args['context']) ? $args['context'] : [];

        ActivityLogger::logSubscription(
            (int) $subscription->id,
            'subscription_cancelled',
            isset($args['log_title']) ? $args['log_title'] : __('Subscription cancelled', 'buy-me-coffee'),
            [
                'status'     => 'info',
                'created_by' => isset($args['created_by']) ? $args['created_by'] : '',
                'context'    => array_merge([
                    'subscription_id'        => (int) $subscription->id,
                    'supporter_id'           => (int) $subscription->supporter_id,
                    'stripe_subscription_id' => $subscription->stripe_subscription_id ?? '',
                ], $context),
            ]
        );

        return true;
    }

    /**
     * Write the cancellation to the local row and verify it landed.
     *
     * Stripe has already stopped billing at this point, so a silent write
     * failure would leave the UI reporting an "active" subscription that no
     * longer exists — callers must be able to surface that.
     *
     * @param int $subscriptionId Local subscription row ID.
     * @return true|\WP_Error
     */
    private function markCancelledLocally($subscriptionId)
    {
        global $wpdb;

        $now = current_time('mysql');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; the write result must be checked.
        $updated = $wpdb->update(
            $wpdb->prefix . 'buymecoffee_subscriptions',
            [
                'status'       => 'cancelled',
                'cancelled_at' => $now,
                'updated_at'   => $now,
            ],
            ['id' => $subscriptionId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return new \WP_Error(
                'bmc_subscription_persist_failed',
                sprintf(
                    /* translators: %s: database error message. */
                    __('The subscription was cancelled at Stripe but could not be saved locally: %s', 'buy-me-coffee'),
                    $wpdb->last_error ?: __('a database error occurred.', 'buy-me-coffee')
                ),
                ['subscription_id' => $subscriptionId]
            );
        }

        // An update reporting zero changed rows is not proof of failure (the row
        // may already hold these values), so confirm the stored state.
        $stored = (new Subscriptions())->find($subscriptionId);

        if (!$stored || $stored->status !== 'cancelled') {
            return new \WP_Error(
                'bmc_subscription_persist_failed',
                __('The subscription was cancelled at Stripe but the local record could not be updated. Please retry so the records match.', 'buy-me-coffee'),
                ['subscription_id' => $subscriptionId]
            );
        }

        return true;
    }
}
