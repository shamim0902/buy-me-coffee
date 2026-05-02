<?php

// write your global functions here
if (!defined('ABSPATH')) exit; // Exit if accessed directly

if (!function_exists('buyMeCoffeeQuery')) {
    function buyMeCoffeeQuery()
    {
        if (!function_exists('buyMeCoffeeDB')) {
            include BUYMECOFFEE_DIR . 'includes/libs/wp-fluent/wp-fluent.php';
        }
        return buyMeCoffeeDB();
    }
}

if (!function_exists('buymecoffee_user_has_active_subscription')) {
    /**
     * Check whether a WP user currently has an active Buy Me Coffee subscription.
     *
     * @param int  $userId       WordPress user ID.
     * @param bool $forceRefresh Recalculate from DB even when cached meta exists.
     * @return bool
     */
    function buymecoffee_user_has_active_subscription($userId, $forceRefresh = false)
    {
        $userId = absint($userId);
        if (!$userId) {
            return false;
        }

        $cached = get_user_meta($userId, 'buymecoffee_has_active_subscription', true);
        if (!$forceRefresh) {
            if ($cached === 'yes') {
                return true;
            }
            if ($cached === 'no') {
                return false;
            }
        }

        $supporters = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('wp_user_id', $userId)
            ->select(['id'])
            ->get();

        if (empty($supporters)) {
            update_user_meta($userId, 'buymecoffee_has_active_subscription', 'no');
            return false;
        }

        $supporterIds = [];
        foreach ($supporters as $supporter) {
            $supporterIds[] = (int) $supporter->id;
        }

        $activeStatuses = apply_filters('buymecoffee_active_subscription_statuses_for_access', ['active']);
        if (empty($activeStatuses) || !is_array($activeStatuses)) {
            $activeStatuses = ['active'];
        }

        $activeCount = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->whereIn('supporter_id', $supporterIds)
            ->whereIn('status', $activeStatuses)
            ->count();

        $hasActiveSubscription = $activeCount > 0;
        update_user_meta($userId, 'buymecoffee_has_active_subscription', $hasActiveSubscription ? 'yes' : 'no');

        return $hasActiveSubscription;
    }
}

if (!function_exists('buymecoffee_user_get_active_level_ids')) {
    /**
     * Return array of membership level IDs for which a WP user has an active subscription.
     *
     * @param int  $userId       WordPress user ID.
     * @param bool $forceRefresh Recalculate from DB even when cached meta exists.
     * @return int[]
     */
    function buymecoffee_user_get_active_level_ids($userId, $forceRefresh = false)
    {
        $userId = absint($userId);
        if (!$userId) {
            return [];
        }

        if (!$forceRefresh) {
            $cached = get_user_meta($userId, 'buymecoffee_active_level_ids', true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $supporters = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('wp_user_id', $userId)
            ->select(['id'])
            ->get();

        if (empty($supporters)) {
            update_user_meta($userId, 'buymecoffee_active_level_ids', []);
            return [];
        }

        $supporterIds = [];
        foreach ($supporters as $supporter) {
            $supporterIds[] = (int) $supporter->id;
        }

        $activeStatuses = apply_filters('buymecoffee_active_subscription_statuses_for_access', ['active']);
        if (empty($activeStatuses) || !is_array($activeStatuses)) {
            $activeStatuses = ['active'];
        }

        $rows = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->whereIn('supporter_id', $supporterIds)
            ->whereIn('status', $activeStatuses)
            ->select(['level_id'])
            ->get();

        $levelIds = [];
        foreach ($rows as $row) {
            if (!empty($row->level_id)) {
                $levelIds[] = (int) $row->level_id;
            }
        }

        $levelIds = array_unique($levelIds);
        update_user_meta($userId, 'buymecoffee_active_level_ids', $levelIds);

        return $levelIds;
    }
}
