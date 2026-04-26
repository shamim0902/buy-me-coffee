<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Models\Supporters;

if (!defined('ABSPATH')) exit;

class UserManager
{
    public function register()
    {
        add_action('buymecoffee_subscription_activated', [$this, 'handleSubscriptionActivated']);
    }

    private function isEnabled(): bool
    {
        $settings = get_option('buymecoffee_payment_setting', []);
        return !empty($settings['enable_account']) && $settings['enable_account'] === 'yes';
    }

    public function handleSubscriptionActivated($subscriptionId)
    {
        if (!$this->isEnabled()) {
            return;
        }

        $subscription = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('id', (int) $subscriptionId)
            ->first();

        if (!$subscription) {
            return;
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', (int) $subscription->supporter_id)
            ->first();

        if (!$supporter || empty($supporter->supporters_email)) {
            return;
        }

        // Skip if already linked to a WP user
        if (!empty($supporter->wp_user_id)) {
            return;
        }

        $userId = $this->getOrCreateUser(
            $supporter->supporters_email,
            $supporter->supporters_name ?? ''
        );

        if (!$userId) {
            return;
        }

        $this->linkUserToSupporter((int) $supporter->id, $userId);
        $this->maybeAutoLogin($userId);
    }

    private function maybeAutoLogin(int $userId)
    {
        // Only auto-login during a front-end AJAX call (payment confirmation),
        // not during Stripe webhooks or WP-CLI.
        if (!wp_doing_ajax() || is_user_logged_in()) {
            return;
        }
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);
    }

    private function getOrCreateUser(string $email, string $name): int
    {
        $existing = get_user_by('email', $email);
        if ($existing) {
            return $existing->ID;
        }

        $username = sanitize_user(strstr($email, '@', true), true);
        if (empty($username)) {
            $username = 'supporter_' . substr(md5($email), 0, 8);
        }

        if (username_exists($username)) {
            $username = $username . '_' . wp_generate_password(4, false);
        }

        $userId = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $email,
            'display_name' => $name ?: $username,
            'role'         => 'subscriber',
            'user_pass'    => wp_generate_password(24),
        ]);

        if (is_wp_error($userId)) {
            return 0;
        }

        wp_send_new_user_notifications($userId, 'user');

        return (int) $userId;
    }

    private function linkUserToSupporter(int $supporterId, int $userId)
    {
        buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', $supporterId)
            ->update(['wp_user_id' => $userId]);
    }
}
