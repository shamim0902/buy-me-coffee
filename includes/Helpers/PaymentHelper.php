<?php

namespace BuyMeCoffee\Helpers;

use BuyMeCoffee\Builder\Methods\Stripe\API;
use BuyMeCoffee\Builder\Methods\Stripe\StripeSettings;
use BuyMeCoffee\Controllers\SubmissionHandler;
use BuyMeCoffee\Models\Buttons;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Services\OneTimePaymentStatusService;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class PaymentHelper
{

    public function getCurrencies()
    {
        return Currencies::all();
    }

    public function getByHash($hash)
    {
        return (new Supporters())->getByHash($hash);
    }

    /**
     * The local Stripe transaction a PaymentIntent belongs to.
     *
     * Checkout stamps the intent onto its transaction before the browser is ever
     * handed it, so a confirmation can prove ownership of the intent it presents
     * without asking Stripe anything. That is what stops an arbitrary intent id
     * from being turned into a claim, a provider call, or a lock on somebody
     * else's donation.
     *
     * @param string $intentId Stripe PaymentIntent id.
     * @return object|null
     */
    public function findStripeTransactionByIntent($intentId)
    {
        $intentId = sanitize_text_field($intentId);
        if (!$intentId) {
            return null;
        }

        return buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('charge_id', $intentId)
            ->where('payment_method', 'stripe')
            ->first();
    }

    /**
     * Answer a repeated confirmation from local state alone.
     *
     * A browser confirmation is replayable by design — a reload, a double click,
     * or a retry after a dropped response all send the same intent again. Once
     * the local transaction has reached a final state, another round trip to
     * Stripe cannot change what is reported, so it is skipped: the same result
     * is rebuilt from the rows and from the intent payload already stored on the
     * transaction. Anything still pending or processing is deliberately not
     * replayed, because for those the provider is the only source of truth.
     *
     * @param string $intentId               Stripe PaymentIntent id.
     * @param int    $requestedSubscriptionId Subscription the caller claims, 0 for none.
     * @return array|null Same shape as updatePaymentData(), or null when the
     *                    confirmation still has to reach Stripe.
     */
    public function replayConfirmationResult($intentId, $requestedSubscriptionId = 0)
    {
        $transaction = $this->findStripeTransactionByIntent($intentId);

        if (!$transaction) {
            return null;
        }

        $status = sanitize_text_field($transaction->status);
        if (!in_array($status, ['paid', 'refunded', 'failed'], true)) {
            return null;
        }

        $subscriptionId = !empty($transaction->subscription_id) ? (int) $transaction->subscription_id : 0;

        $requestedSubscriptionId = absint($requestedSubscriptionId);
        if ($requestedSubscriptionId && $requestedSubscriptionId !== $subscriptionId) {
            // The caller is asking about a different subscription than the one
            // this intent settled; that claim has to be checked against Stripe.
            return null;
        }

        $transactionId = (int) $transaction->id;

        $membershipAccess = $this->getMembershipAccessByTransaction($transactionId);
        $subscription = null;

        if ($subscriptionId) {
            $subscription = buyMeCoffeeQuery()
                ->table('buymecoffee_subscriptions')
                ->where('id', $subscriptionId)
                ->first();
            $membershipAccess = $this->getMembershipAccessBySubscription($subscriptionId) ?: $membershipAccess;
        }

        $isMembership = !empty($membershipAccess) || !empty($subscription->level_id);
        $accessActive = $status === 'paid' && (
            !$isMembership
            || ($membershipAccess && Subscriptions::hasAccessValidity($membershipAccess))
        );

        // The intent Stripe returned for this charge was stored verbatim when the
        // payment was recorded, so its status is replayed instead of re-fetched.
        $storedIntent = json_decode((string) $transaction->payment_note, true);
        $stripeStatus = (is_array($storedIntent) && isset($storedIntent['status']))
            ? sanitize_text_field($storedIntent['status'])
            : '';

        return [
            'payment_status'           => $status,
            'stripe_status'            => $stripeStatus,
            'transaction_id'           => $transactionId,
            'subscription_id'          => $subscriptionId,
            'is_subscription'          => (bool) $subscriptionId,
            'is_membership'            => $isMembership,
            'membership_access_id'     => $membershipAccess ? (int) $membershipAccess->id : 0,
            'membership_access_status' => !empty($membershipAccess->status) ? sanitize_text_field($membershipAccess->status) : '',
            'access_active'            => (bool) $accessActive,
            'replayed'                 => true,
        ];
    }

    /**
     * Record a Stripe PaymentIntent against the order it was created for.
     *
     * This runs from the browser after checkout and can be replayed at will — a
     * customer can reload the confirmation page long after the payment was
     * refunded. A refunded PaymentIntent still reports `status: succeeded`
     * forever, so the reported status is treated as a claim, not an outcome: for
     * one-time payments the transition is decided by
     * OneTimePaymentStatusService, which refuses to move a refunded payment back
     * to paid. Only the descriptive Stripe fields (card, charge reference, raw
     * payload) are written unconditionally; they describe the payment whatever
     * became of it. The returned state is read back from storage, so a refused
     * confirmation reports refunded and no access rather than the paid state the
     * intent claimed.
     *
     * @param string $intentId Stripe PaymentIntent id.
     * @return array|\WP_Error
     */
    public function updatePaymentData($intentId)
    {
        $path = 'payment_intents/' . $intentId;

        $api = new API();
        $response = $api->makeRequest($path, [], (new StripeSettings())->getKeys('secret'));

        if (!$response || is_wp_error($response)) {
            return new \WP_Error('stripe_payment_lookup_failed', __('Unable to verify the Stripe payment.', 'buy-me-coffee'));
        }

        $orderHash = Arr::get($response, 'metadata.ref_id');
        $amount = intval(Arr::get($response, 'amount_received'));

        //verify order
        $paymentHelper = new PaymentHelper();
        $order =  $paymentHelper->getByHash($orderHash);
        if (!$order || empty($order->transaction)) {
            return new \WP_Error('stripe_payment_order_missing', __('Unable to match this payment to an order.', 'buy-me-coffee'));
        }

        // For subscriptions the PI amount may not yet be reflected in amount_received
        // at the moment the confirmation fires, so allow a zero amount_received to pass
        // (the status check below still gates on 'succeeded').
        // payment_total is stored in ×100 minor units; convert to the Stripe
        // amount for the order currency so zero-decimal currencies compare correctly.
        $orderCurrency = !empty($order->transaction->currency) ? $order->transaction->currency : self::getCurrency();
        $expectedAmount = self::toStripeAmount($order->payment_total, $orderCurrency);
        if ($amount > 0 && $expectedAmount !== $amount) {
            return new \WP_Error('stripe_payment_amount_mismatch', __('Payment amount does not match the order.', 'buy-me-coffee'));
        }

        $stripeStatus = sanitize_text_field(Arr::get($response, 'status', ''));
        $reportedStatus = $stripeStatus === 'succeeded' ? 'paid' : 'pending';
        $transactionId = (int) $order->transaction->id;
        $subscriptionId = !empty($order->transaction->subscription_id) ? (int) $order->transaction->subscription_id : 0;
        $card = Arr::get($response, 'charges.data.0.payment_method_details.card');
        $last4 = Arr::get($card, 'last4');
        $cardBand = Arr::get($card, 'brand');

        // Descriptive only: which card paid, which intent it was, what Stripe
        // returned. None of it grants anything, so it is safe to store even for a
        // confirmation whose status claim is refused below.
        $paymentDetails = [
            'charge_id' => sanitize_text_field($intentId),
            'payment_mode' => Arr::get($response, 'livemode') ? 'live' : 'test',
            'payment_note' => json_encode($response),
            'card_last_4' => sanitize_text_field($last4),
            'card_brand' => sanitize_text_field($cardBand)
        ];

        $status = $reportedStatus;

        if ($subscriptionId) {
            // Subscription-linked transactions keep their own lifecycle.
            (new Supporters())->updateData($order->id, [
                'payment_status' => $status,
                'updated_at' => current_time('mysql')
            ]);
            (new Transactions())->updateData($transactionId, array_merge(
                ['status' => sanitize_text_field($status)],
                $paymentDetails
            ));
        } else {
            (new Transactions())->updateData($transactionId, $paymentDetails);

            $applied = (new OneTimePaymentStatusService())->apply($order->transaction, $reportedStatus);

            if (is_wp_error($applied) && $applied->get_error_code() === 'bmc_payment_write_failed') {
                // The status could not be stored at all, so nothing may be
                // reported about it — the caller retries instead.
                return $applied;
            }

            // Whatever the intent claimed, the stored status is the truth: a
            // refused transition leaves the refund in place.
            $status = $this->storedTransactionStatus($transactionId) ?: $reportedStatus;
        }

        // If this transaction belongs to a subscription, activate it now.
        // Webhooks also do this, but the frontend confirmation fires first and
        // without this the subscription stays 'incomplete' until the webhook arrives.
        if ($status === 'paid' && $subscriptionId) {
            $subscriptionUpdate = [
                'status'     => 'active',
                'updated_at' => current_time('mysql'),
            ];

            $periodEnd = $this->resolveStripeSubscriptionPeriodEnd($subscriptionId);
            if ($periodEnd) {
                $subscriptionUpdate['current_period_end'] = $periodEnd;
            }

            (new Subscriptions())->updateData($subscriptionId, $subscriptionUpdate);
            do_action('buymecoffee_subscription_activated', $subscriptionId);
        }

        // Read after the transition: for a one-time payment the service has
        // already activated or left the access row alone.
        $membershipAccess = $this->getMembershipAccessByTransaction($transactionId);
        $membershipAccessId = $membershipAccess ? (int) $membershipAccess->id : 0;

        $subscription = null;
        if ($subscriptionId) {
            // One-time transitions fire this from the service instead, exactly
            // once and only when the status actually moved.
            do_action('buymecoffee_payment_status_updated', $transactionId, $status);

            $subscription = buyMeCoffeeQuery()
                ->table('buymecoffee_subscriptions')
                ->where('id', $subscriptionId)
                ->first();
            $membershipAccess = $this->getMembershipAccessBySubscription($subscriptionId) ?: $membershipAccess;
            if ($membershipAccess && !$membershipAccessId) {
                $membershipAccessId = (int) $membershipAccess->id;
            }
        }

        $isMembership = !empty($membershipAccess) || !empty($subscription->level_id);
        $accessActive = $status === 'paid' && (
            !$isMembership
            || ($membershipAccess && Subscriptions::hasAccessValidity($membershipAccess))
        );

        return [
            'payment_status'           => $status,
            'stripe_status'            => $stripeStatus,
            'transaction_id'           => $transactionId,
            'subscription_id'          => $subscriptionId,
            'is_subscription'          => (bool) $subscriptionId,
            'is_membership'            => $isMembership,
            'membership_access_id'     => $membershipAccessId,
            'membership_access_status' => !empty($membershipAccess->status) ? sanitize_text_field($membershipAccess->status) : '',
            'access_active'            => (bool) $accessActive,
        ];
    }

    /**
     * The payment status currently stored for a transaction.
     *
     * @param int $transactionId Transaction row ID.
     * @return string Empty when the row is gone.
     */
    private function storedTransactionStatus(int $transactionId): string
    {
        $transaction = (new Transactions())->find($transactionId);

        return ($transaction && !empty($transaction->status)) ? sanitize_text_field($transaction->status) : '';
    }

    private function getMembershipAccessByTransaction(int $transactionId)
    {
        if (!$transactionId) {
            return null;
        }

        return buyMeCoffeeQuery()
            ->table('buymecoffee_membership_access')
            ->where('transaction_id', $transactionId)
            ->first();
    }

    private function getMembershipAccessBySubscription(int $subscriptionId)
    {
        if (!$subscriptionId) {
            return null;
        }

        return buyMeCoffeeQuery()
            ->table('buymecoffee_membership_access')
            ->where('subscription_id', $subscriptionId)
            ->first();
    }

    private function resolveStripeSubscriptionPeriodEnd(int $localSubscriptionId): ?string
    {
        $subscription = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('id', $localSubscriptionId)
            ->first();

        if (!$subscription || empty($subscription->stripe_subscription_id)) {
            return null;
        }

        $api = new API();
        $response = $api->makeRequest(
            'subscriptions/' . sanitize_text_field($subscription->stripe_subscription_id),
            [],
            (new StripeSettings())->getKeys('secret'),
            'GET'
        );

        if (!$response || is_wp_error($response)) {
            return null;
        }

        $periodEndTs = (int) Arr::get($response, 'current_period_end', 0);
        if ($periodEndTs <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $periodEndTs);
    }

    /**
     * Currencies Stripe expects in the whole (major) unit rather than the
     * smallest unit — i.e. the amount is NOT multiplied by 100.
     *
     * @see https://docs.stripe.com/currencies#zero-decimal
     * @var string[]
     */
    const STRIPE_ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public static function isStripeZeroDecimalCurrency($currency): bool
    {
        return in_array(strtoupper((string) $currency), self::STRIPE_ZERO_DECIMAL_CURRENCIES, true);
    }

    /**
     * Convert a stored payment_total (always kept in ×100 minor units) into the
     * integer amount Stripe expects for the given currency.
     *
     * For normal currencies Stripe wants the minor-unit value as-is; for
     * zero-decimal currencies (JPY, KRW, …) the smallest unit IS the whole
     * unit, so the ×100 scaling must be undone — otherwise the customer is
     * charged 100× the intended amount.
     *
     * @param int|float $storedTotal Amount as stored in payment_total (×100).
     * @param string    $currency    ISO currency code.
     * @return int Stripe amount.
     */
    public static function toStripeAmount($storedTotal, $currency): int
    {
        $storedTotal = (int) round((float) $storedTotal);

        if (self::isStripeZeroDecimalCurrency($currency)) {
            return (int) round($storedTotal / 100);
        }

        return $storedTotal;
    }

    /**
     * Inverse of toStripeAmount(): convert a Stripe amount (as returned in
     * invoices/payment intents) back into the ×100 minor-unit value the plugin
     * stores in payment_total / subscription amount, so stored amounts stay in
     * one consistent scale regardless of currency.
     *
     * @param int|float $stripeAmount Amount from a Stripe object.
     * @param string    $currency     ISO currency code.
     * @return int Stored (×100 minor unit) amount.
     */
    public static function fromStripeAmount($stripeAmount, $currency): int
    {
        $stripeAmount = (int) round((float) $stripeAmount);

        if (self::isStripeZeroDecimalCurrency($currency)) {
            return $stripeAmount * 100;
        }

        return $stripeAmount;
    }

    public static function getFormattedAmount($amount, $currency)
    {
        $settings = self::getFormattingSettings();
        $amount = floatval($amount / 100);
        $currency = strtoupper($currency ?: self::getCurrency());
        $sign = wp_strip_all_tags(html_entity_decode(self::currencySymbol($currency), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $sign = str_replace("\xc2\xa0", ' ', $sign);

        $decimalSeparator = $settings['decimal_separator'] === 'comma' ? ',' : '.';
        $thousandSeparator = $decimalSeparator === ',' ? '.' : ',';
        $formattedAmount = number_format($amount, 2, $decimalSeparator, $thousandSeparator);

        switch ($settings['currency_position']) {
            case 'after':
                return $formattedAmount . $sign;
            case 'iso_before':
                return $currency . ' ' . $formattedAmount;
            case 'iso_after':
                return $formattedAmount . ' ' . $currency;
            case 'symbool_before_iso':
                return $sign . $formattedAmount . ' ' . $currency;
            case 'symbool_after_iso':
                return $currency . ' ' . $formattedAmount . $sign;
            case 'symbool_and_iso':
                return $currency . ' ' . $sign . $formattedAmount;
            case 'before':
            default:
                return $sign . $formattedAmount;
        }
    }

    public static function getFormattingSettings()
    {
        $settings = (new Buttons())->getButton();
        $decimalSeparator = Arr::get($settings, 'decimal_separator', 'dot');
        $currencyPosition = Arr::get($settings, 'currency_position', 'before');

        $allowedSeparators = ['dot', 'comma'];
        $allowedPositions = [
            'before',
            'after',
            'iso_before',
            'iso_after',
            'symbool_before_iso',
            'symbool_after_iso',
            'symbool_and_iso',
        ];

        return [
            'decimal_separator' => in_array($decimalSeparator, $allowedSeparators, true) ? $decimalSeparator : 'dot',
            'currency_position' => in_array($currencyPosition, $allowedPositions, true) ? $currencyPosition : 'before',
            'currency'          => strtoupper(Arr::get($settings, 'currency', 'USD')),
        ];
    }

    public static function getFrontendFormattingConfig()
    {
        return [
            'formatting_settings' => self::getFormattingSettings(),
            'currency_symbols'    => self::getDecodedCurrencySymbols(),
            'default_currency'    => self::getCurrency() ?: 'USD',
        ];
    }

    public static function getDecodedCurrencySymbols()
    {
        $symbols = [];
        foreach (array_keys(Currencies::all()) as $currency) {
            $symbols[$currency] = wp_strip_all_tags(html_entity_decode(self::currencySymbol($currency), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $symbols;
    }

    public static function getCurrency()
    {
        $settings = (new Buttons())->getButton();
        return $settings['currency'];
    }

    public static function currencySymbol($currency = '')
    {
        if (!$currency) {
            $currency = static::getCurrency()?? 'USD';
        }
        $currency = strtoupper($currency);

        $symbols = apply_filters('buymecoffee_currency_symbols', array(
            'AED' => '&#x62f;.&#x625;',
            'AFN' => '&#x60b;',
            'ALL' => 'L',
            'AMD' => 'AMD',
            'ANG' => '&fnof;',
            'AOA' => 'Kz',
            'ARS' => '&#36;',
            'AUD' => '&#36;',
            'AWG' => '&fnof;',
            'AZN' => 'AZN',
            'BAM' => 'KM',
            'BBD' => '&#36;',
            'BDT' => '&#2547;&nbsp;',
            'BGN' => '&#1083;&#1074;.',
            'BHD' => '.&#x62f;.&#x628;',
            'BIF' => 'Fr',
            'BMD' => '&#36;',
            'BND' => '&#36;',
            'BOB' => 'Bs.',
            'BRL' => '&#82;&#36;',
            'BSD' => '&#36;',
            'BTC' => '&#3647;',
            'BTN' => 'Nu.',
            'BWP' => 'P',
            'BYR' => 'Br',
            'BZD' => '&#36;',
            'CAD' => '&#36;',
            'CDF' => 'Fr',
            'CHF' => '&#67;&#72;&#70;',
            'CLP' => '&#36;',
            'CNY' => '&yen;',
            'COP' => '&#36;',
            'CRC' => '&#x20a1;',
            'CUC' => '&#36;',
            'CUP' => '&#36;',
            'CVE' => '&#36;',
            'CZK' => '&#75;&#269;',
            'DJF' => 'Fr',
            'DKK' => 'DKK',
            'DOP' => 'RD&#36;',
            'DZD' => '&#x62f;.&#x62c;',
            'EGP' => 'EGP',
            'ERN' => 'Nfk',
            'ETB' => 'Br',
            'EUR' => '&euro;',
            'FJD' => '&#36;',
            'FKP' => '&pound;',
            'GBP' => '&pound;',
            'GEL' => '&#x10da;',
            'GGP' => '&pound;',
            'GHS' => '&#x20b5;',
            'GIP' => '&pound;',
            'GMD' => 'D',
            'GNF' => 'Fr',
            'GTQ' => 'Q',
            'GYD' => '&#36;',
            'HKD' => '&#36;',
            'HNL' => 'L',
            'HRK' => 'Kn',
            'HTG' => 'G',
            'HUF' => '&#70;&#116;',
            'IDR' => 'Rp',
            'ILS' => '&#8362;',
            'IMP' => '&pound;',
            'INR' => '&#8377;',
            'IQD' => '&#x639;.&#x62f;',
            'IRR' => '&#xfdfc;',
            'ISK' => 'Kr.',
            'JEP' => '&pound;',
            'JMD' => '&#36;',
            'JOD' => '&#x62f;.&#x627;',
            'JPY' => '&yen;',
            'KES' => 'KSh',
            'KGS' => '&#x43b;&#x432;',
            'KHR' => '&#x17db;',
            'KMF' => 'Fr',
            'KPW' => '&#x20a9;',
            'KRW' => '&#8361;',
            'KWD' => '&#x62f;.&#x643;',
            'KYD' => '&#36;',
            'KZT' => 'KZT',
            'LAK' => '&#8365;',
            'LBP' => '&#x644;.&#x644;',
            'LKR' => '&#xdbb;&#xdd4;',
            'LRD' => '&#36;',
            'LSL' => 'L',
            'LYD' => '&#x644;.&#x62f;',
            'MAD' => '&#x62f;. &#x645;.',
            'MDL' => 'L',
            'MGA' => 'Ar',
            'MKD' => '&#x434;&#x435;&#x43d;',
            'MMK' => 'Ks',
            'MNT' => '&#x20ae;',
            'MOP' => 'P',
            'MRO' => 'UM',
            'MUR' => '&#x20a8;',
            'MVR' => '.&#x783;',
            'MWK' => 'MK',
            'MXN' => '&#36;',
            'MYR' => '&#82;&#77;',
            'MZN' => 'MT',
            'NAD' => '&#36;',
            'NGN' => '&#8358;',
            'NIO' => 'C&#36;',
            'NOK' => '&#107;&#114;',
            'NPR' => '&#8360;',
            'NZD' => '&#36;',
            'OMR' => '&#x631;.&#x639;.',
            'PAB' => 'B/.',
            'PEN' => 'S/.',
            'PGK' => 'K',
            'PHP' => '&#8369;',
            'PKR' => '&#8360;',
            'PLN' => '&#122;&#322;',
            'PRB' => '&#x440;.',
            'PYG' => '&#8370;',
            'QAR' => '&#x631;.&#x642;',
            'RMB' => '&yen;',
            'RON' => 'lei',
            'RSD' => '&#x434;&#x438;&#x43d;.',
            'RUB' => '&#8381;',
            'RWF' => 'Fr',
            'SAR' => '&#x631;.&#x633;',
            'SBD' => '&#36;',
            'SCR' => '&#x20a8;',
            'SDG' => '&#x62c;.&#x633;.',
            'SEK' => '&#107;&#114;',
            'SGD' => '&#36;',
            'SHP' => '&pound;',
            'SLL' => 'Le',
            'SOS' => 'Sh',
            'SRD' => '&#36;',
            'SSP' => '&pound;',
            'STD' => 'Db',
            'SYP' => '&#x644;.&#x633;',
            'SZL' => 'L',
            'THB' => '&#3647;',
            'TJS' => '&#x405;&#x41c;',
            'TMT' => 'm',
            'TND' => '&#x62f;.&#x62a;',
            'TOP' => 'T&#36;',
            'TRY' => '&#8378;',
            'TTD' => '&#36;',
            'TWD' => '&#78;&#84;&#36;',
            'TZS' => 'Sh',
            'UAH' => '&#8372;',
            'UGX' => 'UGX',
            'USD' => '&#36;',
            'UYU' => '&#36;',
            'UZS' => 'UZS',
            'VEF' => 'Bs F',
            'VND' => '&#8363;',
            'VUV' => 'Vt',
            'WST' => 'T',
            'XAF' => 'Fr',
            'XCD' => '&#36;',
            'XOF' => 'Fr',
            'XPF' => 'Fr',
            'YER' => '&#xfdfc;',
            'ZAR' => '&#82;',
            'ZMW' => 'ZK',
        ));
        $currency_symbol = isset($symbols[$currency]) ? $symbols[$currency] : '';
        return apply_filters('buymecoffee_currency_symbol', $currency_symbol, $currency);
    }
}
