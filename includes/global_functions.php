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
        $row = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters_meta')
            ->where('supporter_id', absint($supporterId))
            ->where('meta_key', sanitize_key($key))
            ->select(['meta_value'])
            ->first();

        if (!$row) {
            return null;
        }

        return maybe_unserialize($row->meta_value);
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
        $supporterId = absint($supporterId);
        $key        = sanitize_key($key);
        $serialized = maybe_serialize($value);

        $exists = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters_meta')
            ->where('supporter_id', $supporterId)
            ->where('meta_key', $key)
            ->select(['id'])
            ->first();

        if ($exists) {
            buyMeCoffeeQuery()
                ->table('buymecoffee_supporters_meta')
                ->where('id', (int) $exists->id)
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom supporter meta table column, not a WP meta query.
                ->update(['meta_value' => $serialized]);
        } else {
            buyMeCoffeeQuery()
                ->table('buymecoffee_supporters_meta')
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Custom supporter meta table columns, not WP meta queries.
                ->insert(['supporter_id' => $supporterId, 'meta_key' => $key, 'meta_value' => $serialized]);
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
        buyMeCoffeeQuery()
            ->table('buymecoffee_supporters_meta')
            ->where('supporter_id', absint($supporterId))
            ->where('meta_key', sanitize_key($key))
            ->delete();
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

        $rows = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->select(['level_id'])
            ->whereIn('supporter_id', $supporterIds)
            ->whereNotNull('level_id')
            ->where(function ($whereQuery) {
                $whereQuery->where('status', 'active')
                    ->orWhere(function ($cancelledQuery) {
                        $cancelledQuery->where('status', 'cancelled')
                            ->whereNotNull('current_period_end')
                            ->where('current_period_end', '>', current_time('mysql', true));
                    });
            })
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
