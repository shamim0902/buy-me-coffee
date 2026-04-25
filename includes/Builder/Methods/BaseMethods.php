<?php

namespace BuyMeCoffee\Builder\Methods;

use BuyMeCoffee\Helpers\BuilderHelper;
use BuyMeCoffee\Helpers\PaymentHelper;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

abstract class BaseMethods
{
    public $title = '';
    public $method = '';
    public $description = '';
    public $image = '';

    public static $methods = array();

    public function __construct($title, $method, $description, $image)
    {
        $this->title = $title;
        $this->method = $method;
        $this->description = $description;
        $this->image = $image;

        $this->registerHooks($method);

        add_action('buy-me-coffee/get_payment_settings_' . $this->method, array($this, 'getPaymentSettings'), 10, 1);

        add_filter('buymecoffee_before_save_' . $this->method, array($this, 'sanitize'), 10, 2);

        add_filter('buymecoffee_get_all_methods', array($this, 'getAllMethods'), 10, 1);

        add_action('wp_ajax_nopriv_buymecoffee_payment_confirmation_'. $this->method, array($this, 'paymentConfirmation'));

        add_action('wp_ajax_buymecoffee_payment_confirmation_' . $this->method, array($this, 'paymentConfirmation'));

        add_filter('buymecoffee/payment/get_transaction_url_' . $this->method, array($this, 'getTransactionUrl'), 10, 2);
    }

    public function getAllMethods()
    {
        static::$methods[$this->method] = array(
            'title' => $this->title,
            'route' => $this->method,
            'description' => $this->description,
            'image' => $this->image,
            "status" => $this->isEnabled(),
        );
        return static::$methods;
    }

    public function registerHooks($method)
    {
        add_action('buymecoffee_render_component_' . $method, array($this, 'render'), 10, 1);
    }

    abstract public function isEnabled();

    abstract public function getTransactionUrl($url, $transaction);

    public function getMode()
    {
        $paymentSettings = $this->getSettings();
        return ($paymentSettings['payment_mode'] == 'live') ? 'live' : 'test';
    }

    public function uniqueId($id = '')
    {
        return esc_attr(BuilderHelper::getFormDynamicClass() . '_' . $id);
    }

    public function paymentConfirmation()
    {
        //return if no module extend
    }

    protected function verifyPublicRequestNonce()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce validated manually
        if (!isset($_REQUEST['buymecoffee_nonce'])) {
            if ($this->canAllowLegacyPublicRequest('payment_confirmation')) {
                return;
            }

            wp_send_json_error(array(
                'message' => __('Invalid request nonce', 'buy-me-coffee')
            ), 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce validated manually
        $nonce = sanitize_text_field(wp_unslash($_REQUEST['buymecoffee_nonce']));
        if (!wp_verify_nonce($nonce, 'buymecoffee_nonce')) {
            wp_send_json_error(array(
                'message' => __('Invalid request nonce', 'buy-me-coffee')
            ), 403);
        }
    }

    protected function canAllowLegacyPublicRequest($context = 'general')
    {
        $allowLegacy = apply_filters('buymecoffee_allow_legacy_public_requests', false, $context, $this->method);
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

        return apply_filters('buymecoffee_allow_legacy_public_requests_without_referer', false, $context, $this->method);
    }

    abstract public function render($template);

    abstract public function getPaymentSettings();

    abstract public function getSettings();

    abstract public function sanitize($settings);
}
