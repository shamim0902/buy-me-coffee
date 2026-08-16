<?php

namespace BuyMeCoffee\Services;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * The one-time entry point to the canonical payment transition.
 *
 * Every rule this used to own — the locked row, the single database
 * transaction, the refund that is final, the replay that changes nothing, the
 * hook that fires exactly once after the commit — now lives in
 * PaymentTransitionService, so a subscription invoice and a one-time charge
 * cannot drift into two different notions of what settling a payment means.
 *
 * What remains here is the one thing that is specific to a one-time payment:
 * a transaction that belongs to a subscription is refused rather than treated
 * as a donation, because it keeps its own lifecycle. Callers depend on that
 * refusal, so it stays an explicit, separately named entry point.
 */
class OneTimePaymentStatusService
{
    /**
     * Statuses a provider webhook may report for a one-time transaction.
     */
    const ALLOWED_STATUSES = PaymentTransitionService::ALLOWED_STATUSES;

    /**
     * Statuses no provider webhook may move a transaction out of.
     */
    const TERMINAL_STATUSES = PaymentTransitionService::TERMINAL_STATUSES;

    /**
     * Apply a status to a one-time transaction.
     *
     * @param object $transaction Transaction row.
     * @param string $status      Local payment status to apply.
     * @return array|\WP_Error See PaymentTransitionService::apply().
     */
    public function apply($transaction, $status)
    {
        return (new PaymentTransitionService())->apply($transaction, $status, [
            'scope' => PaymentTransitionService::SCOPE_ONE_TIME,
        ]);
    }
}
