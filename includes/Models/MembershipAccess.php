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

    public function activateByTransaction($transactionId)
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

        $this->updateData((int) $access->id, [
            'status'     => 'active',
            'updated_at' => current_time('mysql'),
        ]);

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
        if ($supporterId && function_exists('buymecoffee_delete_supporter_meta')) {
            buymecoffee_delete_supporter_meta((int) $supporterId, 'active_level_ids');
        }
    }
}
