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
        $offset = intval($args['page'] * $args['posts_per_page']);

        $query = $this->getQuery()
            ->offset($offset)
            ->limit($args['posts_per_page']);

        if (isset($args['filter_top'])) {
            $query->where('payment_status', 'paid')
                ->orWhere('payment_status', 'paid-initially')
                ->orderBy('payment_total', 'DESC');

            $currencyTotalPending = $this->getQuery()
                ->groupBy('currency')
                ->where('payment_status', 'pending')
                ->select($this->raw('SUM(payment_total) as total_amount, currency'))
                ->get();

            foreach ($currencyTotalPending as $currency) {
                $currency->formatted_total = PaymentHelper::getFormattedAmount($currency->total_amount, $currency->currency);
            }
        } else {
            $query->orderBy('id', 'DESC');
        }

        if (isset($args['filter_status']) && $args['filter_status'] !== 'all') {
            $query->where('payment_status', $args['filter_status']);
        }

        if (!empty($args['search'])) {
            $query->where('supporters_name', 'LIKE', '%' . $args['search'] . '%')
                ->orWhere('supporters_email', 'LIKE', '%' . $args['search'] . '%')
                ->Where('payment_status', $args['filter_status']);
        }

        $supporters = $query->get();

        foreach ($supporters as $supporter) {
            $supporter->amount_formatted = PaymentHelper::getFormattedAmount($supporter->payment_total, $supporter->currency);
        }

        $count = $this->getQuery()
            ->select($this->raw('SUM(coffee_count) as total_coffee'))
            ->first();

        $currencyTotal = $this->getQuery()
            ->groupBy('currency')
            ->where('payment_status', 'paid')
            ->orWhere('payment_status', 'paid-initially')
            ->select($this->raw('SUM(payment_total) as total_amount, currency'))
            ->get();

        foreach ($currencyTotal as $currency) {
            $currency->formatted_total = PaymentHelper::getFormattedAmount($currency->total_amount, $currency->currency);
        }
        $total = $query->count();
        $lastPage = ceil($total / $args['posts_per_page']);

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

        //get transaction
        $supporter->transaction = (new Transactions())->getQuery()->where('entry_id', $supporter->id)->first();

        if ($supporter->transaction) {
            $supporter->transaction->transaction_url = apply_filters('buymecoffee/payment/get_transaction_url_' . $supporter->transaction->payment_method, '',  $supporter->transaction);
        };

        if (!empty($supporter->supporters_email)) {
            $otherDonations = $this->getQuery()
                ->where('supporters_email', $supporter->supporters_email)
                ->get();
        } else {
            $otherDonations = $this->getQuery()
                ->where('buymecoffee_supporters.id', $id)->get();
        }

        $supporter->other_donations = $otherDonations;

        $totalAmountPaid = 0;
        $totalAmountPending = 0;
        $totalCoffee = 0;
        foreach ($otherDonations as $value) {
            if (!$value) {
                continue;
            }
            if ($value->payment_status === 'paid') {
                $totalAmountPaid += floatval($value->payment_total);
            }else {
                $totalAmountPending += floatval($value->payment_total);
            }
            $totalCoffee +=  floatval($value->coffee_count);
        }

        $supporter->all_time_total_paid = PaymentHelper::currencySymbol($supporter->currency) .' '. ($totalAmountPaid / 100);
        $supporter->all_time_total_pending = PaymentHelper::currencySymbol($supporter->currency) .' '. ($totalAmountPending / 100);
        $supporter->all_time_total_coffee = $totalCoffee;

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
        }
        return $supporter;
    }

    public function getWeeklyRevenue()
    {
        $revenue = $this->getQuery()->select(
            'currency',
            'payment_status',
            $this->raw('Date(created_at) as date'),
            $this->raw("SUM(round(payment_total / 100, 2)) as total_paid"),
            $this->raw("COUNT(*) as submissions")
        )->whereIn('payment_status', ['paid'])
            ->where('payment_total', '>', 0)
            ->groupBy([$this->raw('Date(created_at)'), 'currency'])
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

    public function delete($id)
    {
        return $this->getQuery()->where('id', $id)->delete();
    }

}
