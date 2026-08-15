<?php

namespace BuyMeCoffee\Services;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * The admin supporter-detail response.
 *
 * Supporters::find() is the internal reader used by the email, gateway and
 * subscription paths, so it returns whole rows — transactions with their raw
 * gateway payload in payment_note, subscriptions with their Stripe ids, the
 * supporter's own submission blob and entry hash. Sending that object straight
 * back to the browser handed every one of those to anyone who merely holds
 * `buy-me-coffee_view_supporters`.
 *
 * This presenter is the response contract instead, and it is an allowlist in
 * both directions:
 *
 *  - A column that is not named here is not sent, so a future migration cannot
 *    widen the response by adding a column. That is the point of listing even
 *    obviously harmless fields.
 *  - The raw provider payload (payment_note), the submission blob
 *    (form_data_raw, other_infos), the entry hashes and the donor's IP address
 *    are named nowhere, so no caller receives them — a payment administrator
 *    included. Those belong to the gateway record, not to an admin screen.
 *
 * Operational provider references — the charge id, the dashboard link built
 * from it, the card brand/last four, the live/test mode and the Stripe
 * subscription and customer ids — are the payment administrator's working
 * data, so they are gated on AccessControl::hasPaymentDataPermission() rather
 * than on supporter access, in every place they appear: the primary
 * transaction, the payment history, the subscription, the membership access
 * rows and the other donations.
 */
class SupporterAdminPresenter
{
    /** Supporter fields every authorized caller receives. */
    const SUPPORTER_FIELDS = [
        'id',
        'supporters_name',
        'supporters_email',
        'supporters_message',
        'supporters_image',
        'currency',
        'payment_status',
        'payment_total',
        'coffee_count',
        'payment_method',
        'created_at',
        'updated_at',
        'all_time_total_paid',
        'all_time_total_pending',
        'all_time_total_coffee',
        'other_donations_total',
    ];

    /** Supporter fields only a payment-authorized caller receives. */
    const SUPPORTER_PAYMENT_FIELDS = [
        'payment_mode',
    ];

    /** Transaction fields every authorized caller receives. */
    const TRANSACTION_FIELDS = [
        'id',
        'entry_id',
        'subscription_id',
        'transaction_type',
        'payment_method',
        'payment_total',
        'currency',
        'status',
        'created_at',
        'updated_at',
    ];

    /** Transaction fields only a payment-authorized caller receives. */
    const TRANSACTION_PAYMENT_FIELDS = [
        'charge_id',
        'card_brand',
        'card_last_4',
        'payment_mode',
        'transaction_url',
    ];

    /** Subscription fields every authorized caller receives. */
    const SUBSCRIPTION_FIELDS = [
        'id',
        'supporter_id',
        'level_id',
        'status',
        'interval_type',
        'amount',
        'currency',
        'current_period_end',
        'cancelled_at',
        'created_at',
        'updated_at',
    ];

    /** Subscription fields only a payment-authorized caller receives. */
    const SUBSCRIPTION_PAYMENT_FIELDS = [
        'stripe_subscription_id',
        'stripe_customer_id',
        'payment_mode',
    ];

    /** Membership access fields every authorized caller receives. */
    const MEMBERSHIP_ACCESS_FIELDS = [
        'id',
        'supporter_id',
        'level_id',
        'transaction_id',
        'subscription_id',
        'access_type',
        'status',
        'starts_at',
        'expires_at',
        'created_at',
        'updated_at',
        'level_name',
        'billing_interval',
        'subscription_amount',
        'subscription_currency',
        'transaction_amount',
        'transaction_currency',
        'transaction_status',
        'transaction_payment_method',
    ];

    /** Membership access fields only a payment-authorized caller receives. */
    const MEMBERSHIP_ACCESS_PAYMENT_FIELDS = [
        'transaction_charge_id',
    ];

    /** Other-donation fields every authorized caller receives. */
    const OTHER_DONATION_FIELDS = [
        'id',
        'supporters_name',
        'currency',
        'payment_status',
        'payment_total',
        'coffee_count',
        'payment_method',
        'created_at',
    ];

    /** Other-donation fields only a payment-authorized caller receives. */
    const OTHER_DONATION_PAYMENT_FIELDS = [
        'payment_mode',
    ];

    /**
     * Build the get_supporter response.
     *
     * @param object $supporter          Row graph from Supporters::find().
     * @param bool   $includePaymentData Caller holds payment-data permission.
     * @return array Response payload.
     */
    public static function present($supporter, $includePaymentData = false)
    {
        $includePaymentData = (bool) $includePaymentData;

        $payload = self::row($supporter, self::SUPPORTER_FIELDS, self::SUPPORTER_PAYMENT_FIELDS, $includePaymentData);

        $payload['transaction'] = !empty($supporter->transaction)
            ? self::row($supporter->transaction, self::TRANSACTION_FIELDS, self::TRANSACTION_PAYMENT_FIELDS, $includePaymentData)
            : null;

        $payload['transactions'] = self::rows(
            isset($supporter->transactions) ? $supporter->transactions : [],
            self::TRANSACTION_FIELDS,
            self::TRANSACTION_PAYMENT_FIELDS,
            $includePaymentData
        );

        $payload['other_donations'] = self::rows(
            isset($supporter->other_donations) ? $supporter->other_donations : [],
            self::OTHER_DONATION_FIELDS,
            self::OTHER_DONATION_PAYMENT_FIELDS,
            $includePaymentData
        );

        $payload['membership_access'] = self::rows(
            isset($supporter->membership_access) ? $supporter->membership_access : [],
            self::MEMBERSHIP_ACCESS_FIELDS,
            self::MEMBERSHIP_ACCESS_PAYMENT_FIELDS,
            $includePaymentData
        );

        // Absent rather than null when there is none, so the detail screen's
        // `v-if="supporter.subscription"` reads exactly as it did before.
        if (!empty($supporter->subscription)) {
            $payload['subscription'] = self::row(
                $supporter->subscription,
                self::SUBSCRIPTION_FIELDS,
                self::SUBSCRIPTION_PAYMENT_FIELDS,
                $includePaymentData
            );
        }

        return $payload;
    }

    /**
     * @param mixed $rows               Iterable of rows.
     * @param array $fields             Always-allowed field names.
     * @param array $paymentFields      Payment-gated field names.
     * @param bool  $includePaymentData Caller holds payment-data permission.
     * @return array List of projected rows.
     */
    private static function rows($rows, array $fields, array $paymentFields, $includePaymentData)
    {
        if (!is_array($rows) && !($rows instanceof \Traversable)) {
            return [];
        }

        $projected = [];

        foreach ($rows as $row) {
            $projected[] = self::row($row, $fields, $paymentFields, $includePaymentData);
        }

        return array_values($projected);
    }

    /**
     * Project one row onto its allowlist.
     *
     * @param mixed $row                Row object or array.
     * @param array $fields             Always-allowed field names.
     * @param array $paymentFields      Payment-gated field names.
     * @param bool  $includePaymentData Caller holds payment-data permission.
     * @return array
     */
    private static function row($row, array $fields, array $paymentFields, $includePaymentData)
    {
        $source = self::toArray($row);
        $allowed = $includePaymentData ? array_merge($fields, $paymentFields) : $fields;

        $projected = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $source)) {
                continue;
            }

            $value = $source[$field];

            // Every allowlisted column holds a scalar. A nested structure means
            // the row is not what this contract describes, so it is dropped
            // rather than serialized into the response.
            if (is_array($value) || is_object($value)) {
                continue;
            }

            if ($field === 'transaction_url') {
                $value = self::safeUrl($value);
                if ($value === '') {
                    continue;
                }
            }

            $projected[$field] = $value;
        }

        return $projected;
    }

    /**
     * A dashboard link is built by a filter any plugin may replace, so only an
     * http(s) URL is passed on: a javascript: or data: link would otherwise be
     * rendered as an admin-clickable anchor.
     *
     * @param mixed $url Filter-provided URL.
     * @return string Empty when unusable.
     */
    private static function safeUrl($url)
    {
        if (!is_string($url) || $url === '') {
            return '';
        }

        $safe = esc_url_raw($url, ['http', 'https']);

        return is_string($safe) ? $safe : '';
    }

    /**
     * @param mixed $row Row object or array.
     * @return array
     */
    private static function toArray($row)
    {
        if (is_array($row)) {
            return $row;
        }

        if ($row instanceof \stdClass) {
            return (array) $row;
        }

        if (is_object($row)) {
            return get_object_vars($row);
        }

        return [];
    }
}
