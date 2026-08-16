<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Translates an authenticated Stripe event into the local payment status it means.
 *
 * A Stripe object's own `status` is not a payment outcome: a fully refunded
 * Charge still reports `status: succeeded`, so reading that field as "paid"
 * turns a refund webhook into revenue and re-grants membership access that was
 * already revoked. Each supported event type is therefore mapped explicitly,
 * from the fields that actually describe the outcome (`refunded`,
 * `amount_refunded`, `payment_status`, `paid`), and anything unsupported,
 * mismatched or ambiguous fails closed so no local state is touched.
 */
class StripeEventMap
{
    /**
     * Events handled by the subscription lifecycle rather than by a one-time
     * transaction status change.
     */
    const SUBSCRIPTION_EVENTS = [
        'invoice.payment_succeeded',
        'customer.subscription.deleted',
        'customer.subscription.updated',
    ];

    /**
     * Events that can move a single transaction's local status, mapped to the
     * Stripe object type each one must carry.
     */
    const ONE_TIME_EVENTS = [
        'charge.succeeded'           => 'charge',
        'charge.refunded'            => 'charge',
        'checkout.session.completed' => 'checkout.session',
        'invoice.paid'               => 'invoice',
    ];

    /**
     * Every event type the webhook accepts.
     *
     * @return string[]
     */
    public static function supportedEvents()
    {
        return array_merge(self::SUBSCRIPTION_EVENTS, array_keys(self::ONE_TIME_EVENTS));
    }

    /**
     * @param string $eventType Stripe event type.
     * @return bool
     */
    public static function isSupported($eventType)
    {
        return in_array((string) $eventType, self::supportedEvents(), true);
    }

    /**
     * @param string $eventType Stripe event type.
     * @return bool
     */
    public static function isSubscriptionEvent($eventType)
    {
        return in_array((string) $eventType, self::SUBSCRIPTION_EVENTS, true);
    }

    /**
     * @param string $eventType Stripe event type.
     * @return bool
     */
    public static function isOneTimeEvent($eventType)
    {
        return array_key_exists((string) $eventType, self::ONE_TIME_EVENTS);
    }

    /**
     * Normalize a Stripe event into an object graph.
     *
     * The API returns arrays and the webhook payload decodes to objects; both
     * reach this class, so everything is converted to one shape before any
     * field is read.
     *
     * @param array|object $event Stripe event.
     * @return object|null Null when the value cannot be an event.
     */
    public static function normalize($event)
    {
        if (!is_array($event) && !is_object($event)) {
            return null;
        }

        $normalized = json_decode(wp_json_encode($event));

        return is_object($normalized) ? $normalized : null;
    }

    /**
     * The order reference (`metadata.ref_id`) stamped on the event's object when
     * the payment was created.
     *
     * @param array|object $event Authenticated Stripe event.
     * @return string Empty when the event carries no usable reference.
     */
    public static function orderReference($event)
    {
        $event = self::normalize($event);

        if (!$event || empty($event->type) || !self::isOneTimeEvent($event->type)) {
            return '';
        }

        if (!isset($event->data->object->metadata->ref_id)) {
            return '';
        }

        $reference = $event->data->object->metadata->ref_id;

        return is_scalar($reference) ? sanitize_text_field((string) $reference) : '';
    }

    /**
     * The local transaction status an authenticated event means.
     *
     * @param array|object $event Authenticated Stripe event.
     * @return string|\WP_Error Local status, or an error describing why the
     *                          event must not change anything.
     */
    public static function resolveLocalStatus($event)
    {
        $event = self::normalize($event);

        if (!$event || empty($event->type) || !isset($event->data->object) || !is_object($event->data->object)) {
            return new \WP_Error(
                'bmc_stripe_malformed_event',
                __('The Stripe event carries no usable data object.', 'buy-me-coffee')
            );
        }

        $eventType = sanitize_text_field($event->type);
        $object    = $event->data->object;

        if (!self::isOneTimeEvent($eventType)) {
            return new \WP_Error(
                'bmc_stripe_unsupported_event',
                sprintf(
                    /* translators: %s: Stripe event type. */
                    __('Stripe event type %s does not map to a payment status.', 'buy-me-coffee'),
                    $eventType
                )
            );
        }

        $objectMismatch = self::requireObjectType($object, $eventType);
        if ($objectMismatch) {
            return $objectMismatch;
        }

        switch ($eventType) {
            case 'charge.refunded':
                // A refunded charge keeps status "succeeded"; the refund itself is
                // only visible in these two fields.
                if (empty($object->refunded) && self::amountRefunded($object) <= 0) {
                    return new \WP_Error(
                        'bmc_stripe_refund_unconfirmed',
                        __('The charge.refunded event reports no refunded amount.', 'buy-me-coffee')
                    );
                }

                return 'refunded';

            case 'charge.succeeded':
                if (self::field($object, 'status') !== 'succeeded') {
                    return new \WP_Error(
                        'bmc_stripe_charge_not_succeeded',
                        __('The charge did not succeed.', 'buy-me-coffee')
                    );
                }

                // A charge that already carries a refund is ambiguous: never read
                // it as a fresh payment.
                if (!empty($object->refunded) || self::amountRefunded($object) > 0) {
                    return new \WP_Error(
                        'bmc_stripe_charge_already_refunded',
                        __('The succeeded charge has already been refunded.', 'buy-me-coffee')
                    );
                }

                return 'paid';

            case 'checkout.session.completed':
                if (self::field($object, 'payment_status') !== 'paid') {
                    return new \WP_Error(
                        'bmc_stripe_session_unpaid',
                        __('The completed checkout session was not paid.', 'buy-me-coffee')
                    );
                }

                return 'paid';

            case 'invoice.paid':
                if (empty($object->paid) || self::field($object, 'status') !== 'paid') {
                    return new \WP_Error(
                        'bmc_stripe_invoice_unpaid',
                        __('The invoice is not marked paid.', 'buy-me-coffee')
                    );
                }

                return 'paid';
        }

        return new \WP_Error(
            'bmc_stripe_unsupported_event',
            sprintf(
                /* translators: %s: Stripe event type. */
                __('Stripe event type %s does not map to a payment status.', 'buy-me-coffee'),
                $eventType
            )
        );
    }

    /**
     * Reject an event whose data object is not the kind that event type carries,
     * so a `charge.refunded` wrapper around some other object cannot be read
     * with charge semantics.
     *
     * @param object $object    Event data object.
     * @param string $eventType Stripe event type.
     * @return \WP_Error|null
     */
    private static function requireObjectType($object, $eventType)
    {
        $expected = self::ONE_TIME_EVENTS[$eventType];
        $actual   = self::field($object, 'object');

        if ($actual === $expected) {
            return null;
        }

        return new \WP_Error(
            'bmc_stripe_object_mismatch',
            sprintf(
                /* translators: 1: expected Stripe object type, 2: received object type, 3: Stripe event type. */
                __('Expected a %1$s object on %3$s but received "%2$s".', 'buy-me-coffee'),
                $expected,
                $actual,
                $eventType
            )
        );
    }

    /**
     * @param object $object Event data object.
     * @return int Refunded amount in Stripe's minor units.
     */
    private static function amountRefunded($object)
    {
        if (!isset($object->amount_refunded) || !is_scalar($object->amount_refunded)) {
            return 0;
        }

        return (int) $object->amount_refunded;
    }

    /**
     * @param object $object Event data object.
     * @param string $key    Field name.
     * @return string Empty when absent or not scalar.
     */
    private static function field($object, $key)
    {
        if (!isset($object->$key) || !is_scalar($object->$key)) {
            return '';
        }

        return sanitize_text_field((string) $object->$key);
    }
}
