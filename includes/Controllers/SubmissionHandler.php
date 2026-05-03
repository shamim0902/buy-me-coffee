<?php

namespace BuyMeCoffee\Controllers;

use BuyMeCoffee\Helpers\ArrayHelper;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Models\Buttons;
use BuyMeCoffee\Models\MembershipLevel;
use BuyMeCoffee\Models\Transactions;

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
        if (!$paymentMethod || !isset($allMethods[$paymentMethod]) || empty($allMethods[$paymentMethod]['status'])) {
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

        $supporterName = ArrayHelper::get($form_data, 'wpm-supporter-name', 'Anonymous');
        $supporterEmail = ArrayHelper::get($form_data, 'wpm-supporter-email');
        $supporterMessage = ArrayHelper::get($form_data, 'wpm-supporter-message');

        if ($isRecurring === 'yes') {
            $this->validateRecurringRequest($paymentMethod, $template, (bool) $membershipLevel);
        }

        $form_data['payment_method']      = $paymentMethod;
        $form_data['payment_total']       = $paymentTotal;
        $form_data['is_recurring']        = $isRecurring;
        $form_data['recurring_interval']  = $recurringInterval;

        $hash = sanitize_text_field($this->getHash());
        $reference = ArrayHelper::get($form_data, '__buymecoffee_ref', '');

        $entries = array(
            'supporters_name' => $supporterName,
            'supporters_email' => $supporterEmail,
            'supporters_message' => $supporterMessage,
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

         if ($paymentTotal > 0) {
            do_action('buymecoffee_make_payment_' . $paymentMethod, $transactionId->id, $entryId, $form_data);
        }

        wp_send_json_success(array(
            'message' => __('Thanks for your support! <3', 'buy-me-coffee'),
            'submission_id' => $entryId
        ), 200);

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

        if ($paymentMethod !== 'stripe') {
            wp_send_json_error([
                'message' => __('Membership checkout is only available with Stripe.', 'buy-me-coffee')
            ], 400);
        }

        if (empty($allMethods['stripe']['status']) || $allMethods['stripe']['status'] !== 'yes') {
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

        $interval = isset($level->interval_type) ? sanitize_text_field($level->interval_type) : 'month';
        if (!in_array($interval, ['month', 'year'], true)) {
            $interval = 'month';
        }

        $paymentTotal = $levelPrice;
        $quantity = 1;
        $isRecurring = 'yes';
        $recurringInterval = $interval;

        $formData['bmc_level_id'] = $levelId;
        $formData['buymecoffee_amount'] = (string) ($levelPrice / 100);
        $formData['buymecoffee_quantity'] = '1';
        $formData['payment_total'] = $levelPrice;
        $formData['currency'] = $currency;
        $formData['is_recurring'] = 'yes';
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
