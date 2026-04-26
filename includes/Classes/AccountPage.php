<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Models\Supporters;

if (!defined('ABSPATH')) exit;

class AccountPage
{
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
