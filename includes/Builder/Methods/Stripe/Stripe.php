<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

use BuyMeCoffee\Builder\Methods\BaseMethods;
use BuyMeCoffee\Classes\ActivityLogger;
use BuyMeCoffee\Classes\Vite;
use BuyMeCoffee\Helpers\ArrayHelper;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;

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

        if ($requestedSubscriptionId && !$this->activateMatchedSubscription($intentId, $requestedSubscriptionId)) {
            wp_send_json_error([
                'message' => __('Subscription confirmation mismatch. Please refresh and try again.', 'buy-me-coffee')
            ], 403);
        }

        // Fallback / full update: re-query Stripe to record charge details, card info, etc.
        // Also activates subscription if subscription_id is on the transaction (covers webhook-less setups).
        (new PaymentHelper())->updatePaymentData($intentId);

        wp_send_json_success([
            'message' => __('Payment confirmation received', 'buy-me-coffee')
        ], 200);
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

        if ($orderHash && (!hash_equals((string) $transaction->entry_hash, $orderHash) || !hash_equals((string) $supporter->entry_hash, $orderHash))) {
            return false;
        }

        if (!$orderHash && !$this->intentBelongsToSubscription($intentId, $localSubscription)) {
            return false;
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
            'payment_note' => wp_json_encode($intent),
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

            $transaction->payment_args = $paymentArgs;

            $responseData = [
                'nextAction' => 'stripe',
                'actionName' => 'custom',
                'buttonState' => 'hide',
                'intent' => $invoiceResponse,
                'order_items' => $transaction,
                'message_to_show' => __('Payment Modal is opening, Please complete the payment', 'buy-me-coffee'),
            ];

            wp_send_json_success($responseData, 200);
        } catch (\Exception $e) {
            wp_send_json_error([
                'status' => 'failed',
                'message' => $e->getMessage()
            ], 423);
        }
    }

    public function intentData($args)
    {
        $sessionPayload = array(
            'amount' => $args['amount'],
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

    public function verifyIpn()
    {
        self::debugLog('--- Webhook handler triggered ---');

        $data = (new IPN())->IPNData();
        if (!$data || is_wp_error($data)) {
            $error = is_wp_error($data) ? $data->get_error_message() : 'empty payload';
            self::debugLog('Aborting: payload invalid — ' . $error);
            status_header(400);
            exit;
        }

        $eventType = isset($data->type) ? sanitize_text_field($data->type) : '';
        if (!$eventType) {
            self::debugLog('Aborting: event type missing from payload');
            status_header(400);
            exit;
        }

        $acceptedEvents = [
            'invoice.payment_succeeded',
            'customer.subscription.deleted',
            'customer.subscription.updated',
            'charge.succeeded',
            'charge.refunded',
            'checkout.session.completed',
            'invoice.paid',
        ];

        if (!in_array($eventType, $acceptedEvents, true)) {
            self::debugLog('Skipping unhandled event type: ' . $eventType);
            status_header(200);
            exit;
        }

        // Re-fetch the event from Stripe API to verify authenticity.
        // This confirms the event is genuine without requiring a webhook secret.
        $eventId = sanitize_text_field($data->id);
        self::debugLog('Re-fetching event from Stripe API — event_id: ' . $eventId);
        $event = (new API())->getEvent($eventId);

        if (!$event || is_wp_error($event)) {
            $error = is_wp_error($event) ? $event->get_error_message() : 'empty response';
            self::debugLog('Aborting: Stripe API re-fetch failed — ' . $error);
            status_header(400);
            exit;
        }

        self::debugLog('Event verified via Stripe API — type: ' . $eventType);

        // Convert array response to object for consistent downstream handling
        $event = json_decode(wp_json_encode($event));

        // Handle subscription-specific webhook events
        $subscriptionEvents = [
            'invoice.payment_succeeded',
            'customer.subscription.deleted',
            'customer.subscription.updated',
        ];

        if (in_array($eventType, $subscriptionEvents, true)) {
            if (!$this->acquireEventLock($eventId)) {
                self::debugLog('Duplicate event, already processed — event_id: ' . $eventId);
                status_header(200);
                exit;
            }

            self::debugLog('Processing subscription event: ' . $eventType);
            $keys    = StripeSettings::getKeys();
            $handler = new StripeSubscriptions();

            if ($eventType === 'invoice.payment_succeeded') {
                $handler->handleRenewalWebhook($event, $keys['secret']);
            } elseif ($eventType === 'customer.subscription.deleted') {
                $handler->handleSubscriptionCancelled($event);
            } elseif ($eventType === 'customer.subscription.updated') {
                $handler->handleSubscriptionUpdated($event);
            }

            self::debugLog('Subscription event processed successfully: ' . $eventType);
            status_header(200);
            exit;
        }

        // One-time payment event handling
        $orderHash = $this->getOrderHash($event);
        if (!$orderHash) {
            self::debugLog('No order hash (ref_id) found in event metadata — event_type: ' . $eventType);
            status_header(200);
            exit;
        }

        self::debugLog('Order hash extracted: ' . $orderHash);

        $status = isset($event->data->object->status) ? sanitize_text_field($event->data->object->status) : '';
        if (!$status) {
            self::debugLog('Aborting: no status field on event data.object — event_type: ' . $eventType);
            status_header(400);
            exit;
        }

        if ($status === 'succeeded') {
            $status = 'paid';
        }

        self::debugLog('Updating payment status to "' . $status . '" for order: ' . $orderHash);
        $this->updateStatus($orderHash, $status);
        status_header(200);
        exit;
    }

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

        do_action('buymecoffee_payment_status_updated', $transaction->id, $status);
        self::debugLog('updateStatus: done — transaction #' . $transaction->id . ' status updated and action fired');
    }

    public static function getOrderHash($event)
    {
        $eventType = $event->type;

        $metaDataEvents = [
            'checkout.session.completed',
            'charge.refunded',
            'charge.succeeded',
            'invoice.paid'
        ];

        if (in_array($eventType, $metaDataEvents)) {
            $data = $event->data->object;
            $metaData = (array)$data->metadata;
            $refId = ArrayHelper::get($metaData, 'ref_id');
            self::debugLog('getOrderHash: event_type=' . $eventType . ', metadata=' . wp_json_encode($metaData) . ', ref_id=' . ($refId ?: '(empty)'));
            return $refId;
        }

        self::debugLog('getOrderHash: event_type "' . $eventType . '" not in metadata-events list');
        return false;
    }

    private function acquireEventLock($eventId)
    {
        $lockKey = 'buymecoffee_stripe_event_lock_' . md5($eventId);
        if (get_transient($lockKey)) {
            return false;
        }

        return set_transient($lockKey, 1, DAY_IN_SECONDS * 14);
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
