<?php

namespace BuyMeCoffee\Controllers;

use BuyMeCoffee\Helpers\ArrayHelper;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Models\Buttons;
use BuyMeCoffee\Models\MembershipLevel;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Services\PublicRequestGuard;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class SubmissionHandler
{
    public function handleSubmission()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked manually below
        if (!isset($_REQUEST['buymecoffee_nonce'])) {
            if (!$this->canAllowLegacyPublicRequest('submission')) {
                wp_send_json_error(array(
                    'message' => __("Invalid request nonce", 'buy-me-coffee')
                ), 403);
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked manually
        if (isset($_REQUEST['buymecoffee_nonce'])) {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['buymecoffee_nonce'])), 'buymecoffee_nonce')) {
                wp_send_json_error(array(
                    'message' => __("Invalid request nonce", 'buy-me-coffee')
                ), 403);
            }
        }

        // Size ceiling and layered rate limits are consumed here: before the
        // payload is read, before a supporter row is written, and before any
        // gateway is asked to create a remote payment.
        PublicRequestGuard::enforce('submission');

        $idempotencyKey = $this->resolveIdempotencyKey();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form payload check
        if (!isset($_REQUEST['form_data'])) {
            wp_send_json_error(array(
                'message' => __("Invalid form data", 'buy-me-coffee')
            ), 403);
        }

        //Let's sanitize all data
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Form data is sanitized in sanitizeFormData method
        $form_data = $this->sanitizeFormData(wp_unslash($_REQUEST['form_data']));

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Public form submission
        $paymentMethod = isset($_REQUEST['payment_method']) ? sanitize_text_field(wp_unslash($_REQUEST['payment_method'])) : '';

        $allMethods = PaymentHandler::getAllMethods();
        if (!$paymentMethod || !isset($allMethods[$paymentMethod]) || !PaymentHandler::isMethodEnabled($allMethods[$paymentMethod])) {
            wp_send_json_error([
                'message' => __('Invalid or disabled payment method.', 'buy-me-coffee')
            ], 400);
        }

        $template = (new Buttons())->getButton();
        $currency = strtoupper(sanitize_text_field(ArrayHelper::get($template, 'currency', PaymentHelper::getCurrency())));

        $paymentData = $this->calculatePaymentData($form_data, $template);
        $paymentTotal = $paymentData['payment_total'];
        $quantity = $paymentData['coffee_count'];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Public form submission
        $isRecurring      = isset($_REQUEST['is_recurring']) ? sanitize_text_field(wp_unslash($_REQUEST['is_recurring'])) : 'no';
        $isRecurring      = $isRecurring === 'yes' ? 'yes' : 'no';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Public form submission
        $recurringInterval = isset($_REQUEST['recurring_interval']) ? sanitize_text_field(wp_unslash($_REQUEST['recurring_interval'])) : 'month';
        $allowedIntervals  = ['month', 'year'];
        if (!in_array($recurringInterval, $allowedIntervals, true)) {
            $recurringInterval = 'month';
        }

        $membershipLevel = $this->bindMembershipCheckout(
            $form_data,
            $paymentMethod,
            $paymentTotal,
            $quantity,
            $isRecurring,
            $recurringInterval,
            $currency,
            $allMethods
        );

        if ($isRecurring === 'yes') {
            $this->validateRecurringRequest($paymentMethod, $template, (bool) $membershipLevel);
        }

        $form_data['payment_method']      = $paymentMethod;
        $form_data['payment_total']       = $paymentTotal;
        $form_data['is_recurring']        = $isRecurring;
        $form_data['recurring_interval']  = $recurringInterval;

        // Everything above this line only reads and validates. A request refused
        // up to here has consumed no idempotency key, so the same attempt can be
        // corrected and retried with the same key.
        $claim = $this->claimSubmission($idempotencyKey);

        try {
            $this->createSubmission($form_data, $paymentMethod, $paymentTotal, $quantity, $currency, $isRecurring, $claim);
        } catch (\Throwable $error) {
            // Nothing durable was produced, so the key must not stay burned. A
            // claim completed inside createSubmission() is already settled and
            // is left exactly as it is.
            if ($claim) {
                PublicRequestGuard::releaseClaim($claim['key'], $claim['owner']);
            }

            throw $error;
        }
    }

    /**
     * Write the supporter and transaction rows and hand off to the gateway.
     *
     * Reached only once per successful idempotency key: it is the whole
     * side-effecting half of a submission.
     *
     * @param array      $form_data     Sanitized form payload.
     * @param string     $paymentMethod Selected gateway route.
     * @param int        $paymentTotal  Amount in ×100 minor units.
     * @param int        $quantity      Coffee count.
     * @param string     $currency      Uppercase currency code.
     * @param string     $isRecurring   'yes' or 'no'.
     * @param array|null $claim         Idempotency lease held for this attempt.
     * @return void
     */
    private function createSubmission($form_data, $paymentMethod, $paymentTotal, $quantity, $currency, $isRecurring, $claim = null)
    {
        $hash = sanitize_text_field($this->getHash());
        $reference = ArrayHelper::get($form_data, '__buymecoffee_ref', '');

        $entries = array(
            'supporters_name' => ArrayHelper::get($form_data, 'wpm-supporter-name', 'Anonymous'),
            'supporters_email' => ArrayHelper::get($form_data, 'wpm-supporter-email'),
            'supporters_message' => ArrayHelper::get($form_data, 'wpm-supporter-message'),
            'form_data_raw' => maybe_serialize($form_data),
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'payment_status' => 'pending',
            'entry_hash' => $hash,
            'payment_total' => $paymentTotal,
            'coffee_count' => $quantity,
            'reference' => $reference,
            'status' => 'new',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        $entries = apply_filters('buymecoffee_supporter_entries', $entries);

        do_action('buymecoffee_before_supporters_data_insert', $entries);

        // DO ENTRIES INSERT
        $entryId = buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert($entries);

        do_action('buymecoffee_after_supporters_data_insert', $entries);

        $transaction = array(
            'entry_hash'       => $hash,
            'entry_id'         => $entryId,
            'charge_id'        => '',
            'payment_method'   => sanitize_text_field($paymentMethod),
            'payment_total'    => $paymentTotal,
            'currency'         => $currency,
            'status'           => 'pending',
            'transaction_type' => $isRecurring === 'yes' ? 'recurring' : 'one_time',
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
        );

         $transactionTable = (new Transactions())->getQuery();
         $transactionTable->insert($transaction);

         $transactionId =
             $transactionTable->select(['id'])
            ->where('entry_hash', $hash)
            ->where('entry_id', $entryId)
            ->first();

        if ($claim) {
            // Durable local state now exists for this attempt, so it may never
            // run again — whatever the gateway does next belongs to this one
            // submission. Settling the lease here rather than after the response
            // matters because the gateway handoff below ends the request with
            // die(), which no later statement would survive.
            //
            // Nothing about the response is stored: a gateway handoff carries
            // client secrets and provider URLs, and the guard table is not a
            // place to keep them. A retry of a completed key is answered with a
            // conflict instead, so it can never create a second payment.
            PublicRequestGuard::completeClaim($claim['key'], $claim['owner'], [], 'submission');
        }

         if ($paymentTotal > 0) {
            do_action('buymecoffee_make_payment_' . $paymentMethod, $transactionId->id, $entryId, $form_data);
        }

        wp_send_json_success(array(
            'message' => __('Thanks for your support! <3', 'buy-me-coffee'),
            'submission_id' => $entryId
        ), 200);

    }

    /**
     * Read the idempotency key the browser generated for this attempt.
     *
     * The key is mandatory. Without one, this endpoint is exactly what it was
     * before: an unauthenticated route where a double click, a retried request
     * or a scripted burst each create their own supporter row and their own
     * remote payment. An attacker would simply omit the field, so accepting a
     * keyless submission would make the whole protection optional.
     *
     * The escape hatch is deliberately narrow, single-purpose and off: it exists
     * only for a host that has to keep serving a client which cannot produce a
     * key at all, and turning it on knowingly restores the old behaviour for
     * that site alone.
     *
     * @return string Empty only when the escape hatch is enabled.
     */
    private function resolveIdempotencyKey()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified above.
        $raw = isset($_REQUEST['idempotency_key']) ? wp_unslash($_REQUEST['idempotency_key']) : '';
        $key = is_string($raw) ? sanitize_text_field($raw) : '';

        if ($key === '') {
            if (apply_filters('buymecoffee_allow_unkeyed_submission', false)) {
                return '';
            }

            wp_send_json_error([
                'message' => __('Your browser could not start a secure donation. Please reload the page and try again.', 'buy-me-coffee'),
                'code'    => 'idempotency_key_required',
            ], 400);
        }

        if (!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $key)) {
            wp_send_json_error([
                'message' => __('Invalid submission key. Please reload the page and try again.', 'buy-me-coffee'),
                'code'    => 'invalid_idempotency_key',
            ], 400);
        }

        return $key;
    }

    /**
     * Claim an attempt's key so only one of its retries may create anything.
     *
     * The cryptographically random key identifies the attempt itself, independent
     * of the caller's current address. Mobile and proxied clients can change IPs
     * between a dropped response and a retry; scoping the claim to the address
     * would let that retry create a second donation. No response data is replayed,
     * so possession of a key can reveal only that the attempt already exists.
     *
     * @param string $idempotencyKey Key supplied by the browser.
     * @return array|null Claim record, or null when the escape hatch is enabled.
     */
    private function claimSubmission($idempotencyKey)
    {
        if ($idempotencyKey === '') {
            return null;
        }

        $claim = PublicRequestGuard::claim(
            'submission',
            $idempotencyKey
        );

        if ($claim['acquired']) {
            return $claim;
        }

        if (!$claim['available']) {
            $decision = PublicRequestGuard::unavailable();
            PublicRequestGuard::sendRetryAfter($decision['retry_after']);

            wp_send_json_error([
                'message' => $decision['message'],
                'code'    => $decision['code'],
            ], $decision['http']);
        }

        if ($claim['state'] === PublicRequestGuard::STATE_COMPLETED) {
            wp_send_json_error([
                'message' => __('This donation has already been submitted. Please reload the page to start a new one.', 'buy-me-coffee'),
                'code'    => 'submission_already_completed',
            ], 409);
        }

        PublicRequestGuard::sendRetryAfter($claim['retry_after']);

        wp_send_json_error([
            'message' => __('This donation is already being processed. Please wait a moment before trying again.', 'buy-me-coffee'),
            'code'    => 'submission_in_progress',
        ], 409);
    }

    private function calculatePaymentData($formData, $template)
    {
        $defaultAmount = floatval(ArrayHelper::get($template, 'defaultAmount', 5));
        if ($defaultAmount <= 0) {
            $defaultAmount = 5.0;
        }

        $unitAmount = floatval(ArrayHelper::get($formData, 'buymecoffee_amount', $defaultAmount));
        if ($unitAmount <= 0) {
            $unitAmount = $defaultAmount;
        }

        $quantityRaw = ArrayHelper::get($formData, 'radio-group');
        if ($quantityRaw === null || $quantityRaw === '') {
            $quantityRaw = ArrayHelper::get($formData, 'buymecoffee_quantity', 1);
        }

        $quantity = intval($quantityRaw);
        if ($quantity < 1) {
            $quantity = 1;
        }
        if ($quantity > 1000) {
            $quantity = 1000;
        }

        return [
            'payment_total' => max(0, (int) round($unitAmount * 100 * $quantity)),
            'coffee_count'  => $quantity
        ];
    }

    private function bindMembershipCheckout(
        array &$formData,
        string &$paymentMethod,
        int &$paymentTotal,
        int &$quantity,
        string &$isRecurring,
        string &$recurringInterval,
        string $currency,
        array $allMethods
    ): ?object {
        $levelId = !empty($formData['bmc_level_id']) ? absint($formData['bmc_level_id']) : 0;
        if (!$levelId) {
            return null;
        }

        if (!MonetizationController::isMembershipActive()) {
            wp_send_json_error([
                'message' => __('New memberships are currently paused.', 'buy-me-coffee')
            ], 400);
        }

        if ($paymentMethod !== 'stripe') {
            wp_send_json_error([
                'message' => __('Membership checkout is only available with Stripe.', 'buy-me-coffee')
            ], 400);
        }

        if (empty($allMethods['stripe']) || !PaymentHandler::isMethodEnabled($allMethods['stripe'])) {
            wp_send_json_error([
                'message' => __('Stripe must be active for membership checkout.', 'buy-me-coffee')
            ], 400);
        }

        $level = (new MembershipLevel())->find($levelId);
        if (!$level || $level->status !== 'active') {
            wp_send_json_error([
                'message' => __('Selected membership level is not available.', 'buy-me-coffee')
            ], 400);
        }

        $levelPrice = isset($level->price) ? absint($level->price) : 0;
        if ($levelPrice <= 0) {
            wp_send_json_error([
                'message' => __('Selected membership level has an invalid price.', 'buy-me-coffee')
            ], 400);
        }

        $supporterEmail = sanitize_email(ArrayHelper::get($formData, 'wpm-supporter-email', ''));
        if (!$supporterEmail || !is_email($supporterEmail)) {
            wp_send_json_error([
                'message' => __('A valid email address is required for membership access.', 'buy-me-coffee')
            ], 400);
        }
        $formData['wpm-supporter-email'] = $supporterEmail;

        $interval = isset($level->interval_type) ? sanitize_text_field($level->interval_type) : 'month';
        if (!in_array($interval, ['month', 'year'], true)) {
            $interval = 'month';
        }

        $paymentType = isset($level->payment_type) ? sanitize_key($level->payment_type) : 'subscription';
        if (!in_array($paymentType, ['one_time', 'subscription'], true)) {
            $paymentType = 'subscription';
        }

        $paymentTotal = $levelPrice;
        $quantity = 1;
        $isRecurring = $paymentType === 'subscription' ? 'yes' : 'no';
        $recurringInterval = $interval;

        $formData['bmc_level_id'] = $levelId;
        $formData['bmc_level_payment_type'] = $paymentType;
        $formData['buymecoffee_amount'] = (string) ($levelPrice / 100);
        $formData['buymecoffee_quantity'] = '1';
        $formData['payment_total'] = $levelPrice;
        $formData['currency'] = $currency;
        $formData['is_recurring'] = $isRecurring;
        $formData['recurring_interval'] = $interval;

        return $level;
    }

    private function validateRecurringRequest($paymentMethod, $template, $isMembershipCheckout = false)
    {
        if ($paymentMethod !== 'stripe') {
            wp_send_json_error([
                'message' => __('Recurring donations are only available with Stripe.', 'buy-me-coffee')
            ], 400);
        }

        if (!$isMembershipCheckout && ArrayHelper::get($template, 'allow_recurring', 'no') !== 'yes') {
            wp_send_json_error([
                'message' => __('Recurring donations are not enabled.', 'buy-me-coffee')
            ], 400);
        }

    }

    private function sanitizeFormData($formDataArray)
    {
        if (!is_array($formDataArray)) {
            return [];
        }

        $sanitizedData = [];
        foreach ($formDataArray as $value) {
            if (!is_array($value) || !isset($value['name'])) {
                continue;
            }

            $name = sanitize_text_field($value['name']);
            $rawValue = isset($value['value']) ? $value['value'] : '';

            if ($name === 'wpm-supporter-email') {
                $sanitizedData[$name] = sanitize_email($rawValue);
            } else {
                $sanitizedData[$name] = sanitize_text_field($rawValue);
            }
        }
        return $sanitizedData;
    }

    private function getHash()
    {
        try {
            return 'buymecoffee_' . bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            return 'buymecoffee_' . wp_generate_password(32, false, false);
        }
    }

    private function canAllowLegacyPublicRequest($context = 'submission')
    {
        $allowLegacy = apply_filters('buymecoffee_allow_legacy_public_requests', false, $context, 'submit');
        if (!$allowLegacy) {
            return false;
        }

        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$siteHost) {
            return false;
        }

        $hostsToCheck = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Header values are sanitized below
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Header values are sanitized below
            $hostsToCheck[] = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'])), PHP_URL_HOST);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Header values are sanitized below
        if (!empty($_SERVER['HTTP_REFERER'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Header values are sanitized below
            $hostsToCheck[] = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])), PHP_URL_HOST);
        }

        foreach ($hostsToCheck as $host) {
            if (!empty($host) && strtolower($host) === strtolower($siteHost)) {
                return true;
            }
        }

        return apply_filters('buymecoffee_allow_legacy_public_requests_without_referer', false, $context, 'submit');
    }

}
