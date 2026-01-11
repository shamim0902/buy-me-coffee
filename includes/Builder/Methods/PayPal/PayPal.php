<?php

namespace BuyMeCoffee\Builder\Methods\PayPal;

use BuyMeCoffee\Helpers\ArrayHelper;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Builder\Methods\BaseMethods;
use BuyMeCoffee\Classes\Vite;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class PayPal extends BaseMethods
{
    public function __construct()
    {
        parent::__construct(
            'PayPal',
            'paypal',
            'PayPal is the faster, safer way to send money, make an online payment, receive money or set up a merchant account.',
            Vite::staticPath() . 'images/PayPal.svg'
        );
        add_action('buymecoffee_make_payment_paypal', array($this, 'makePayment'), 10, 3);
        add_action('buymecoffee_paypal_action_web_accept', array($this, 'updateStatus'), 10, 2);
        add_action("buymecoffee_ipn_endpoint_paypal", array($this, 'verifyIpn'), 10, 2);
        add_action('buymecoffee_get_payment_settings_paypal', array($this, 'getPaymentSettings'));
        add_filter('buymecoffee_before_save_paypal', array($this, 'validateProSettings'), 10, 1);
    }

    public function validateProSettings($settings)
    {
        // Only validate if payment type is 'pro'
        if (!isset($settings['payment_type']) || $settings['payment_type'] !== 'pro') {
            return $settings;
        }

        // Get the payment mode to determine which keys to validate
        $mode = isset($settings['payment_mode']) ? $settings['payment_mode'] : 'test';

        if ($mode === 'test') {
            $clientId = isset($settings['test_public_key']) ? trim($settings['test_public_key']) : '';
            $clientSecret = isset($settings['test_secret_key']) ? trim($settings['test_secret_key']) : '';
        } else {
            $clientId = isset($settings['live_public_key']) ? trim($settings['live_public_key']) : '';
            $clientSecret = isset($settings['live_secret_key']) ? trim($settings['live_secret_key']) : '';
        }

        // Check if keys are provided
        if (empty($clientId) || empty($clientSecret)) {
            wp_send_json_error(array(
                'message' => __('PayPal Pro requires both Client ID and Secret Key. Please provide valid credentials.', 'buy-me-coffee')
            ), 400);
        }

        // Validate credentials by attempting to get an access token
        try {
            // Temporarily set the settings for API validation
            $tempSettings = new PayPalSettings();

            // Create a mock settings object for validation
            $validationSettings = array(
                'payment_mode' => $mode,
                'test_public_key' => isset($settings['test_public_key']) ? $settings['test_public_key'] : '',
                'test_secret_key' => isset($settings['test_secret_key']) ? $settings['test_secret_key'] : '',
                'live_public_key' => isset($settings['live_public_key']) ? $settings['live_public_key'] : '',
                'live_secret_key' => isset($settings['live_secret_key']) ? $settings['live_secret_key'] : '',
            );

            // Test the credentials by getting access token
            $apiUrl = $mode === 'test'
                ? 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
                : 'https://api.paypal.com/v1/oauth2/token';

            $auth = base64_encode($clientId . ':' . $clientSecret);

            $response = wp_remote_post($apiUrl, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . $auth,
                    'Accept' => 'application/json',
                    'Accept-Language' => 'en_US',
                ),
                'body' => array(
                    'grant_type' => 'client_credentials',
                ),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                wp_send_json_error(array(
                    /* translators: %s: Error message from PayPal API */
                    'message' => sprintf(__('PayPal API connection failed: %s', 'buy-me-coffee'), $response->get_error_message())
                ), 400);
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($http_code !== 200) {
                $error = json_decode($body, true);
                $errorMessage = isset($error['error_description']) ? $error['error_description'] : (isset($error['error']) ? $error['error'] : 'Invalid credentials');

                wp_send_json_error(array(
                    /* translators: %s: Error message from PayPal API describing why credentials are invalid */
                    'message' => sprintf(__('PayPal credentials validation failed: %s. Please check your Client ID and Secret Key.', 'buy-me-coffee'), $errorMessage)
                ), 400);
            }

            // If we reach here, credentials are valid
        } catch (\Exception $e) {
            wp_send_json_error(array(
                /* translators: %s: Exception error message */
                'message' => sprintf(__('PayPal validation error: %s', 'buy-me-coffee'), $e->getMessage())
            ), 400);
        }

        return $settings;
    }

    public function sanitize($settings)
    {
        foreach ($settings as $key => $value) {
            if ($key === 'paypal_email') {
                $settings[$key] = sanitize_email($value);
            } else {
                $settings[$key] = sanitize_text_field($value);
            }
        }
        return $settings;
    }

    public function paymentConfirmation()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $chargeId = isset($_REQUEST['charge_id']) ? sanitize_text_field(wp_unslash($_REQUEST['charge_id'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $hash = isset($_REQUEST['hash']) ?  sanitize_text_field(wp_unslash($_REQUEST['hash'])) : '';

        $this->updatePayment($chargeId, $hash);
    }
    public function verifyIpn()
    {
        (new IPN())->ipnVerificationProcess();
    }

    public function getPaymentSettings()
    {
        wp_send_json_success(array(
            'settings' => $this->getSettings(),
            'webhook_url' => site_url() . '?buymecoffee_ipn_listener=1&method=paypal'
        ), 200);
    }

    public function getSettings($key = null)
    {
        return PayPalSettings::get($key);
    }

    public function maybeShowModal($transactions, $paypalSettings)
    {
        $paymentIntent = $this->modalPaymentIntent($transactions);
        $responseData = [
            'nextAction' => 'paypal',
            'hash' => $transactions->entry_hash,
            'actionName' => 'custom',
            'buttonState' => 'hide',
            'purchase_units' => $paymentIntent,
            'confirmation_url' => $this->successUrl($transactions),
            'message_to_show' => __('Payment Modal is opening, Please complete the payment', 'buy-me-coffee'),
        ];

        wp_send_json_success($responseData, 200);
    }

    public function modalPaymentIntent($transactions)
    {
        $total = $transactions->payment_total ? $transactions->payment_total : 5;
        $total = $total / 100;
        $currencyCode = $transactions->currency;
        $intent = [
            'reference_id' => $transactions->entry_hash,
            'amount' => [
                'value' => $total,
                'breakdown' => [
                    'item_total' => [
                        'currency_code' => $currencyCode,
                        'value' => $total,
                    ]
                ]
            ],
            'items' => array([
                'name' => 'Buy coffee for you',
                'unit_amount' => [
                    'currency_code' => $currencyCode,
                    'value' => $total,
                ],
                'quantity' => '1',
            ])
        ];

        return apply_filters('buymecoffee_paypal_modal_payment_intent', $intent, $transactions);
    }

    public function makePayment($transactionId, $entryId, $form_data)
    {
        $paypalSettings = $this->getSettings();
        $transactionModel = new Transactions();
        $transaction = $transactionModel->find($transactionId);

        if ($paypalSettings['payment_type'] === 'pro') {
            $this->maybeShowModal($transaction, $paypalSettings);
        }

        $paypal_redirect = 'https://www.paypal.com/cgi-bin/webscr/?';

        if ($paypalSettings['payment_mode'] === 'test') {
            $paypal_redirect = 'https://www.sandbox.paypal.com/cgi-bin/webscr/?';
        }

        $supportersModel = new Supporters();
        $supporter = $supportersModel->find($entryId);
        $listener_url = apply_filters('buymecoffee_paypal_ipn_url', site_url('?buymecoffee_ipn_listener=1&method=paypal'), $supporter);

        $paypal_args = array(
            'cmd' => '_cart',
            'upload' => '1',
            'business' => $paypalSettings['paypal_email'],
            'email' => $supporter->supporters_email,
            'no_shipping' => '1',
            'no_note' => '1',
            'currency_code' => $supporter->currency ?? 'USD',
            'charset' => 'UTF-8',
            'custom' => $transactionId,
            'return' => $this->successUrl($supporter),
            'notify_url' => $listener_url,
            'cancel_return' => $this->failedUrl($supporter),
            'image_url' => '',
        );

        $payment_item = $this->cartItems($transaction);

        if (!$payment_item) {
            return;
        }
        $paypal_args = array_merge($payment_item, $paypal_args);

        $paypal_args = apply_filters('buymecoffee_paypal_payment_args', $paypal_args);

        if (!$payment_item && $paypal_args['cmd'] == '_cart') {
            return;
        }

        $supportersModel->updateData($entryId, array(
            'payment_mode' => $paypalSettings['payment_mode']
        ));

        if ($transactionId) {
            $transactionModel->updateData($transactionId, array(
                'payment_mode' => $paypalSettings['payment_mode']
            ));
        }

        $paypal_redirect .= http_build_query($paypal_args);

        wp_send_json_success(array(
            'message' => __('You are redirected for payment.', 'buy-me-coffee'),
            'id' => $entryId,
            'redirectTo' => $paypal_redirect,
            'messageToShow' => __('Your are redirecting to paypal now', 'buy-me-coffee')
        ), 200);
        exit;
    }

    public function cartItems($item)
    {
        $paypal_args = array(
            'item_name_1' => 'coffee_payment',
            'quantity_1' => 1,
            'amount_1' => round($item->payment_total / 100, 2)
        );

        return $paypal_args;
    }

    public function successUrl($supporter)
    {
        return add_query_arg(array(
            'share_coffee' => '',
            'buymecoffee_success' => 1,
            'hash' => $supporter->entry_hash,
            'payment_method' => 'paypal'
        ), home_url());
    }

    public function failedUrl($supporter)
    {
        return add_query_arg(array(
            'buymecoffee_failed' => 1,
            'share_coffee' => '',
            'buymecoffee_submission' => $supporter->entry_hash,
            'payment_method' => 'paypal'
        ), home_url());
    }

    public function getTransactionUrl($url, $transaction)
    {
        if ($transaction->payment_mode === 'test') {
            return 'https://www.sandbox.paypal.com/activity/payment/' . $transaction->charge_id;
        }
        return 'https://www.paypal.com/activity/payment/' . $transaction->charge_id;
    }

    public function updateStatus($data, $payment_id)
    {
        if ($data['txn_type'] != 'web_accept' && $data['txn_type'] != 'cart' && $data['payment_status'] != 'Refunded') {
            return;
        }

        if (empty($payment_id)) {
            return;
        }

        $transaction = (new Transactions())->find($payment_id);

        if (defined('BUYMECOFFEE_PAYPAL_IPN_DEBUG')) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging when BUYMECOFFEE_PAYPAL_IPN_DEBUG is defined
            error_log('IPN For Transaction: ' . wp_json_encode($transaction));
        }

        if (!$transaction) {
            return;
        }

        if ($transaction->payment_method != 'paypal') {
            return;
        }

        $currency_code = strtolower($data['mc_currency']);

        if ($currency_code != strtolower($transaction->currency)) {
            $this->changeStatus('failed', $transaction);
            return;
        }

        $payment_status = strtolower($data['payment_status']);

        $paypal_amount = $data['mc_gross'];
        $isMismatchAmount = false;
        if (number_format((float)($transaction->payment_total / 100), 2) - number_format((float)$paypal_amount, 2) > 1) {
            $isMismatchAmount = true;
        }

        if ($isMismatchAmount) {
            $this->changeStatus('failed', $transaction);
            return;
        }

        if ('completed' == $payment_status || $transaction->payment_mode == 'test') {
            $this->changeStatus('paid', $transaction, $data);
            return;
        }

        if ('pending' == $payment_status && isset($data['pending_reason'])) {
            $this->changeStatus('processing', $transaction, $data);
        }
    }

    public function changeStatus($status, $transaction = null,  $data = array())
    {
        $supportersModel = new Supporters();
        $transactionModel = new Transactions();

        $updateData = array(
            'payment_status' => $status,
            'updated_at' => current_time('mysql')
        );

        $supportersModel->updateData($transaction->entry_id, $updateData);

        if (!empty($data) && isset($data['txn_id'])) {
            $updateData = [
                'status' => $status,
                'updated_at' => current_time('mysql'),
                'payment_note' => wp_json_encode($data),
                'charge_id' => sanitize_text_field($data['txn_id'])
            ];
        }

        $transactionModel->updateData($transaction->id, $updateData);

    }

    public function maybeLoadModalScript()
    {
        $settings = $this->getSettings();
        if ($settings['payment_type'] == 'standard') {
            return;
        };

        $mode = $settings['payment_mode'];
        $clientId = $mode === 'test' ? $settings['test_public_key'] : $settings['live_public_key'];

        //phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        wp_enqueue_script('wpm-buymecoffee-checkout-sdk-' . $this->method, 'https://www.paypal.com/sdk/js?client-id=' . $clientId, [], null, false);

        Vite::enqueueScript('wpm-buymecoffee-checkout-handler-' . $this->method, 'js/PaymentMethods/paypal-checkout.js', ['wpm-buymecoffee-checkout-sdk-paypal', 'jquery'], '1.0.1', false);

    }

    public function render($template)
    {
        $this->maybeLoadModalScript();
        $id = $this->uniqueId('paypal_card');

        ?>
        <label class="wpm_paypal_card_label" for="<?php echo esc_attr($id); ?>">
            <img width="60px" src="<?php echo esc_url(Vite::staticPath() . 'images/PayPal.svg'); ?>" alt="">
            <input
                    style="outline: none;"
                    type="radio" name="wpm_payment_method" class="wpm_paypal_card" id="<?php echo esc_attr($id); ?>"
                    value="paypal"/>
        </label>
        <?php
    }


    public function updatePayment($chargeId, $hash)
    {
        if ($chargeId == '' || $hash == '') {
            wp_send_json_error(array(
                'message' => __('Invalid request', 'buy-me-coffee'),
            ), 400);
        }

        $payment_intent = (new API())->verifyTransaction($chargeId);

        if (!isset($payment_intent['status']) || $payment_intent['status'] != 'COMPLETED') {
            return;
        }

        $purchaseUnit = ArrayHelper::get($payment_intent, 'purchase_units.0');
        $vendorChargeId = ArrayHelper::get($purchaseUnit, 'payments.captures.0.id', '');
        $amount = 100 * floatval(ArrayHelper::get($purchaseUnit, 'amount.value', 0));
        $mode = $this->getSettings('payment_mode');

        $updateData = array(
            'charge_id' => sanitize_text_field($vendorChargeId),
            'payment_mode' => sanitize_text_field($mode),
            'payment_total' => $amount,
            'payment_note' => wp_json_encode($payment_intent),
            'status' => 'paid',
            'updated_at' => current_time('mysql'),
        );

        $transactionId = buyMeCoffeeQuery()->table('buymecoffee_transactions')
            ->where('entry_hash', $hash)->update($updateData);

        buyMeCoffeeQuery()->table('buymecoffee_supporters')
            ->where('entry_hash', $hash)
            ->update([
                    'payment_total' => $amount,
                    'payment_status' => 'paid',
                    'payment_mode' => sanitize_text_field($mode),
                    'updated_at' => current_time('mysql')
            ]);

        wp_send_json_success(array(
            'message' => __('Payment updated successfully', 'buy-me-coffee'),
            'data' => $transactionId
        ), 200);
    }

    public function isEnabled()
    {
        // TODO: Implement isEnabled() method.
        $settings = $this->getSettings();
        return $settings['enable'] === 'yes';
    }
}
