<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit;

class ActivityLogHooks
{
    public function register(): void
    {
        // Priority 20 — fires AFTER EmailNotifications (registered at default priority 10)
        add_action('buymecoffee_payment_status_updated', [$this, 'onPaymentStatusUpdated'], 20, 2);

        // Fires from SubmissionHandler after supporter row is inserted
        add_action('buymecoffee_after_supporters_data_insert', [$this, 'onFormSubmitted'], 10, 1);
    }

    /**
     * Triggered by: buymecoffee_payment_status_updated($transactionId, $status)
     * Sources: Stripe IPN, PayPal IPN, PayPal confirmation, admin manual update, refund completion
     */
    public function onPaymentStatusUpdated(int $transactionId, string $status): void
    {
        $eventMap = [
            'paid'      => ['payment_completed', 'success', 'Payment completed'],
            'failed'    => ['payment_failed',    'failed',  'Payment failed'],
            'refunded'  => ['refund_completed',  'info',    'Refund completed'],
            'refunding' => ['refund_initiated',  'info',    'Refund in progress'],
            'pending'   => ['payment_pending',   'info',    'Payment pending'],
        ];

        if (!isset($eventMap[$status])) {
            return;
        }

        [$event, $logStatus, $title] = $eventMap[$status];

        if ($this->hasPaymentEvent($transactionId, $event)) {
            return;
        }

        $transaction = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('id', $transactionId)
            ->first();

        $context = ['transaction_id' => $transactionId, 'new_status' => $status];
        if ($transaction) {
            $context['charge_id']  = $transaction->charge_id ?? '';
            $context['amount']     = $transaction->payment_total;
            $context['currency']   = $transaction->currency;
            $context['method']     = $transaction->payment_method;
            $context['entry_id']   = (int) ($transaction->entry_id ?? 0);
        }

        ActivityLogger::logPayment($transactionId, $event, $title, [
            'status'  => $logStatus,
            'context' => $context,
        ]);
    }

    private function hasPaymentEvent(int $transactionId, string $event): bool
    {
        $existing = buyMeCoffeeQuery()
            ->table(ActivityLogger::TABLE)
            ->where('object_type', 'payment')
            ->where('object_id', $transactionId)
            ->where('event', $event)
            ->first();

        return (bool) $existing;
    }

    /**
     * Triggered by: buymecoffee_after_supporters_data_insert($entries)
     * Fires after the supporter row is written — looks it up by entry_hash to get its ID.
     */
    public function onFormSubmitted(array $entries): void
    {
        $hash = $entries['entry_hash'] ?? '';
        if (!$hash) {
            return;
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('entry_hash', sanitize_text_field($hash))
            ->first();

        if (!$supporter) {
            return;
        }

        ActivityLogger::logSubmission((int) $supporter->id, 'form_submitted', 'Donation form submitted', [
            'status'  => 'info',
            'context' => [
                'supporter_id' => $supporter->id,
                'amount'       => $entries['payment_total'] ?? 0,
                'currency'     => $entries['currency'] ?? '',
                'method'       => $entries['payment_method'] ?? '',
            ],
        ]);
    }
}
