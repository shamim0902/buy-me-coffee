<?php

namespace BuyMeCoffee\Services;

use BuyMeCoffee\Models\Supporters;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Deletes a supporter and everything attached to them.
 *
 * A donor keeps being charged if the local rows disappear while the Stripe
 * agreement is still live, and the remote ID needed to reconcile the charge is
 * lost with them. So every recurring agreement that can still bill is cancelled
 * and confirmed at Stripe first; only then is the local graph removed, inside a
 * single transaction. When any cancellation cannot be confirmed nothing local is
 * deleted, so the admin can fix the cause and retry.
 */
class SupporterDeletionService
{
    /**
     * @var array<string, string> Child table => column the deletion is keyed on.
     */
    const CHILD_TABLES = [
        'buymecoffee_subscriptions'     => 'supporter_id',
        'buymecoffee_membership_access' => 'supporter_id',
        'buymecoffee_supporters_meta'   => 'supporter_id',
        'buymecoffee_transactions'      => 'entry_id',
    ];

    /**
     * Delete a supporter and all related records.
     *
     * @param int $supporterId Supporter row ID.
     * @return array|\WP_Error {
     *     @type int   $supporter_id
     *     @type int[] $cancelled_subscription_ids Subscriptions cancelled at Stripe by this call.
     *     @type array $deleted                    Row counts per table.
     * }
     */
    public function delete($supporterId)
    {
        $supporterId = absint($supporterId);

        if (!$supporterId) {
            return new \WP_Error('bmc_invalid_supporter', __('Invalid supporter ID', 'buy-me-coffee'));
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', $supporterId)
            ->first();

        if (!$supporter) {
            return new \WP_Error('bmc_supporter_not_found', __('Supporter not found', 'buy-me-coffee'));
        }

        $cancellation = $this->cancelRemoteAgreements($supporterId);

        if (is_wp_error($cancellation)) {
            return $cancellation;
        }

        // Capture what the cache invalidation needs before the rows are gone.
        $siblingSupporterIds = $this->getSiblingSupporterIds($supporter);

        $deleted = $this->deleteLocalGraph($supporterId);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        $this->invalidateCaches($siblingSupporterIds);

        return [
            'supporter_id'               => $supporterId,
            'cancelled_subscription_ids' => $cancellation,
            'deleted'                    => $deleted,
        ];
    }

    /**
     * Cancel every local subscription that can still bill remotely.
     *
     * Each confirmed cancellation is written locally straight away, so a retry
     * after a partial failure does not ask Stripe to cancel it a second time.
     *
     * @param int $supporterId Supporter row ID.
     * @return int[]|\WP_Error IDs cancelled at Stripe by this call.
     */
    private function cancelRemoteAgreements($supporterId)
    {
        $subscriptions = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('supporter_id', $supporterId)
            ->get();

        if (!$subscriptions) {
            return [];
        }

        $service   = new SubscriptionCancellationService();
        $cancelled = [];
        $failures  = [];

        foreach ($subscriptions as $subscription) {
            if (!SubscriptionCancellationService::needsRemoteCancellation($subscription)) {
                continue;
            }

            $result = $service->cancel($subscription, [
                'log_title' => __('Subscription cancelled before supporter deletion', 'buy-me-coffee'),
                'context'   => ['reason' => 'supporter_deletion'],
            ]);

            if (is_wp_error($result)) {
                $failures[] = [
                    'subscription_id'        => (int) $subscription->id,
                    'stripe_subscription_id' => (string) $subscription->stripe_subscription_id,
                    'code'                   => $result->get_error_code(),
                    'message'                => $result->get_error_message(),
                ];
                continue;
            }

            $cancelled[] = (int) $subscription->id;
        }

        if ($failures) {
            $messages = wp_list_pluck($failures, 'message');

            return new \WP_Error(
                'bmc_supporter_delete_blocked',
                sprintf(
                    /* translators: 1: number of subscriptions that could not be cancelled, 2: list of provider errors. */
                    _n(
                        'Nothing was deleted: %1$d subscription is still active at Stripe. %2$s',
                        'Nothing was deleted: %1$d subscriptions are still active at Stripe. %2$s',
                        count($failures),
                        'buy-me-coffee'
                    ),
                    count($failures),
                    implode(' ', $messages)
                ),
                [
                    'supporter_id'               => (int) $supporterId,
                    'cancelled_subscription_ids' => $cancelled,
                    'failed_subscriptions'       => $failures,
                ]
            );
        }

        return $cancelled;
    }

    /**
     * Remove the supporter row and every child record in one transaction.
     *
     * @param int $supporterId Supporter row ID.
     * @return array|\WP_Error Deleted row counts per table.
     */
    private function deleteLocalGraph($supporterId)
    {
        global $wpdb;

        $transactionIds  = $this->getChildIds('buymecoffee_transactions', 'entry_id', $supporterId);
        $subscriptionIds = $this->getChildIds('buymecoffee_subscriptions', 'supporter_id', $supporterId);

        $usesTransaction = $this->managesTransaction($supporterId);

        if ($usesTransaction && $wpdb->query('START TRANSACTION') === false) {
            return new \WP_Error(
                'bmc_supporter_delete_failed',
                sprintf(
                    /* translators: %s: database error message. */
                    __('Nothing was deleted: the database refused to start a transaction. %s', 'buy-me-coffee'),
                    $wpdb->last_error
                ),
                ['supporter_id' => (int) $supporterId]
            );
        }

        $deleted = [];

        try {
            // Activity logs: supporter events, then payment and subscription events
            // keyed by the child rows that are about to disappear.
            $deleted['activities'] = $this->runDelete(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                    "DELETE FROM {$wpdb->prefix}buymecoffee_activities WHERE object_type IN ('submission', 'email') AND object_id = %d",
                    $supporterId
                )
            );

            $deleted['activities'] += $this->deleteActivitiesFor('payment', $transactionIds);
            $deleted['activities'] += $this->deleteActivitiesFor('subscription', $subscriptionIds);

            foreach (self::CHILD_TABLES as $table => $column) {
                $deleted[$table] = $this->runDelete(
                    $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column are fixed values from CHILD_TABLES.
                        "DELETE FROM {$wpdb->prefix}{$table} WHERE {$column} = %d",
                        $supporterId
                    )
                );
            }

            $deletedSupporters = $this->runDelete(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                    "DELETE FROM {$wpdb->prefix}buymecoffee_supporters WHERE id = %d",
                    $supporterId
                )
            );

            if ($deletedSupporters < 1) {
                throw new \RuntimeException(__('The supporter record could not be deleted.', 'buy-me-coffee'));
            }

            $deleted['buymecoffee_supporters'] = $deletedSupporters;
        } catch (\Exception $exception) {
            $rolledBack = true;

            if ($usesTransaction) {
                $rolledBack = $wpdb->query('ROLLBACK') !== false;
            }

            $message = sprintf(
                /* translators: %s: database error message. */
                __('Nothing was deleted: %s Any Stripe subscriptions for this supporter are already cancelled, so retrying is safe.', 'buy-me-coffee'),
                $exception->getMessage()
            );

            if (!$rolledBack) {
                $message .= ' ' . __('The partial deletion could not be rolled back — please check this supporter before retrying.', 'buy-me-coffee');
            }

            return new \WP_Error(
                'bmc_supporter_delete_failed',
                $message,
                [
                    'supporter_id' => (int) $supporterId,
                    'rolled_back'  => $rolledBack,
                ]
            );
        }

        if ($usesTransaction && $wpdb->query('COMMIT') === false) {
            $commitError = $wpdb->last_error;
            $rolledBack = $wpdb->query('ROLLBACK') !== false;
            $message = sprintf(
                /* translators: %s: database error message. */
                __('Nothing was deleted: the deletion could not be committed. %s', 'buy-me-coffee'),
                $commitError
            );

            if (!$rolledBack) {
                $message .= ' ' . __('The rollback also failed — please inspect this supporter before retrying.', 'buy-me-coffee');
            }

            return new \WP_Error(
                'bmc_supporter_delete_failed',
                $message,
                [
                    'supporter_id' => (int) $supporterId,
                    'rolled_back'  => $rolledBack,
                ]
            );
        }

        return $deleted;
    }

    /**
     * Run one delete statement and fail loudly when the database rejects it.
     *
     * @param string $sql Prepared statement.
     * @return int Affected rows.
     * @throws \RuntimeException When the query fails.
     */
    private function runDelete($sql)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Statement is prepared by the caller; deletion must report affected rows.
        $result = $wpdb->query($sql);

        if ($result === false) {
            throw new \RuntimeException($wpdb->last_error ?: __('A database error occurred.', 'buy-me-coffee'));
        }

        return (int) $result;
    }

    /**
     * @param string $objectType Activity object type.
     * @param int[]  $objectIds  Deleted child row IDs.
     * @return int Affected rows.
     */
    private function deleteActivitiesFor($objectType, array $objectIds)
    {
        global $wpdb;

        if (!$objectIds) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($objectIds), '%d'));

        return $this->runDelete(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated, values are bound.
                "DELETE FROM {$wpdb->prefix}buymecoffee_activities WHERE object_type = %s AND object_id IN ({$placeholders})",
                array_merge([$objectType], $objectIds)
            )
        );
    }

    /**
     * @param string $table       Table name without prefix.
     * @param string $column      Foreign key column.
     * @param int    $supporterId Supporter row ID.
     * @return int[]
     */
    private function getChildIds($table, $column, $supporterId)
    {
        $rows = buyMeCoffeeQuery()
            ->table($table)
            ->where($column, $supporterId)
            ->select('id')
            ->get();

        if (!$rows) {
            return [];
        }

        return array_map(function ($row) {
            return (int) $row->id;
        }, $rows);
    }

    /**
     * Supporter rows sharing this supporter's WP user, whose cached level list
     * may have been computed from the rows being deleted.
     *
     * @param object $supporter Supporter row.
     * @return int[]
     */
    private function getSiblingSupporterIds($supporter)
    {
        if (empty($supporter->wp_user_id) || !function_exists('buymecoffee_get_supporter_ids_for_user')) {
            return [];
        }

        $ids = buymecoffee_get_supporter_ids_for_user((int) $supporter->wp_user_id);

        return array_values(array_diff(array_map('absint', (array) $ids), [(int) $supporter->id]));
    }

    /**
     * Invalidate every cache that could still describe the deleted supporter.
     * Called once, after the delete is committed.
     *
     * @param int[] $siblingSupporterIds Supporter rows sharing the same WP user.
     * @return void
     */
    private function invalidateCaches(array $siblingSupporterIds)
    {
        Supporters::flushPublicSupportersCache();
        Supporters::flushAdminReportCache();

        if (!function_exists('buymecoffee_delete_supporter_meta')) {
            return;
        }

        foreach ($siblingSupporterIds as $siblingId) {
            buymecoffee_delete_supporter_meta($siblingId, 'active_level_ids');
        }
    }

    /**
     * Whether this service opens its own database transaction.
     *
     * MySQL has no nested transactions: a caller that is already inside one
     * (the feature test runner, a bulk routine) would have it implicitly
     * committed by START TRANSACTION. Such callers can return false and keep
     * managing the transaction themselves.
     *
     * @param int $supporterId Supporter row ID.
     * @return bool
     */
    private function managesTransaction($supporterId)
    {
        return (bool) apply_filters('buymecoffee_supporter_delete_manages_transaction', true, $supporterId);
    }
}
