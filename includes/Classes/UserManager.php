<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Models\MembershipAccess;

if (!defined('ABSPATH')) exit;

class UserManager
{
    public function register()
    {
        add_action('buymecoffee_subscription_activated', [$this, 'handleSubscriptionActivated']);
        add_action('buymecoffee_subscription_cancelled', [$this, 'handleSubscriptionCancelled']);
        add_action('buymecoffee_subscription_status_changed', [$this, 'handleSubscriptionStatusChanged'], 10, 3);
        add_action('buymecoffee_membership_access_activated', [$this, 'handleMembershipAccessActivated']);
        add_action('buymecoffee_payment_status_updated', [$this, 'handlePaymentStatusUpdated'], 10, 2);
    }

    /**
     * Keep membership access in sync with a transaction's payment status.
     *
     * A refunded/failed one-time (or manual) membership must lose content
     * access; without this the access row stays 'active' forever because no
     * further gateway event fires after a refund. Conversely, restoring a
     * transaction to 'paid' re-grants the access that a prior refund revoked.
     *
     * @param int    $transactionId Transaction row ID.
     * @param string $status        New payment status.
     */
    public function handlePaymentStatusUpdated($transactionId, $status)
    {
        $transactionId = (int) $transactionId;
        if (!$transactionId) {
            return;
        }

        $transaction = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('id', $transactionId)
            ->first();

        if (!$transaction) {
            return;
        }

        // Subscription entitlement follows the subscription lifecycle and its
        // billing period, so the linked transaction is not handled here the way
        // a one-time payment is: activation belongs to subscription_activated,
        // and a single failed renewal must not cut off a member Stripe is still
        // retrying.
        //
        // A refund is the exception, because nothing else ends it. The
        // cancellation hook only fires when the agreement itself ends, and the
        // period would otherwise simply run to term — so a refunded subscriber
        // keeps the content they were refunded for. Only the payment covering
        // the current period may decide that, so an older refunded renewal
        // cannot revoke a period a later payment has already covered.
        if (!empty($transaction->subscription_id)) {
            if ($status === 'refunded' && $this->coversCurrentPeriod($transaction)) {
                (new MembershipAccess())->revokeBySubscription((int) $transaction->subscription_id, $status);
            }

            return;
        }

        if (in_array($status, ['refunded', 'failed'], true)) {
            (new MembershipAccess())->revokeByTransaction($transactionId, $status);
            return;
        }

        // Re-grant access if a previously refunded/failed transaction is set
        // back to paid, so the buyer isn't left locked out of gated content.
        if ($status === 'paid') {
            (new MembershipAccess())->activateByTransaction($transactionId);
        }
    }

    /**
     * Whether a transaction is the payment that bought the period now running.
     *
     * Asked as "has anything since actually paid?", because only a later
     * payment that succeeded can supersede this one. Reading the newest row of
     * any status instead would let a renewal still sitting at pending — one
     * whose paid transition never completed — stand in for a payment that was
     * never made, and a refund of the payment that really did advance the
     * period would then be waved through as superseded.
     *
     * @param object $transaction Transaction row, carrying a subscription_id.
     * @return bool
     */
    private function coversCurrentPeriod($transaction)
    {
        $laterPayment = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('subscription_id', (int) $transaction->subscription_id)
            ->where('status', 'paid')
            ->where('id', '>', (int) $transaction->id)
            ->first();

        return !$laterPayment;
    }

    private function isEnabled(): bool
    {
        $settings = get_option('buymecoffee_payment_setting', []);
        return !empty($settings['enable_account']) && $settings['enable_account'] === 'yes';
    }

    public function handleSubscriptionActivated($subscriptionId)
    {
        $subscription = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('id', (int) $subscriptionId)
            ->first();

        if (!$subscription) {
            return;
        }

        $isMembershipAccess = !empty($subscription->level_id);
        if ($isMembershipAccess) {
            (new MembershipAccess())->upsertFromSubscription((int) $subscription->id);
            return;
        }

        if (!$isMembershipAccess && !$this->isEnabled()) {
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
            $this->syncSubscriptionAccessMeta((int) $supporter->wp_user_id);
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
        (new MembershipAccess())->upsertFromSubscription((int) $subscriptionId);
        $this->syncBySubscriptionId((int) $subscriptionId);
    }

    public function handleSubscriptionStatusChanged($subscriptionId, $oldStatus, $newStatus)
    {
        (new MembershipAccess())->upsertFromSubscription((int) $subscriptionId);
        $this->syncBySubscriptionId((int) $subscriptionId);
    }

    public function handleMembershipAccessActivated($accessId)
    {
        $access = (new MembershipAccess())->find((int) $accessId);
        if (!$access) {
            return;
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', (int) $access->supporter_id)
            ->first();

        if (!$supporter || empty($supporter->supporters_email)) {
            return;
        }

        if (!empty($supporter->wp_user_id)) {
            (new MembershipAccess())->updateData((int) $access->id, [
                'wp_user_id' => (int) $supporter->wp_user_id,
                'updated_at' => current_time('mysql'),
            ]);
            $this->syncSubscriptionAccessMeta((int) $supporter->wp_user_id);
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
        (new MembershipAccess())->updateData((int) $access->id, [
            'wp_user_id' => $userId,
            'updated_at' => current_time('mysql'),
        ]);
        $this->syncSubscriptionAccessMeta($userId);

        if (!empty($userData['created']) && !is_user_logged_in() && $this->isBrowserRequest()) {
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId, true);
        }
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
        // Invalidate level IDs cache — will be re-built on next access check
        $supporterIds = buymecoffee_get_supporter_ids_for_user($userId);
        if (!empty($supporterIds)) {
            buymecoffee_delete_supporter_meta($supporterIds[0], 'active_level_ids');
        }
    }
}
