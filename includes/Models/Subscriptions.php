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
        $posts_per_page = isset($args['posts_per_page']) ? max(1, min(100, intval($args['posts_per_page']))) : 10;
        $search         = isset($args['search']) ? sanitize_text_field($args['search']) : '';
        $filter_status  = isset($args['filter_status']) ? sanitize_text_field($args['filter_status']) : 'all';

        $offset = $page * $posts_per_page;
        $query = $this->buildIndexQuery($search, $filter_status);

        $results = $query
            ->select('buymecoffee_subscriptions.*', 'buymecoffee_supporters.supporters_name', 'buymecoffee_supporters.supporters_email')
            ->orderBy('buymecoffee_subscriptions.created_at', 'DESC')
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
            ->table('buymecoffee_subscriptions')
            ->leftJoin('buymecoffee_supporters', 'buymecoffee_subscriptions.supporter_id', '=', 'buymecoffee_supporters.id');

        if ($filterStatus && $filterStatus !== 'all') {
            $query->where('buymecoffee_subscriptions.status', $filterStatus);
        }

        if ($search) {
            $query->where(function ($whereQuery) use ($search) {
                $whereQuery->where('buymecoffee_supporters.supporters_name', 'LIKE', '%' . $search . '%')
                    ->orWhere('buymecoffee_supporters.supporters_email', 'LIKE', '%' . $search . '%');
            });
        }

        return $query;
    }

    public function getStats()
    {
        $stats = $this->getQuery()
            ->select(
                $this->raw('COUNT(*) as active_count'),
                $this->raw("COALESCE(SUM(CASE WHEN interval_type = 'year' THEN ROUND(amount / 12) ELSE amount END), 0) as mrr")
            )
            ->where('status', 'active')
            ->first();

        $active_count = (int) ($stats->active_count ?? 0);
        $mrr = (int) ($stats->mrr ?? 0);

        // Recent active subscriptions for dashboard widget
        $recent_rows = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->leftJoin('buymecoffee_supporters', 'buymecoffee_subscriptions.supporter_id', '=', 'buymecoffee_supporters.id')
            ->select(
                'buymecoffee_subscriptions.id',
                'buymecoffee_subscriptions.amount',
                'buymecoffee_subscriptions.currency',
                'buymecoffee_subscriptions.interval_type',
                'buymecoffee_subscriptions.status',
                'buymecoffee_supporters.supporters_name',
                'buymecoffee_supporters.supporters_email'
            )
            ->where('buymecoffee_subscriptions.status', 'active')
            ->orderBy('buymecoffee_subscriptions.created_at', 'DESC')
            ->limit(4)
            ->get();

        $recent = [];
        foreach ($recent_rows as $row) {
            $amountFormatted = \BuyMeCoffee\Helpers\PaymentHelper::getFormattedAmount((int) $row->amount, $row->currency ?: 'USD');
            $interval = ($row->interval_type === 'year') ? '/yr' : '/mo';
            $recent[] = [
                'id'     => $row->id,
                'name'   => $row->supporters_name ?: 'Anonymous',
                'email'  => $row->supporters_email ?: '',
                'amount' => $amountFormatted . $interval,
                'plan'   => ucfirst($row->interval_type ?? 'month') . 'ly',
                'status' => $row->status,
            ];
        }

        return [
            'active_count' => $active_count,
            'mrr'          => $mrr,
            'recent'       => $recent,
        ];
    }
}
