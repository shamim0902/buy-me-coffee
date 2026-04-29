<?php

namespace BuyMeCoffee\Models;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Transactions extends Model
{
    protected $table = "buymecoffee_transactions";
    public function updateData($id, $data)
    {
        return $this->getQuery()->where('id', $id)->update($data);
    }

    public function find($id, $column='id')
    {
        return $this->getQuery()->where($column, $id)->first();
    }

    public function delete($id, $column = 'id')
    {
        return $this->getQuery()->where($column, $id)->delete();
    }

    public function getByPaymentId($chargeId, $method = 'paypal')
    {
        $payment = $this->getQuery()
            ->where('charge_id', $chargeId)
            ->where('payment_method', $method)
            ->first();

        if ($payment) {
            return $payment->id;
        }
        return false;
    }
}
