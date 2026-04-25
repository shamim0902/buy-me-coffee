<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit;

class ActivityLogger
{
    const TABLE = 'buymecoffee_activities';

    /**
     * Core log method. All typed wrappers funnel through here.
     *
     * @param string $objectType  'payment' | 'subscription' | 'submission' | 'email'
     * @param int    $objectId    Row ID in the corresponding table
     * @param string $event       e.g. 'payment_completed', 'subscription_renewed'
     * @param string $title       Short human-readable one-liner
     * @param array  $args {
     *     @type string $status      'success'|'info'|'warning'|'failed'  Default: 'info'
     *     @type string $description Longer HTML/plain content
     *     @type array  $context     Key-value pairs JSON-encoded into context column
     *     @type string $created_by  Override creator; default resolves from WP session or 'system'
     * }
     * @return int|false Inserted row ID or false on failure
     */
    public static function log(
        string $objectType,
        int $objectId,
        string $event,
        string $title,
        array $args = []
    ) {
        if (!$objectId || !$objectType || !$event) {
            return false;
        }

        $createdBy = 'system';
        if (empty($args['created_by'])) {
            if (is_user_logged_in()) {
                $user      = wp_get_current_user();
                $createdBy = $user->user_login ?: 'admin';
            }
        } else {
            $createdBy = $args['created_by'];
        }

        $allowedStatuses = ['success', 'info', 'warning', 'failed'];
        $status = isset($args['status']) && in_array($args['status'], $allowedStatuses, true)
            ? $args['status']
            : 'info';

        $data = [
            'object_type' => sanitize_text_field($objectType),
            'object_id'   => (int) $objectId,
            'event'       => sanitize_text_field($event),
            'status'      => $status,
            'title'       => sanitize_text_field($title),
            'description' => isset($args['description'])
                ? wp_kses_post($args['description'])
                : null,
            'context'     => isset($args['context']) && is_array($args['context'])
                ? wp_json_encode($args['context'])
                : null,
            'created_by'  => sanitize_text_field($createdBy),
            'created_at'  => current_time('mysql'),
        ];

        $result = buyMeCoffeeQuery()->table(self::TABLE)->insert($data);
        return $result ? (int) $result : false;
    }

    // ── Typed convenience wrappers ──────────────────────────────────────────

    public static function logPayment(int $transactionId, string $event, string $title, array $args = [])
    {
        return self::log('payment', $transactionId, $event, $title, $args);
    }

    public static function logSubscription(int $subscriptionId, string $event, string $title, array $args = [])
    {
        return self::log('subscription', $subscriptionId, $event, $title, $args);
    }

    public static function logSubmission(int $supporterId, string $event, string $title, array $args = [])
    {
        return self::log('submission', $supporterId, $event, $title, $args);
    }

    public static function logEmail(int $supporterId, string $event, string $title, array $args = [])
    {
        return self::log('email', $supporterId, $event, $title, $args);
    }

    // ── Query helpers ────────────────────────────────────────────────────────

    /**
     * Fetch paginated activities for a given object (or all activities).
     *
     * @param string $objectType  Pass 'all' to query across all types
     * @param int    $objectId    Pass 0 to fetch all entries of the given type
     * @param int    $page        0-based page number
     * @param int    $perPage
     * @return array{ logs: array, total: int }
     */
    public static function getForObject(
        string $objectType,
        int $objectId = 0,
        int $page = 0,
        int $perPage = 20
    ): array {
        $query = buyMeCoffeeQuery()->table(self::TABLE);

        if ($objectType !== 'all') {
            $query = $query->where('object_type', $objectType);
        }
        if ($objectId > 0) {
            $query = $query->where('object_id', $objectId);
        }

        $total = (int) $query->count();

        $logs = buyMeCoffeeQuery()->table(self::TABLE);
        if ($objectType !== 'all') {
            $logs = $logs->where('object_type', $objectType);
        }
        if ($objectId > 0) {
            $logs = $logs->where('object_id', $objectId);
        }
        $logs = $logs
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($page * $perPage)
            ->get();

        foreach ($logs as $log) {
            if (!empty($log->context)) {
                $log->context = json_decode($log->context, true);
            }
        }

        return ['logs' => $logs, 'total' => $total];
    }

    /**
     * Fetch all activities related to a supporter:
     *   - submission + email events logged against supporter.id
     *   - payment events logged against any transaction.id belonging to that supporter
     *
     * @param int $supporterId  buymecoffee_supporters.id
     * @param int $page         0-based
     * @param int $perPage
     * @return array{ logs: array, total: int }
     */
    public static function getForSupporter(
        int $supporterId,
        int $page = 0,
        int $perPage = 20
    ): array {
        global $wpdb;

        $actTable   = $wpdb->prefix . self::TABLE;
        $transTable = $wpdb->prefix . 'buymecoffee_transactions';
        $offset     = $page * $perPage;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are safe constants
        $where = $wpdb->prepare(
            "(object_type IN ('submission','email') AND object_id = %d)
              OR (object_type = 'payment' AND object_id IN (
                   SELECT id FROM {$transTable} WHERE entry_id = %d
              ))",
            $supporterId,
            $supporterId
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fully built above
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$actTable} WHERE {$where}");

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fully built above
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$actTable} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $perPage,
                $offset
            )
        );
        // phpcs:enable

        foreach ($logs as $log) {
            if (!empty($log->context)) {
                $log->context = json_decode($log->context, true);
            }
        }

        return ['logs' => $logs ?: [], 'total' => $total];
    }
}
