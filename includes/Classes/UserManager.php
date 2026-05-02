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

        $this->linkUserToSupporter((int) $supporter->id, $userId);
        $this->syncSubscriptionAccessMeta($userId);

        // Auto-login newly created users when the request comes from the browser (AJAX),
        // not from a webhook (which has no browser session).
        if (!empty($userData['created']) && !is_user_logged_in() && $this->isBrowserRequest()) {
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId, true);
        }
    }

    private function isBrowserRequest(): bool
    {
        // Webhook requests use the Stripe-Signature header; browser AJAX does not.
        // Also check for the standard WP AJAX action which confirms it's a user-initiated request.
        if (!empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            return false;
        }

        return defined('DOING_AJAX') && DOING_AJAX;
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
        buymecoffee_user_has_active_subscription($userId, true);
        delete_user_meta($userId, 'buymecoffee_active_level_ids');
    }
}
