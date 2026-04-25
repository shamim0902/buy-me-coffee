<?php

namespace BuyMeCoffee\Controllers;

use BuyMeCoffee\Helpers\ArrayHelper;
use BuyMeCoffee\Helpers\PaymentHelper;
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
        $paymentMethod = isset($_REQUEST['payment_method']) ? sanitize_text_field(wp_unslash($_REQUEST['payment_method'])) : 'paypal';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form submission
        $paymentTotal = isset($_REQUEST['payment_total']) ? max(0, intval($_REQUEST['payment_total'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form submission
        $quantity = isset($_REQUEST['coffee_count']) ? max(1, intval($_REQUEST['coffee_count'])) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Public form submission
        $currency = isset($_REQUEST['currency']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['currency']))) : false;

        if (!$currency) {
            $currency = PaymentHelper::getCurrency();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Public form submission
        $isRecurring      = isset($_REQUEST['is_recurring']) ? sanitize_text_field(wp_unslash($_REQUEST['is_recurring'])) : 'no';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Public form submission
        $recurringInterval = isset($_REQUEST['recurring_interval']) ? sanitize_text_field(wp_unslash($_REQUEST['recurring_interval'])) : 'month';
        $allowedIntervals  = ['month', 'year'];
        if (!in_array($recurringInterval, $allowedIntervals, true)) {
            $recurringInterval = 'month';
        }

        $form_data['payment_method']      = $paymentMethod;
        $form_data['payment_total']       = $paymentTotal;
        $form_data['is_recurring']        = $isRecurring;
        $form_data['recurring_interval']  = $recurringInterval;

        $supporterName = ArrayHelper::get($form_data, 'wpm-supporter-name', 'Anonymous');
        $supporterEmail = ArrayHelper::get($form_data, 'wpm-supporter-email');
        $supporterMessage = ArrayHelper::get($form_data, 'wpm-supporter-message');

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
            \BuyMeCoffee\Classes\ActivityLogger::logPayment((int) $transactionId->id, 'payment_initiated', 'Payment initiated via ' . $paymentMethod, [
                'status'  => 'info',
                'context' => [
                    'transaction_id' => $transactionId->id,
                    'supporter_id'   => $entryId,
                    'amount'         => $paymentTotal,
                    'currency'       => $currency,
                    'method'         => $paymentMethod,
                ],
            ]);
            do_action('buymecoffee_make_payment_' . $paymentMethod, $transactionId->id, $entryId, $form_data);
        }

        wp_send_json_success(array(
            'message' => __('Thanks for your support! <3', 'buy-me-coffee'),
            'submission_id' => $entryId
        ), 200);

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
        $prefix = 'buymecoffee_' . time();
        $uid = uniqid($prefix);
        // now let's make a unique number from 1 to 999
        $uid .= wp_rand(1, 999);
        $uid = str_replace(array("'", '/', '?', '#', "\\"), '', $uid);
        return $uid;
    }

    private function canAllowLegacyPublicRequest($context = 'submission')
    {
        $allowLegacy = apply_filters('buymecoffee_allow_legacy_public_requests', true, $context, 'submit');
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

        return apply_filters('buymecoffee_allow_legacy_public_requests_without_referer', true, $context, 'submit');
    }

}
