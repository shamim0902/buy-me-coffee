<?php

namespace BuyMeCoffee\Builder\Methods\PayPal;

use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Services\PublicRequestGuard;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class IPN
{
    /**
     * Public PayPal IPN listener. Terminates the request.
     *
     * The decision itself lives in processIncomingIpn() so every outcome can be
     * asserted in tests without exit() ending the process.
     *
     * @return void
     */
    public function ipnVerificationProcess()
    {
        $outcome = $this->processIncomingIpn();

        if (!$outcome['terminate']) {
            // Historic behaviour for a request that is not an IPN at all: the
            // page carries on rendering exactly as before.
            return;
        }

        if (!empty($outcome['retry_after'])) {
            PublicRequestGuard::sendRetryAfter($outcome['retry_after']);
        }

        status_header((int) $outcome['http']);
        exit;
    }

    /**
     * Verify an incoming IPN with PayPal and apply it at most once.
     *
     * The mandatory `_notify-validate` round trip is unchanged: nothing about a
     * notification is believed, and no local state is touched, until PayPal
     * answers VERIFIED. Three controls sit around it.
     *
     * Before verification, the body is read under a ceiling and a budget keyed
     * on the exact posted body bounds how often one repeated payload can make
     * this site call PayPal. A second budget bounds unverified traffic per
     * address — but it is charged only when PayPal answers anything other than
     * VERIFIED, so a site receiving a large volume of genuine notifications from
     * PayPal's shared delivery addresses can never be throttled by it. Anything
     * refused is answered 429 so PayPal redelivers rather than losing it.
     *
     * After verification, the notification is claimed atomically on a
     * fingerprint of the whole authenticated message, so PayPal's own duplicate
     * deliveries apply their mutations exactly once while every distinct stage
     * of a payment is still applied.
     *
     * @param string|null $rawBody Posted body; read from the request when null.
     * @return array Outcome, see outcome().
     */
    public function processIncomingIpn($rawBody = null)
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below
        if (isset($_SERVER['REQUEST_METHOD']) && sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) != 'POST') {
            return self::outcome('not_a_post', 200, ['terminate' => false, 'message' => 'not a POST request']);
        }

        $config = PublicRequestGuard::routeConfig('ipn_paypal');

        // Cheap, pre-read: a declared body past the ceiling never reaches the
        // stream at all.
        $sizeCheck = PublicRequestGuard::checkSize('ipn_paypal');
        if (!$sizeCheck['allowed']) {
            return self::outcome($sizeCheck['code'], $sizeCheck['http'], [
                'message'     => $sizeCheck['message'],
                'retry_after' => $sizeCheck['retry_after'],
            ]);
        }

        // Without the guard table there is no atomic claim, so a redelivery
        // could be applied twice. 500-class is retryable; PayPal resends.
        if (!PublicRequestGuard::isAvailable()) {
            $decision = PublicRequestGuard::unavailable();

            return self::outcome($decision['code'], $decision['http'], [
                'message'     => $decision['message'],
                'retry_after' => $decision['retry_after'],
            ]);
        }

        // The declared length is a claim; this is the measurement. The read
        // stops one byte past the ceiling, so an oversized or unframed body can
        // never be held in memory in full.
        $read      = PublicRequestGuard::measureBody($config['max_bytes'], $rawBody);
        $post_data = $read['body'];

        if ($read['exceeded'] || !PublicRequestGuard::checkSize('ipn_paypal', $read['bytes'])['allowed']) {
            return self::outcome('request_too_large', 413, [
                'message' => 'the posted body is larger than this endpoint accepts',
            ]);
        }

        $encoded_data = 'cmd=_notify-validate';
        $arg_separator = ini_get('arg_separator.output');
        if ($post_data || strlen($post_data) > 0) {
            $encoded_data .= $arg_separator . $post_data;
        } else {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- PayPal IPN webhook callback
            if (empty($_POST)) {
                return self::outcome('empty_payload', 200, ['terminate' => false, 'message' => 'no IPN body']);
            } else {
                foreach ($_POST as $key => $value) {
                    $encoded_data .= $arg_separator . "$key=" . urlencode($value);
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification.Missing
        }

        // The reassembled body is measured too: the $_POST path above builds it
        // from an already-parsed payload, which the stream measurement never saw.
        if (!PublicRequestGuard::checkSize('ipn_paypal', strlen($encoded_data))['allowed']) {
            return self::outcome('request_too_large', 413, [
                'message' => 'the posted body is larger than this endpoint accepts',
            ]);
        }

        // Keyed on the exact body, so this only ever throttles the same
        // notification being posted again and again.
        $replayBudget = PublicRequestGuard::inspect('ipn_paypal', [
            'identifier' => 'body|' . PublicRequestGuard::fingerprint('ipn-body|' . $encoded_data),
            'buckets'    => ['payload'],
        ]);

        if (!$replayBudget['allowed']) {
            return self::outcome($replayBudget['code'], $replayBudget['http'], [
                'message'     => $replayBudget['message'],
                'retry_after' => $replayBudget['retry_after'],
            ]);
        }

        // Spent only by notifications PayPal refused to confirm, so real traffic
        // from a shared PayPal address never fills it.
        $invalidBudget = PublicRequestGuard::budgetRemaining('ipn_paypal_invalid');
        if (!$invalidBudget['allowed']) {
            return self::outcome($invalidBudget['code'], $invalidBudget['http'], [
                'message'     => $invalidBudget['message'],
                'retry_after' => $invalidBudget['retry_after'],
            ]);
        }

        // Every IPN, sandbox or live, must be echoed back to PayPal and answered
        // with VERIFIED before any transaction state is touched.
        $verified = $this->verifyWithPayPal($encoded_data);
        if (is_wp_error($verified) || $verified !== true) {
            PublicRequestGuard::chargeBudget('ipn_paypal_invalid');

            return self::outcome('verification_failed', 400, ['message' => 'PayPal did not answer VERIFIED']);
        }

        parse_str($encoded_data, $encoded_data_array);

        foreach ($encoded_data_array as $key => $value) {
            if (false !== strpos($key, 'amp;')) {
                $new_key = str_replace('&amp;', '&', $key);
                $new_key = str_replace('amp;', '&', $new_key);
                unset($encoded_data_array[$key]);
                $encoded_data_array[$new_key] = $value;
            }
        }
        $defaults = array(
            'txn_type' => '',
            'payment_status' => '',
            'custom' => ''
        );

        $encoded_data_array = wp_parse_args($encoded_data_array, $defaults);

        if (!is_array($encoded_data_array) || empty($encoded_data_array)) {
            return self::outcome('unreadable_payload', 200, ['terminate' => false, 'message' => 'IPN body could not be parsed']);
        }

        // Claimed only now: the notification is authentic, so it is safe to
        // record it as consumed. PayPal redelivers until it gets a 200, and each
        // stage of a payment arrives as its own notification, so the claim keys
        // on the identity of this whole message rather than on the transaction.
        $claim = PublicRequestGuard::claim('paypal_ipn', self::replayKey($encoded_data_array));

        if (!$claim['acquired']) {
            if (!$claim['available']) {
                $decision = PublicRequestGuard::unavailable();

                return self::outcome($decision['code'], $decision['http'], [
                    'message'     => $decision['message'],
                    'retry_after' => $decision['retry_after'],
                ]);
            }

            if ($claim['state'] === PublicRequestGuard::STATE_COMPLETED) {
                return self::outcome('duplicate_ipn', 200, [
                    'message' => 'this notification was already applied',
                ]);
            }

            // Still being applied by another worker. Answering 200 here would
            // let PayPal drop the notification while that worker may still fail.
            return self::outcome('ipn_in_progress', 409, [
                'message'     => 'this notification is already being applied',
                'retry_after' => $claim['retry_after'],
            ]);
        }

        $payment_id = 0;
        $transactions = new Transactions();

        if (!empty($encoded_data_array['parent_txn_id'])) {
            $payment_id = $transactions->getByPaymentId($encoded_data_array['parent_txn_id'],  'paypal');
        } elseif (!empty($encoded_data_array['txn_id'])) {
            $payment_id = $transactions->getByPaymentId($encoded_data_array['txn_id'], 'paypal');
        }

        if (empty($payment_id)) {
            $payment_id = !empty($encoded_data_array['custom']) ? absint($encoded_data_array['custom']) : 0;
        }

        try {
            do_action('buymecoffee_paypal_action_web_accept', $encoded_data_array, $payment_id);
        } catch (\Throwable $error) {
            // The notification was never fully applied, so its claim is given
            // back and PayPal's redelivery gets another chance at it.
            PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);

            return self::outcome('processing_failed', 500, [
                'transaction_id' => (int) $payment_id,
                'message'        => 'the verified notification could not be applied',
            ]);
        }

        PublicRequestGuard::completeClaim($claim['key'], $claim['owner'], [], 'paypal_ipn');

        return self::outcome('ipn_processed', 200, [
            'transaction_id' => (int) $payment_id,
            'message'        => 'verified notification applied',
        ]);
    }

    /**
     * Identity of one PayPal notification, for replay protection.
     *
     * PayPal stamps every notification it sends with its own `ipn_track_id`,
     * which identifies that message and nothing else, so it is used whenever it
     * is present. Without it the identity is a digest of the entire verified
     * payload, ordered canonically so a re-encoded body is still recognised as
     * the same message.
     *
     * A partial tuple of fields is not enough: two legitimate notifications can
     * share a transaction, a status and an amount — a repeated subscription
     * payment of the same value is exactly that — and keying on those alone
     * would silently discard the second one as a duplicate.
     *
     * The value is hashed again inside the guard before storage, so neither the
     * track id nor any payload field is ever written down in readable form.
     *
     * @param array $data Verified IPN fields.
     * @return string
     */
    private static function replayKey(array $data)
    {
        $trackId = isset($data['ipn_track_id']) ? trim((string) $data['ipn_track_id']) : '';
        if ($trackId !== '') {
            return 'track|' . $trackId;
        }

        $canonical = $data;

        // Our own verification marker is not part of what PayPal sent.
        unset($canonical['cmd']);

        self::canonicalize($canonical);

        return 'payload|' . hash('sha256', (string) wp_json_encode($canonical));
    }

    /**
     * Sort a decoded notification into a stable order, at every depth.
     *
     * @param array $data Payload, sorted in place.
     * @return void
     */
    private static function canonicalize(array &$data)
    {
        ksort($data);

        foreach ($data as &$value) {
            if (is_array($value)) {
                self::canonicalize($value);
            }
        }
        unset($value);
    }

    /**
     * Build the record of what an IPN request decided.
     *
     * @param string $code  Machine-readable outcome.
     * @param int    $http  Status code the endpoint answers with.
     * @param array  $extra Outcome details.
     * @return array
     */
    private static function outcome($code, $http, array $extra = [])
    {
        return array_merge([
            'code'           => $code,
            'http'           => (int) $http,
            'message'        => '',
            'transaction_id' => 0,
            'retry_after'    => 0,
            'terminate'      => true,
        ], $extra);
    }

    private function verifyWithPayPal($encodedData)
    {
        $verificationUrl = $this->getPaypalRedirect(true, true);

        $response = wp_safe_remote_post($verificationUrl, [
            'headers' => [
                'Connection' => 'Close',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $encodedData,
            'timeout' => 45,
            'httpversion' => '1.1',
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if (trim($body) !== 'VERIFIED') {
            return new \WP_Error('paypal_ipn_invalid', __('PayPal IPN verification failed', 'buy-me-coffee'));
        }

        return true;
    }

    private function isTestMode()
    {
        $settings = get_option('buymecoffee_payment_settings_paypal', []);
        if (isset($settings['payment_mode']) && $settings['payment_mode'] == 'live') {
            return false;
        }
        return true;
    }

    private function getPaypalRedirect($ssl_check = false, $ipn = false)
    {
        $protocol = 'http://';
        if (is_ssl() || !$ssl_check) {
            $protocol = 'https://';
        }

        // Check the current payment mode
        if ($this->isTestMode()) {
            // Test mode
            if ($ipn) {
                $paypal_uri = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';
            } else {
                $paypal_uri = $protocol . 'www.sandbox.paypal.com/cgi-bin/webscr';
            }
        } else {
            // Live mode
            if ($ipn) {
                $paypal_uri = 'https://ipnpb.paypal.com/cgi-bin/webscr';
            } else {
                $paypal_uri = $protocol . 'www.paypal.com/cgi-bin/webscr';
            }
        }
        return apply_filters('buymecoffee_ipn/paypal_url', $paypal_uri, $ssl_check, $ipn);
    }
}
