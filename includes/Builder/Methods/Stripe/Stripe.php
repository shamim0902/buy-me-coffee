<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

use BuyMeCoffee\Builder\Methods\BaseMethods;
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

        // Primary: if JS passed the local subscription ID, activate it immediately.
        // This runs before the Stripe re-query so the subscription is active right away.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_REQUEST['subscriptionId'])) {
            $subscriptionId = absint($_REQUEST['subscriptionId']);
            if ($subscriptionId) {
                (new Subscriptions())->updateData($subscriptionId, [
                    'status'     => 'active',
                    'updated_at' => current_time('mysql'),
                ]);
            }
        }

        // Fallback / full update: re-query Stripe to record charge details, card info, etc.
        // Also activates subscription if subscription_id is on the transaction (covers webhook-less setups).
        (new PaymentHelper())->updatePaymentData($intentId);

        wp_send_json_success([
            'message' => __('Payment confirmation received', 'buy-me-coffee')
        ], 200);
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
        $data = (new IPN())->IPNData();
        if (!$data || is_wp_error($data)) {
            status_header(400);
            exit;
        }

        $eventType = isset($data->type) ? sanitize_text_field($data->type) : '';
        if (!$eventType) {
            status_header(400);
            exit;
        }

        // Handle subscription-specific webhook events
        $subscriptionEvents = [
            'invoice.payment_succeeded',
            'customer.subscription.deleted',
            'customer.subscription.updated',
        ];

        if (in_array($eventType, $subscriptionEvents, true)) {
            $keys    = StripeSettings::getKeys();
            $handler = new StripeSubscriptions();

            if ($eventType === 'invoice.payment_succeeded') {
                $handler->handleRenewalWebhook($data, $keys['secret']);
            } elseif ($eventType === 'customer.subscription.deleted') {
                $handler->handleSubscriptionCancelled($data);
            } elseif ($eventType === 'customer.subscription.updated') {
                $handler->handleSubscriptionUpdated($data);
            }

            status_header(200);
            exit;
        }

        // Existing one-time payment event handling
        $eventId = isset($data->id) ? sanitize_text_field($data->id) : '';
        if (!$eventId) {
            status_header(400);
            exit;
        }

        $invoice = (new API())->getInvoice($eventId);
        if (!$invoice || is_wp_error($invoice)) {
            status_header(400);
            exit;
        }

        $orderHash = $this->getOrderHash($invoice);
        if (!$orderHash) {
            status_header(200);
            exit;
        }

        $status = isset($invoice->data->object->status) ? sanitize_text_field($invoice->data->object->status) : '';
        if (!$status) {
            status_header(400);
            exit;
        }

        if ($status === 'succeeded') {
            $status = 'paid';
        }

        $this->updateStatus($orderHash, $status);
        status_header(200);
        exit;
    }

    public function updateStatus($orderHash, $status)
    {
        $transactions = new Transactions();
        $transaction = $transactions->find($orderHash, 'entry_hash');
        if (!$transaction) {
            return;
        }

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
            return Arr::get($metaData, 'ref_id');
        }

        return false;
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
            'webhook_url' => site_url() . '?buymecoffee_stripe_listener=1'
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
            <img width="50px" src="<?php echo esc_url(Vite::staticPath() . 'images/stripe.svg'); ?>" alt="">
            <input
                    style="outline: none;"
                    type="radio" class="wpm_stripe_card" name="wpm_payment_method" id="<?php echo esc_attr($id); ?>"
                    value="stripe"/>
        </label>
        <?php
    }

    public function isEnabled()
    {
        // TODO: Implement isEnabled() method.
        $settings = $this->getSettings();
        return $settings['enable'] === 'yes';
    }
}