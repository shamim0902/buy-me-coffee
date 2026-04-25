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
        $page           = isset($args['page']) ? max(0, intval($args['page'])) : 0;
        $posts_per_page = isset($args['posts_per_page']) ? max(1, intval($args['posts_per_page'])) : 10;
        $search         = isset($args['search']) ? sanitize_text_field($args['search']) : '';
        $filter_status  = isset($args['filter_status']) ? sanitize_text_field($args['filter_status']) : 'all';

        $offset = $page * $posts_per_page;
        $query = $this->buildIndexQuery($search, $filter_status);

        $results = $query
            ->select('s.*', 'sup.supporters_name', 'sup.supporters_email')
            ->orderBy('s.created_at', 'DESC')
            ->limit($posts_per_page)
            ->offset($offset)
            ->get();

        $total = (int) $this->buildIndexQuery($search, $filter_status)->count();

        wp_send_json_success([
            'subscriptions' => $results,
            'total'         => $total,
        ], 200);
    }

    private function buildIndexQuery($search, $filterStatus)
    {
        $query = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions as s')
            ->leftJoin('buymecoffee_supporters as sup', 's.supporter_id', '=', 'sup.id');

        if ($filterStatus && $filterStatus !== 'all') {
            $query->where('s.status', $filterStatus);
        }

        if ($search) {
            $query->where(function ($whereQuery) use ($search) {
                $whereQuery->where('sup.supporters_name', 'LIKE', '%' . $search . '%')
                    ->orWhere('sup.supporters_email', 'LIKE', '%' . $search . '%');
            });
        }

        return $query;
    }

    public function getStats()
    {
        $active_count = (int) $this->getQuery()
            ->where('status', 'active')
            ->count();

        // MRR: sum of monthly-normalised amounts for active subscriptions.
        // Yearly amounts are divided by 12.
        $rows = $this->getQuery()
            ->select('amount', 'interval_type')
            ->where('status', 'active')
            ->get();

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
