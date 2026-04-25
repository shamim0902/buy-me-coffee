<?php

namespace BuyMeCoffee\Models;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Subscriptions extends Model
{
    protected $table = 'buymecoffee_subscriptions';

    public function updateData($id, $data)
    {
        return $this->getQuery()->where('id', $id)->update($data);
    }

    public function findByStripeId($stripeSubscriptionId)
    {
        return $this->getQuery()
            ->where('stripe_subscription_id', sanitize_text_field($stripeSubscriptionId))
            ->first();
    }

    public function index($args = [])
    {
        global $wpdb;

        $page           = isset($args['page']) ? max(0, intval($args['page'])) : 0;
        $posts_per_page = isset($args['posts_per_page']) ? max(1, intval($args['posts_per_page'])) : 10;
        $search         = isset($args['search']) ? sanitize_text_field($args['search']) : '';
        $filter_status  = isset($args['filter_status']) ? sanitize_text_field($args['filter_status']) : 'all';

        $sub_table  = $wpdb->prefix . 'buymecoffee_subscriptions';
        $sup_table  = $wpdb->prefix . 'buymecoffee_supporters';

        $where   = [];
        $params  = [];

        if ($filter_status && $filter_status !== 'all') {
            $where[]  = 's.status = %s';
            $params[] = $filter_status;
        }

        if ($search) {
            $where[]  = '(sup.supporters_name LIKE %s OR sup.supporters_email LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe (prefixed by wpdb)
        $count_sql = "SELECT COUNT(*) FROM $sub_table s LEFT JOIN $sup_table sup ON s.supporter_id = sup.id $where_sql";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int) $wpdb->get_var($count_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $offset = $page * $posts_per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe
        $data_sql = "SELECT s.*, sup.supporters_name, sup.supporters_email
                     FROM $sub_table s
                     LEFT JOIN $sup_table sup ON s.supporter_id = sup.id
                     $where_sql
                     ORDER BY s.created_at DESC
                     LIMIT %d OFFSET %d";

        $query_params   = array_merge($params, [$posts_per_page, $offset]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare($data_sql, $query_params));

        wp_send_json_success([
            'subscriptions' => $results,
            'total'         => $total,
        ], 200);
    }

    public function getStats()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'buymecoffee_subscriptions';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $active_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", 'active')
        );

        // MRR: sum of monthly-normalised amounts for active subscriptions
        // yearly amounts are divided by 12
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT amount, interval_type FROM $table WHERE status = %s",
                'active'
            )
        );

        $mrr = 0;
        foreach ($rows as $row) {
            $amount = (int) $row->amount;
            if ($row->interval_type === 'year') {
                $mrr += (int) round($amount / 12);
            } else {
                $mrr += $amount;
            }
        }

        return [
            'active_count' => $active_count,
            'mrr'          => $mrr,
        ];
    }
}
