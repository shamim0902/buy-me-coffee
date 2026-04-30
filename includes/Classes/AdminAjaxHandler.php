<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Helpers\SanitizeHelper;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Builder\Methods\PayPal\PayPal;
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
use BuyMeCoffee\Controllers\PaymentHandler;
use BuyMeCoffee\Builder\Methods\Stripe\Stripe;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Helpers\Currencies;

use BuyMeCoffee\Models\Buttons;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Classes\EmailNotifications;
use BuyMeCoffee\Classes\ActivityLogger;
use BuyMeCoffee\Builder\Methods\Stripe\StripeSettings;
use BuyMeCoffee\Builder\Methods\Stripe\API as StripeAPI;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class AdminAjaxHandler
{
    public function registerEndpoints()
    {
        add_action('wp_ajax_buymecoffee_admin_ajax', array($this, 'handleEndPoint'));
    }

    public function handleEndPoint()
    {
        if (!isset($_REQUEST['buymecoffee_nonce']) ) {
            wp_send_json_error(array(
                'message' => __("Invalid nonce", 'buy-me-coffee')
            ), 403);
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['buymecoffee_nonce'])), 'buymecoffee_nonce')) {
            wp_send_json_error(array(
                'message' => __("Invalid buymecoffee_nonce", 'buy-me-coffee')
            ), 403);
        }

        if (!AccessControl::hasTopLevelMenuPermission()) {
            wp_send_json_error(array(
                'message' => __("Sorry, you are not allowed to perform this action.", 'buy-me-coffee')
            ), 403);
        }

        $route = isset($_REQUEST['route']) ? sanitize_text_field(wp_unslash($_REQUEST['route'])) : '';

        $validRoutes = array(
            'get_data' => 'getPaymentSettings',
            'save_payment_settings' => 'savePaymentSettings',
            'validate_stripe_keys' => 'validateStripeKeys',
            'save_form_design' => 'saveFormDesign',
            'gateways' => 'getAllMethods',

            'save_settings' => 'saveSettings',
            'get_settings' => 'getSettings',
            'reset_template_settings' => 'resetDefaultSettings',

            'get_weekly_revenue' => 'getWeeklyRevenue',
            'get_supporters' => 'getSupporters',
            'get_top_supporters' => 'getTopSupporters',
            'edit_supporter' => 'editSupporter',
            'get_supporter' => 'getSupporter',
            'delete_supporter' => 'deleteSupporter',
            'update_payment_status' => 'updatePaymentStatus',
            'status_report' => 'statusReport',

            'get_email_notifications'  => 'getEmailNotifications',
            'save_email_notification'  => 'saveEmailNotification',
            'send_test_email'          => 'sendTestEmail',

            'get_supporters_list'    => 'getSupportersList',
            'get_supporter_stats'    => 'getSupporterStats',
            'get_supporter_settings' => 'getSupporterSettings',
            'save_supporter_settings' => 'saveSupporterSettings',

            'get_subscriptions'      => 'getSubscriptions',
            'get_subscription'       => 'getSubscription',
            'cancel_subscription'    => 'cancelSubscription',
            'fetch_subscription'     => 'fetchSubscription',
            'get_subscription_stats' => 'getSubscriptionStats',

            'refund_transaction' => 'refundTransaction',

            'get_activities'    => 'getActivities',
            'dismiss_whats_new' => 'dismissWhatsNew',
            'get_review_prompt' => 'getReviewPrompt',
            'review_prompt_action' => 'reviewPromptAction',
        );

        if (!$this->canAccessRoute($route)) {
            wp_send_json_error(array(
                'message' => __("Sorry, you do not have permission for this action.", 'buy-me-coffee')
            ), 403);
        }

        if (isset($validRoutes[$route])) {
            do_action('buymecoffee_doing_ajax_forms_' . $route);
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized in sanitizeTextArray method
            $data = isset($_REQUEST['data']) ? $this->sanitizeTextArray(wp_unslash($_REQUEST['data'])) : [];
            return $this->{$validRoutes[$route]}($data);
        }
        do_action('buymecoffee_admin_ajax_handler_catch', $route);
        wp_send_json_error(array(
            'message' => __("Invalid route requested.", 'buy-me-coffee')
        ), 400);
    }

    public function getAllMethods()
    {
        $methods = PaymentHandler::getAllMethods();

        // Aggregate transaction stats
        $totalCount  = (int) buyMeCoffeeQuery()->table('buymecoffee_transactions')->count();
        $totalAmountRow = buyMeCoffeeQuery()->table('buymecoffee_transactions')
            ->where('status', 'paid')
            ->select(buyMeCoffeeQuery()->raw('SUM(payment_total) as total'))
            ->first();
        $totalAmount = $totalAmountRow ? (int) $totalAmountRow->total : 0;

        $stats = [
            'transaction_count' => $totalCount,
            'total_amount'      => $totalAmount,
        ];

        // Enrich each gateway with per-method details
        $enriched = [];
        foreach ($methods as $key => $method) {
            $settings = get_option('buymecoffee_payment_settings_' . $key, []);
            $method['payment_mode'] = isset($settings['payment_mode']) ? $settings['payment_mode'] : 'test';

            // Last transaction for this gateway
            $last = buyMeCoffeeQuery()
                ->table('buymecoffee_transactions')
                ->where('payment_method', $key)
                ->orderBy('created_at', 'DESC')
                ->first();
            $method['last_transaction'] = $last
                ? human_time_diff(strtotime($last->created_at)) . ' ago'
                : null;

            // Supported currencies (static per gateway)
            $currencyMap = [
                'stripe' => '135+ supported',
                'paypal' => 'USD, EUR, GBP +15 more',
            ];
            $method['currencies'] = isset($currencyMap[$key]) ? $currencyMap[$key] : 'Multiple';

            $enriched[] = $method;
        }

        wp_send_json_success([
            'gateways' => $enriched,
            'stats'    => $stats,
        ], 200);
    }

    public function statusReport()
    {
        $report = (new Supporters())->statusReport();
        wp_send_json_success($report, 200);
    }

    public function updatePaymentStatus($request)
    {
        $id = intval($request['id']);
        $status = sanitize_text_field($request['status']);
        $allowedStatuses = apply_filters('buymecoffee_allowed_payment_statuses', [
            'pending',
            'processing',
            'paid',
            'paid-initially',
            'failed',
            'refunded',
            'refunding',
        ]);

        if (!is_array($allowedStatuses) || !in_array($status, $allowedStatuses, true)) {
            wp_send_json_error([
                'message' => __('Invalid payment status.', 'buy-me-coffee')
            ], 400);
        }

        $supporter = (new Supporters())->getQuery()->where('id', $id)->update(['payment_status' => $status]);
        (new Transactions())->getQuery()->where('entry_id', $id)->update(['status' => $status]);
        wp_send_json_success($supporter, 200);
    }

    public function getSupporter($request)
    {
        $id = intval($request['id']);
        $supporter = (new Supporters())->find($id);

        $supporter->supporters_image = get_avatar_url($supporter->supporters_email);

        if ($supporter) {
            wp_send_json_success($supporter, 200);
        }
    }

    public function getSupporters($request)
    {
        return (new Supporters())->index($request);
    }

    public function getSupportersList($request)
    {
        return (new Supporters())->getUniqueSupporters($request);
    }

    public function getSupporterStats()
    {
        $stats = (new Supporters())->getSupporterStats();
        wp_send_json_success($stats, 200);
    }

    public function getTopSupporters()
    {
        $supporters = (new Supporters())->getTopSupportersList(10);
        wp_send_json_success(['supporters' => $supporters], 200);
    }

    public function getSupporterSettings()
    {
        $settings = get_option('buymecoffee_supporters_display_settings', []);
        $defaults = [
            'show_name'       => 'yes',
            'show_avatar'     => 'yes',
            'show_amount'     => 'no',
            'show_message'    => 'yes',
            'max_supporters'  => 20,
            'hide_email'      => 'yes',
            'allow_anonymous' => 'yes',
        ];
        wp_send_json_success(wp_parse_args($settings, $defaults), 200);
    }

    public function saveSupporterSettings($request)
    {
        $toggleKeys = ['show_name', 'show_avatar', 'show_amount', 'show_message', 'hide_email', 'allow_anonymous'];
        $settings = [];
        foreach ($toggleKeys as $key) {
            if (isset($request[$key])) {
                $settings[$key] = sanitize_text_field($request[$key]) === 'yes' ? 'yes' : 'no';
            }
        }
        if (isset($request['max_supporters'])) {
            $settings['max_supporters'] = max(1, min(100, absint($request['max_supporters'])));
        }
        update_option('buymecoffee_supporters_display_settings', $settings, false);
        wp_send_json_success(['message' => __('Settings saved successfully', 'buy-me-coffee')], 200);
    }

    public function editSupporter($request)
    {
        $id = absint(Arr::get($request, 'id'));
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid supporter ID', 'buy-me-coffee')], 400);
        }

        $supporterModel = new Supporters();
        $supporter = $supporterModel->getQuery()->where('id', $id)->first();
        if (!$supporter) {
            wp_send_json_error(['message' => __('Supporter not found', 'buy-me-coffee')], 404);
        }

        $updateData = [
            'updated_at' => current_time('mysql'),
        ];

        if (isset($request['name'])) {
            $updateData['supporters_name'] = sanitize_text_field(Arr::get($request, 'name', ''));
        }

        if (isset($request['email'])) {
            $updateData['supporters_email'] = sanitize_email(Arr::get($request, 'email', ''));
        }

        if (isset($request['amount'])) {
            $updateData['payment_total'] = max(0, absint(Arr::get($request, 'amount')));
        }

        $supporterModel->updateData($id, $updateData);
        $updatedSupporter = $supporterModel->getQuery()->where('id', $id)->first();
        wp_send_json_success($updatedSupporter, 200);
    }

    public function deleteSupporter($request)
    {
        $id = intval(Arr::get($request, 'id'));
        $supporterModel = new Supporters();
        $supporter = $supporterModel->find($id);

        if (!$supporter) {
            wp_send_json_error(['message' => __('Supporter not found', 'buy-me-coffee')], 404);
        }

        // Collect transaction IDs before deleting them (needed for activity log cleanup)
        $transactionIds = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('entry_id', $id)
            ->select('id')
            ->get();

        $txIds = array_map(function ($tx) {
            return (int) $tx->id;
        }, $transactionIds);

        // Delete activity logs for this supporter (submission + email events)
        buyMeCoffeeQuery()
            ->table('buymecoffee_activities')
            ->whereIn('object_type', ['submission', 'email'])
            ->where('object_id', $id)
            ->delete();

        // Delete activity logs for this supporter's transactions (payment events)
        if ($txIds) {
            buyMeCoffeeQuery()
                ->table('buymecoffee_activities')
                ->where('object_type', 'payment')
                ->whereIn('object_id', $txIds)
                ->delete();
        }

        // Delete subscriptions linked to this supporter
        $subscriptions = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('supporter_id', $id)
            ->select('id')
            ->get();

        if ($subscriptions) {
            $subIds = array_map(function ($sub) {
                return (int) $sub->id;
            }, $subscriptions);

            // Delete subscription activity logs
            buyMeCoffeeQuery()
                ->table('buymecoffee_activities')
                ->where('object_type', 'subscription')
                ->whereIn('object_id', $subIds)
                ->delete();

            buyMeCoffeeQuery()
                ->table('buymecoffee_subscriptions')
                ->where('supporter_id', $id)
                ->delete();
        }

        // Delete transactions
        (new Transactions())->delete($id, 'entry_id');

        // Delete supporter entry
        $supporterModel->delete($id);

        wp_send_json_success(['message' => __('Supporter and all related data deleted', 'buy-me-coffee')], 200);
    }

    public function getPaymentSettings($request)
    {
        $method = Arr::get($request, 'method');
        do_action('buymecoffee_get_payment_settings_' . $method);
    }

    public function resetDefaultSettings($request)
    {
        $settings = (new Buttons())->getButton($isDefault = true);
        update_option('buymecoffee_payment_setting', $settings, false);
        do_action('buymecoffee_after_reset_template', $settings);

        wp_send_json_success(array(
            'settings' => $settings,
            'message' => __("Settings successfully updated", 'buy-me-coffee')
        ), 200);

    }

    public function saveSettings($data)
    {
        $data = $data ?: array();

        update_option('buymecoffee_payment_setting', $data, false);
        do_action('buymecoffee_after_save_template', $data);

        wp_send_json_success(array(
            'message' => __("Settings successfully updated", 'buy-me-coffee')
        ), 200);
    }

    public function saveFormDesign($data)
    {
        $settings = (new Buttons())->getButton();
        if (!isset($settings['advanced'])) {
            return;
        }

        if (!empty($data['button_style']) && !empty($data['bg_style']) && !empty($data['border_style'])) {
            $settings['advanced']['button_style'] = $data['button_style'];
            $settings['advanced']['bg_style'] = $data['bg_style'];
            $settings['advanced']['border_style'] = $data['border_style'];
        }

        if (isset($data['quote'])) {
            $settings['advanced']['quote'] = sanitize_text_field($data['quote']);
        }

        if (isset($data['banner_image'])) {
            $settings['advanced']['banner_image'] = esc_url_raw($data['banner_image']);
        }

        if (isset($data['image'])) {
            $settings['advanced']['image'] = esc_url_raw($data['image']);
        }

        if (isset($data['yourName'])) {
            $settings['yourName'] = sanitize_text_field($data['yourName']);
        }

        $this->saveSettings($settings);
    }

    public function sanitizeTextArray($data)
    {
        // Keys that may contain multi-line text
        $textareaKeys = ['body', 'message', 'content'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (in_array($k, $textareaKeys, true)) {
                        $data[$key][$k] = sanitize_textarea_field($v);
                    } else {
                        $data[$key][$k] = sanitize_text_field($v);
                    }
                }
            } else {
                if (in_array($key, $textareaKeys, true)) {
                    $data[$key] = sanitize_textarea_field($value);
                } else {
                    $data[$key] = sanitize_text_field($value);
                }
            }
        }
        return $data;
    }

    public function getSettings()
    {
        $settings = (new Buttons())->getButton();

        $rawPages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC', 'post_status' => 'publish']);
        $pages = [];
        if (is_array($rawPages)) {
            foreach ($rawPages as $page) {
                $pages[] = ['id' => $page->ID, 'title' => $page->post_title];
            }
        }

        wp_send_json_success(
            array(
                'template'   => $settings,
                'currencies' => Currencies::all(),
                'pages'      => $pages,
            ),
            200
        );
    }

    public function getWeeklyRevenue($data = [])
    {
        $dateFrom = !empty($data['date_from']) ? sanitize_text_field($data['date_from']) : '';
        (new Supporters())->getWeeklyRevenue($dateFrom);
    }


    public function savePaymentSettings($data = array())
    {
        $settings = Arr::get($data, 'settings');
        $method = Arr::get($data, 'method');
        (new PaymentHandler())->saveSettings($method, $settings);
    }

    public function validateStripeKeys($data)
    {
        $secretKey = sanitize_text_field(Arr::get($data, 'secret_key', ''));
        if (empty($secretKey)) {
            wp_send_json_error(['message' => __('Secret key is required.', 'buy-me-coffee')], 400);
        }

        $response = wp_remote_get('https://api.stripe.com/v1/balance', [
            'headers' => ['Authorization' => 'Bearer ' . $secretKey],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 400);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $errorMessage = isset($body['error']['message']) ? $body['error']['message'] : __('Invalid API key.', 'buy-me-coffee');
            wp_send_json_error(['message' => $errorMessage], 400);
        }

        wp_send_json_success(['message' => __('Stripe API key is valid.', 'buy-me-coffee')], 200);
    }

    public function getEmailNotifications()
    {
        $notifications = EmailNotifications::getNotifications();
        wp_send_json_success(['notifications' => array_values($notifications)], 200);
    }

    public function saveEmailNotification($data)
    {
        $id = sanitize_key(Arr::get($data, 'id', ''));
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid notification ID', 'buy-me-coffee')], 400);
        }

        $notification = [
            'enabled' => !empty($data['enabled']),
            'subject' => Arr::get($data, 'subject', ''),
            'body'    => Arr::get($data, 'body', ''),
        ];

        $saved = EmailNotifications::saveNotification($id, $notification);

        if ($saved) {
            wp_send_json_success(['message' => __('Notification saved', 'buy-me-coffee')], 200);
        } else {
            wp_send_json_error(['message' => __('Failed to save notification', 'buy-me-coffee')], 400);
        }
    }

    public function sendTestEmail($data)
    {
        $id = sanitize_key(Arr::get($data, 'id', ''));
        $to = sanitize_email(Arr::get($data, 'to', get_option('admin_email')));

        $notifications = EmailNotifications::getNotifications();
        if (!isset($notifications[$id])) {
            wp_send_json_error(['message' => __('Notification not found', 'buy-me-coffee')], 404);
        }

        $n    = $notifications[$id];
        $vars = [
            'donor_name'     => 'Jane Doe',
            'donor_email'    => $to,
            'amount'         => '5.00 USD',
            'payment_method' => 'Stripe',
            'site_name'      => get_bloginfo('name'),
            'site_url'       => site_url(),
            'admin_email'    => get_option('admin_email'),
        ];

        $subject = EmailNotifications::parse($n['subject'], $vars);
        $body    = EmailNotifications::parse($n['body'], $vars) . "\n\n[This is a test email]";

        EmailNotifications::send($to, $subject, $body);

        wp_send_json_success(['message' => __('Test email sent to ', 'buy-me-coffee') . $to], 200);
    }

    public function getSubscriptions($request)
    {
        (new Subscriptions())->index($request);
    }

    public function getSubscription($request)
    {
        $id           = intval(Arr::get($request, 'id'));
        $subscription = (new Subscriptions())->find($id);

        if (!$subscription) {
            wp_send_json_error(['message' => __('Subscription not found', 'buy-me-coffee')], 404);
        }

        // Backfill missing period end for older rows that were activated before
        // current_period_end was persisted in the confirmation path.
        $currentPeriodEnd = isset($subscription->current_period_end) ? (string) $subscription->current_period_end : '';
        if (
            (empty($currentPeriodEnd) || $currentPeriodEnd === '0000-00-00 00:00:00')
            && !empty($subscription->stripe_subscription_id)
        ) {
            $keys = StripeSettings::getKeys();
            $remote = (new StripeAPI())->makeRequest(
                'subscriptions/' . sanitize_text_field($subscription->stripe_subscription_id),
                [],
                $keys['secret'],
                'GET'
            );

            if (!is_wp_error($remote)) {
                $periodEndTs = (int) Arr::get($remote, 'current_period_end', 0);
                if ($periodEndTs > 0) {
                    $periodEnd = gmdate('Y-m-d H:i:s', $periodEndTs);
                    (new Subscriptions())->updateData($id, [
                        'current_period_end' => $periodEnd,
                        'updated_at'         => current_time('mysql'),
                    ]);
                    $subscription->current_period_end = $periodEnd;
                }
            }
        }

        $supporter = (new Supporters())->find((int) $subscription->supporter_id);
        if ($supporter) {
            $subscription->supporters_name  = $supporter->supporters_name;
            $subscription->supporters_email = $supporter->supporters_email;
            $subscription->supporters_image = get_avatar_url($supporter->supporters_email);
            $subscription->entry_hash       = $supporter->entry_hash;
        }

        // Fetch related transactions
        $transactions = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('subscription_id', $id)
            ->orderBy('created_at', 'DESC')
            ->get();

        $subscription->transactions = $transactions;

        wp_send_json_success($subscription, 200);
    }

    public function cancelSubscription($request)
    {
        $id           = intval(Arr::get($request, 'id'));
        $subscription = (new Subscriptions())->find($id);

        if (!$subscription) {
            wp_send_json_error(['message' => __('Subscription not found', 'buy-me-coffee')], 404);
        }

        if ($subscription->status === 'cancelled') {
            wp_send_json_error(['message' => __('Subscription is already cancelled', 'buy-me-coffee')], 400);
        }

        // Cancel on Stripe
        if (!empty($subscription->stripe_subscription_id)) {
            $keys   = StripeSettings::getKeys();
            $apiKey = $keys['secret'];
            $response = (new StripeAPI())->makeRequest(
                'subscriptions/' . sanitize_text_field($subscription->stripe_subscription_id),
                [],
                $apiKey,
                'DELETE'
            );

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => $response->get_error_message()], 400);
            }

            $remoteStatus = sanitize_text_field(Arr::get($response, 'status', ''));
            if ($remoteStatus !== 'canceled') {
                wp_send_json_error(['message' => __('Stripe cancellation was not confirmed. Please try again.', 'buy-me-coffee')], 400);
            }
        }

        // Update local record
        (new Subscriptions())->updateData($id, [
            'status'       => 'cancelled',
            'cancelled_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ]);
        do_action('buymecoffee_subscription_cancelled', (int) $id);

        ActivityLogger::logSubscription($id, 'subscription_cancelled', 'Subscription cancelled by admin', [
            'status'  => 'info',
            'context' => [
                'subscription_id'        => $id,
                'supporter_id'           => $subscription->supporter_id,
                'stripe_subscription_id' => $subscription->stripe_subscription_id ?? '',
                'by_admin'               => true,
            ],
        ]);

        wp_send_json_success(['message' => __('Subscription cancelled successfully', 'buy-me-coffee')], 200);
    }

    public function fetchSubscription($request)
    {
        $id           = intval(Arr::get($request, 'id'));
        $subscription = (new Subscriptions())->find($id);

        if (!$subscription) {
            wp_send_json_error(['message' => __('Subscription not found', 'buy-me-coffee')], 404);
        }

        if (empty($subscription->stripe_subscription_id)) {
            wp_send_json_error(['message' => __('This subscription has no remote subscription ID.', 'buy-me-coffee')], 400);
        }

        $result = (new \BuyMeCoffee\Builder\Methods\Stripe\StripeSubscriptions())->fetchFromRemote($subscription);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'message' => __('Subscription fetched successfully from Stripe!', 'buy-me-coffee'),
        ], 200);
    }

    public function getSubscriptionStats()
    {
        $stats = (new Subscriptions())->getStats();
        wp_send_json_success($stats, 200);
    }

    public function refundTransaction($request)
    {
        $id = intval($request['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid transaction ID.', 'buy-me-coffee')], 400);
        }

        $transaction = (new Transactions())->find($id);
        if (!$transaction) {
            wp_send_json_error(['message' => __('Transaction not found.', 'buy-me-coffee')], 404);
        }

        if ($transaction->status === 'refunded') {
            wp_send_json_error(['message' => __('This transaction has already been refunded.', 'buy-me-coffee')], 400);
        }

        if (!in_array($transaction->status, ['paid', 'refunding'], true)) {
            wp_send_json_error(['message' => __('Only paid transactions can be refunded.', 'buy-me-coffee')], 400);
        }

        if (empty($transaction->charge_id)) {
            wp_send_json_error(['message' => __('No charge ID on record — please process the refund directly from the payment gateway dashboard.', 'buy-me-coffee')], 400);
        }

        ActivityLogger::logPayment((int) $transaction->id, 'refund_initiated', 'Refund initiated', [
            'status'  => 'info',
            'context' => [
                'transaction_id' => $transaction->id,
                'amount'         => $transaction->payment_total,
                'currency'       => $transaction->currency,
                'method'         => $transaction->payment_method,
                'charge_id'      => $transaction->charge_id,
            ],
        ]);

        $result = apply_filters('buymecoffee_process_refund_' . $transaction->payment_method, null, $transaction);

        if ($result === null) {
            wp_send_json_error(['message' => __('Refunds are not supported for this payment method.', 'buy-me-coffee')], 400);
        }

        if (is_wp_error($result)) {
            ActivityLogger::logPayment((int) $transaction->id, 'refund_failed', 'Refund failed', [
                'status'  => 'failed',
                'context' => [
                    'transaction_id' => $transaction->id,
                    'error'          => $result->get_error_message(),
                ],
            ]);
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        if (is_array($result)) {
            $gatewayStatus = strtolower(sanitize_text_field(Arr::get($result, 'status', '')));

            if (in_array($gatewayStatus, ['succeeded', 'completed'], true)) {
                $this->markRefunded($transaction, $result);
            }

            if (in_array($gatewayStatus, ['pending', 'processing'], true)) {
                ActivityLogger::logPayment((int) $transaction->id, 'refund_pending', 'Refund is pending confirmation', [
                    'status'  => 'warning',
                    'context' => [
                        'transaction_id' => $transaction->id,
                        'gateway_status' => $gatewayStatus,
                        'refund_id'      => Arr::get($result, 'refund_id', ''),
                    ],
                ]);

                wp_send_json_success([
                    'message'        => __('Refund request submitted and is pending confirmation from the gateway.', 'buy-me-coffee'),
                    'refund_status'  => 'pending',
                    'refund_id'      => sanitize_text_field(Arr::get($result, 'refund_id', '')),
                    'gateway_status' => $gatewayStatus,
                ], 202);
            }

            ActivityLogger::logPayment((int) $transaction->id, 'refund_failed', 'Refund returned non-terminal status', [
                'status'  => 'failed',
                'context' => [
                    'transaction_id' => $transaction->id,
                    'gateway_status' => $gatewayStatus,
                ],
            ]);
            wp_send_json_error(['message' => __('Refund could not be finalized. Gateway returned status: ', 'buy-me-coffee') . $gatewayStatus], 400);
        }

        if ($result === true) {
            $this->markRefunded($transaction);
        }

        wp_send_json_error(['message' => __('Unexpected refund response received.', 'buy-me-coffee')], 400);
    }

    private function markRefunded($transaction, $refundMeta = [])
    {
        $updateData = [
            'status'     => 'refunded',
            'updated_at' => current_time('mysql'),
        ];

        if (!empty($refundMeta)) {
            $updateData['payment_note'] = wp_json_encode([
                'refund_id'      => sanitize_text_field(Arr::get($refundMeta, 'refund_id', '')),
                'refund_status'  => sanitize_text_field(Arr::get($refundMeta, 'status', '')),
                'refunded_at'    => current_time('mysql'),
            ]);
        }

        (new Transactions())->updateData($transaction->id, $updateData);

        $supporterStatus = $this->calculateSupporterPaymentStatus((int) $transaction->entry_id);
        (new Supporters())->updateData($transaction->entry_id, [
            'payment_status' => $supporterStatus,
            'updated_at'     => current_time('mysql'),
        ]);

        ActivityLogger::logPayment((int) $transaction->id, 'refund_completed', 'Refund completed successfully', [
            'status'  => 'success',
            'context' => [
                'transaction_id' => $transaction->id,
                'refund_id'      => Arr::get($refundMeta, 'refund_id', ''),
            ],
        ]);

        do_action('buymecoffee_payment_status_updated', $transaction->id, 'refunded');
        wp_send_json_success([
            'message'        => __('Transaction refunded successfully.', 'buy-me-coffee'),
            'refund_status'  => 'succeeded',
            'refund_id'      => sanitize_text_field(Arr::get($refundMeta, 'refund_id', '')),
            'gateway_status' => sanitize_text_field(Arr::get($refundMeta, 'status', 'succeeded')),
        ], 200);
    }

    public function getActivities($request)
    {
        $perPage     = max(1, min(100, (int) Arr::get($request, 'per_page', 20)));
        $page        = max(0, (int) Arr::get($request, 'page', 0));
        $supporterId = max(0, (int) Arr::get($request, 'supporter_id', 0));

        // Supporter-scoped query: spans submission + email + payment types
        if ($supporterId > 0) {
            $result = ActivityLogger::getForSupporter($supporterId, $page, $perPage);
            wp_send_json_success(array_merge($result, ['page' => $page, 'per_page' => $perPage]), 200);
        }

        $objectType = sanitize_text_field(Arr::get($request, 'object_type', 'all'));
        $objectId   = max(0, (int) Arr::get($request, 'object_id', 0));

        $allowed = ['all', 'payment', 'subscription', 'submission', 'email'];
        if (!in_array($objectType, $allowed, true)) {
            wp_send_json_error(['message' => __('Invalid object type.', 'buy-me-coffee')], 400);
        }

        $result = ActivityLogger::getForObject($objectType, $objectId, $page, $perPage);
        wp_send_json_success(array_merge($result, ['page' => $page, 'per_page' => $perPage]), 200);
    }

    public function dismissWhatsNew()
    {
        update_user_meta(get_current_user_id(), 'buymecoffee_whats_new_seen', BUYMECOFFEE_VERSION);
        wp_send_json_success([], 200);
    }

    public function getReviewPrompt($request = [])
    {
        $routeName = sanitize_text_field(Arr::get($request, 'route_name', ''));
        $eligibleRoutes = ['Dashboard', 'RecentTransactions', 'Subscriptions'];

        if (!in_array($routeName, $eligibleRoutes, true)) {
            wp_send_json_success(['visible' => false], 200);
        }

        $signals = $this->getReviewPromptSignals();
        $mood = $this->buildReviewPromptMood($routeName, $signals);

        if (!$mood['eligible']) {
            wp_send_json_success(['visible' => false], 200);
        }

        $userId = get_current_user_id();
        $state = get_user_meta($userId, 'buymecoffee_review_prompt_state', true);
        if (!is_array($state)) {
            $state = [];
        }

        $now = current_time('timestamp');
        $status = sanitize_text_field($state['status'] ?? 'pending');
        if (in_array($status, ['reviewed', 'dismissed'], true)) {
            wp_send_json_success(['visible' => false], 200);
        }

        $snoozedUntil = absint($state['snoozed_until'] ?? 0);
        if ($snoozedUntil > $now) {
            wp_send_json_success(['visible' => false], 200);
        }

        if (empty($state['initialized_at'])) {
            $state['initialized_at'] = $now;
            $state['visit_count'] = 0;
            $state['visit_target'] = random_int(1, 3);
            $state['status'] = 'pending';
        }

        $lastTrackedRoute = sanitize_text_field($state['last_tracked_route'] ?? '');
        $lastTrackedAt = absint($state['last_tracked_at'] ?? 0);
        $shouldCountVisit = $lastTrackedRoute !== $routeName || ($now - $lastTrackedAt) > MINUTE_IN_SECONDS;

        if ($shouldCountVisit) {
            $state['visit_count'] = absint($state['visit_count'] ?? 0) + 1;
            $state['last_tracked_route'] = $routeName;
            $state['last_tracked_at'] = $now;
        }

        update_user_meta($userId, 'buymecoffee_review_prompt_state', $state);

        if (absint($state['visit_count'] ?? 0) < absint($state['visit_target'] ?? 1)) {
            wp_send_json_success(['visible' => false], 200);
        }

        if (empty($state['shown_at'])) {
            $state['shown_at'] = $now;
            update_user_meta($userId, 'buymecoffee_review_prompt_state', $state);
        }

        wp_send_json_success([
            'visible' => true,
            'review_url' => 'https://wordpress.org/support/plugin/buy-me-coffee/reviews/#new-post',
            'paid_count' => $signals['paid_count'],
            'supporter_label' => $signals['paid_count'] === 1
                ? __('donation', 'buy-me-coffee')
                : __('donations', 'buy-me-coffee'),
            'mood_score' => $mood['score'],
            'mood_reason' => $mood['reason'],
            'mood_question' => $mood['question'],
            'review_title' => $mood['review_title'],
            'review_message' => $mood['review_message'],
            'stat_label' => $mood['stat_label'],
            'stat_value' => $mood['stat_value'],
        ], 200);
    }

    public function reviewPromptAction($request = [])
    {
        $action = sanitize_text_field(Arr::get($request, 'review_action', ''));
        $allowedActions = ['snooze', 'reviewed', 'dismiss'];

        if (!in_array($action, $allowedActions, true)) {
            wp_send_json_error(['message' => __('Invalid review prompt action.', 'buy-me-coffee')], 400);
        }

        $userId = get_current_user_id();
        $state = get_user_meta($userId, 'buymecoffee_review_prompt_state', true);
        if (!is_array($state)) {
            $state = [];
        }

        $now = current_time('timestamp');

        if ($action === 'snooze') {
            $state['status'] = 'pending';
            $state['snoozed_until'] = $now + (14 * DAY_IN_SECONDS);
        } elseif ($action === 'dismiss') {
            $state['status'] = 'dismissed';
            $state['dismissed_at'] = $now;
        } else {
            $state['status'] = 'reviewed';
            $state['reviewed_at'] = $now;
        }

        update_user_meta($userId, 'buymecoffee_review_prompt_state', $state);

        wp_send_json_success([], 200);
    }

    private function calculateSupporterPaymentStatus($entryId)
    {
        $transactions = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('entry_id', $entryId)
            ->get();

        $statuses = [];
        foreach ($transactions as $transaction) {
            if (!empty($transaction->status)) {
                $statuses[] = sanitize_text_field($transaction->status);
            }
        }

        if (in_array('paid', $statuses, true)) {
            return 'paid';
        }

        if (in_array('processing', $statuses, true) || in_array('pending', $statuses, true) || in_array('refunding', $statuses, true)) {
            return 'pending';
        }

        if (in_array('failed', $statuses, true)) {
            return 'failed';
        }

        return 'refunded';
    }

    private function canAccessRoute($route)
    {
        $readOnlyRoutes = [
            'get_data',
            'gateways',
            'get_settings',
            'get_weekly_revenue',
            'get_supporters',
            'get_top_supporters',
            'get_supporter',
            'status_report',
            'get_email_notifications',
            'get_subscriptions',
            'get_subscription',
            'get_subscription_stats',
            'get_supporters_list',
            'get_supporter_stats',
            'get_supporter_settings',
            'get_activities',
            'get_review_prompt',
        ];

        $topLevelWriteRoutes = [
            'dismiss_whats_new',
            'review_prompt_action',
        ];

        if (in_array($route, $readOnlyRoutes, true)) {
            return AccessControl::hasTopLevelMenuPermission();
        }

        if (in_array($route, $topLevelWriteRoutes, true)) {
            return AccessControl::hasTopLevelMenuPermission();
        }

        return AccessControl::hasFinancialPermission();
    }

    private function getReviewPromptSignals()
    {
        $paidStatuses = ['paid', 'paid-initially'];

        $paidCount = (int) buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->whereIn('status', $paidStatuses)
            ->count();

        $paidTotalRow = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->whereIn('status', $paidStatuses)
            ->select(buyMeCoffeeQuery()->raw('SUM(payment_total) as total'))
            ->first();

        $activeSubscriptions = (int) buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('status', 'active')
            ->count();

        return [
            'paid_count' => $paidCount,
            'paid_total' => $paidTotalRow ? (int) $paidTotalRow->total : 0,
            'active_subscriptions' => $activeSubscriptions,
        ];
    }

    private function buildReviewPromptMood($routeName, $signals)
    {
        $paidCount = (int) $signals['paid_count'];
        $paidTotal = (int) $signals['paid_total'];
        $activeSubscriptions = (int) $signals['active_subscriptions'];

        $baseMood = [
            'eligible' => false,
            'score' => 0,
            'reason' => '',
            'question' => __('Is Buy Me Coffee feeling helpful right now?', 'buy-me-coffee'),
            'review_title' => __('You just turned support into something real.', 'buy-me-coffee'),
            'review_message' => __('If this plugin helped create that moment, a kind review would genuinely mean a lot to us.', 'buy-me-coffee'),
            'stat_label' => __('Successful donations', 'buy-me-coffee'),
            'stat_value' => (string) $paidCount,
        ];

        if ($routeName === 'Subscriptions') {
            if ($activeSubscriptions < 1) {
                return $baseMood;
            }

            return array_merge($baseMood, [
                'eligible' => true,
                'score' => 90,
                'reason' => __('You have active recurring support.', 'buy-me-coffee'),
                'question' => __('Your recurring support is running. Does that feel like a win today?', 'buy-me-coffee'),
                'review_title' => __('Recurring support is a big milestone.', 'buy-me-coffee'),
                'review_message' => __('If Buy Me Coffee helped you earn steady support, your review can help another creator find the same path.', 'buy-me-coffee'),
                'stat_label' => __('Active subscriptions', 'buy-me-coffee'),
                'stat_value' => (string) $activeSubscriptions,
            ]);
        }

        if ($routeName === 'RecentTransactions') {
            if ($paidCount < 1 || $paidTotal < 1) {
                return $baseMood;
            }

            return array_merge($baseMood, [
                'eligible' => true,
                'score' => $paidCount > 2 ? 88 : 78,
                'reason' => __('You are reviewing successful transactions.', 'buy-me-coffee'),
                'question' => __('You are looking at successful donations. Feeling good about how Buy Me Coffee is working?', 'buy-me-coffee'),
                'review_title' => __('Those successful donations matter.', 'buy-me-coffee'),
                'review_message' => __('If Buy Me Coffee helped make these payments simple, a short review would help us keep improving it.', 'buy-me-coffee'),
                'stat_label' => __('Successful donations', 'buy-me-coffee'),
                'stat_value' => (string) $paidCount,
            ]);
        }

        if ($routeName === 'Dashboard') {
            if (($paidCount < 1 || $paidTotal < 1) && $activeSubscriptions < 1) {
                return $baseMood;
            }

            $hasRecurringWin = $activeSubscriptions > 0;

            return array_merge($baseMood, [
                'eligible' => true,
                'score' => $hasRecurringWin ? 92 : 82,
                'reason' => $hasRecurringWin
                    ? __('Your dashboard has active recurring support.', 'buy-me-coffee')
                    : __('Your dashboard has successful donations.', 'buy-me-coffee'),
                'question' => $hasRecurringWin
                    ? __('You have recurring support coming in. Does Buy Me Coffee feel useful today?', 'buy-me-coffee')
                    : __('You have successful support showing here. Is Buy Me Coffee helping you right now?', 'buy-me-coffee'),
                'review_title' => $hasRecurringWin
                    ? __('That recurring support is worth celebrating.', 'buy-me-coffee')
                    : __('You just turned support into something real.', 'buy-me-coffee'),
                'review_message' => __('If this plugin helped create that moment, a kind review would genuinely mean a lot to us.', 'buy-me-coffee'),
                'stat_label' => $hasRecurringWin ? __('Active subscriptions', 'buy-me-coffee') : __('Successful donations', 'buy-me-coffee'),
                'stat_value' => (string) ($hasRecurringWin ? $activeSubscriptions : $paidCount),
            ]);
        }

        return $baseMood;
    }
}
