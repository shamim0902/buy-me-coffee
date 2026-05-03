<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Builder\Methods\Stripe\StripeSettings;
use BuyMeCoffee\Builder\Methods\Stripe\API as StripeAPI;

if (!defined('ABSPATH')) exit;

class AccountPage
{
    public function registerAjax()
    {
        add_action('wp_ajax_buymecoffee_cancel_subscription', [$this, 'handleCancelSubscription']);
    }

    public function handleCancelSubscription()
    {
        check_ajax_referer('buymecoffee_nonce', 'buymecoffee_nonce');

        $subscriptionId = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;
        if (!$subscriptionId) {
            wp_send_json_error(['message' => __('Invalid subscription.', 'buy-me-coffee')]);
        }

        $userId = get_current_user_id();
        if (!$userId) {
            wp_send_json_error(['message' => __('You must be logged in.', 'buy-me-coffee')]);
        }

        // Verify the subscription belongs to this user
        $subscription = (new Subscriptions())->find($subscriptionId);
        if (!$subscription) {
            wp_send_json_error(['message' => __('Subscription not found.', 'buy-me-coffee')]);
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', (int) $subscription->supporter_id)
            ->first();

        if (!$supporter || (int) $supporter->wp_user_id !== $userId) {
            wp_send_json_error(['message' => __('You do not have permission to cancel this subscription.', 'buy-me-coffee')]);
        }

        if ($subscription->status === 'cancelled') {
            wp_send_json_error(['message' => __('Subscription is already cancelled.', 'buy-me-coffee')]);
        }

        // Cancel on Stripe
        if (!empty($subscription->stripe_subscription_id)) {
            $keys = StripeSettings::getKeys();
            $response = (new StripeAPI())->makeRequest(
                'subscriptions/' . sanitize_text_field($subscription->stripe_subscription_id),
                [],
                $keys['secret'],
                'DELETE'
            );

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => $response->get_error_message()]);
            }
        }

        (new Subscriptions())->updateData($subscriptionId, [
            'status'       => 'cancelled',
            'cancelled_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ]);

        do_action('buymecoffee_subscription_cancelled', $subscriptionId);

        wp_send_json_success(['message' => __('Subscription cancelled successfully.', 'buy-me-coffee')]);
    }

    private function isEnabled(): bool
    {
        $settings = get_option('buymecoffee_payment_setting', []);
        return !empty($settings['enable_account']) && $settings['enable_account'] === 'yes';
    }

    public function render(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        if (!is_user_logged_in()) {
            return View::make('templates.AccountLogin', [
                'redirect' => get_permalink(),
            ]);
        }

        // Admins should use the admin panel, not the subscriber account page.
        // Subscribers can only see their own linked account — never anyone else's.
        $currentUserId = get_current_user_id();
        $supportersModel = new Supporters();
        $supporters = $supportersModel->findAllByWpUser($currentUserId);

        if (empty($supporters)) {
            return View::make('templates.AccountNoRecord', []);
        }

        $supporterIds = [];
        foreach ($supporters as $supporter) {
            $supporterIds[] = (int) $supporter->id;
        }

        $subscriptions = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->whereIn('supporter_id', $supporterIds)
            ->orderBy('created_at', 'DESC')
            ->get();

        $transactions = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->whereIn('entry_id', $supporterIds)
            ->orderBy('created_at', 'DESC')
            ->limit(20)
            ->get();

        return View::make('templates.SubscriberAccount', [
            'supporter'     => $supporters[0],
            'subscriptions' => $subscriptions,
            'transactions'  => $transactions,
            'user'          => wp_get_current_user(),
        ]);
    }
}
