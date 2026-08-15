<?php

namespace BuyMeCoffee\Services;

use BuyMeCoffee\Models\MembershipAccess;
use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Models\Supporters;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * The one place a payment status is written and the canonical payment
 * transition is dispatched.
 *
 * Every provider outcome — a Stripe webhook, a Stripe browser confirmation, a
 * Stripe subscription invoice, a verified PayPal IPN, a PayPal capture — ends up
 * here, because each of those used to write `paid` itself and then either fire
 * `buymecoffee_payment_status_updated` unconditionally or not fire it at all.
 * The first duplicates every side effect attached to that hook (cache
 * invalidation, donor and admin email, payment activity, entitlement
 * synchronisation); the second silently skips all of them.
 *
 * The rules this service enforces for a transition are therefore:
 *
 *  - the decision is made against a row read with SELECT ... FOR UPDATE and
 *    written inside one database transaction, so two authentic events in flight
 *    at the same moment cannot both decide on stale state, and a failed
 *    supporter write leaves nothing behind;
 *  - a status identical to the one already stored is a no-op — a replay must not
 *    re-run work that already ran;
 *  - a refund is final: no later, or replayed, success event may restore revenue
 *    or entitlements the customer was refunded for;
 *  - the hook, and the entitlement work that goes with it, run only once the
 *    write is committed, and only when the status actually moved;
 *  - the caller is told, explicitly, whether a transition occurred.
 *
 * Subscription state is deliberately not part of a status transition: it is
 * owned by activateSubscription() below, which is equally idempotent, so a
 * subscription cannot be activated twice by two paid events for the same period.
 */
class PaymentTransitionService
{
    /**
     * Statuses a provider event may report for a transaction.
     */
    const ALLOWED_STATUSES = ['pending', 'processing', 'paid', 'failed', 'refunded'];

    /**
     * Statuses no provider event may move a transaction out of. A refund is the
     * end of a payment's life: a later — or replayed — success event must never
     * restore revenue or entitlements the customer was refunded for.
     */
    const TERMINAL_STATUSES = ['refunded'];

    /**
     * Any transaction may be transitioned.
     */
    const SCOPE_ANY = 'any';

    /**
     * Only a transaction that is not linked to a subscription may be
     * transitioned. Used by the one-time entry point, which callers rely on to
     * refuse a subscription-linked row rather than treat it as a donation.
     */
    const SCOPE_ONE_TIME = 'one_time';

    /**
     * Claim family used to make "record this invoice once" atomic.
     */
    const INVOICE_CLAIM = 'stripe_invoice';

    /**
     * Apply a status to a transaction.
     *
     * The transaction argument only identifies the row; every value the decision
     * rests on is re-read from the locked row inside this call, because the
     * caller's copy may have been fetched before a concurrent refund landed.
     *
     * @param object $transaction Transaction row (only `id` is trusted).
     * @param string $status      Local payment status to apply.
     * @param array  $options     {
     *     @type string $scope One of SCOPE_ANY (default) or SCOPE_ONE_TIME.
     * }
     * @return array|\WP_Error {
     *     @type int    $transaction_id
     *     @type string $from                 Status before this call.
     *     @type string $to                   Status now stored.
     *     @type bool   $changed              False when the call was a no-op.
     *     @type string $supporter_status     Aggregate status written to the supporter.
     *     @type int    $membership_access_id Access row activated by this call, 0 otherwise.
     *     @type int    $subscription_id      Subscription this transaction belongs to, 0 for none.
     * }
     */
    public function apply($transaction, $status, array $options = [])
    {
        global $wpdb;

        if (!is_object($transaction) || empty($transaction->id)) {
            return new \WP_Error(
                'bmc_payment_transaction_missing',
                __('No transaction was given to update.', 'buy-me-coffee')
            );
        }

        $transactionId = (int) $transaction->id;
        $status        = sanitize_key($status);
        $scope         = isset($options['scope']) ? (string) $options['scope'] : self::SCOPE_ANY;

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return new \WP_Error(
                'bmc_payment_status_unsupported',
                sprintf(
                    /* translators: %s: payment status. */
                    __('"%s" is not a payment status this plugin stores.', 'buy-me-coffee'),
                    $status
                ),
                ['transaction_id' => $transactionId]
            );
        }

        $ownsTransaction = $this->managesTransaction($transactionId);

        if ($ownsTransaction && $wpdb->query('START TRANSACTION') === false) {
            return $this->writeFailed(
                __('The database refused to start a transaction; nothing was changed.', 'buy-me-coffee'),
                ['transaction_id' => $transactionId, 'stage' => 'begin']
            );
        }

        $locked = $this->lockTransaction($transactionId);

        if (!$locked) {
            $this->endTransaction($ownsTransaction);

            return new \WP_Error(
                'bmc_payment_transaction_missing',
                __('No transaction was given to update.', 'buy-me-coffee'),
                ['transaction_id' => $transactionId]
            );
        }

        $subscriptionId = !empty($locked->subscription_id) ? (int) $locked->subscription_id : 0;

        if ($scope === self::SCOPE_ONE_TIME && $subscriptionId) {
            $this->endTransaction($ownsTransaction);

            return new \WP_Error(
                'bmc_payment_not_one_time',
                __('This transaction belongs to a subscription and keeps its own lifecycle.', 'buy-me-coffee'),
                ['transaction_id' => $transactionId]
            );
        }

        $current = isset($locked->status) ? sanitize_key((string) $locked->status) : '';

        // Same status again — a replayed or duplicated event. Writing it would
        // re-fire the payment hook and with it email, activity, cache
        // invalidation and entitlement work that already ran.
        if ($current === $status) {
            $this->endTransaction($ownsTransaction);

            return $this->result($transactionId, $current, $status, false, '', 0, $subscriptionId);
        }

        if (in_array($current, self::TERMINAL_STATUSES, true)) {
            $this->endTransaction($ownsTransaction);

            return new \WP_Error(
                'bmc_payment_status_terminal',
                sprintf(
                    /* translators: 1: stored status, 2: reported status. */
                    __('A %1$s payment is final and cannot become %2$s again.', 'buy-me-coffee'),
                    $current,
                    $status
                ),
                [
                    'transaction_id' => $transactionId,
                    'from'           => $current,
                    'to'             => $status,
                ]
            );
        }

        $now = current_time('mysql');

        // Written against the status just read, so even without the row lock the
        // update cannot overwrite a status some other request wrote meanwhile.
        $where = ['id' => $transactionId];
        if ($locked->status !== null) {
            $where['status'] = $locked->status;
        }

        $written = $this->write(
            'buymecoffee_transactions',
            ['status' => $status, 'updated_at' => $now],
            $where
        );

        if ($written === false || (int) $written < 1) {
            $this->endTransaction($ownsTransaction);

            return $this->writeFailed(
                __('The transaction status could not be written; nothing was changed.', 'buy-me-coffee'),
                ['transaction_id' => $transactionId, 'stage' => 'transaction']
            );
        }

        $supporterId     = absint($locked->entry_id);
        $supporterStatus = '';

        if ($supporterId) {
            // The supporter reflects every transaction they have, not just this
            // one, so a refund here cannot erase another payment they made, and a
            // renewal cannot demote a donor who is already paid. The status just
            // written is part of that sum: it is read back inside this
            // transaction.
            $supporterStatus = Supporters::aggregatePaymentStatus($supporterId);

            $written = $this->write(
                'buymecoffee_supporters',
                ['payment_status' => $supporterStatus, 'updated_at' => $now],
                ['id' => $supporterId]
            );

            if ($written === false) {
                // The transaction write goes back with it: a payment must never
                // be left settled on one row and not the other.
                $this->endTransaction($ownsTransaction);

                return $this->writeFailed(
                    __('The supporter record could not be written, so the payment status was rolled back.', 'buy-me-coffee'),
                    [
                        'transaction_id' => $transactionId,
                        'supporter_id'   => $supporterId,
                        'stage'          => 'supporter',
                    ]
                );
            }
        }

        if ($ownsTransaction && $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');

            return $this->writeFailed(
                __('The payment status could not be committed; nothing was changed.', 'buy-me-coffee'),
                ['transaction_id' => $transactionId, 'stage' => 'commit']
            );
        }

        // Past this point the transition is durable, so the work that must
        // happen exactly once — and must never happen for a write that was
        // rolled back — runs here and nowhere earlier.
        //
        // Only a real first move into paid grants access. Repeats never reach
        // here, and a refund revokes through the canonical hook below.
        $membershipAccessId = 0;
        if ($status === 'paid' && !$subscriptionId) {
            // The row lock was released at COMMIT above, so a concurrent refund
            // may be transitioning this transaction — and revoking its access —
            // right now. activateByTransaction() therefore grants in one
            // statement conditional on the transaction still being stored as
            // paid, so nothing here can revive access a refund just took away.
            $membershipAccessId = (int) (new MembershipAccess())->activateByTransaction($transactionId);
        }

        do_action('buymecoffee_payment_status_updated', $transactionId, $status);

        return $this->result($transactionId, $current, $status, true, $supporterStatus, $membershipAccessId, $subscriptionId);
    }

    /**
     * Bring a subscription into its active state exactly once.
     *
     * A first payment is announced more than once by design — the browser
     * confirmation and the `subscription_create` invoice describe the same
     * event, and a renewal invoice can be redelivered — so activation is a
     * compare-and-set: only a subscription that is not already active is moved,
     * and only the call that moved it announces the activation. A renewal is the
     * case where nothing about the status changes but the billing period does,
     * so the period — and the access row projected from it — is refreshed
     * whenever it actually moves, and left alone when it did not.
     *
     * @param int         $subscriptionId Local subscription row ID.
     * @param string|null $periodEnd      Billing period end (MySQL datetime), when known.
     * @return array {
     *     @type int    $subscription_id
     *     @type bool   $activated Whether this call moved the subscription to active.
     *     @type string $from      Status before this call.
     * }
     */
    public function activateSubscription($subscriptionId, $periodEnd = null)
    {
        global $wpdb;

        $subscriptionId = absint($subscriptionId);
        if (!$subscriptionId) {
            return ['subscription_id' => 0, 'activated' => false, 'from' => ''];
        }

        $subscription = (new Subscriptions())->getQuery()->where('id', $subscriptionId)->first();
        if (!$subscription) {
            return ['subscription_id' => $subscriptionId, 'activated' => false, 'from' => ''];
        }

        $from      = sanitize_key((string) $subscription->status);
        $now       = current_time('mysql');
        $periodEnd = $periodEnd ? sanitize_text_field($periodEnd) : '';

        // A period end identical to the stored one is a repeat announcement of a
        // period the site already knows about, so it is not written and the
        // access row it projects is not touched.
        $periodMoved = $periodEnd !== '' && (string) $subscription->current_period_end !== $periodEnd;

        if ($periodMoved) {
            (new Subscriptions())->updateData($subscriptionId, [
                'current_period_end' => $periodEnd,
                'updated_at'         => $now,
            ]);
        }

        // One statement, so of two paid events for the same period exactly one
        // is told it activated the subscription.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared below; atomic compare-and-set on a plugin table.
        $activated = $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb->prefix.
            "UPDATE {$wpdb->prefix}buymecoffee_subscriptions
                SET status = 'active', updated_at = %s
              WHERE id = %d AND status <> 'active'",
            $now,
            $subscriptionId
        ));

        // Initial entitlement activation is owned by the canonical
        // subscription_activated hook below. Updating it here as well would run
        // the same upsert twice. A renewal has no activation hook, so only an
        // already-active subscription whose period moved refreshes access here.
        if (!empty($subscription->level_id) && !$activated && $periodMoved) {
            (new MembershipAccess())->upsertFromSubscription($subscriptionId);
        }

        if ($activated) {
            do_action('buymecoffee_subscription_activated', $subscriptionId);
        }

        return [
            'subscription_id' => $subscriptionId,
            'activated'       => (bool) $activated,
            'from'            => $from,
        ];
    }

    /**
     * Record a provider-created subscription payment once, and transition it.
     *
     * A renewal is the one payment the site learns about only from a webhook, so
     * the row has to be created here — and created at most once, however often
     * the invoice is announced. The existing-row lookup alone cannot promise
     * that: it is a read followed by an insert, and two deliveries arriving
     * together both pass it. The identity of the invoice is therefore claimed
     * atomically first, and the claim is only marked consumed once the row and
     * its transition are durable, so a delivery that fails part-way stays
     * redeliverable.
     *
     * The row is inserted pending and moved to paid through apply(), rather than
     * inserted paid: that way the canonical transition — and every side effect
     * hanging off it — is guaranteed for the row, and a delivery interrupted
     * between the two is completed by the next one instead of leaving a paid
     * renewal nobody was ever told about.
     *
     * @param array $columns  Column values for the new transaction row.
     * @param array $identity {
     *     @type int    $subscription_id Local subscription the invoice belongs to.
     *     @type string $invoice_id      Provider invoice id, the replay identity.
     * }
     * @return array|\WP_Error apply()'s result plus {@type bool $created}.
     */
    public function recordRenewal(array $columns, array $identity)
    {
        $subscriptionId = isset($identity['subscription_id']) ? absint($identity['subscription_id']) : 0;
        $invoiceId      = isset($identity['invoice_id']) ? sanitize_text_field($identity['invoice_id']) : '';

        if (!$subscriptionId) {
            return new \WP_Error(
                'bmc_payment_subscription_missing',
                __('A renewal must belong to a local subscription.', 'buy-me-coffee')
            );
        }

        $existing = $this->findInvoiceTransaction($subscriptionId, $invoiceId);
        if ($existing) {
            return $this->settleRecordedRenewal($existing);
        }

        $claim = null;

        if ($invoiceId) {
            $claim = PublicRequestGuard::claim(self::INVOICE_CLAIM, 'invoice|' . $invoiceId);

            if (!$claim['acquired']) {
                return $this->refuseInvoiceClaim($claim, $subscriptionId, $invoiceId);
            }

            // Re-read under the claim: a delivery that queued behind another one
            // must decide on the row that one left behind.
            $existing = $this->findInvoiceTransaction($subscriptionId, $invoiceId);
            if ($existing) {
                $settled = $this->settleRecordedRenewal($existing);

                if (!is_wp_error($settled)) {
                    $this->settleInvoiceClaim($claim);
                } else {
                    $this->releaseInvoiceClaim($claim);
                }

                return $settled;
            }
        }

        $columns['status'] = 'pending';

        $transactionId = (int) buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert($columns);

        if (!$transactionId) {
            $this->releaseInvoiceClaim($claim);

            return $this->writeFailed(
                __('The renewal payment could not be recorded; nothing was changed.', 'buy-me-coffee'),
                ['subscription_id' => $subscriptionId, 'stage' => 'renewal_insert']
            );
        }

        $result = $this->apply((object) ['id' => $transactionId], 'paid');

        if (is_wp_error($result)) {
            // The row exists but was never settled. The claim goes back so the
            // provider's redelivery finishes it, and finds the row waiting.
            $this->releaseInvoiceClaim($claim);

            return $result;
        }

        $this->settleInvoiceClaim($claim);

        return array_merge($result, ['created' => true]);
    }

    /**
     * The transaction already recorded for a provider invoice, if any.
     *
     * @param int    $subscriptionId Local subscription row ID.
     * @param string $invoiceId      Provider invoice id.
     * @return object|null
     */
    public function findInvoiceTransaction($subscriptionId, $invoiceId)
    {
        $subscriptionId = absint($subscriptionId);
        $invoiceId      = sanitize_text_field($invoiceId);

        if (!$subscriptionId || $invoiceId === '') {
            return null;
        }

        return buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('subscription_id', $subscriptionId)
            ->where('payment_method', 'stripe')
            ->where('payment_note', 'like', '%"invoice_id":"' . addcslashes($invoiceId, '%_') . '"%')
            ->first();
    }

    /**
     * Bring an already recorded renewal to paid.
     *
     * A row that exists but is not paid is what an interrupted delivery leaves
     * behind, so the redelivery finishes it rather than inserting a second one.
     *
     * @param object $transaction Recorded transaction row.
     * @return array|\WP_Error
     */
    private function settleRecordedRenewal($transaction)
    {
        $result = $this->apply($transaction, 'paid');

        if (is_wp_error($result)) {
            return $result;
        }

        return array_merge($result, ['created' => false]);
    }

    /**
     * Turn a refused invoice claim into a decision the caller can act on.
     *
     * A consumed claim means the invoice was already recorded, which is a
     * duplicate and not a failure. One still in flight is a conflict: the
     * delivery must be retried rather than dropped, because the worker holding
     * it may still fail.
     *
     * @param array  $claim          Refused claim record.
     * @param int    $subscriptionId Local subscription row ID.
     * @param string $invoiceId      Provider invoice id.
     * @return array|\WP_Error
     */
    private function refuseInvoiceClaim(array $claim, $subscriptionId, $invoiceId)
    {
        if (!$claim['available']) {
            // Without the guard table there is no atomic identity for the
            // invoice, so recording it could duplicate the payment. Fail closed
            // and let the provider redeliver.
            return new \WP_Error(
                'bmc_payment_guard_unavailable',
                __('The renewal could not be recorded safely right now. Please retry.', 'buy-me-coffee'),
                ['subscription_id' => $subscriptionId, 'retryable' => true]
            );
        }

        if ($claim['state'] === PublicRequestGuard::STATE_COMPLETED) {
            $existing = $this->findInvoiceTransaction($subscriptionId, $invoiceId);

            if ($existing) {
                return $this->settleRecordedRenewal($existing);
            }

            return [
                'transaction_id'       => 0,
                'from'                 => '',
                'to'                   => '',
                'changed'              => false,
                'supporter_status'     => '',
                'membership_access_id' => 0,
                'subscription_id'      => $subscriptionId,
                'created'              => false,
            ];
        }

        return new \WP_Error(
            'bmc_payment_invoice_in_progress',
            __('This renewal is already being recorded. Please retry.', 'buy-me-coffee'),
            [
                'subscription_id' => $subscriptionId,
                'retryable'       => true,
                'retry_after'     => (int) $claim['retry_after'],
            ]
        );
    }

    /**
     * Mark an invoice consumed, but only once its transition is durable.
     *
     * @param array|null $claim Acquired claim, or null when there was no invoice identity.
     * @return void
     */
    private function settleInvoiceClaim($claim)
    {
        if (!$claim) {
            return;
        }

        PublicRequestGuard::completeClaim($claim['key'], $claim['owner'], [], self::INVOICE_CLAIM);
    }

    /**
     * Give an invoice identity back so the provider's retry may use it.
     *
     * @param array|null $claim Acquired claim, or null when there was none.
     * @return void
     */
    private function releaseInvoiceClaim($claim)
    {
        if (!$claim) {
            return;
        }

        PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);
    }

    /**
     * Read the transaction again, holding it against any concurrent transition
     * for as long as this database transaction is open.
     *
     * @param int $transactionId Transaction row ID.
     * @return object|null
     */
    private function lockTransaction($transactionId)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name comes from $wpdb->prefix, and the row must be read locked and uncached.
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, entry_id, subscription_id, status FROM {$wpdb->prefix}buymecoffee_transactions WHERE id = %d FOR UPDATE",
            $transactionId
        ));
    }

    /**
     * Whether this service opens and owns its own database transaction.
     *
     * MySQL has no nested transactions: a caller already inside one — the
     * feature test runner, a bulk routine — would have it implicitly committed
     * by START TRANSACTION. Such callers return false and keep managing the
     * transaction themselves; the row is still locked for the transition.
     *
     * @param int $transactionId Transaction row ID.
     * @return bool
     */
    private function managesTransaction($transactionId)
    {
        return (bool) apply_filters('buymecoffee_payment_status_manages_transaction', true, $transactionId);
    }

    /**
     * Discard the open transaction, releasing the row lock. Used for every exit
     * that must leave nothing written.
     *
     * @param bool $ownsTransaction Whether this service opened the transaction.
     * @return void
     */
    private function endTransaction($ownsTransaction)
    {
        global $wpdb;

        if ($ownsTransaction) {
            $wpdb->query('ROLLBACK');
        }
    }

    /**
     * A failure the caller may retry: the provider should send the event again.
     *
     * @param string $message Reason.
     * @param array  $data    Error data.
     * @return \WP_Error
     */
    private function writeFailed($message, array $data)
    {
        global $wpdb;

        if (!empty($wpdb->last_error)) {
            $data['db_error'] = $wpdb->last_error;
        }

        $data['retryable'] = true;

        return new \WP_Error('bmc_payment_write_failed', $message, $data);
    }

    /**
     * @param string $table  Table name without prefix.
     * @param array  $data   Columns to write.
     * @param array  $where  Row identity.
     * @return int|false Affected rows, or false when the database rejected it.
     */
    private function write($table, array $data, array $where)
    {
        global $wpdb;

        return $wpdb->update($wpdb->prefix . $table, $data, $where);
    }

    /**
     * @param int    $transactionId      Transaction row ID.
     * @param string $from               Status before the call.
     * @param string $to                 Status after the call.
     * @param bool   $changed            Whether anything was written.
     * @param string $supporterStatus    Aggregate status written to the supporter.
     * @param int    $membershipAccessId Access row activated by this call.
     * @param int    $subscriptionId     Subscription this transaction belongs to.
     * @return array
     */
    private function result($transactionId, $from, $to, $changed, $supporterStatus = '', $membershipAccessId = 0, $subscriptionId = 0)
    {
        return [
            'transaction_id'       => (int) $transactionId,
            'from'                 => (string) $from,
            'to'                   => (string) $to,
            'changed'              => (bool) $changed,
            'supporter_status'     => (string) $supporterStatus,
            'membership_access_id' => (int) $membershipAccessId,
            'subscription_id'      => (int) $subscriptionId,
        ];
    }
}
