<?php

namespace BuyMeCoffee\Models;

use BuyMeCoffee\Helpers\PaymentHelper;
use WpFluent\Exception;
use WpFluent\QueryBuilder\Transaction;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Supporters extends Model
{
    protected $table = "buymecoffee_supporters";
    public function index($args)
    {
        global $wpdb;
        $supTable = $wpdb->prefix . 'buymecoffee_supporters';
        $txTable  = $wpdb->prefix . 'buymecoffee_transactions';

        $page = isset($args['page']) ? max(0, intval($args['page'])) : 0;
        $postsPerPage = isset($args['posts_per_page']) ? max(1, min(100, intval($args['posts_per_page']))) : 10;
        $offset = $page * $postsPerPage;

        // Use a correlated subquery for transaction_type instead of a JOIN
        // to avoid duplicate rows when a supporter has multiple transactions.
        $query = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->select(
                'buymecoffee_supporters.*',
                $this->raw("(SELECT transaction_type FROM {$txTable} WHERE entry_id = {$supTable}.id ORDER BY id DESC LIMIT 1) as transaction_type")
            )
            ->offset($offset)
            ->limit($postsPerPage);

        if (isset($args['filter_top'])) {
            $query->where('buymecoffee_supporters.payment_status', 'paid')
                ->orWhere('buymecoffee_supporters.payment_status', 'paid-initially')
                ->orderBy('buymecoffee_supporters.payment_total', 'DESC');

            $currencyTotalPending = $this->getQuery()
                ->groupBy('currency')
                ->where('payment_status', 'pending')
                ->select($this->raw('SUM(payment_total) as total_amount, currency'))
                ->get();

            foreach ($currencyTotalPending as $currency) {
                $currency->formatted_total = PaymentHelper::getFormattedAmount($currency->total_amount, $currency->currency);
            }
        } else {
            $query->orderBy('buymecoffee_supporters.id', 'DESC');
        }

        if (isset($args['filter_status']) && $args['filter_status'] !== 'all') {
            $query->where('buymecoffee_supporters.payment_status', $args['filter_status']);
        }

        if (isset($args['filter_method']) && $args['filter_method'] !== 'all') {
            $query->where('buymecoffee_supporters.payment_method', sanitize_text_field($args['filter_method']));
        }

        if (!empty($args['search'])) {
            $search = $args['search'];
            $query->where(function($q) use ($search) {
                $q->where('buymecoffee_supporters.supporters_name', 'LIKE', '%' . $search . '%')
                  ->orWhere('buymecoffee_supporters.supporters_email', 'LIKE', '%' . $search . '%');
            });
        }

        if (!empty($args['date_from'])) {
            $query->where('buymecoffee_supporters.created_at', '>=', sanitize_text_field($args['date_from']));
        }

        $supporters = $query->get();

        foreach ($supporters as $supporter) {
            $supporter->amount_formatted = PaymentHelper::getFormattedAmount($supporter->payment_total, $supporter->currency);
        }

        $count = $this->getQuery()
            ->where('payment_status', 'paid')
            ->orWhere('payment_status', 'paid-initially')
            ->select($this->raw('SUM(coffee_count) as total_coffee'))
            ->first();

        // Sum revenue from the transactions table (includes renewals),
        // not from the supporters table (which only has the initial amount).
        $currencyTotal = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->groupBy('currency')
            ->where('status', 'paid')
            ->select($this->raw('SUM(payment_total) as total_amount, currency'))
            ->get();

        foreach ($currencyTotal as $currency) {
            $currency->formatted_total = PaymentHelper::getFormattedAmount($currency->total_amount, $currency->currency);
        }
        $total = $query->count();
        $lastPage = ceil($total / $postsPerPage);

        wp_send_json_success(
            array(
                'supporters' => $supporters,
                'total' => $total,
                'last_page' => $lastPage,
                'reports' => array(
                    'total_supporters' => $total,
                    'total_coffee' => $count->total_coffee,
                    'currency_total' => $currencyTotal,
                    'currency_total_pending' => $currencyTotalPending??[],
                )
            ),
            200
        );
    }

    public function statusReport()
    {
        $total = $this->getQuery()->count();
        $totalPaid = $this->getQuery()->where('payment_status', 'paid')->count();
        $totalPending = $this->getQuery()->where('payment_status', 'pending')->count();
        $totalFailed = $this->getQuery()->where('payment_status', 'failed')->count();
        $totalRefunded = $this->getQuery()->where('payment_status', 'refunded')->count();

        wp_send_json_success(
            array(
                'total' => $total,
                'total_paid' => $totalPaid,
                'total_pending' => $totalPending,
                'total_refunded' => $totalRefunded,
                'total_failed' => $totalFailed,
            ),
            200
        );
    }

    public function updateData($entryId, $data)
    {
        $supporters = $this->getQuery()->where('id', $entryId)->update($data);
        return $supporters;
    }

    public function find($id)
    {
        $supporter = $this->getQuery()
            ->where('buymecoffee_supporters.id', $id)
            ->first();

        if (!$supporter) {
            throw new Exception(esc_html__('No supporters found!', 'buy-me-coffee'));
        }

        // Get ALL transactions for this supporter (includes renewals)
        $transactions = (new Transactions())->getQuery()
            ->where('entry_id', $supporter->id)
            ->orderBy('created_at', 'DESC')
            ->limit(100)
            ->get();

        // Primary transaction (latest) for the detail card
        $supporter->transaction = !empty($transactions) ? $transactions[0] : null;

        if ($supporter->transaction) {
            $supporter->transaction->transaction_url = apply_filters(
                'buymecoffee/payment/get_transaction_url_' . $supporter->transaction->payment_method,
                '',
                $supporter->transaction
            );
        }

        // All transactions for this supporter entry (for payment history)
        $supporter->transactions = $transactions;

        // Add transaction URLs to all transactions
        foreach ($supporter->transactions as $tx) {
            $tx->transaction_url = apply_filters(
                'buymecoffee/payment/get_transaction_url_' . $tx->payment_method,
                '',
                $tx
            );
        }

        // Other donation entries from the same email (separate form submissions)
        if (!empty($supporter->supporters_email)) {
            $otherDonations = $this->getQuery()
                ->where('supporters_email', $supporter->supporters_email)
                ->orderBy('created_at', 'DESC')
                ->limit(20)
                ->get();
        } else {
            $otherDonations = $this->getQuery()->where('buymecoffee_supporters.id', $id)->get();
        }

        $supporter->other_donations = $otherDonations;

        global $wpdb;

        $supportersTable = $wpdb->prefix . 'buymecoffee_supporters';
        $transactionsTable = $wpdb->prefix . 'buymecoffee_transactions';

        if (!empty($supporter->supporters_email)) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are plugin-owned tables using the WordPress prefix
            $donationStats = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as donation_count, COALESCE(SUM(coffee_count), 0) as total_coffee
                FROM {$supportersTable}
                WHERE supporters_email = %s",
                $supporter->supporters_email
            ));

            $transactionStats = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN t.status = %s THEN t.payment_total ELSE 0 END), 0) as total_paid,
                    COALESCE(SUM(CASE WHEN t.status <> %s OR t.status IS NULL THEN t.payment_total ELSE 0 END), 0) as total_pending
                FROM {$transactionsTable} t
                INNER JOIN {$supportersTable} s ON s.id = t.entry_id
                WHERE s.supporters_email = %s",
                'paid',
                'paid',
                $supporter->supporters_email
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are plugin-owned tables using the WordPress prefix
            $donationStats = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as donation_count, COALESCE(SUM(coffee_count), 0) as total_coffee
                FROM {$supportersTable}
                WHERE id = %d",
                (int) $supporter->id
            ));

            $transactionStats = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN status = %s THEN payment_total ELSE 0 END), 0) as total_paid,
                    COALESCE(SUM(CASE WHEN status <> %s OR status IS NULL THEN payment_total ELSE 0 END), 0) as total_pending
                FROM {$transactionsTable}
                WHERE entry_id = %d",
                'paid',
                'paid',
                (int) $supporter->id
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $totalAmountPaid = (int) ($transactionStats->total_paid ?? 0);
        $totalAmountPending = (int) ($transactionStats->total_pending ?? 0);
        $totalCoffee = (float) ($donationStats->total_coffee ?? 0);
        $supporter->other_donations_total = (int) ($donationStats->donation_count ?? count($otherDonations));

        $currencySymbol = html_entity_decode(PaymentHelper::currencySymbol($supporter->currency), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $supporter->all_time_total_paid    = $currencySymbol . ' ' . ($totalAmountPaid / 100);
        $supporter->all_time_total_pending = $currencySymbol . ' ' . ($totalAmountPending / 100);
        $supporter->all_time_total_coffee  = $totalCoffee;

        // Subscription data if this is a recurring supporter
        $subscriptionId = null;
        foreach ($transactions as $tx) {
            if (!empty($tx->subscription_id)) {
                $subscriptionId = (int) $tx->subscription_id;
                break;
            }
        }

        if ($subscriptionId) {
            $supporter->subscription = buyMeCoffeeQuery()
                ->table('buymecoffee_subscriptions')
                ->where('id', $subscriptionId)
                ->first();
        }

        return $supporter;
    }

    public function getByHash($hash)
    {
        $supporter = $this->getQuery()
            ->where('entry_hash', $hash)
            ->first();

        if ($supporter) {
            $transaction = (new Transactions())->getQuery()
                ->where('entry_id', $supporter->id)
                ->where('entry_hash', $hash)
                ->first();
            $supporter->transaction = $transaction;

            if ($transaction && ($transaction->transaction_type ?? '') === 'recurring' && !empty($transaction->subscription_id)) {
                $supporter->subscription = buyMeCoffeeQuery()
                    ->table('buymecoffee_subscriptions')
                    ->where('id', (int) $transaction->subscription_id)
                    ->first();
            }
        }
        return $supporter;
    }

    public function getWeeklyRevenue($dateFrom = '')
    {
        $query = $this->getQuery()->select(
            'currency',
            'payment_status',
            $this->raw('Date(created_at) as date'),
            $this->raw("SUM(round(payment_total / 100, 2)) as total_paid"),
            $this->raw("COUNT(*) as submissions")
        )->whereIn('payment_status', ['paid'])
            ->where('payment_total', '>', 0);

        if (!empty($dateFrom)) {
            $query->where('created_at', '>=', $dateFrom);
        }

        $revenue = $query->groupBy([$this->raw('Date(created_at)'), 'currency'])
            ->orderBy('id', 'desc')
            ->limit(50)
            ->getArray();

        $group = array();
        foreach ( $revenue as $value ) {
            $group[$value['currency']][] = $value;
        }

        $groupSelect = array();
        $chartData = array();
        $valueLength = 0;
        $topPaidCurrency = '';
        foreach ($group as $key => $value) {
            if ($valueLength < count($value)) {
                $valueLength = count($value);
                $topPaidCurrency = $key;
            }

            $groupSelect[] = array(
                'label' => $key,
                'value' => $key,
            );
            foreach ($value as $val) {
                $chartData[$key]['label'][] = $val['date'];
                $chartData[$key]['data'][] = floatval($val['total_paid']);
            }
        }

        wp_send_json_success([
            'data' => $group,
            'options' => $groupSelect,
            'chartData' => $chartData,
            'topPaidCurrency' => $topPaidCurrency,
        ], 200);
    }

    public function findByWpUser(int $wpUserId)
    {
        return $this->getQuery()
            ->where('wp_user_id', $wpUserId)
            ->first();
    }

    public function findAllByWpUser(int $wpUserId)
    {
        return $this->getQuery()
            ->where('wp_user_id', $wpUserId)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    public function delete($id)
    {
        return $this->getQuery()->where('id', $id)->delete();
    }

    public function getLatest($limit = 5)
    {
        return $this->getQuery()
            ->where('payment_status', 'paid')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Get unique supporters grouped by email with aggregated stats.
     */
    public function getUniqueSupporters($args)
    {
        global $wpdb;
        $supTable = $wpdb->prefix . 'buymecoffee_supporters';
        $txTable  = $wpdb->prefix . 'buymecoffee_transactions';
        $subTable = $wpdb->prefix . 'buymecoffee_subscriptions';

        $page         = isset($args['page']) ? max(0, intval($args['page'])) : 0;
        $postsPerPage = isset($args['posts_per_page']) ? max(1, min(100, intval($args['posts_per_page']))) : 12;
        $offset       = $page * $postsPerPage;
        $search       = isset($args['search']) ? sanitize_text_field($args['search']) : '';

        $filter = isset($args['filter']) ? sanitize_text_field($args['filter']) : 'all';
        if (!in_array($filter, ['all', 'subscribers', 'one-time'], true)) {
            $filter = 'all';
        }

        $conditions = [];
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $conditions[] = $wpdb->prepare("(s.supporters_name LIKE %s OR s.supporters_email LIKE %s)", $like, $like);
        }

        // Filter: subscribers vs one-time
        if ($filter === 'subscribers') {
            $conditions[] = "s.id IN (SELECT supporter_id FROM {$subTable} WHERE status = 'active')";
        } elseif ($filter === 'one-time') {
            $conditions[] = "s.id NOT IN (SELECT supporter_id FROM {$subTable})";
        }

        $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Use a single raw SQL query with LEFT JOINs to get aggregated data per unique email.
        // Anonymous donors (empty email) are grouped individually via COALESCE.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb->prefix, dynamic WHERE is pre-prepared
        $groupCol = "COALESCE(NULLIF(s.supporters_email, ''), CONCAT('anon_', s.id))";

        // $whereClause is either empty or built entirely from $wpdb->prepare() calls above.
        $countSql = "SELECT COUNT(*) FROM (
            SELECT {$groupCol} as grp FROM {$supTable} s {$whereClause} GROUP BY grp
        ) as cnt";
        $total = (int) $wpdb->get_var($countSql);

        $sql = "SELECT
                MAX(s.id) as latest_entry_id,
                MAX(s.supporters_name) as supporters_name,
                s.supporters_email,
                MAX(s.currency) as currency,
                MAX(s.created_at) as last_donation_date,
                COUNT(DISTINCT s.id) as entry_count,
                COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.payment_total ELSE 0 END), 0) as total_paid,
                COUNT(DISTINCT CASE WHEN t.status = 'paid' THEN t.id END) as donation_count,
                MAX(CASE WHEN sub.status = 'active' THEN 1 ELSE 0 END) as has_subscription
            FROM {$supTable} s
            LEFT JOIN {$txTable} t ON t.entry_id = s.id
            LEFT JOIN {$subTable} sub ON sub.supporter_id = s.id
            {$whereClause}
            GROUP BY {$groupCol}
            ORDER BY MAX(s.created_at) DESC
            LIMIT %d OFFSET %d";

        $results = $wpdb->get_results($wpdb->prepare($sql, $postsPerPage, $offset));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        foreach ($results as $supporter) {
            $supporter->total_paid      = (int) $supporter->total_paid;
            $supporter->donation_count  = (int) $supporter->donation_count;
            $supporter->has_subscription = (bool) $supporter->has_subscription;
            $supporter->total_formatted = PaymentHelper::getFormattedAmount(
                $supporter->total_paid,
                $supporter->currency ?: 'USD'
            );
            $supporter->avatar = $supporter->supporters_email
                ? get_avatar_url($supporter->supporters_email)
                : '';
        }

        wp_send_json_success([
            'supporters' => $results,
            'total'      => $total,
        ], 200);
    }

    /**
     * Aggregate stats for the Supporters page metric cards.
     */
    public function getSupporterStats()
    {
        global $wpdb;
        $supTable = $wpdb->prefix . 'buymecoffee_supporters';
        $txTable  = $wpdb->prefix . 'buymecoffee_transactions';
        $subTable = $wpdb->prefix . 'buymecoffee_subscriptions';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats = $wpdb->get_row("SELECT
            COUNT(DISTINCT COALESCE(NULLIF(s.supporters_email, ''), CONCAT('anon_', s.id))) as total_supporters,
            COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.payment_total ELSE 0 END), 0) as lifetime_revenue,
            (SELECT COUNT(*) FROM {$subTable} WHERE status = 'active') as active_subscribers,
            MAX(s.currency) as currency
            FROM {$supTable} s
            LEFT JOIN {$txTable} t ON t.entry_id = s.id
        ");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $totalSupporters = (int) ($stats->total_supporters ?? 0);
        $lifetimeRevenue = (int) ($stats->lifetime_revenue ?? 0);
        $avgDonation     = $totalSupporters > 0 ? (int) round($lifetimeRevenue / $totalSupporters) : 0;
        $currency        = $stats->currency ?: 'USD';

        return [
            'total_supporters'   => $totalSupporters,
            'lifetime_revenue'   => PaymentHelper::getFormattedAmount($lifetimeRevenue, $currency),
            'active_subscribers' => (int) ($stats->active_subscribers ?? 0),
            'avg_donation'       => PaymentHelper::getFormattedAmount($avgDonation, $currency),
        ];
    }

    /**
     * Get top supporters ranked by lifetime paid amount.
     */
    public function getTopSupportersList($limit = 10)
    {
        global $wpdb;
        $supTable = $wpdb->prefix . 'buymecoffee_supporters';
        $txTable  = $wpdb->prefix . 'buymecoffee_transactions';
        $subTable = $wpdb->prefix . 'buymecoffee_subscriptions';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb->prefix
        $sql = "SELECT
                MAX(s.id) as latest_entry_id,
                MAX(s.supporters_name) as supporters_name,
                s.supporters_email,
                MAX(s.currency) as currency,
                COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.payment_total ELSE 0 END), 0) as total_paid,
                COUNT(DISTINCT CASE WHEN t.status = 'paid' THEN t.id END) as donation_count,
                MAX(CASE WHEN sub.status = 'active' THEN 1 ELSE 0 END) as has_subscription,
                MAX(s.created_at) as last_donation_date
            FROM {$supTable} s
            LEFT JOIN {$txTable} t ON t.entry_id = s.id
            LEFT JOIN {$subTable} sub ON sub.supporter_id = s.id
            WHERE s.supporters_email IS NOT NULL AND s.supporters_email != ''
            GROUP BY s.supporters_email
            HAVING total_paid > 0
            ORDER BY total_paid DESC
            LIMIT %d";

        $results = $wpdb->get_results($wpdb->prepare($sql, $limit));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        foreach ($results as $supporter) {
            $supporter->total_paid       = (int) $supporter->total_paid;
            $supporter->donation_count   = (int) $supporter->donation_count;
            $supporter->has_subscription = (bool) $supporter->has_subscription;
            $supporter->total_formatted  = PaymentHelper::getFormattedAmount($supporter->total_paid, $supporter->currency ?: 'USD');
            $supporter->avatar           = get_avatar_url($supporter->supporters_email);
        }

        return $results;
    }

    /**
     * Get top supporters for the public wall shortcode, grouped by email and
     * ranked by lifetime paid amount. Respects display settings.
     */
    public function getPublicSupporters($settings = [])
    {
        global $wpdb;
        $supTable = $wpdb->prefix . 'buymecoffee_supporters';
        $txTable  = $wpdb->prefix . 'buymecoffee_transactions';

        $limit       = isset($settings['max_supporters']) ? (int) $settings['max_supporters'] : 20;
        $showName    = ($settings['show_name'] ?? 'yes') === 'yes';
        $showAmount  = ($settings['show_amount'] ?? 'no') === 'yes';
        $showMessage = ($settings['show_message'] ?? 'yes') === 'yes';
        $showAvatar  = ($settings['show_avatar'] ?? 'yes') === 'yes';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb->prefix
        $sql = "SELECT
                MAX(s.supporters_name) as supporters_name,
                s.supporters_email,
                MAX(s.currency) as currency,
                COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.payment_total ELSE 0 END), 0) as total_paid,
                COUNT(DISTINCT CASE WHEN t.status = 'paid' THEN t.id END) as donation_count
            FROM {$supTable} s
            LEFT JOIN {$txTable} t ON t.entry_id = s.id
            WHERE s.supporters_email IS NOT NULL AND s.supporters_email != ''
            GROUP BY s.supporters_email
            HAVING total_paid > 0
            ORDER BY total_paid DESC
            LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $result = [];
        $rank   = 0;
        foreach ($rows as $row) {
            $rank++;
            $item = new \stdClass();
            $item->rank           = $rank;
            $item->name           = $showName ? ($row->supporters_name ?: __('Anonymous', 'buy-me-coffee')) : __('Anonymous', 'buy-me-coffee');
            $item->avatar         = ($showAvatar && !empty($row->supporters_email)) ? get_avatar_url($row->supporters_email, ['size' => 80]) : '';
            $item->amount         = $showAmount ? PaymentHelper::getFormattedAmount((int) $row->total_paid, $row->currency ?: 'USD') : '';
            $item->donation_count = (int) $row->donation_count;
            $result[] = $item;
        }

        return $result;
    }
}
