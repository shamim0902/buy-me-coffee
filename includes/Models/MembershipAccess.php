<?php

namespace BuyMeCoffee\Models;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class MembershipAccess extends Model
{
    protected $table = 'buymecoffee_membership_access';

    public function upsertFromSubscription($subscriptionId, $fireAction = true)
    {
        $subscriptionId = absint($subscriptionId);
        if (!$subscriptionId) {
            return 0;
        }

        $subscription = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('id', $subscriptionId)
            ->first();

        if (!$subscription || empty($subscription->level_id)) {
            return 0;
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', (int) $subscription->supporter_id)
            ->first();

        $transaction = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('subscription_id', $subscriptionId)
            ->orderBy('id', 'ASC')
            ->first();

        $accessType = 'subscription';
        if (($subscription->payment_mode ?? '') === 'manual') {
            $accessType = 'manual';
        } elseif (($subscription->interval_type ?? '') === 'one_time') {
            $accessType = 'one_time';
        }

        $expiresAt = null;
        if ($accessType === 'subscription' && !empty($subscription->current_period_end) && $subscription->current_period_end !== '0000-00-00 00:00:00') {
            $expiresAt = $subscription->current_period_end;
        }

        $identity = ['subscription_id' => $subscriptionId];
        $storedSubscriptionId = $subscriptionId;

        if ($accessType !== 'subscription') {
            $storedSubscriptionId = null;
            if ($transaction) {
                $identity = ['transaction_id' => (int) $transaction->id];
            } else {
                $identity = [
                    'supporter_id' => (int) $subscription->supporter_id,
                    'level_id'     => (int) $subscription->level_id,
                    'access_type'  => $accessType,
                ];
            }
        }

        return $this->upsert([
            'supporter_id'    => (int) $subscription->supporter_id,
            'wp_user_id'      => !empty($supporter->wp_user_id) ? (int) $supporter->wp_user_id : null,
            'level_id'        => (int) $subscription->level_id,
            'transaction_id'  => $transaction ? (int) $transaction->id : null,
            'subscription_id' => $storedSubscriptionId,
            'access_type'     => $accessType,
            'status'          => sanitize_text_field($subscription->status ?: 'incomplete'),
            'starts_at'       => !empty($subscription->created_at) ? $subscription->created_at : current_time('mysql'),
            'expires_at'      => $expiresAt,
        ], $identity, $fireAction);
    }

    public function createPendingForTransaction($transactionId, $supporterId, $levelId, $accessType = 'one_time')
    {
        $transactionId = absint($transactionId);
        $supporterId = absint($supporterId);
        $levelId = absint($levelId);

        if (!$transactionId || !$supporterId || !$levelId) {
            return 0;
        }

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', $supporterId)
            ->first();

        return $this->upsert([
            'supporter_id'    => $supporterId,
            'wp_user_id'      => !empty($supporter->wp_user_id) ? (int) $supporter->wp_user_id : null,
            'level_id'        => $levelId,
            'transaction_id'  => $transactionId,
            'subscription_id' => null,
            'access_type'     => sanitize_key($accessType) ?: 'one_time',
            'status'          => 'incomplete',
            'starts_at'       => current_time('mysql'),
            'expires_at'      => null,
        ], ['transaction_id' => $transactionId], false);
    }

    public function grantManualAccess($supporterId, $wpUserId, $levelId)
    {
        $supporterId = absint($supporterId);
        $wpUserId = absint($wpUserId);
        $levelId = absint($levelId);

        if (!$supporterId || !$levelId) {
            return 0;
        }

        return $this->upsert([
            'supporter_id'    => $supporterId,
            'wp_user_id'      => $wpUserId ?: null,
            'level_id'        => $levelId,
            'transaction_id'  => null,
            'subscription_id' => null,
            'access_type'     => 'manual',
            'status'          => 'active',
            'starts_at'       => current_time('mysql'),
            'expires_at'      => null,
        ], [
            'supporter_id' => $supporterId,
            'level_id'     => $levelId,
            'access_type'  => 'manual',
        ], true);
    }

    /**
     * Activate the access a transaction paid for, but only while that payment
     * still stands.
     *
     * The payment and the access it grants are two rows, so a refund landing
     * between "this payment is paid" and the activation below would otherwise
     * re-open the access the refund had just revoked, and leave a refunded
     * customer inside gated content. The move is therefore a single conditional
     * statement joined to the transaction: the database decides it against the
     * refund's committed state — blocking on its row lock while it is still in
     * flight — never against a status this process read a moment earlier.
     *
     * @param int $transactionId Transaction row ID.
     * @return int Access row ID that is active because of this call, 0 when
     *             nothing was activated.
     */
    public function activateByTransaction($transactionId)
    {
        global $wpdb;

        $transactionId = absint($transactionId);
        if (!$transactionId) {
            return 0;
        }

        $access = $this->getQuery()
            ->where('transaction_id', $transactionId)
            ->first();

        if (!$access) {
            return 0;
        }

        // Already active — nothing to do. Avoids redundant re-activation side
        // effects when this runs on every paid confirmation.
        if ($access->status === 'active') {
            return (int) $access->id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared below; atomic compare-and-set on plugin tables.
        $activated = $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
            "UPDATE {$wpdb->prefix}{$this->table} a
               INNER JOIN {$wpdb->prefix}buymecoffee_transactions t ON t.id = a.transaction_id
                SET a.status = 'active', a.updated_at = %s
              WHERE a.id = %d AND a.status <> 'active' AND t.status = 'paid'",
            current_time('mysql'),
            (int) $access->id
        ));

        // Refused: the payment is no longer paid, or another request activated
        // the row first and owns the announcement below.
        if (!$activated) {
            return 0;
        }

        $this->invalidateSupporterAccessCache((int) $access->supporter_id);
        do_action('buymecoffee_membership_access_activated', (int) $access->id);

        return (int) $access->id;
    }

    public function updateData($id, $data)
    {
        global $wpdb;

        return $wpdb->update(
            $wpdb->prefix . $this->table,
            $data,
            ['id' => absint($id)]
        );
    }

    /**
     * Revoke the access granted by a transaction (e.g. after a refund).
     *
     * Moves the access row out of any access-granting status so that
     * getActiveLevelIdsForUser() stops returning its level, and clears the
     * cached level list. Used for one-time / manual grants keyed to a
     * transaction; subscription access is revoked through its own lifecycle.
     *
     * @param int    $transactionId Transaction row ID.
     * @param string $status        New access status (default 'refunded').
     * @return int Access row ID that was revoked, or 0 if none.
     */
    public function revokeByTransaction($transactionId, $status = 'refunded')
    {
        $transactionId = absint($transactionId);
        if (!$transactionId) {
            return 0;
        }

        $access = $this->getQuery()
            ->where('transaction_id', $transactionId)
            ->first();

        if (!$access) {
            return 0;
        }

        $status = sanitize_key($status) ?: 'refunded';

        $this->updateData((int) $access->id, [
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        ]);

        $this->invalidateSupporterAccessCache((int) $access->supporter_id);
        do_action('buymecoffee_membership_access_revoked', (int) $access->id, $status);

        return (int) $access->id;
    }

    public function getActiveLevelIdsForUser($userId)
    {
        $userId = absint($userId);
        if (!$userId) {
            return [];
        }

        $now = current_time('mysql', true);
        $supporterIds = buymecoffee_get_supporter_ids_for_user($userId);
        if (empty($supporterIds)) {
            return [];
        }

        $rows = $this->getQuery()
            ->select(['level_id'])
            ->whereIn('supporter_id', $supporterIds)
            ->where(function ($whereQuery) use ($now) {
                $whereQuery->where(function ($activeQuery) use ($now) {
                    $activeQuery->where('status', 'active')
                        ->where(function ($accessQuery) use ($now) {
                            $accessQuery->whereIn('access_type', ['one_time', 'manual'])
                                ->orWhere(function ($periodQuery) use ($now) {
                                    $periodQuery->where('access_type', 'subscription')
                                        ->whereNotNull('expires_at')
                                        ->where('expires_at', '>', $now);
                                });
                        });
                })
                    ->orWhere(function ($cancelledQuery) use ($now) {
                        $cancelledQuery->where('status', 'cancelled')
                            ->where('access_type', 'subscription')
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '>', $now);
                    });
            })
            ->get();

        $levelIds = [];
        foreach ($rows as $row) {
            if (!empty($row->level_id)) {
                $levelIds[] = (int) $row->level_id;
            }
        }

        return array_values(array_unique($levelIds));
    }

    /**
     * Earliest future expiry among the user's time-bounded (subscription) access
     * rows, or null when the user only has non-expiring grants (one-time/manual).
     *
     * Used to give the cached level list a TTL so that when a subscription
     * period lapses — after which no further gateway event fires — the cache is
     * recomputed instead of granting access forever.
     *
     * @param int $userId WordPress user ID.
     * @return string|null MySQL datetime (UTC) or null.
     */
    public function getNextAccessExpiryForUser($userId)
    {
        $userId = absint($userId);
        if (!$userId) {
            return null;
        }

        $supporterIds = buymecoffee_get_supporter_ids_for_user($userId);
        if (empty($supporterIds)) {
            return null;
        }

        $now = current_time('mysql', true);

        $row = $this->getQuery()
            ->select(['expires_at'])
            ->whereIn('supporter_id', $supporterIds)
            ->where('access_type', 'subscription')
            ->whereIn('status', ['active', 'cancelled'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->orderBy('expires_at', 'ASC')
            ->first();

        return ($row && !empty($row->expires_at)) ? $row->expires_at : null;
    }

    private function upsert(array $data, array $identity, $fireAction)
    {
        global $wpdb;

        $existing = $this->findExisting($identity);
        $now = current_time('mysql');

        $row = [
            'supporter_id'    => (int) $data['supporter_id'],
            'wp_user_id'      => !empty($data['wp_user_id']) ? (int) $data['wp_user_id'] : null,
            'level_id'        => (int) $data['level_id'],
            'transaction_id'  => !empty($data['transaction_id']) ? (int) $data['transaction_id'] : null,
            'subscription_id' => !empty($data['subscription_id']) ? (int) $data['subscription_id'] : null,
            'access_type'     => sanitize_key($data['access_type'] ?? 'subscription'),
            'status'          => sanitize_text_field($data['status'] ?? 'incomplete'),
            'starts_at'       => !empty($data['starts_at']) ? sanitize_text_field($data['starts_at']) : $now,
            'expires_at'      => !empty($data['expires_at']) ? sanitize_text_field($data['expires_at']) : null,
            'updated_at'      => $now,
        ];

        if ($existing) {
            $this->updateData((int) $existing->id, $row);
            $accessId = (int) $existing->id;
        } else {
            $row['created_at'] = $now;
            $inserted = $wpdb->insert($wpdb->prefix . $this->table, $row);
            $accessId = $inserted ? (int) $wpdb->insert_id : 0;
        }

        if ($accessId) {
            $this->invalidateSupporterAccessCache((int) $row['supporter_id']);
            if ($fireAction && $this->rowGrantsAccess((object) $row)) {
                do_action('buymecoffee_membership_access_activated', $accessId);
            }
        }

        return $accessId;
    }

    private function findExisting(array $identity)
    {
        $query = $this->getQuery();
        foreach ($identity as $key => $value) {
            if ($value === null || $value === '') {
                $query->whereNull($key);
            } else {
                $query->where($key, $value);
            }
        }

        return $query->first();
    }

    private function rowGrantsAccess($row)
    {
        if ($row->status !== 'active' && $row->status !== 'cancelled') {
            return false;
        }

        if ($row->status === 'active' && in_array($row->access_type, ['one_time', 'manual'], true)) {
            return true;
        }

        return $row->access_type === 'subscription'
            && !empty($row->expires_at)
            && strtotime($row->expires_at) > time();
    }

    private function invalidateSupporterAccessCache($supporterId)
    {
        $supporterId = absint($supporterId);
        if (!$supporterId || !function_exists('buymecoffee_delete_supporter_meta')) {
            return;
        }

        // The read cache is keyed to the user's *primary* supporter row, which may
        // differ from the row that changed. Clear this supporter plus every sibling
        // row belonging to the same WP user so the primary key is always cleared.
        $supporterIds = [$supporterId];

        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('id', $supporterId)
            ->select(['wp_user_id'])
            ->first();

        if ($supporter && !empty($supporter->wp_user_id) && function_exists('buymecoffee_get_supporter_ids_for_user')) {
            $supporterIds = array_unique(array_merge(
                $supporterIds,
                buymecoffee_get_supporter_ids_for_user((int) $supporter->wp_user_id)
            ));
        }

        foreach ($supporterIds as $id) {
            buymecoffee_delete_supporter_meta((int) $id, 'active_level_ids');
        }
    }
}
