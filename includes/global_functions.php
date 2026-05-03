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

if (!function_exists('buymecoffee_subscription_access_where')) {
    /**
     * Build the WHERE clause for subscriptions that grant content access.
     *
     * A subscription grants access if:
     *   - status is 'active', OR
     *   - status is 'cancelled' but current_period_end is still in the future
     *     (the subscriber already paid for this billing cycle)
     *
     * @param string $table  Fully-qualified subscriptions table name.
     * @return string SQL WHERE fragment (without leading WHERE/AND).
     */
    function buymecoffee_subscription_access_where($table)
    {
        $now = current_time('mysql', true); // UTC
        return "({$table}.status = 'active' OR ({$table}.status = 'cancelled' AND {$table}.current_period_end IS NOT NULL AND {$table}.current_period_end > '{$now}'))";
    }
}

if (!function_exists('buymecoffee_user_get_active_level_ids')) {
    /**
     * Return array of membership level IDs for which a WP user has access.
     *
     * Includes cancelled subscriptions whose paid period hasn't expired yet.
     *
     * Caches result in buymecoffee_supporters_meta (key: active_level_ids).
     *
     * @param int  $userId       WordPress user ID.
     * @param bool $forceRefresh Recalculate from DB even when cached.
     * @return int[]
     */
    function buymecoffee_user_get_active_level_ids($userId, $forceRefresh = false)
    {
        global $wpdb;

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

        $table        = $wpdb->prefix . 'buymecoffee_subscriptions';
        $placeholders = implode(',', array_fill(0, count($supporterIds), '%d'));
        $accessWhere  = buymecoffee_subscription_access_where($table);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and access clause are hardcoded
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT level_id FROM {$table} WHERE supporter_id IN ({$placeholders}) AND {$accessWhere} AND level_id IS NOT NULL",
            ...$supporterIds
        ));

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
