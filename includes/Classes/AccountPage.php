<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Services\SubscriptionCancellationService;

if (!defined('ABSPATH')) exit;

class AccountPage
{
    public function registerAjax()
    {
        add_action('wp_ajax_buymecoffee_cancel_subscription', [$this, 'handleCancelSubscription']);
        add_action('admin_bar_menu', [$this, 'addAccountAdminBarLink'], 999);
        add_action('admin_init', [$this, 'redirectDonorFromWpAdmin'], 1);
        add_filter('login_redirect', [$this, 'redirectDonorAfterLogin'], 10, 3);
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

        $result = (new SubscriptionCancellationService())->cancel($subscription, [
            'log_title' => __('Subscription cancelled by supporter', 'buy-me-coffee'),
            'context'   => ['by_supporter' => true],
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Subscription cancelled successfully.', 'buy-me-coffee')]);
    }

    private function isEnabled(): bool
    {
        $settings = get_option('buymecoffee_payment_setting', []);
        return !empty($settings['enable_account']) && $settings['enable_account'] === 'yes';
    }

    public function addAccountAdminBarLink($adminBar)
    {
        if (!$this->isRestrictedDonorUser()) {
            return;
        }

        $accountUrl = $this->getDonorAccountUrl();
        if (!$accountUrl) {
            return;
        }

        $adminBar->remove_node('dashboard');
        $adminBar->remove_node('wp-logo');
        $adminBar->remove_node('site-name');
        $adminBar->remove_node('user-info');
        $adminBar->remove_node('edit-profile');
        $adminBar->remove_node('updates');
        $adminBar->remove_node('comments');
        $adminBar->remove_node('new-content');
        $adminBar->remove_node('search');

        $adminBar->add_node([
            'id'     => 'buymecoffee_account',
            'title'  => __('Donor Account', 'buy-me-coffee'),
            'href'   => $accountUrl,
            'meta'   => [
                'class' => 'buymecoffee-account-admin-bar-link',
            ],
        ]);

        $accountNode = $adminBar->get_node('my-account');
        if ($accountNode) {
            $adminBar->add_node([
                'id'    => 'my-account',
                'title' => $accountNode->title,
                'href'  => $accountUrl,
            ]);
        }

        $adminBar->add_node([
            'id'     => 'buymecoffee_account_user_menu',
            'parent' => 'user-actions',
            'title'  => __('Donor Account', 'buy-me-coffee'),
            'href'   => $accountUrl,
        ]);
    }

    public function redirectDonorFromWpAdmin()
    {
        if ($this->shouldAllowAdminRequest() || !$this->isRestrictedDonorUser()) {
            return;
        }

        $accountUrl = $this->getDonorAccountUrl();
        if (!$accountUrl) {
            return;
        }

        wp_safe_redirect($accountUrl);
        exit;
    }

    public function redirectDonorAfterLogin($redirectTo, $requestedRedirectTo, $user)
    {
        if (!$user instanceof \WP_User || !$this->isRestrictedDonorUser((int) $user->ID)) {
            return $redirectTo;
        }

        $accountUrl = $this->getDonorAccountUrl();
        return $accountUrl ?: $redirectTo;
    }

    private function isRestrictedDonorUser(int $userId = 0): bool
    {
        $userId = $userId ?: get_current_user_id();
        if (!$userId) {
            return false;
        }

        if (user_can($userId, 'manage_options') || user_can($userId, 'buy-me-coffee_full_access') || user_can($userId, 'buy-me-coffee_can_view_menus')) {
            return false;
        }

        $shouldRestrict = $this->hasSupporterRecordForUser($userId) || $this->isSubscriberDonorAccount($userId);

        return (bool) apply_filters('buymecoffee_restrict_donor_wp_admin', $shouldRestrict, $userId);
    }

    private function shouldAllowAdminRequest(): bool
    {
        if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }

        global $pagenow;
        return in_array($pagenow, ['admin-ajax.php', 'admin-post.php'], true);
    }

    private function hasSupporterRecordForUser(int $userId): bool
    {
        $user = get_userdata($userId);
        $email = $user instanceof \WP_User ? sanitize_email($user->user_email) : '';

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where(function ($query) use ($userId, $email) {
                $query->where('wp_user_id', $userId);

                if ($email) {
                    $query->orWhere('supporters_email', $email);
                }
            })
            ->select('id')
            ->first();

        return !empty($supporter);
    }

    private function isSubscriberDonorAccount(int $userId): bool
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return false;
        }

        return in_array('subscriber', (array) $user->roles, true);
    }

    private function getDonorAccountUrl(): string
    {
        $accountUrl = $this->getAccountPageUrl();
        return $accountUrl ?: home_url('/');
    }

    private function getAccountPageUrl(): string
    {
        $settings = get_option('buymecoffee_payment_setting', []);
        $accountPageId = !empty($settings['account_page_id']) ? (int) $settings['account_page_id'] : 0;
        if (!$accountPageId) {
            return '';
        }

        $url = get_permalink($accountPageId);
        return $url ? (string) $url : '';
    }

    public function render(): string
    {
        global $wpdb;

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
        $supporters = $supportersModel->findAllByWpUser($currentUserId, 20);

        if (empty($supporters)) {
            return View::make('templates.AccountNoRecord', []);
        }

        $supporterIds = [];
        foreach ($supporters as $supporter) {
            $supporterIds[] = (int) $supporter->id;
        }

        $subscriptionRows = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->select([
                buyMeCoffeeQuery()->raw('0 as access_id'),
                'buymecoffee_subscriptions.id' => 'subscription_id',
                buyMeCoffeeQuery()->raw("'subscription' as access_type"),
                'buymecoffee_subscriptions.status',
                'buymecoffee_subscriptions.current_period_end',
                'buymecoffee_subscriptions.created_at',
                'buymecoffee_subscriptions.amount',
                'buymecoffee_subscriptions.currency',
                'buymecoffee_subscriptions.interval_type',
            ])
            ->whereIn('supporter_id', $supporterIds)
            ->where(function ($subscriptionQuery) {
                $subscriptionQuery->whereNotNull('stripe_subscription_id')
                    ->orWhereNull('level_id');
            })
            ->orderBy('created_at', 'DESC')
            ->limit(20)
            ->get();

        $transactionsTable = $wpdb->prefix . 'buymecoffee_transactions';

        $accessRows = buyMeCoffeeQuery()
            ->table('buymecoffee_membership_access')
            ->leftJoin('buymecoffee_transactions', 'buymecoffee_membership_access.transaction_id', '=', 'buymecoffee_transactions.id')
            ->select([
                'buymecoffee_membership_access.id' => 'access_id',
                buyMeCoffeeQuery()->raw('0 as subscription_id'),
                'buymecoffee_membership_access.access_type',
                'buymecoffee_membership_access.status',
                'buymecoffee_membership_access.expires_at' => 'current_period_end',
                'buymecoffee_membership_access.starts_at' => 'created_at',
                buyMeCoffeeQuery()->raw("COALESCE({$transactionsTable}.payment_total, 0) as amount"),
                buyMeCoffeeQuery()->raw("COALESCE({$transactionsTable}.currency, '') as currency"),
                'buymecoffee_membership_access.access_type' => 'interval_type',
            ])
            ->whereIn('buymecoffee_membership_access.supporter_id', $supporterIds)
            ->whereNull('buymecoffee_membership_access.subscription_id')
            ->orderBy('buymecoffee_membership_access.created_at', 'DESC')
            ->limit(20)
            ->get();

        $subscriptions = array_merge((array) $subscriptionRows, (array) $accessRows);
        usort($subscriptions, function ($a, $b) {
            return strtotime($b->created_at ?? '') <=> strtotime($a->created_at ?? '');
        });
        $subscriptions = array_slice($subscriptions, 0, 20);

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
