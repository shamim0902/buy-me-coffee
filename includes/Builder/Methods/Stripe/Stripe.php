<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

use BuyMeCoffee\Builder\Methods\BaseMethods;
use BuyMeCoffee\Classes\ActivityLogger;
use BuyMeCoffee\Classes\Vite;
use BuyMeCoffee\Helpers\ArrayHelper;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Models\MembershipAccess;
use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Services\GatewayAuditData;
use BuyMeCoffee\Services\OneTimePaymentStatusService;
use BuyMeCoffee\Services\PublicRequestGuard;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Stripe extends BaseMethods
{
    public function __construct()
    {
        parent::__construct(
            'Stripe',
            'stripe',
            'Stripe is a payment gateway that allows you to accept payments from your customers.',
            Vite::staticPath() . 'images/stripe.svg'
        );

        $this->supports = ['one_time', 'subscription'];

        add_action('buymecoffee_make_payment_stripe', array($this, 'makePayment'), 10, 3);
        add_action("buymecoffee_ipn_endpoint_stripe", array($this, 'verifyIpn'), 10, 2);
        add_action('buymecoffee_get_payment_settings_stripe', array($this, 'getPaymentSettings'));
        add_filter('buymecoffee_process_refund_stripe', array($this, 'processRefund'), 10, 2);
    }

    public function makePayment($transactionId, $entryId, $form_data)
    {
        $transactionModel = new Transactions();
        $transaction = $transactionModel->find($transactionId);

        $supportersModel = new Supporters();
        $supporter = $supportersModel->find($entryId);
        if (!$transaction || !$supporter) {
            wp_send_json_error([
                'status' => 'failed',
                'message' => __('Sorry no transaction created!', 'buy-me-coffee')
            ], 423);
        }

        $hash = $transaction->entry_hash;

        $keys = StripeSettings::getKeys();
        $apiKey = $keys['secret'];

        $paymentArgs = array(
            'supporter_id' => $supporter->id,
            'client_reference_id' => $hash,
            'amount' => (int) round($transaction->payment_total, 0),
            'currency' => strtolower($transaction->currency),
            'description' => "Buy coffee from {$supporter->supporters_name}",
            'supporters_email' => $supporter->supporters_email,
            'supporters_name' => $supporter->supporters_name,
            'success_url' => $this->successUrl($supporter),
            'public_key' => $keys['public']
        );

        // Pass membership level ID through to subscription creation
        if (!empty($form_data['bmc_level_id'])) {
            $paymentArgs['bmc_level_id'] = absint($form_data['bmc_level_id']);
        }

        $isRecurring = isset($form_data['is_recurring']) && $form_data['is_recurring'] === 'yes';
        if ($isRecurring) {
            $interval        = isset($form_data['recurring_interval']) ? sanitize_text_field($form_data['recurring_interval']) : 'month';
            $allowedIntervals = ['month', 'year'];
            if (!in_array($interval, $allowedIntervals, true)) {
                $interval = 'month';
            }
            (new StripeSubscriptions())->createSubscription($transaction, $paymentArgs, $apiKey, $interval);
        } else {
            if (!empty($paymentArgs['bmc_level_id'])) {
                $membershipAccessId = $this->createOneTimeMembershipAccess($transaction, $paymentArgs);
                if ($membershipAccessId) {
                    $paymentArgs['membership_access_id'] = $membershipAccessId;
                }
            }

            $this->handleInlinePayment($transaction, $paymentArgs, $apiKey);
        }
    }

    public function paymentConfirmation()
    {
        $this->verifyPublicRequestNonce();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe payment confirmation callback
        if (!isset($_REQUEST['intentId'])) {
            wp_send_json_error([
                'message' => __('Payment intent is missing', 'buy-me-coffee')
            ], 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe payment confirmation callback
        $intentId = sanitize_text_field(wp_unslash($_REQUEST['intentId']));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe payment confirmation callback
        $requestedSubscriptionId = isset($_REQUEST['subscriptionId']) ? absint($_REQUEST['subscriptionId']) : 0;

        // Consumed before any Stripe call. The per-operation bucket is scoped to
        // the caller as well as to the intent, so replaying somebody else's
        // intent id cannot use up the budget their own browser needs.
        PublicRequestGuard::enforce('payment_confirmation_stripe', ['identifier' => $intentId]);

        $helper = new PaymentHelper();

        // Ownership before anything else. Checkout bound this intent to a local
        // transaction before the browser saw it, so an intent this site never
        // created is refused here — it can neither reach Stripe nor take out a
        // claim that would lock a real donor's confirmation.
        $transaction = $helper->findStripeTransactionByIntent($intentId);
        if (!$transaction) {
            wp_send_json_error([
                'message' => __('This payment could not be matched to an order.', 'buy-me-coffee'),
                'code'    => 'payment_intent_not_recognized',
            ], 404);
        }

        // The subscription the caller claims has to be the one this intent was
        // bound to at checkout; anything else is refused before a provider call.
        $boundSubscriptionId = !empty($transaction->subscription_id) ? (int) $transaction->subscription_id : 0;
        if ($requestedSubscriptionId && $requestedSubscriptionId !== $boundSubscriptionId) {
            wp_send_json_error([
                'message' => __('Subscription confirmation mismatch. Please refresh and try again.', 'buy-me-coffee'),
                'code'    => 'subscription_mismatch',
            ], 403);
        }

        // A confirmation whose local result is already final is answered from
        // storage: no customer, intent, invoice or subscription lookup at Stripe.
        $replayed = $helper->replayConfirmationResult($intentId, $requestedSubscriptionId);
        if ($replayed) {
            wp_send_json_success(wp_parse_args($replayed, [
                'message' => __('Payment confirmation received', 'buy-me-coffee'),
            ]), 200);
        }

        // Two browser tabs, a reload during 3-D Secure, or a retry from a second
        // address are all the same operation on the same intent, so the lease is
        // taken on the intent itself rather than on whoever is asking. Anything
        // that reaches Stripe from here does so holding it.
        $claim = PublicRequestGuard::claim('stripe_confirmation', 'intent|' . $intentId);

        if (!$claim['acquired']) {
            $this->refuseUnclaimedConfirmation($claim, $helper, $intentId, $requestedSubscriptionId);
        }

        if ($requestedSubscriptionId && !$this->activateMatchedSubscription($intentId, $requestedSubscriptionId)) {
            PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);

            wp_send_json_error([
                'message' => __('Subscription confirmation mismatch. Please refresh and try again.', 'buy-me-coffee')
            ], 403);
        }

        // Fallback / full update: re-query Stripe to record charge details, card info, etc.
        // Also activates subscription if subscription_id is on the transaction (covers webhook-less setups).
        $paymentResult = (new PaymentHelper())->updatePaymentData($intentId);

        if (is_wp_error($paymentResult)) {
            PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);

            wp_send_json_error([
                'message' => $paymentResult->get_error_message(),
            ], 400);
        }

        $response = wp_parse_args((array) $paymentResult, [
            'message' => __('Payment confirmation received', 'buy-me-coffee'),
        ]);

        if (in_array(ArrayHelper::get($response, 'payment_status', ''), ['paid', 'refunded', 'failed'], true)) {
            // Settled: the answer cannot change, so it is safe to keep and to
            // replay. It describes statuses and row ids only — no intent id, no
            // client secret, nothing that would be a token in storage.
            PublicRequestGuard::completeClaim($claim['key'], $claim['owner'], $response, 'stripe_confirmation');
        } else {
            // Still in flight at Stripe. Burning the lease would leave the donor
            // unable to ask again, so it is given straight back.
            PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);
        }

        wp_send_json_success($response, 200);
    }

    /**
     * Answer a confirmation that could not take the lease on its intent.
     *
     * A finished one replays local state; one still running is a conflict the
     * caller may retry, and neither costs a Stripe call.
     *
     * @param array         $claim                   Refused claim record.
     * @param PaymentHelper $helper                  Shared helper instance.
     * @param string        $intentId                Stripe PaymentIntent id.
     * @param int           $requestedSubscriptionId Subscription the caller claims.
     * @return void
     */
    private function refuseUnclaimedConfirmation(array $claim, PaymentHelper $helper, $intentId, $requestedSubscriptionId)
    {
        if (!$claim['available']) {
            $decision = PublicRequestGuard::unavailable();
            PublicRequestGuard::sendRetryAfter($decision['retry_after']);

            wp_send_json_error([
                'message' => $decision['message'],
                'code'    => $decision['code'],
            ], $decision['http']);
        }

        if ($claim['state'] === PublicRequestGuard::STATE_COMPLETED) {
            $replayed = $helper->replayConfirmationResult($intentId, $requestedSubscriptionId);
            if ($replayed) {
                wp_send_json_success(wp_parse_args($replayed, [
                    'message' => __('Payment confirmation received', 'buy-me-coffee'),
                ]), 200);
            }

            if (is_array($claim['payload'])) {
                wp_send_json_success($claim['payload'], 200);
            }
        }

        PublicRequestGuard::sendRetryAfter($claim['retry_after']);

        wp_send_json_error([
            'message' => __('This payment is still being confirmed. Please try again in a moment.', 'buy-me-coffee'),
            'code'    => 'confirmation_in_progress',
        ], 409);
    }

    private function activateMatchedSubscription($intentId, $requestedSubscriptionId)
    {
        $requestedSubscriptionId = absint($requestedSubscriptionId);
        if (!$requestedSubscriptionId) {
            return false;
        }

        $intent = (new API())->makeRequest(
            'payment_intents/' . $intentId,
            [],
            StripeSettings::getKeys('secret'),
            'GET'
        );

        if (is_wp_error($intent)) {
            return false;
        }

        $intentStatus = sanitize_text_field(ArrayHelper::get($intent, 'status', ''));
        if (!in_array($intentStatus, ['succeeded', 'processing'], true)) {
            return false;
        }

        $orderHash = sanitize_text_field(ArrayHelper::get($intent, 'metadata.ref_id', ''));

        $localSubscription = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('id', $requestedSubscriptionId)
            ->first();

        if (!$localSubscription) {
            return false;
        }

        $transaction = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('subscription_id', $requestedSubscriptionId)
            ->where('payment_method', 'stripe')
            ->first();

        if (!$transaction || (int) $transaction->entry_id !== (int) $localSubscription->supporter_id) {
            return false;
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', (int) $localSubscription->supporter_id)
            ->first();

        if (!$supporter) {
            return false;
        }

        $intentAmount = (int) ArrayHelper::get($intent, 'amount', 0);
        if ($intentAmount <= 0) {
            $intentAmount = (int) ArrayHelper::get($intent, 'amount_received', 0);
        }

        // Local amount is stored in ×100 minor units; the Stripe intent amount is
        // in the currency's native scale, so convert before comparing.
        $expectedAmount = PaymentHelper::toStripeAmount((int) $localSubscription->amount, $localSubscription->currency);
        if ($intentAmount > 0 && $expectedAmount > 0 && $intentAmount !== $expectedAmount) {
            return false;
        }

        $intentCurrency = strtolower(sanitize_text_field(ArrayHelper::get($intent, 'currency', '')));
        $expectedCurrency = strtolower(sanitize_text_field($localSubscription->currency));
        if ($intentCurrency && $expectedCurrency && $intentCurrency !== $expectedCurrency) {
            return false;
        }

        if ($orderHash && (!hash_equals((string) $transaction->entry_hash, $orderHash) || !hash_equals((string) $supporter->entry_hash, $orderHash))) {
            return false;
        }

        if (!$orderHash && !$this->intentBelongsToSubscription($intentId, $localSubscription)) {
            return false;
        }

        if ($intentStatus !== 'succeeded') {
            (new Supporters())->updateData((int) $supporter->id, [
                'payment_status' => 'pending',
                'updated_at'     => current_time('mysql'),
            ]);
            (new Transactions())->updateData((int) $transaction->id, [
                'status'       => 'pending',
                'charge_id'    => sanitize_text_field($intentId),
                'payment_mode' => !empty($intent['livemode']) ? 'live' : 'test',
                'payment_note' => GatewayAuditData::stripePaymentIntentNote($intent),
                'updated_at'   => current_time('mysql'),
            ]);

            return true;
        }

        $update = [
            'status'     => 'active',
            'updated_at' => current_time('mysql'),
        ];

        if ($localSubscription && !empty($localSubscription->stripe_subscription_id)) {
            $stripeSubscription = (new API())->makeRequest(
                'subscriptions/' . sanitize_text_field($localSubscription->stripe_subscription_id),
                [],
                StripeSettings::getKeys('secret'),
                'GET'
            );

            if (!is_wp_error($stripeSubscription)) {
                $periodEndTs = (int) ArrayHelper::get($stripeSubscription, 'current_period_end', 0);
                if ($periodEndTs > 0) {
                    $update['current_period_end'] = gmdate('Y-m-d H:i:s', $periodEndTs);
                }
            }
        }

        (new Subscriptions())->updateData($requestedSubscriptionId, $update);
        (new Supporters())->updateData((int) $supporter->id, [
            'payment_status' => $intentStatus === 'succeeded' ? 'paid' : 'pending',
            'updated_at'     => current_time('mysql'),
        ]);
        (new Transactions())->updateData((int) $transaction->id, [
            'status'       => $intentStatus === 'succeeded' ? 'paid' : 'pending',
            'charge_id'    => sanitize_text_field($intentId),
            'payment_mode' => !empty($intent['livemode']) ? 'live' : 'test',
            'payment_note' => GatewayAuditData::stripePaymentIntentNote($intent),
            'updated_at'   => current_time('mysql'),
        ]);

        do_action('buymecoffee_subscription_activated', $requestedSubscriptionId);

        return true;
    }

    private function intentBelongsToSubscription($intentId, $subscription): bool
    {
        if (empty($subscription->stripe_subscription_id)) {
            return false;
        }

        $invoices = (new API())->getList('invoices', [
            'subscription' => sanitize_text_field($subscription->stripe_subscription_id),
            'limit'        => 10,
        ], StripeSettings::getKeys('secret'));

        if (is_wp_error($invoices) || empty($invoices['data'])) {
            return false;
        }

        foreach ($invoices['data'] as $invoice) {
            if (!empty($invoice['payment_intent']) && hash_equals((string) $intentId, (string) $invoice['payment_intent'])) {
                return true;
            }
        }

        return false;
    }

    public function handleInlinePayment($transaction, $paymentArgs, $apiKey)
    {
        try {
            if (!empty($paymentArgs['supporters_email'])) {
                $customer = (new API())->makeRequest('customers', [
                        'name' => $paymentArgs['supporters_name'],
                        'email' => $paymentArgs['supporters_email'],
                ],$apiKey, 'POST');
                if (!is_wp_error($customer) && isset($customer['id'])) {
                    $paymentArgs['vendor_customer_id'] = $customer['id'];
                }
            }

            $intentData = $this->intentData($paymentArgs);
            $invoiceResponse = (new API())->makeRequest('payment_intents', $intentData, $apiKey, 'POST');

            if (is_wp_error($invoiceResponse)) {
                wp_send_json_error([
                    'status' => 'failed',
                    'message' => $invoiceResponse->get_error_message()
                ], 423);
            }

            // Bind the intent to this order before the browser is handed it, so
            // the confirmation that comes back can be matched to local state
            // without asking Stripe who the intent belongs to.
            $intentId = isset($invoiceResponse['id']) ? sanitize_text_field($invoiceResponse['id']) : '';
            if (!$intentId || !(new PaymentHelper())->bindStripeIntent((int) $transaction->id, $intentId)) {
                // The confirmation matches on this binding and on nothing else,
                // so handing the browser an intent that was never bound would
                // send the donor to a payment whose confirmation is refused as
                // unrecognised. Refusing here instead costs an abandoned
                // checkout; going on costs a paid donation nobody can confirm.
                wp_send_json_error([
                    'status'  => 'failed',
                    'message' => __('This payment could not be started. Please try again.', 'buy-me-coffee'),
                ], 500);
            }

            $transaction->charge_id = $intentId;

            $transaction->payment_args = $paymentArgs;
            $responseData = [
                'nextAction' => 'stripe',
                'actionName' => 'custom',
                'buttonState' => 'hide',
                'intent' => $invoiceResponse,
                'order_items' => $transaction,
                'message_to_show' => __('Payment Modal is opening, Please complete the payment', 'buy-me-coffee'),
            ];

            if (!empty($paymentArgs['membership_access_id'])) {
                $responseData['membership_access_id'] = (int) $paymentArgs['membership_access_id'];
                $responseData['is_membership'] = true;
            }

            wp_send_json_success($responseData, 200);
        } catch (\Exception $e) {
            wp_send_json_error([
                'status' => 'failed',
                'message' => $e->getMessage()
            ], 423);
        }
    }

    private function createOneTimeMembershipAccess($transaction, array $paymentArgs): int
    {
        $levelId = !empty($paymentArgs['bmc_level_id']) ? absint($paymentArgs['bmc_level_id']) : 0;
        if (!$levelId) {
            return 0;
        }

        $accessId = (new MembershipAccess())->createPendingForTransaction(
            (int) $transaction->id,
            (int) $paymentArgs['supporter_id'],
            $levelId,
            'one_time'
        );

        if (!$accessId) {
            return 0;
        }

        ActivityLogger::logMembershipAccess((int) $accessId, 'membership_access_created', 'One-time membership access created', [
            'status'  => 'info',
            'context' => [
                'membership_access_id' => (int) $accessId,
                'transaction_id'  => (int) $transaction->id,
                'supporter_id'    => (int) $paymentArgs['supporter_id'],
                'level_id'        => $levelId,
                'amount'          => (int) $paymentArgs['amount'],
                'currency'        => $paymentArgs['currency'],
            ],
        ]);

        return (int) $accessId;
    }

    public function intentData($args)
    {
        $sessionPayload = array(
            // Stored amounts are ×100 minor units; convert to the amount Stripe
            // expects for this currency (zero-decimal currencies must not be ×100).
            'amount' => PaymentHelper::toStripeAmount($args['amount'], $args['currency']),
            'currency' => $args['currency'],
            'metadata' => [
                'ref_id'  => $args['client_reference_id'],
                'supporter' => admin_url('admin.php?page=buy-me-coffee.php#/supporter/' . $args['supporter_id'])
            ],
        );

        if (isset($args['vendor_customer_id'])) {
            $sessionPayload['customer'] = $args['vendor_customer_id'];
        }

        return $sessionPayload;
    }
    public function successUrl($supporter)
    {
        return add_query_arg(array(
            'share_coffee' => '',
            'buymecoffee_success' => 1,
            'hash' => $supporter->entry_hash,
            'payment_method' => 'stripe'
        ), home_url());
    }

    public function getTransactionUrl($url, $transaction)
    {
        return 'https://dashboard.stripe.com/' . $transaction->payment_mode . '/payments/' . $transaction->charge_id;
    }

    /**
     * Public Stripe webhook endpoint. Terminates the request.
     *
     * The decision itself lives in processIncomingEvent() so every outcome can
     * be asserted in tests without exit() ending the process.
     */
    public function verifyIpn()
    {
        $outcome = $this->processIncomingEvent();

        self::debugLog('Webhook outcome: ' . $outcome['code'] . ' (HTTP ' . $outcome['http'] . ') — ' . $outcome['message']);

        if (!empty($outcome['retry_after'])) {
            PublicRequestGuard::sendRetryAfter($outcome['retry_after']);
        }

        status_header((int) $outcome['http']);
        exit;
    }

    /**
     * Authenticate an incoming webhook payload and process the real event.
     *
     * The posted payload is unauthenticated, so its claimed type is used for
     * nothing but deciding whether the event is worth fetching. Everything after
     * the fetch — the type dispatched on, the id locked against replay, the
     * object read for the payment outcome — comes from Stripe's own copy.
     *
     * @param object|null $payload Decoded payload; read from the request when null.
     * @param string|null $rawBody Raw body; read from the request when null.
     * @return array Outcome, see outcome().
     */
    public function processIncomingEvent($payload = null, $rawBody = null)
    {
        self::debugLog('--- Webhook handler triggered ---');

        $config = PublicRequestGuard::routeConfig('webhook_stripe');

        // Cheap, pre-read: a declared body far larger than any Stripe event is
        // refused before the stream is touched at all.
        $sizeCheck = PublicRequestGuard::checkSize('webhook_stripe');
        if (!$sizeCheck['allowed']) {
            return self::outcome($sizeCheck['code'], $sizeCheck['http'], [
                'message'     => $sizeCheck['message'],
                'retry_after' => $sizeCheck['retry_after'],
            ]);
        }

        // Without the guard table there is no atomic claim, so a redelivery
        // could be applied twice. 503 keeps the event on Stripe's retry queue.
        if (!PublicRequestGuard::isAvailable()) {
            $decision = PublicRequestGuard::unavailable();

            return self::outcome($decision['code'], $decision['http'], [
                'message'     => $decision['message'],
                'retry_after' => $decision['retry_after'],
            ]);
        }

        if ($payload === null) {
            // Content-Length is a claim, not a measurement: the body is read
            // under the ceiling and one byte past it is already too much.
            $read = PublicRequestGuard::measureBody($config['max_bytes'], $rawBody);

            if ($read['exceeded'] || !PublicRequestGuard::checkSize('webhook_stripe', $read['bytes'])['allowed']) {
                return self::outcome('request_too_large', 413, [
                    'message' => 'the posted body is larger than this endpoint accepts',
                ]);
            }

            $payload = (new IPN())->IPNData($read['body']);
        }

        if (!$payload || is_wp_error($payload)) {
            $error = is_wp_error($payload) ? $payload->get_error_message() : 'empty payload';
            return self::outcome('invalid_payload', 400, ['message' => $error]);
        }

        $claimedType = isset($payload->type) ? sanitize_text_field($payload->type) : '';
        if (!$claimedType) {
            return self::outcome('missing_event_type', 400, ['message' => 'event type missing from payload']);
        }

        // Cheap pre-filter only: an unhandled claimed type is not worth a
        // round trip to Stripe. It never decides how the event is handled.
        if (!StripeEventMap::isSupported($claimedType)) {
            return self::outcome('unsupported_event', 200, ['message' => 'unhandled claimed type: ' . $claimedType]);
        }

        $claimedId = isset($payload->id) ? sanitize_text_field($payload->id) : '';
        if (!$claimedId) {
            return self::outcome('missing_event_id', 400, ['message' => 'event id missing from payload']);
        }

        // An event id that an authenticated delivery already finished needs no
        // second round trip to Stripe. Only a durable, completed claim is
        // trusted here: it is written after Stripe itself returned the event, so
        // an unauthenticated caller cannot create one, and one still in progress
        // is deliberately not short-circuited — it has to stay retryable.
        $settled = PublicRequestGuard::readClaim('stripe_event', $claimedId);
        if ($settled && $settled['state'] === PublicRequestGuard::STATE_COMPLETED) {
            return self::outcome('duplicate_event', 200, [
                'event_id' => $claimedId,
                'message'  => 'event already processed',
            ]);
        }

        // Bounds how often one claimed event id may be turned into a Stripe API
        // request, which is the only amplification an unauthenticated caller has
        // here. The bucket is scoped to the caller, so a flood from one address
        // cannot spend the budget a genuine delivery needs.
        $fetchBudget = PublicRequestGuard::inspect('webhook_stripe_event', ['identifier' => $claimedId]);
        if (!$fetchBudget['allowed']) {
            return self::outcome($fetchBudget['code'], $fetchBudget['http'], [
                'event_id'    => $claimedId,
                'message'     => $fetchBudget['message'],
                'retry_after' => $fetchBudget['retry_after'],
            ]);
        }

        // A budget spent only by deliveries that fail to authenticate. A genuine
        // event never charges it, so a site receiving thousands of real webhooks
        // an hour from Stripe's shared addresses can never be throttled by it.
        $invalidBudget = PublicRequestGuard::budgetRemaining('webhook_stripe_invalid');
        if (!$invalidBudget['allowed']) {
            return self::outcome($invalidBudget['code'], $invalidBudget['http'], [
                'event_id'    => $claimedId,
                'message'     => $invalidBudget['message'],
                'retry_after' => $invalidBudget['retry_after'],
            ]);
        }

        self::debugLog('Re-fetching event from Stripe API — event_id: ' . $claimedId);
        $event = (new API())->getEvent($claimedId);

        if (!$event || is_wp_error($event)) {
            PublicRequestGuard::chargeBudget('webhook_stripe_invalid');

            $error = is_wp_error($event) ? $event->get_error_message() : 'empty response';
            return self::outcome('event_fetch_failed', 400, ['message' => 'Stripe API re-fetch failed — ' . $error]);
        }

        return $this->processAuthenticatedEvent($event);
    }

    /**
     * Process an event that Stripe itself returned.
     *
     * @param array|object $event Authenticated Stripe event.
     * @return array Outcome, see outcome().
     */
    public function processAuthenticatedEvent($event)
    {
        $event = StripeEventMap::normalize($event);

        $eventId   = ($event && isset($event->id) && is_scalar($event->id)) ? sanitize_text_field((string) $event->id) : '';
        $eventType = ($event && isset($event->type) && is_scalar($event->type)) ? sanitize_text_field((string) $event->type) : '';

        if (!$eventId || !$eventType) {
            return self::outcome('malformed_event', 400, ['message' => 'the fetched event has no id or type']);
        }

        if (!StripeEventMap::isSupported($eventType)) {
            return self::outcome('unsupported_event', 200, [
                'event_id'   => $eventId,
                'event_type' => $eventType,
                'message'    => 'unhandled event type: ' . $eventType,
            ]);
        }

        self::debugLog('Event verified via Stripe API — type: ' . $eventType);

        if (StripeEventMap::isSubscriptionEvent($eventType)) {
            return $this->processSubscriptionEvent($event, $eventId, $eventType);
        }

        return $this->processOneTimeEvent($event, $eventId, $eventType);
    }

    /**
     * @param object $event     Authenticated Stripe event.
     * @param string $eventId   Event id as returned by Stripe.
     * @param string $eventType Event type as returned by Stripe.
     * @return array Outcome, see outcome().
     */
    private function processSubscriptionEvent($event, $eventId, $eventType)
    {
        $context = ['event_id' => $eventId, 'event_type' => $eventType];

        $claim = PublicRequestGuard::claim('stripe_event', $eventId);
        if (!$claim['acquired']) {
            return self::claimRefusal($claim, $context);
        }

        self::debugLog('Processing subscription event: ' . $eventType);
        $keys    = StripeSettings::getKeys();
        $handler = new StripeSubscriptions();

        try {
            if ($eventType === 'invoice.payment_succeeded') {
                $handler->handleRenewalWebhook($event, $keys['secret']);
            } elseif ($eventType === 'customer.subscription.deleted') {
                $handler->handleSubscriptionCancelled($event);
            } elseif ($eventType === 'customer.subscription.updated') {
                $handler->handleSubscriptionUpdated($event);
            }
        } catch (\Throwable $error) {
            // The event was never fully applied, so it must stay redeliverable.
            PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);

            return self::outcome('processing_failed', 500, array_merge($context, [
                'message' => 'the authenticated event could not be applied',
            ]));
        }

        PublicRequestGuard::completeClaim($claim['key'], $claim['owner'], [], 'stripe_event');

        return self::outcome('subscription_event_processed', 200, array_merge($context, [
            'message' => 'subscription event processed',
        ]));
    }

    /**
     * Turn a refused event claim into an outcome.
     *
     * A finished delivery is answered 200 so the provider stops resending it. A
     * delivery still running elsewhere must NOT be: answering 200 would let the
     * provider drop the event while the worker holding it may still fail, and
     * the event would be lost. It is a conflict the provider retries instead.
     *
     * @param array $claim   Refused claim record.
     * @param array $context Event id and type.
     * @return array Outcome, see outcome().
     */
    private static function claimRefusal(array $claim, array $context)
    {
        if (!$claim['available']) {
            $decision = PublicRequestGuard::unavailable();

            return self::outcome($decision['code'], $decision['http'], array_merge($context, [
                'message'     => $decision['message'],
                'retry_after' => $decision['retry_after'],
            ]));
        }

        if ($claim['state'] === PublicRequestGuard::STATE_COMPLETED) {
            return self::outcome('duplicate_event', 200, array_merge($context, [
                'message' => 'event already processed',
            ]));
        }

        return self::outcome('event_in_progress', 409, array_merge($context, [
            'message'     => 'this event is already being processed',
            'retry_after' => $claim['retry_after'],
        ]));
    }

    /**
     * @param object $event     Authenticated Stripe event.
     * @param string $eventId   Event id as returned by Stripe.
     * @param string $eventType Event type as returned by Stripe.
     * @return array Outcome, see outcome().
     */
    private function processOneTimeEvent($event, $eventId, $eventType)
    {
        $context = ['event_id' => $eventId, 'event_type' => $eventType];

        $status = StripeEventMap::resolveLocalStatus($event);
        if (is_wp_error($status)) {
            // Nothing is written and no event is consumed: an event this build
            // cannot read must never be able to change local state.
            return self::outcome('unmapped_event', 400, array_merge($context, [
                'message' => $status->get_error_code() . ' — ' . $status->get_error_message(),
            ]));
        }

        $orderHash = self::getOrderHash($event);
        if (!$orderHash) {
            return self::outcome('missing_reference', 200, array_merge($context, [
                'message' => 'no order hash (ref_id) in event metadata',
            ]));
        }

        $transaction = (new Transactions())->find($orderHash, 'entry_hash');
        if (!$transaction) {
            return self::outcome('transaction_not_found', 200, array_merge($context, [
                'message' => 'no transaction for entry_hash: ' . $orderHash,
            ]));
        }

        $context['transaction_id'] = (int) $transaction->id;
        $context['status']         = $status;

        // Claimed only now: the event is authentic, readable and matched to a
        // local transaction, so it is safe to mark it consumed. The claim is an
        // atomic insert in a plugin table, so of two deliveries arriving at once
        // exactly one gets past this line.
        $claim = PublicRequestGuard::claim('stripe_event', $eventId);
        if (!$claim['acquired']) {
            return self::claimRefusal($claim, array_merge($context, ['status' => '']));
        }

        try {
            // Subscription-linked transactions keep their existing lifecycle.
            if (!empty($transaction->subscription_id)) {
                $this->updateStatus($orderHash, $status);

                PublicRequestGuard::completeClaim($claim['key'], $claim['owner'], [], 'stripe_event');

                return self::outcome('subscription_transaction_updated', 200, array_merge($context, [
                    'changed' => true,
                    'message' => 'subscription-linked transaction updated to ' . $status,
                ]));
            }

            $result = (new OneTimePaymentStatusService())->apply($transaction, $status);
        } catch (\Throwable $error) {
            PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);

            return self::outcome('processing_failed', 500, array_merge($context, [
                'message' => 'the authenticated event could not be applied',
            ]));
        }

        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'bmc_payment_write_failed') {
                // The event was never applied, so let Stripe's retry try again.
                PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);

                return self::outcome('update_failed', 500, array_merge($context, [
                    'message' => $result->get_error_message(),
                ]));
            }

            // A refused transition is a decision, not a failure: this event has
            // been considered and must not be considered again.
            PublicRequestGuard::completeClaim($claim['key'], $claim['owner'], [], 'stripe_event');

            return self::outcome('transition_refused', 200, array_merge($context, [
                'status'  => '',
                'message' => $result->get_error_code() . ' — ' . $result->get_error_message(),
            ]));
        }

        PublicRequestGuard::completeClaim($claim['key'], $claim['owner'], [], 'stripe_event');

        if (!$result['changed']) {
            return self::outcome('status_unchanged', 200, array_merge($context, [
                'message' => 'transaction is already ' . $result['to'],
            ]));
        }

        return self::outcome('payment_status_updated', 200, array_merge($context, [
            'changed' => true,
            'message' => 'transaction moved from ' . $result['from'] . ' to ' . $result['to'],
        ]));
    }

    /**
     * Build the record of what a webhook request decided.
     *
     * @param string $code  Machine-readable outcome.
     * @param int    $http  Status code the endpoint answers with.
     * @param array  $extra Outcome details.
     * @return array
     */
    private static function outcome($code, $http, array $extra = [])
    {
        return array_merge([
            'code'           => $code,
            'http'           => (int) $http,
            'message'        => '',
            'event_id'       => '',
            'event_type'     => '',
            'status'         => '',
            'transaction_id' => 0,
            'changed'        => false,
            'retry_after'    => 0,
        ], $extra);
    }

    /**
     * Write a payment status straight onto an order.
     *
     * The webhook only routes subscription-linked transactions here, so the
     * subscription activation below keeps working exactly as before. One-time
     * transactions go through OneTimePaymentStatusService instead, which
     * enforces the local transition policy this method has no notion of.
     *
     * @param string $orderHash Transaction entry_hash.
     * @param string $status    Local payment status.
     * @return void
     */
    public function updateStatus($orderHash, $status)
    {
        $transactions = new Transactions();
        $transaction = $transactions->find($orderHash, 'entry_hash');
        if (!$transaction) {
            self::debugLog('updateStatus: no transaction found for entry_hash: ' . $orderHash);
            return;
        }

        self::debugLog('updateStatus: transaction #' . $transaction->id . ' — setting status to "' . $status . '"');

        $supportersModel = new Supporters();
        $supportersModel->updateData($transaction->entry_id, [
            'payment_status' => sanitize_text_field($status),
            'updated_at' => current_time('mysql')
        ]);

        $transactions->updateData($transaction->id, [
            'status' => sanitize_text_field($status),
            'updated_at' => current_time('mysql')
        ]);

        if ($status === 'paid' && !empty($transaction->subscription_id)) {
            (new Subscriptions())->updateData((int) $transaction->subscription_id, [
                'status'     => 'active',
                'updated_at' => current_time('mysql'),
            ]);
            do_action('buymecoffee_subscription_activated', (int) $transaction->subscription_id);
        }

        if ($status === 'paid' && empty($transaction->subscription_id)) {
            (new MembershipAccess())->activateByTransaction((int) $transaction->id);
        }

        do_action('buymecoffee_payment_status_updated', $transaction->id, $status);
        self::debugLog('updateStatus: done — transaction #' . $transaction->id . ' status updated and action fired');
    }

    /**
     * The order reference stamped on a one-time event's object.
     *
     * @param array|object $event Authenticated Stripe event.
     * @return string|false Order hash, or false when the event carries none.
     */
    public static function getOrderHash($event)
    {
        $reference = StripeEventMap::orderReference($event);

        if (!$reference) {
            self::debugLog('getOrderHash: no ref_id on this event');
            return false;
        }

        self::debugLog('getOrderHash: ref_id=' . $reference);

        return $reference;
    }

    public function sanitize($settings)
    {
        $currentSettings = $this->getSettings();
        $secretFields = ['live_secret_key', 'test_secret_key', 'live_webhook_secret', 'test_webhook_secret'];

        foreach ($settings as $key => $value) {
            if (strpos($key, 'has_') === 0) {
                unset($settings[$key]);
                continue;
            }

            $settings[$key] = sanitize_text_field($value);

            if (in_array($key, $secretFields, true) && $settings[$key] === '' && !empty($currentSettings[$key])) {
                // Preserve already-saved secrets unless user explicitly replaces them.
                $settings[$key] = $currentSettings[$key];
            }
        }

        $settings['enable'] = ($settings['enable'] ?? 'no') === 'yes' ? 'yes' : 'no';
        $settings['payment_mode'] = ($settings['payment_mode'] ?? 'test') === 'live' ? 'live' : 'test';

        return $settings;
    }

    public function getPaymentSettings()
    {
        $currentSettings = $this->getSettings();
        $settings = $currentSettings;
        $settings['live_secret_key'] = '';
        $settings['test_secret_key'] = '';
        $settings['live_webhook_secret'] = '';
        $settings['test_webhook_secret'] = '';
        $settings['has_live_secret_key'] = !empty($currentSettings['live_secret_key']);
        $settings['has_test_secret_key'] = !empty($currentSettings['test_secret_key']);
        $settings['has_live_webhook_secret'] = !empty($currentSettings['live_webhook_secret']);
        $settings['has_test_webhook_secret'] = !empty($currentSettings['test_webhook_secret']);

        wp_send_json_success(array(
            'settings' => $settings,
            'webhook_url' => add_query_arg([
                'buymecoffee_ipn_listener' => 1,
                'method' => 'stripe'
            ], site_url('/'))
        ), 200);
    }

    public function getSettings()
    {
        $settings = get_option('buymecoffee_payment_settings_' . $this->method, []);
//vdd($settings);
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
        return wp_parse_args($settings, $defaults);
    }

    public function maybeLoadModalScript()
    {
        //phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        wp_enqueue_script('wpm-buymecoffee-checkout-sdk-' . $this->method, 'https://js.stripe.com/v3/', array(), null, true);
        Vite::enqueueScript('wpm-buymecoffee-checkout-handler-' . $this->method, 'js/PaymentMethods/stripe-checkout.js', ['wpm-buymecoffee-checkout-sdk-stripe', 'jquery'], '1.0.1', true);
    }

    public function render($template)
    {
        $this->maybeLoadModalScript();
        $id = $this->uniqueId('stripe_card');
        ?>
        <label class="wpm_stripe_card_label" for="<?php echo esc_attr($id); ?>">
            <img width="50px" src="<?php echo esc_url(Vite::staticPath() . 'images/card.svg'); ?>" alt="<?php esc_attr_e('Card', 'buy-me-coffee'); ?>">
            <input
                    style="outline: none;"
                    type="radio" class="wpm_stripe_card" name="wpm_payment_method" id="<?php echo esc_attr($id); ?>"
                    value="stripe"/>
        </label>
        <?php
    }

    /**
     * Handle a refund request for a Stripe transaction.
     * Hooked via filter: buymecoffee_process_refund_stripe
     *
     * @param null      $result     Incoming filter value (null = unhandled).
     * @param object    $transaction Transaction object.
     * @return true|\WP_Error  true on success, WP_Error on failure.
     */
    public function processRefund($result, $transaction)
    {
        $keys = StripeSettings::getKeys();

        // charge_id may be a PaymentIntent ID (pi_…) or a Charge ID (ch_…)
        $refundData = strpos($transaction->charge_id, 'pi_') === 0
            ? ['payment_intent' => $transaction->charge_id]
            : ['charge'         => $transaction->charge_id];

        $response = (new API())->makeRequest('refunds', $refundData, $keys['secret'], 'POST');

        if (is_wp_error($response)) {
            return $response;
        }

        $status = strtolower(sanitize_text_field(ArrayHelper::get($response, 'status', '')));
        if (!$status) {
            return new \WP_Error('stripe_refund_invalid', __('Stripe refund response is invalid.', 'buy-me-coffee'));
        }

        if (in_array($status, ['failed', 'canceled'], true)) {
            return new \WP_Error('stripe_refund_failed', __('Stripe refund failed. Please check your Stripe dashboard.', 'buy-me-coffee'));
        }

        return [
            'status' => $status,
            'refund_id' => sanitize_text_field(ArrayHelper::get($response, 'id', '')),
        ];
    }

    public function isEnabled()
    {
        $settings = $this->getSettings();
        return $settings['enable'] === 'yes';
    }

    private static function debugLog($message)
    {
        if (defined('BUYMECOFFEE_DEBUG') && BUYMECOFFEE_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging
            error_log('[BuyMeCoffee][Stripe Webhook] ' . $message);
        }
    }
}
