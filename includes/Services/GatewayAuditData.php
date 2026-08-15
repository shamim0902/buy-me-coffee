<?php

namespace BuyMeCoffee\Services;

use BuyMeCoffee\Helpers\ArrayHelper as Arr;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * The single writer of transaction payment notes.
 *
 * A gateway response is a whole customer record: it carries the payer's name,
 * email and address, the merchant's own metadata, and — for a Stripe
 * PaymentIntent — a client_secret that is a bearer credential for that payment.
 * Storing it verbatim turned every note into a copy of the provider's record,
 * kept forever in a column that any later feature might read back or export.
 *
 * So a note is never derived from the response by removing things; it is built
 * from a fixed list of operational fields the plugin actually depends on. A
 * field the plugin does not name is not stored, which is what makes a provider
 * adding new fields — or an attacker controlling a webhook body — unable to put
 * anything new into the column.
 *
 * Two backstops sit under every projection, so a future projector cannot widen
 * the contract by accident:
 *  - a key naming an identity, a credential or a raw payload is dropped, and
 *  - a value that looks like a secret is dropped,
 * whatever the projection asked for. Nested arrays and objects are never
 * stored at all: a note is a flat list of scalars.
 */
class GatewayAuditData
{
    /** Longest stored string; provider ids and statuses are far shorter. */
    const MAX_STRING_LENGTH = 255;

    /**
     * Keys a note may never carry, whatever a projector calls them: identity
     * and contact data, credentials, merchant metadata, and raw payloads.
     */
    const FORBIDDEN_KEY_PATTERN = '/(secret|token|password|passwd|credential|access|refresh|signature|api_?key|private|customer|payer|recipient|receiver|email|phone|address|shipping|billing_details|metadata|raw|body|payload|card|cvc|cvv|iban|ssn|tax)/i';

    /** Values that read as a credential, whatever key they arrived under. */
    const SECRET_VALUE_PATTERN = '/(client_secret|_secret|\b(?:sk|rk|pk)_(?:live|test)_|bearer\s|begin [a-z ]*private key)/i';

    /**
     * Note for a Stripe PaymentIntent recorded against a transaction.
     *
     * `status` is required: PaymentHelper::replayConfirmationResult() answers a
     * repeated browser confirmation from this note instead of calling Stripe
     * again, and reports the intent status it finds here.
     *
     * @param array|object $intent Stripe PaymentIntent.
     * @return string JSON note.
     */
    public static function stripePaymentIntentNote($intent)
    {
        $intent = self::toArray($intent);

        $amount = Arr::get($intent, 'amount');
        if (!$amount) {
            $amount = Arr::get($intent, 'amount_received');
        }

        return self::encode([
            'gateway'     => 'stripe',
            'object'      => 'payment_intent',
            'id'          => self::text(Arr::get($intent, 'id')),
            'status'      => self::text(Arr::get($intent, 'status')),
            'amount'      => self::amount($amount),
            'currency'    => self::text(Arr::get($intent, 'currency')),
            'livemode'    => !empty(Arr::get($intent, 'livemode')),
            'recorded_at' => current_time('mysql'),
        ]);
    }

    /**
     * Note for a Stripe invoice-derived transaction (renewals and remote sync).
     *
     * `invoice_id` is required and is matched as a JSON substring by the
     * renewal dedupe queries in StripeSubscriptions, so it stays a plain
     * string under exactly that key.
     *
     * @param string $invoiceId Stripe invoice id.
     * @param array  $context   Optional event_type, billing_reason, fetched_from.
     * @return string JSON note.
     */
    public static function stripeInvoiceNote($invoiceId, $context = [])
    {
        $context = is_array($context) ? $context : [];

        $note = [
            'gateway'    => 'stripe',
            'object'     => 'invoice',
            'invoice_id' => self::text($invoiceId),
        ];

        foreach (['event_type', 'billing_reason', 'fetched_from'] as $key) {
            if (isset($context[$key])) {
                $note[$key] = self::text($context[$key]);
            }
        }

        $note['recorded_at'] = current_time('mysql');

        return self::encode($note);
    }

    /**
     * Note for a captured PayPal order.
     *
     * `id` is required: PayPal::settledOrderMatches() recognises a donor
     * replaying their confirmation by comparing the order id they present with
     * the one recorded here, because the transaction's charge_id holds the
     * capture id rather than the order id.
     *
     * @param array|object $order PayPal order resource.
     * @return string JSON note.
     */
    public static function payPalOrderNote($order)
    {
        $order = self::toArray($order);

        return self::encode([
            'gateway'     => 'paypal',
            'object'      => 'order',
            'id'          => self::text(Arr::get($order, 'id')),
            'status'      => self::text(Arr::get($order, 'status')),
            'capture_id'  => self::text(Arr::get($order, 'purchase_units.0.payments.captures.0.id')),
            'amount'      => self::text(Arr::get($order, 'purchase_units.0.amount.value')),
            'currency'    => self::text(Arr::get($order, 'purchase_units.0.amount.currency_code')),
            'recorded_at' => current_time('mysql'),
        ]);
    }

    /**
     * Note for a verified PayPal IPN.
     *
     * Deliberately carries no `id` key: settledOrderMatches() reads `id` as the
     * PayPal *order* id, and an IPN never describes one. The transaction and
     * status fields are what the IPN status handling reasons about.
     *
     * @param array $data Verified IPN fields.
     * @return string JSON note.
     */
    public static function payPalIpnNote($data)
    {
        $data = self::toArray($data);

        return self::encode([
            'gateway'        => 'paypal',
            'object'         => 'ipn',
            'txn_id'         => self::text(Arr::get($data, 'txn_id')),
            'txn_type'       => self::text(Arr::get($data, 'txn_type')),
            'parent_txn_id'  => self::text(Arr::get($data, 'parent_txn_id')),
            'payment_status' => self::text(Arr::get($data, 'payment_status')),
            'pending_reason' => self::text(Arr::get($data, 'pending_reason')),
            'mc_gross'       => self::text(Arr::get($data, 'mc_gross')),
            'mc_currency'    => self::text(Arr::get($data, 'mc_currency')),
            'recorded_at'    => current_time('mysql'),
        ]);
    }

    /**
     * Note for a completed refund.
     *
     * @param array $meta Refund result: refund_id and status.
     * @return string JSON note.
     */
    public static function refundNote($meta)
    {
        $meta = self::toArray($meta);

        return self::encode([
            'object'        => 'refund',
            'refund_id'     => self::text(Arr::get($meta, 'refund_id')),
            'refund_status' => self::text(Arr::get($meta, 'status')),
            'refunded_at'   => current_time('mysql'),
        ]);
    }

    /**
     * Apply both backstops to an already-projected note.
     *
     * @param array $note Projected fields.
     * @return array Storable fields.
     */
    public static function project($note)
    {
        if (!is_array($note)) {
            return [];
        }

        $safe = [];

        foreach ($note as $key => $value) {
            $key = is_string($key) ? $key : (string) $key;

            if ($key === '' || preg_match(self::FORBIDDEN_KEY_PATTERN, $key)) {
                continue;
            }

            // A note is a flat list of scalars: nothing nested is ever stored,
            // so a whole provider sub-object cannot ride along under a name
            // that happens to pass the key check.
            if (is_array($value) || is_object($value)) {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
                continue;
            }

            $string = self::text($value);

            if ($string !== '' && preg_match(self::SECRET_VALUE_PATTERN, $string)) {
                continue;
            }

            $safe[$key] = $string;
        }

        return $safe;
    }

    /**
     * @param array $note Projected fields.
     * @return string JSON note.
     */
    public static function encode($note)
    {
        return (string) wp_json_encode(self::project($note));
    }

    /**
     * @param mixed $value Provider value.
     * @return string Bounded, sanitized text.
     */
    private static function text($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        $string = sanitize_text_field((string) $value);

        if (strlen($string) > self::MAX_STRING_LENGTH) {
            $string = substr($string, 0, self::MAX_STRING_LENGTH);
        }

        return $string;
    }

    /**
     * @param mixed $value Provider amount.
     * @return int
     */
    private static function amount($value)
    {
        if (is_array($value) || is_object($value)) {
            return 0;
        }

        return (int) round((float) $value);
    }

    /**
     * @param mixed $payload Provider payload.
     * @return array
     */
    private static function toArray($payload)
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload)) {
            $decoded = json_decode((string) wp_json_encode($payload), true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
