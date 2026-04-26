<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Classes\ActivityLogger;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class StripeSubscriptions
{
    /**
     * Create a Stripe subscription and return the inline payment intent for the first invoice.
     *
     * @param object $transaction  Transaction row
     * @param array  $paymentArgs  Shared payment arguments (amount, currency, keys, etc.)
     * @param string $apiKey       Stripe secret key
     * @param string $interval     'month' or 'year'
     */
    public function createSubscription($transaction, $paymentArgs, $apiKey, $interval)
    {
        try {
            // Always create a Stripe customer — subscriptions require it
            $customerData = [];
            if (!empty($paymentArgs['supporters_name'])) {
                $customerData['name'] = sanitize_text_field($paymentArgs['supporters_name']);
            }
            if (!empty($paymentArgs['supporters_email'])) {
                $customerData['email'] = sanitize_email($paymentArgs['supporters_email']);
            }

            $customer = (new API())->makeRequest('customers', $customerData, $apiKey, 'POST');

            if (is_wp_error($customer) || empty($customer['id'])) {
                wp_send_json_error([
                    'status'  => 'failed',
                    'message' => __('Could not create Stripe customer. Please try again.', 'buy-me-coffee'),
                ], 423);
                return;
            }

            $customerId = $customer['id'];

            // Create (or reuse cached) Stripe product for recurring donations
            $mode      = (strpos($apiKey, 'sk_live_') === 0) ? 'live' : 'test';
            $productId = $this->getOrCreateProduct($apiKey, $mode);

            // Build subscription payload
            $priceData = [
                'currency'    => strtolower($paymentArgs['currency']),
                'unit_amount' => (int) $paymentArgs['amount'],
                'recurring'   => ['interval' => $interval],
            ];

            if ($productId) {
                $priceData['product'] = $productId;
            } else {
                // Fallback: inline product_data (newer API versions only)
                $priceData['product_data'] = ['name' => __('Recurring Donation', 'buy-me-coffee')];
            }

            $subscriptionData = [
                'customer' => $customerId,
                'items'    => [['price_data' => $priceData]],
                'payment_behavior' => 'default_incomplete',
                'expand'           => ['latest_invoice.payment_intent'],
                'metadata'         => [
                    'ref_id'    => $paymentArgs['client_reference_id'],
                    'supporter' => admin_url('admin.php?page=buy-me-coffee.php#/supporter/' . $paymentArgs['supporter_id']),
                ],
            ];

            $stripeSubscription = (new API())->makeRequest('subscriptions', $subscriptionData, $apiKey, 'POST');

            if (is_wp_error($stripeSubscription)) {
                wp_send_json_error([
                    'status'  => 'failed',
                    'message' => $stripeSubscription->get_error_message(),
                ], 423);
            }

            // Extract the payment intent from the first invoice
            $paymentIntent = isset($stripeSubscription['latest_invoice']['payment_intent'])
                ? $stripeSubscription['latest_invoice']['payment_intent']
                : null;

            if (!$paymentIntent) {
                wp_send_json_error([
                    'status'  => 'failed',
                    'message' => __('Could not retrieve payment intent from subscription.', 'buy-me-coffee'),
                ], 423);
            }

            // Stamp ref_id onto the payment intent so updatePaymentData() can find the order.
            // Stripe puts subscription metadata on the subscription object, not the payment intent,
            // so we explicitly update the PI here.
            (new API())->makeRequest(
                'payment_intents/' . $paymentIntent['id'],
                ['metadata' => ['ref_id' => $paymentArgs['client_reference_id']]],
                $apiKey,
                'POST'
            );

            // Determine payment mode from Stripe object
            $paymentMode = isset($stripeSubscription['livemode']) && $stripeSubscription['livemode'] ? 'live' : 'test';

            // Insert local subscription record
            $subscriptionModel = new Subscriptions();
            $localSubscriptionId = $subscriptionModel->getQuery()->insert([
                'supporter_id'          => (int) $paymentArgs['supporter_id'],
                'stripe_subscription_id' => sanitize_text_field($stripeSubscription['id']),
                'stripe_customer_id'    => $customerId ? sanitize_text_field($customerId) : '',
                'interval_type'         => sanitize_text_field($interval),
                'amount'                => (int) $paymentArgs['amount'],
                'currency'              => sanitize_text_field(strtolower($paymentArgs['currency'])),
                'status'                => 'incomplete',
                'payment_mode'          => $paymentMode,
                'created_at'            => current_time('mysql'),
                'updated_at'            => current_time('mysql'),
            ]);

            ActivityLogger::logSubscription((int) $localSubscriptionId, 'subscription_created', 'Subscription created', [
                'status'     => 'info',
                'context'    => [
                    'subscription_id'        => $localSubscriptionId,
                    'supporter_id'           => $paymentArgs['supporter_id'],
                    'stripe_subscription_id' => $stripeSubscription['id'],
                    'interval'               => $interval,
                    'amount'                 => $paymentArgs['amount'],
                    'currency'               => $paymentArgs['currency'],
                ],
            ]);

            // Link transaction to subscription
            (new Transactions())->updateData($transaction->id, [
                'transaction_type' => 'recurring',
                'subscription_id'  => (int) $localSubscriptionId,
                'updated_at'       => current_time('mysql'),
            ]);

            $transaction->payment_args   = $paymentArgs;
            $transaction->subscription_id = (int) $localSubscriptionId;

            wp_send_json_success([
                'nextAction'      => 'stripe',
                'actionName'      => 'custom',
                'buttonState'     => 'hide',
                'intent'          => $paymentIntent,
                'order_items'     => $transaction,
                'is_subscription' => true,
                'subscription_id' => (int) $localSubscriptionId,
                'message_to_show' => __('Setting up your recurring donation, please complete the payment below.', 'buy-me-coffee'),
            ], 200);

        } catch (\Exception $e) {
            wp_send_json_error([
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ], 423);
        }
    }

    /**
     * Get or create a Stripe product for recurring donations.
     * Caches the product ID in wp_options per mode (test/live) to avoid creating duplicates.
     *
     * @param string $apiKey  Stripe secret key
     * @param string $mode    'test' or 'live'
     * @return string|null    Stripe product ID, or null on failure
     */
    private function getOrCreateProduct($apiKey, $mode)
    {
        $optionKey = 'buymecoffee_stripe_recurring_product_' . $mode;
        $cachedId  = get_option($optionKey, '');

        if ($cachedId) {
            return $cachedId;
        }

        $product = (new API())->makeRequest('products', [
            'name'     => __('Recurring Donation', 'buy-me-coffee'),
            'metadata' => ['source' => 'buy-me-coffee'],
        ], $apiKey, 'POST');

        if (is_wp_error($product) || empty($product['id'])) {
            return null;
        }

        update_option($optionKey, $product['id'], false);

        return $product['id'];
    }

    /**
     * Handle invoice.payment_succeeded webhook.
     * - If billing_reason = subscription_create: activate the subscription.
     * - Otherwise: create a new renewal transaction.
     *
     * @param object $event    Validated webhook payload from IPNData()
     * @param string $apiKey   Stripe secret key
     */
    public function handleRenewalWebhook($event, $apiKey)
    {
        $object        = isset($event->data->object) ? $event->data->object : null;
        $stripeSubId   = isset($object->subscription) ? sanitize_text_field($object->subscription) : null;
        $billingReason = isset($object->billing_reason) ? sanitize_text_field($object->billing_reason) : '';
        $invoiceId     = isset($object->id) ? sanitize_text_field($object->id) : '';

        if (!$stripeSubId) {
            return;
        }

        $subscriptionModel = new Subscriptions();
        $subscription      = $subscriptionModel->findByStripeId($stripeSubId);

        if (!$subscription) {
            return;
        }

        $periodEnd = isset($object->period_end) ? gmdate('Y-m-d H:i:s', (int) $object->period_end) : null;

        if ($billingReason === 'subscription_create') {
            // First payment: activate the local subscription record
            $subscriptionModel->updateData($subscription->id, [
                'status'             => 'active',
                'current_period_end' => $periodEnd,
                'updated_at'         => current_time('mysql'),
            ]);

            ActivityLogger::logSubscription((int) $subscription->id, 'subscription_activated', 'Subscription activated', [
                'status'     => 'success',
                'created_by' => 'webhook:stripe',
                'context'    => [
                    'subscription_id' => $subscription->id,
                    'supporter_id'    => $subscription->supporter_id,
                    'period_end'      => $periodEnd,
                ],
            ]);
            do_action('buymecoffee_subscription_activated', (int) $subscription->id);
            return;
        }

        // Renewal: create a new transaction record
        $amountPaid      = isset($object->amount_paid) ? (int) $object->amount_paid : 0;
        $currency        = isset($object->currency) ? sanitize_text_field($object->currency) : 'usd';
        $paymentIntentId = isset($object->payment_intent) ? sanitize_text_field($object->payment_intent) : '';

        $chargeId = '';
        if ($paymentIntentId && $apiKey) {
            $pi = (new API())->makeRequest('payment_intents/' . $paymentIntentId, [], $apiKey, 'GET');
            if (!is_wp_error($pi) && isset($pi['latest_charge'])) {
                $chargeId = sanitize_text_field($pi['latest_charge']);
            }
        }

        $supportersModel = new Supporters();
        $supporter       = $supportersModel->find((int) $subscription->supporter_id);
        if (!$supporter) {
            return;
        }

        // Deduplicate webhook retries/replays by invoice id for recurring renewals.
        if ($invoiceId) {
            $duplicate = buyMeCoffeeQuery()
                ->table('buymecoffee_transactions')
                ->where('subscription_id', (int) $subscription->id)
                ->where('payment_method', 'stripe')
                ->where('payment_note', 'like', '%"invoice_id":"' . $invoiceId . '"%')
                ->first();

            if ($duplicate) {
                return;
            }
        }

        buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
            'entry_id'         => (int) $subscription->supporter_id,
            'entry_hash'       => sanitize_text_field($supporter->entry_hash),
            'subscription_id'  => (int) $subscription->id,
            'transaction_type' => 'recurring',
            'payment_method'   => 'stripe',
            'charge_id'        => $chargeId,
            'payment_total'    => $amountPaid,
            'status'           => 'paid',
            'currency'         => strtoupper($currency),
            'payment_mode'     => sanitize_text_field($subscription->payment_mode),
            'payment_note'     => wp_json_encode([
                'invoice_id' => $invoiceId,
                'event_type' => isset($event->type) ? sanitize_text_field($event->type) : '',
            ]),
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
        ]);

        $subscriptionModel->updateData($subscription->id, [
            'status'             => 'active',
            'current_period_end' => $periodEnd,
            'updated_at'         => current_time('mysql'),
        ]);

        ActivityLogger::logSubscription((int) $subscription->id, 'subscription_renewed', 'Subscription renewed', [
            'status'     => 'success',
            'created_by' => 'webhook:stripe',
            'context'    => [
                'subscription_id' => $subscription->id,
                'supporter_id'    => $subscription->supporter_id,
                'amount'          => $amountPaid,
                'currency'        => $currency,
                'charge_id'       => $chargeId,
                'invoice_id'      => $invoiceId,
                'period_end'      => $periodEnd,
            ],
        ]);
    }

    /**
     * Handle customer.subscription.deleted webhook.
     *
     * @param object $event  Validated webhook payload
     */
    public function handleSubscriptionCancelled($event)
    {
        $object      = isset($event->data->object) ? $event->data->object : null;
        $stripeSubId = isset($object->id) ? sanitize_text_field($object->id) : null;

        if (!$stripeSubId) {
            return;
        }

        $subscriptionModel = new Subscriptions();
        $subscription      = $subscriptionModel->findByStripeId($stripeSubId);

        if (!$subscription) {
            return;
        }

        $subscriptionModel->updateData($subscription->id, [
            'status'       => 'cancelled',
            'cancelled_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ]);

        ActivityLogger::logSubscription((int) $subscription->id, 'subscription_cancelled', 'Subscription cancelled via Stripe', [
            'status'     => 'info',
            'created_by' => 'webhook:stripe',
            'context'    => [
                'subscription_id'        => $subscription->id,
                'supporter_id'           => $subscription->supporter_id,
                'stripe_subscription_id' => $stripeSubId,
            ],
        ]);
    }

    /**
     * Handle customer.subscription.updated webhook.
     *
     * @param object $event  Validated webhook payload
     */
    public function handleSubscriptionUpdated($event)
    {
        $object      = isset($event->data->object) ? $event->data->object : null;
        $stripeSubId = isset($object->id) ? sanitize_text_field($object->id) : null;

        if (!$stripeSubId) {
            return;
        }

        $subscriptionModel = new Subscriptions();
        $subscription      = $subscriptionModel->findByStripeId($stripeSubId);

        if (!$subscription) {
            return;
        }

        $stripeStatus = isset($object->status) ? sanitize_text_field($object->status) : '';
        $periodEnd    = isset($object->current_period_end) ? gmdate('Y-m-d H:i:s', (int) $object->current_period_end) : null;

        $update = ['updated_at' => current_time('mysql')];

        if ($stripeStatus) {
            $statusMap = [
                'active'            => 'active',
                'past_due'          => 'past_due',
                'unpaid'            => 'past_due',
                'canceled'          => 'cancelled',
                'incomplete'        => 'incomplete',
                'incomplete_expired' => 'cancelled',
                'trialing'          => 'active',
                'paused'            => 'past_due',
            ];
            $update['status'] = isset($statusMap[$stripeStatus]) ? $statusMap[$stripeStatus] : $stripeStatus;
        }

        if ($periodEnd) {
            $update['current_period_end'] = $periodEnd;
        }

        $subscriptionModel->updateData($subscription->id, $update);

        if (!empty($update['status'])) {
            ActivityLogger::logSubscription((int) $subscription->id, 'subscription_status_changed', 'Subscription status changed to ' . $update['status'], [
                'status'     => 'info',
                'created_by' => 'webhook:stripe',
                'context'    => [
                    'subscription_id' => $subscription->id,
                    'supporter_id'    => $subscription->supporter_id,
                    'old_status'      => $subscription->status,
                    'new_status'      => $update['status'],
                    'stripe_status'   => $stripeStatus,
                ],
            ]);
        }
    }
}
