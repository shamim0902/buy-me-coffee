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

// ── Supporter Meta helpers (uses buymecoffee_supporters_meta table) ──

if (!function_exists('buymecoffee_get_supporter_meta')) {
    /**
     * Get a meta value for a supporter.
     *
     * @param int    $supporterId Supporter row ID.
     * @param string $key         Meta key.
     * @return mixed|null Unserialized value or null if not found.
     */
    function buymecoffee_get_supporter_meta($supporterId, $key)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'buymecoffee_supporters_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$table} WHERE supporter_id = %d AND meta_key = %s LIMIT 1",
            $supporterId,
            $key
        ));

        if ($value === null) {
            return null;
        }

        return maybe_unserialize($value);
    }
}

if (!function_exists('buymecoffee_update_supporter_meta')) {
    /**
     * Insert or update a meta value for a supporter.
     *
     * @param int    $supporterId Supporter row ID.
     * @param string $key         Meta key.
     * @param mixed  $value       Value (will be serialized if non-scalar).
     */
    function buymecoffee_update_supporter_meta($supporterId, $key, $value)
    {
        global $wpdb;
        $table      = $wpdb->prefix . 'buymecoffee_supporters_meta';
        $serialized = maybe_serialize($value);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE supporter_id = %d AND meta_key = %s LIMIT 1",
            $supporterId,
            $key
        ));

        if ($exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                ['meta_value' => $serialized],
                ['supporter_id' => $supporterId, 'meta_key' => $key],
                ['%s'],
                ['%d', '%s']
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert(
                $table,
                ['supporter_id' => $supporterId, 'meta_key' => $key, 'meta_value' => $serialized],
                ['%d', '%s', '%s']
            );
        }
    }
}

if (!function_exists('buymecoffee_delete_supporter_meta')) {
    /**
     * Delete a meta value for a supporter.
     *
     * @param int    $supporterId Supporter row ID.
     * @param string $key         Meta key.
     */
    function buymecoffee_delete_supporter_meta($supporterId, $key)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'buymecoffee_supporters_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($table, ['supporter_id' => $supporterId, 'meta_key' => $key], ['%d', '%s']);
    }
}

// ── Resolve supporter IDs for a WP user ──

if (!function_exists('buymecoffee_get_supporter_ids_for_user')) {
    /**
     * Get all supporter IDs linked to a WP user.
     *
     * @param int $userId WordPress user ID.
     * @return int[]
     */
    function buymecoffee_get_supporter_ids_for_user($userId)
    {
        $supporters = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('wp_user_id', absint($userId))
            ->select(['id'])
            ->get();

        $ids = [];
        foreach ($supporters as $supporter) {
            $ids[] = (int) $supporter->id;
        }
        return $ids;
    }
}

// ── Subscription access checks ──

if (!function_exists('buymecoffee_user_has_active_subscription')) {
    /**
     * Check whether a WP user currently has an active Buy Me Coffee subscription.
     *
     * Caches result in buymecoffee_supporters_meta (key: has_active_subscription).
     *
     * @param int  $userId       WordPress user ID.
     * @param bool $forceRefresh Recalculate from DB even when cached.
     * @return bool
     */
    function buymecoffee_user_has_active_subscription($userId, $forceRefresh = false)
    {
        $userId = absint($userId);
        if (!$userId) {
            return false;
        }

        $supporterIds = buymecoffee_get_supporter_ids_for_user($userId);
        if (empty($supporterIds)) {
            return false;
        }

        // Use the first supporter for meta storage
        $primarySupporterId = $supporterIds[0];

        if (!$forceRefresh) {
            $cached = buymecoffee_get_supporter_meta($primarySupporterId, 'has_active_subscription');
            if ($cached === 'yes') {
                return true;
            }
            if ($cached === 'no') {
                return false;
            }
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

        $hasActive = $activeCount > 0;
        buymecoffee_update_supporter_meta($primarySupporterId, 'has_active_subscription', $hasActive ? 'yes' : 'no');

        return $hasActive;
    }
}

if (!function_exists('buymecoffee_user_get_active_level_ids')) {
    /**
     * Return array of membership level IDs for which a WP user has an active subscription.
     *
     * Caches result in buymecoffee_supporters_meta (key: active_level_ids).
     *
     * @param int  $userId       WordPress user ID.
     * @param bool $forceRefresh Recalculate from DB even when cached.
     * @return int[]
     */
    function buymecoffee_user_get_active_level_ids($userId, $forceRefresh = false)
    {
        $userId = absint($userId);
        if (!$userId) {
            return [];
        }

        $supporterIds = buymecoffee_get_supporter_ids_for_user($userId);
        if (empty($supporterIds)) {
            return [];
        }

        $primarySupporterId = $supporterIds[0];

        if (!$forceRefresh) {
            $cached = buymecoffee_get_supporter_meta($primarySupporterId, 'active_level_ids');
            if (is_array($cached)) {
                return $cached;
            }
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
        buymecoffee_update_supporter_meta($primarySupporterId, 'active_level_ids', $levelIds);

        return $levelIds;
    }
}
