<?php

namespace BuyMeCoffee\Services;

use BuyMeCoffee\Models\MembershipAccess;
use BuyMeCoffee\Models\Supporters;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Applies a payment status to a one-time transaction and its supporter.
 *
 * Provider webhooks arrive out of order and more than once, so this is the one
 * place that decides whether a reported status may be written at all: a refund
 * is final, a repeat of the status already stored changes nothing, and only a
 * genuine first move into `paid` may activate one-time membership access.
 * Access revocation is deliberately left to the canonical
 * `buymecoffee_payment_status_updated` hook, which already revokes exactly once,
 * so a refund cannot revoke twice through two different paths.
 *
 * Subscription-linked transactions are out of scope and rejected; they keep
 * their own lifecycle.
 *
 * Two authentic events can also be in flight at the same moment — a late
 * `charge.succeeded` and a `charge.refunded` are different event ids, so neither
 * is stopped by the replay lock. The decision is therefore made against a row
 * locked with SELECT ... FOR UPDATE and written inside one database
 * transaction, so the loser of that race re-reads the refund rather than the
 * status it was handed, and a failed supporter write leaves nothing behind.
 * Entitlements and hooks fire only once the write is committed.
 */
class OneTimePaymentStatusService
{
    /**
     * Statuses a provider webhook may report for a one-time transaction.
     */
    const ALLOWED_STATUSES = ['pending', 'processing', 'paid', 'failed', 'refunded'];

    /**
     * Statuses no provider webhook may move a transaction out of. A refund is
     * the end of a payment's life: a later — or replayed — success event must
     * never restore revenue or entitlements the customer was refunded for.
     */
    const TERMINAL_STATUSES = ['refunded'];

    /**
     * Apply a status to a one-time transaction.
     *
     * The transaction argument only identifies the row; every value the decision
     * rests on is re-read from the locked row inside this call, because the
     * caller's copy may have been fetched before a concurrent refund landed.
     *
     * @param object $transaction Transaction row.
     * @param string $status      Local payment status to apply.
     * @return array|\WP_Error {
     *     @type int    $transaction_id
     *     @type string $from             Status before this call.
     *     @type string $to               Status now stored.
     *     @type bool   $changed          False when the call was a no-op.
     *     @type string $supporter_status Aggregate status written to the supporter.
     *     @type int    $membership_access_id Access row activated by this call, 0 otherwise.
     * }
     */
    public function apply($transaction, $status)
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

        if (!empty($locked->subscription_id)) {
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

            return $this->result($transactionId, $current, $status, false);
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
            // one, so a refund here cannot erase another payment they made. The
            // status just written is part of that sum: it is read back inside
            // this transaction.
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
        // The row lock was released at COMMIT above, so a concurrent refund can
        // transition this transaction — and revoke its access — before any of
        // the lines below run. Everything after the commit therefore reports the
        // status that is durably stored now, never the one this call asked for:
        // announcing 'paid' for a payment already stored as refunded is not a
        // stale detail, it is a paid-payment email, a payment_completed entry
        // and, through UserManager, a request to re-grant the access the refund
        // has just taken away.
        $announced = $this->storedStatus($transactionId) ?: $status;

        $membershipAccessId = 0;
        if ($announced === 'paid') {
            // Conditional on the stored payment inside one statement, so the
            // grant is decided against the refund's committed state rather than
            // against the read above, which is already history by now.
            $membershipAccessId = (int) (new MembershipAccess())->activateByTransaction($transactionId);
        }

        do_action('buymecoffee_payment_status_updated', $transactionId, $announced);

        return $this->result($transactionId, $current, $announced, true, $supporterStatus, $membershipAccessId);
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
     * @return array
     */
    /**
     * The payment status currently stored for a transaction.
     *
     * @param int $transactionId Transaction row ID.
     * @return string Empty when the row is gone.
     */
    private function storedStatus($transactionId)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from $wpdb->prefix; fresh uncached read required to re-check status after commit.
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}buymecoffee_transactions WHERE id = %d",
            (int) $transactionId
        ));

        return sanitize_key((string) $stored);
    }

    private function result($transactionId, $from, $to, $changed, $supporterStatus = '', $membershipAccessId = 0)
    {
        return [
            'transaction_id'       => (int) $transactionId,
            'from'                 => (string) $from,
            'to'                   => (string) $to,
            'changed'              => (bool) $changed,
            'supporter_status'     => (string) $supporterStatus,
            'membership_access_id' => (int) $membershipAccessId,
        ];
    }
}
