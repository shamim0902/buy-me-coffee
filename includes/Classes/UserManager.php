<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit;

class UserManager
{
    public function register()
    {
        add_action('buymecoffee_subscription_activated', [$this, 'handleSubscriptionActivated']);
        add_action('buymecoffee_subscription_cancelled', [$this, 'handleSubscriptionCancelled']);
        add_action('buymecoffee_subscription_status_changed', [$this, 'handleSubscriptionStatusChanged'], 10, 3);
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

        $userData = $this->getOrCreateUser(
            $supporter->supporters_email,
            $supporter->supporters_name ?? ''
        );

        if (empty($userData['user_id'])) {
            return;
        }

        $userId = (int) $userData['user_id'];
        $isCreated = !empty($userData['created']);

        $this->linkUserToSupporter((int) $supporter->id, $userId);
        $this->syncSubscriptionAccessMeta($userId);
        $this->maybeAutoLogin($userId, $isCreated);
    }

    public function handleSubscriptionCancelled($subscriptionId)
    {
        $this->syncBySubscriptionId((int) $subscriptionId);
    }

    public function handleSubscriptionStatusChanged($subscriptionId, $oldStatus, $newStatus)
    {
        $this->syncBySubscriptionId((int) $subscriptionId);
    }

    private function syncBySubscriptionId(int $subscriptionId): void
    {
        $subscription = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('id', $subscriptionId)
            ->first();

        if (!$subscription) {
            return;
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', (int) $subscription->supporter_id)
            ->first();

        if (!$supporter || empty($supporter->wp_user_id)) {
            return;
        }

        $this->syncSubscriptionAccessMeta((int) $supporter->wp_user_id);
    }

    private function maybeAutoLogin(int $userId, bool $isCreated)
    {
        // Only auto-login during a front-end AJAX call (payment confirmation),
        // and only for accounts created in this payment flow.
        if (!$isCreated || !wp_doing_ajax() || is_user_logged_in()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action guard for payment confirmation callbacks
        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
        if (strpos($action, 'buymecoffee_payment_confirmation_') !== 0) {
            return;
        }

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return;
        }

        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true, is_ssl());
        do_action('wp_login', $user->user_login, $user);
    }

    private function getOrCreateUser(string $email, string $name): array
    {
        $existing = get_user_by('email', $email);
        if ($existing) {
            return [
                'user_id' => (int) $existing->ID,
                'created' => false
            ];
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
            return [
                'user_id' => 0,
                'created' => false
            ];
        }

        // Sends WordPress account creation email to the user with set-password flow.
        wp_send_new_user_notifications($userId, 'user');
        $this->sendAccountCreatedEmail((int) $userId);

        return [
            'user_id' => (int) $userId,
            'created' => true
        ];
    }

    private function sendAccountCreatedEmail(int $userId): void
    {
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return;
        }

        $settings = get_option('buymecoffee_payment_setting', []);
        $accountPageId = !empty($settings['account_page_id']) ? (int) $settings['account_page_id'] : 0;
        $accountPageUrl = $accountPageId ? (string) get_permalink($accountPageId) : '';
        $accountUrl = $accountPageUrl ?: wp_login_url();

        $subject = sprintf(
            /* translators: %s: Site name */
            __('Your %s supporter account is ready', 'buy-me-coffee'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );

        $body = sprintf(
            /* translators: 1: Display name, 2: Account URL, 3: Password reset URL */
            __("Hi %1\$s,\n\nYour supporter account has been created successfully.\n\nAccount page: %2\$s\nSet password: %3\$s\n\nThank you for supporting us.", 'buy-me-coffee'),
            $user->display_name ?: $user->user_login,
            esc_url_raw($accountUrl),
            esc_url_raw(wp_lostpassword_url())
        );

        wp_mail($user->user_email, $subject, $body);
    }

    private function linkUserToSupporter(int $supporterId, int $userId)
    {
        buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', $supporterId)
            ->update(['wp_user_id' => $userId]);
    }

    private function syncSubscriptionAccessMeta(int $userId): void
    {
        buy_me_coffee_user_has_active_subscription($userId, true);
    }
}
