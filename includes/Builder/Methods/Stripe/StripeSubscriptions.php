<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Models\MembershipAccess;
use BuyMeCoffee\Classes\ActivityLogger;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Services\GatewayAuditData;
use BuyMeCoffee\Services\PaymentTransitionService;

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
                // amount is stored in ×100 minor units; convert to the Stripe
                // native amount for this currency (zero-decimal must not be ×100).
                'unit_amount' => PaymentHelper::toStripeAmount((int) $paymentArgs['amount'], $paymentArgs['currency']),
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
            $periodEndTs = isset($stripeSubscription['current_period_end']) ? (int) $stripeSubscription['current_period_end'] : 0;
            $periodEnd   = $periodEndTs > 0 ? gmdate('Y-m-d H:i:s', $periodEndTs) : null;

            // Insert local subscription record
            $subscriptionModel = new Subscriptions();
            $levelId = !empty($paymentArgs['bmc_level_id']) ? absint($paymentArgs['bmc_level_id']) : null;
            $localSubscriptionId = $subscriptionModel->getQuery()->insert([
                'supporter_id'          => (int) $paymentArgs['supporter_id'],
                'stripe_subscription_id' => sanitize_text_field($stripeSubscription['id']),
                'stripe_customer_id'    => $customerId ? sanitize_text_field($customerId) : '',
                'interval_type'         => sanitize_text_field($interval),
                'amount'                => (int) $paymentArgs['amount'],
                'currency'              => sanitize_text_field(strtolower($paymentArgs['currency'])),
                'status'                => 'incomplete',
                'payment_mode'          => $paymentMode,
                'current_period_end'    => $periodEnd,
                'level_id'              => $levelId,
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
                    'period_end'             => $periodEnd,
                ],
            ]);

            // Link transaction to subscription, and bind the first invoice's
            // intent to it before the browser is handed that intent: the
            // confirmation that comes back is then matched to local state, and
            // to this subscription, without asking Stripe who it belongs to.
            (new Transactions())->updateData($transaction->id, [
                'transaction_type' => 'recurring',
                'subscription_id'  => (int) $localSubscriptionId,
                'charge_id'        => sanitize_text_field($paymentIntent['id']),
                'updated_at'       => current_time('mysql'),
            ]);

            $transaction->payment_args   = $paymentArgs;
            $transaction->subscription_id = (int) $localSubscriptionId;

            if ($levelId) {
                (new MembershipAccess())->upsertFromSubscription((int) $localSubscriptionId, false);
            }

            wp_send_json_success([
                'nextAction'      => 'stripe',
                'actionName'      => 'custom',
                'buttonState'     => 'hide',
                'intent'          => $paymentIntent,
                'order_items'     => $transaction,
                'is_subscription' => true,
                'is_membership'   => !empty($levelId),
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
     * - If billing_reason = subscription_create: settle the donation the
     *   subscription was started with, and activate the subscription.
     * - Otherwise: record the renewal payment.
     *
     * Both branches go through PaymentTransitionService, so the payment status,
     * the supporter, the canonical transition hook and everything hanging off it
     * are written by the same code that a browser confirmation uses — once, and
     * only when something actually moved.
     *
     * @param object $event    Validated webhook payload from IPNData()
     * @param string $apiKey   Stripe secret key
     * @return array Outcome, see subscriptionOutcome().
     */
    public function handleRenewalWebhook($event, $apiKey)
    {
        $object        = isset($event->data->object) ? $event->data->object : null;
        $stripeSubId   = isset($object->subscription) ? sanitize_text_field($object->subscription) : null;
        $billingReason = isset($object->billing_reason) ? sanitize_text_field($object->billing_reason) : '';
        $invoiceId     = isset($object->id) ? sanitize_text_field($object->id) : '';

        self::debugLog('handleRenewalWebhook: stripe_sub_id=' . ($stripeSubId ?: '(none)') . ', billing_reason=' . ($billingReason ?: '(none)') . ', invoice_id=' . ($invoiceId ?: '(none)'));

        if (!$stripeSubId) {
            self::debugLog('handleRenewalWebhook: no subscription ID in invoice object, skipping');
            return self::subscriptionOutcome('no_subscription_reference');
        }

        $subscriptionModel = new Subscriptions();
        $subscription      = $subscriptionModel->findByStripeId($stripeSubId);

        if (!$subscription) {
            self::debugLog('handleRenewalWebhook: no local subscription found for stripe_sub_id: ' . $stripeSubId);
            return self::subscriptionOutcome('subscription_not_found');
        }

        self::debugLog('handleRenewalWebhook: local subscription #' . $subscription->id . ' found, status=' . $subscription->status);

        // Fetch current_period_end from the Stripe subscription object.
        // The invoice's period_end is just the invoice draft/finalize timestamp,
        // NOT the subscription billing period end.
        $periodEnd = null;
        $stripeSub = (new API())->makeRequest('subscriptions/' . $stripeSubId, [], $apiKey, 'GET');
        if (!is_wp_error($stripeSub) && isset($stripeSub['current_period_end'])) {
            $periodEnd = gmdate('Y-m-d H:i:s', (int) $stripeSub['current_period_end']);
        }
        self::debugLog('handleRenewalWebhook: fetched current_period_end=' . ($periodEnd ?: '(none)'));

        if ($billingReason === 'subscription_create') {
            self::debugLog('handleRenewalWebhook: first payment — activating subscription #' . $subscription->id);

            return $this->applyInitialInvoice($subscription, $object, $periodEnd);
        }

        // Renewal: create a new transaction record
        $amountPaid      = isset($object->amount_paid) ? (int) $object->amount_paid : 0;
        $currency        = isset($object->currency) ? sanitize_text_field($object->currency) : 'usd';
        // Stripe amounts are in the currency's native scale; store in ×100 minor units.
        $storedAmount    = PaymentHelper::fromStripeAmount($amountPaid, $currency);
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
            return self::subscriptionOutcome('supporter_not_found');
        }

        self::debugLog('handleRenewalWebhook: renewal payment — recording invoice for subscription #' . $subscription->id);

        // One row per invoice, whatever the delivery does: the service claims
        // the invoice identity atomically, inserts at most once, and dispatches
        // the canonical transition for the row it created.
        $recorded = (new PaymentTransitionService())->recordRenewal([
            'entry_id'         => (int) $subscription->supporter_id,
            'entry_hash'       => sanitize_text_field($supporter->entry_hash),
            'subscription_id'  => (int) $subscription->id,
            'transaction_type' => 'recurring',
            'payment_method'   => 'stripe',
            'charge_id'        => $chargeId,
            'payment_total'    => $storedAmount,
            'currency'         => strtoupper($currency),
            'payment_mode'     => sanitize_text_field($subscription->payment_mode),
            'payment_note'     => GatewayAuditData::stripeInvoiceNote($invoiceId, [
                'event_type' => isset($event->type) ? sanitize_text_field($event->type) : '',
            ]),
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
        ], [
            'subscription_id' => (int) $subscription->id,
            'invoice_id'      => $invoiceId,
        ]);

        if (is_wp_error($recorded)) {
            self::debugLog('handleRenewalWebhook: renewal not recorded — ' . $recorded->get_error_code());

            return self::retryableOutcome($recorded);
        }

        // The billing period this invoice paid for, and the access it keeps
        // alive, are refreshed on every renewal; the activation announcement is
        // not, because the subscription was already active.
        // The renewal transaction that paid for this period is what the period
        // refresh is bound to, so a refund of it refuses the refresh as well.
        (new PaymentTransitionService())->activateSubscription((int) $subscription->id, $periodEnd, (int) $recorded['transaction_id']);

        if (empty($recorded['created'])) {
            self::debugLog('handleRenewalWebhook: invoice "' . $invoiceId . '" was already recorded');

            return self::subscriptionOutcome('duplicate_invoice', [
                'transaction_id' => (int) $recorded['transaction_id'],
                'changed'        => (bool) $recorded['changed'],
            ]);
        }

        ActivityLogger::logSubscription((int) $subscription->id, 'subscription_renewed', 'Subscription renewed', [
            'status'     => 'success',
            'created_by' => 'webhook:stripe',
            'context'    => [
                'subscription_id' => $subscription->id,
                'supporter_id'    => $subscription->supporter_id,
                'amount'          => $storedAmount,
                'currency'        => $currency,
                'charge_id'       => $chargeId,
                'invoice_id'      => $invoiceId,
                'period_end'      => $periodEnd,
            ],
        ]);

        return self::subscriptionOutcome('renewal_recorded', [
            'transaction_id' => (int) $recorded['transaction_id'],
            'changed'        => (bool) $recorded['changed'],
        ]);
    }

    /**
     * Apply the invoice that pays for a subscription's first period.
     *
     * The browser confirmation normally settles this donation, but it only runs
     * if the donor's browser comes back: a closed tab, a lost connection or a
     * blocked request leaves the transaction and the supporter pending forever
     * while the money is already taken. This invoice is Stripe's own statement
     * that the first period was paid, so it settles the same donation through
     * the same transition — and because that transition is idempotent, the
     * ordinary case where the browser already settled it changes nothing.
     *
     * @param object      $subscription Local subscription row.
     * @param object|null $invoice      Invoice object from the authenticated event.
     * @param string|null $periodEnd    Billing period end, when Stripe reported one.
     * @return array Outcome, see subscriptionOutcome().
     */
    private function applyInitialInvoice($subscription, $invoice, $periodEnd)
    {
        $original = $this->findInitialTransaction((int) $subscription->id);

        // Activation grants recurring membership access. It must therefore be
        // conditional on the same durable paid transaction as the canonical
        // payment transition: a malformed invoice, a missing checkout row, or
        // a terminal refund may not activate the subscription on its own.
        if (!$original || !$this->invoiceReportsPayment($invoice)) {
            return self::subscriptionOutcome('initial_invoice_not_applied', [
                'transaction_id' => $original ? (int) $original->id : 0,
            ]);
        }

        $transition = (new PaymentTransitionService())->apply($original, 'paid');

        if (is_wp_error($transition)) {
            if ($this->isRetryable($transition)) {
                self::debugLog('handleRenewalWebhook: initial invoice could not be applied — ' . $transition->get_error_code());

                return self::retryableOutcome($transition);
            }

            // A terminal refusal is a durable decision. Consume the event, but
            // leave the subscription and its entitlements unchanged.
            self::debugLog('handleRenewalWebhook: initial invoice refused — ' . $transition->get_error_code());

            return self::subscriptionOutcome('initial_invoice_refused', [
                'transaction_id' => (int) $original->id,
            ]);
        }

        $activation = (new PaymentTransitionService())->activateSubscription((int) $subscription->id, $periodEnd, (int) $original->id);

        if (!empty($activation['activated'])) {
            ActivityLogger::logSubscription((int) $subscription->id, 'subscription_activated', 'Subscription activated', [
                'status'     => 'success',
                'created_by' => 'webhook:stripe',
                'context'    => [
                    'subscription_id' => $subscription->id,
                    'supporter_id'    => $subscription->supporter_id,
                    'period_end'      => $periodEnd,
                ],
            ]);
        }

        return self::subscriptionOutcome('initial_invoice_applied', [
            'transaction_id' => (int) $transition['transaction_id'],
            'changed'        => (bool) $transition['changed'],
            'activated'      => !empty($activation['activated']),
        ]);
    }

    /**
     * The transaction the subscription was started with.
     *
     * Checkout creates it and links it to the subscription before Stripe is ever
     * asked to bill, so it is the oldest row pointing at this subscription;
     * every later row is a renewal recorded from its own invoice.
     *
     * @param int $subscriptionId Local subscription row ID.
     * @return object|null
     */
    private function findInitialTransaction($subscriptionId)
    {
        return buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('subscription_id', absint($subscriptionId))
            ->orderBy('id', 'ASC')
            ->first();
    }

    /**
     * Whether an invoice object actually states that it was paid.
     *
     * `invoice.payment_succeeded` says so by definition, but the money state is
     * read from the invoice's own fields rather than from the event's name, so a
     * malformed or unexpected object can never settle a donation.
     *
     * @param object|null $invoice Invoice object from the authenticated event.
     * @return bool
     */
    private function invoiceReportsPayment($invoice)
    {
        if (!is_object($invoice)) {
            return false;
        }

        if (!empty($invoice->paid)) {
            return true;
        }

        return isset($invoice->amount_paid) && (int) $invoice->amount_paid > 0;
    }

    /**
     * Whether a refused transition is worth another delivery.
     *
     * @param \WP_Error $error Transition error.
     * @return bool
     */
    private function isRetryable($error)
    {
        $data = $error->get_error_data();

        return is_array($data) && !empty($data['retryable']);
    }

    /**
     * Build the record of what a subscription event decided.
     *
     * @param string $code  Machine-readable outcome.
     * @param array  $extra Outcome details.
     * @return array
     */
    private static function subscriptionOutcome($code, array $extra = [])
    {
        return array_merge([
            'code'           => $code,
            'retryable'      => false,
            'transaction_id' => 0,
            'changed'        => false,
            'activated'      => false,
            'message'        => '',
            'retry_after'    => 0,
        ], $extra);
    }

    /**
     * Turn a transition failure into an outcome the webhook can answer with, so
     * the provider redelivers instead of the event being marked consumed.
     *
     * @param \WP_Error $error Transition error.
     * @return array
     */
    private static function retryableOutcome($error)
    {
        $data = $error->get_error_data();

        return self::subscriptionOutcome($error->get_error_code(), [
            'retryable'   => true,
            'message'     => $error->get_error_message(),
            'retry_after' => (is_array($data) && !empty($data['retry_after'])) ? (int) $data['retry_after'] : 0,
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

        self::debugLog('handleSubscriptionCancelled: stripe_sub_id=' . ($stripeSubId ?: '(none)'));

        if (!$stripeSubId) {
            return;
        }

        $subscriptionModel = new Subscriptions();
        $subscription      = $subscriptionModel->findByStripeId($stripeSubId);

        if (!$subscription) {
            self::debugLog('handleSubscriptionCancelled: no local subscription for stripe_sub_id: ' . $stripeSubId);
            return;
        }

        self::debugLog('handleSubscriptionCancelled: cancelling local subscription #' . $subscription->id);

        $subscriptionModel->updateData($subscription->id, [
            'status'       => 'cancelled',
            'cancelled_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ]);
        if (!empty($subscription->level_id)) {
            (new MembershipAccess())->upsertFromSubscription((int) $subscription->id);
        }
        do_action('buymecoffee_subscription_cancelled', (int) $subscription->id);

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

        self::debugLog('handleSubscriptionUpdated: stripe_sub_id=' . ($stripeSubId ?: '(none)'));

        if (!$stripeSubId) {
            return;
        }

        $subscriptionModel = new Subscriptions();
        $subscription      = $subscriptionModel->findByStripeId($stripeSubId);

        if (!$subscription) {
            self::debugLog('handleSubscriptionUpdated: no local subscription for stripe_sub_id: ' . $stripeSubId);
            return;
        }

        $stripeStatus = isset($object->status) ? sanitize_text_field($object->status) : '';
        self::debugLog('handleSubscriptionUpdated: subscription #' . $subscription->id . ' current_status=' . $subscription->status . ', stripe_status=' . ($stripeStatus ?: '(none)'));
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
        if (!empty($subscription->level_id)) {
            (new MembershipAccess())->upsertFromSubscription((int) $subscription->id);
        }
        if (!empty($update['status']) && $update['status'] !== $subscription->status) {
            do_action('buymecoffee_subscription_status_changed', (int) $subscription->id, (string) $subscription->status, (string) $update['status']);
        }

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

    /**
     * Fetch a subscription from Stripe and sync all invoices as local transactions.
     *
     * @param object $subscription  Local subscription row from DB
     * @return object|\WP_Error     Updated subscription or WP_Error on failure
     */
    public function fetchFromRemote($subscription)
    {
        $stripeSubId = $subscription->stripe_subscription_id;
        if (!$stripeSubId) {
            return new \WP_Error('missing_stripe_id', __('This subscription has no Stripe subscription ID.', 'buy-me-coffee'));
        }

        $keys   = StripeSettings::getKeys();
        $apiKey = $keys['secret'];
        $api    = new API();

        // 1. Fetch subscription from Stripe
        $stripeSub = $api->makeRequest('subscriptions/' . sanitize_text_field($stripeSubId), [], $apiKey, 'GET');
        if (is_wp_error($stripeSub)) {
            return $stripeSub;
        }

        // 2. Map Stripe status to local status
        $statusMap = [
            'active'             => 'active',
            'past_due'           => 'past_due',
            'unpaid'             => 'past_due',
            'canceled'           => 'cancelled',
            'incomplete'         => 'incomplete',
            'incomplete_expired' => 'cancelled',
            'trialing'           => 'active',
            'paused'             => 'past_due',
        ];

        $stripeStatus = sanitize_text_field($stripeSub['status'] ?? '');
        $localStatus  = isset($statusMap[$stripeStatus]) ? $statusMap[$stripeStatus] : $stripeStatus;
        $periodEndTs  = (int) ($stripeSub['current_period_end'] ?? 0);
        $periodEnd    = $periodEndTs > 0 ? gmdate('Y-m-d H:i:s', $periodEndTs) : null;

        $subscriptionUpdate = [
            'status'     => $localStatus,
            'updated_at' => current_time('mysql'),
        ];
        if ($periodEnd) {
            $subscriptionUpdate['current_period_end'] = $periodEnd;
        }
        if ($localStatus === 'cancelled' && empty($subscription->cancelled_at)) {
            $cancelledTs = (int) ($stripeSub['canceled_at'] ?? 0);
            $subscriptionUpdate['cancelled_at'] = $cancelledTs > 0
                ? gmdate('Y-m-d H:i:s', $cancelledTs)
                : current_time('mysql');
        }

        // 3. Fetch all paid invoices for this subscription
        $invoicesResponse = $api->getList('invoices', [
            'subscription' => $stripeSubId,
            'status'       => 'paid',
            'limit'        => 100,
        ], $apiKey);

        $newTransactions = 0;

        if (!is_wp_error($invoicesResponse) && !empty($invoicesResponse['data'])) {
            $supporter = (new Supporters())->find((int) $subscription->supporter_id);
            if (!$supporter) {
                return new \WP_Error('supporter_not_found', __('Supporter not found for this subscription.', 'buy-me-coffee'));
            }

            foreach ($invoicesResponse['data'] as $invoice) {
                $invoiceId     = sanitize_text_field($invoice['id'] ?? '');
                $paymentIntent = sanitize_text_field($invoice['payment_intent'] ?? '');
                $amountPaid    = (int) ($invoice['amount_paid'] ?? 0);
                $currency      = sanitize_text_field($invoice['currency'] ?? 'usd');

                if (!$invoiceId) {
                    continue;
                }

                // Check if this invoice already has a local transaction
                $existing = null;

                // Match by charge_id (payment_intent)
                if ($paymentIntent) {
                    $existing = buyMeCoffeeQuery()
                        ->table('buymecoffee_transactions')
                        ->where('subscription_id', (int) $subscription->id)
                        ->where('charge_id', $paymentIntent)
                        ->first();
                }

                // Match by invoice_id in payment_note
                if (!$existing && $invoiceId) {
                    $existing = buyMeCoffeeQuery()
                        ->table('buymecoffee_transactions')
                        ->where('subscription_id', (int) $subscription->id)
                        ->where('payment_method', 'stripe')
                        ->where('payment_note', 'like', '%"invoice_id":"' . addcslashes($invoiceId, '%_') . '"%')
                        ->first();
                }

                if ($existing) {
                    // Update charge_id if missing
                    $updates = [];
                    if (empty($existing->charge_id) && $paymentIntent) {
                        $updates['charge_id'] = $paymentIntent;
                    }
                    if ($existing->status !== 'paid') {
                        $updates['status'] = 'paid';
                    }
                    if ($updates) {
                        $updates['updated_at'] = current_time('mysql');
                        buyMeCoffeeQuery()
                            ->table('buymecoffee_transactions')
                            ->where('id', $existing->id)
                            ->update($updates);
                    }
                    continue;
                }

                // Also skip if this is the first invoice and we already have
                // the original transaction from createSubscription()
                $billingReason = sanitize_text_field($invoice['billing_reason'] ?? '');
                if ($billingReason === 'subscription_create') {
                    // Check if the original transaction exists (no invoice_id in payment_note)
                    $original = buyMeCoffeeQuery()
                        ->table('buymecoffee_transactions')
                        ->where('subscription_id', (int) $subscription->id)
                        ->where('payment_method', 'stripe')
                        ->orderBy('id', 'ASC')
                        ->first();

                    if ($original) {
                        // Backfill charge_id if missing
                        if (empty($original->charge_id) && $paymentIntent) {
                            buyMeCoffeeQuery()
                                ->table('buymecoffee_transactions')
                                ->where('id', $original->id)
                                ->update([
                                    'charge_id'  => $paymentIntent,
                                    'status'     => 'paid',
                                    'updated_at' => current_time('mysql'),
                                ]);
                        }
                        continue;
                    }
                }

                // Create new transaction for this invoice
                $chargeId = $paymentIntent;
                $invoiceCreated = (int) ($invoice['created'] ?? 0);

                buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
                    'entry_id'         => (int) $subscription->supporter_id,
                    'entry_hash'       => sanitize_text_field($supporter->entry_hash),
                    'subscription_id'  => (int) $subscription->id,
                    'transaction_type' => 'recurring',
                    'payment_method'   => 'stripe',
                    'charge_id'        => $chargeId,
                    // Stripe amounts are native scale; store in ×100 minor units.
                    'payment_total'    => PaymentHelper::fromStripeAmount($amountPaid, $currency),
                    'status'           => 'paid',
                    'currency'         => strtoupper($currency),
                    'payment_mode'     => sanitize_text_field($subscription->payment_mode),
                    'payment_note'     => GatewayAuditData::stripeInvoiceNote($invoiceId, [
                        'billing_reason' => $billingReason,
                        'fetched_from'   => 'remote_sync',
                    ]),
                    'created_at'       => $invoiceCreated > 0 ? gmdate('Y-m-d H:i:s', $invoiceCreated) : current_time('mysql'),
                    'updated_at'       => current_time('mysql'),
                ]);

                $newTransactions++;
            }
        }

        if ($newTransactions > 0) {
            Supporters::flushPublicSupportersCache();
            Supporters::flushAdminReportCache();
        }

        // 4. Update local subscription record
        (new Subscriptions())->updateData($subscription->id, $subscriptionUpdate);
        if (!empty($subscription->level_id)) {
            (new MembershipAccess())->upsertFromSubscription((int) $subscription->id);
        }

        ActivityLogger::logSubscription((int) $subscription->id, 'subscription_fetched', 'Subscription fetched from Stripe', [
            'status'  => 'info',
            'context' => [
                'subscription_id'   => $subscription->id,
                'stripe_status'     => $stripeStatus,
                'local_status'      => $localStatus,
                'period_end'        => $periodEnd,
                'new_transactions'  => $newTransactions,
            ],
        ]);

        return (new Subscriptions())->find($subscription->id);
    }

    private static function debugLog($message)
    {
        if (defined('BUYMECOFFEE_DEBUG') && BUYMECOFFEE_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging
            error_log('[BuyMeCoffee][Stripe Webhook] ' . $message);
        }
    }
}
