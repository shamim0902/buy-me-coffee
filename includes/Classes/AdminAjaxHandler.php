<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Helpers\SanitizeHelper;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Builder\Methods\PayPal\PayPal;
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
use BuyMeCoffee\Controllers\PaymentHandler;
use BuyMeCoffee\Builder\Methods\Stripe\Stripe;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Helpers\Currencies;

use BuyMeCoffee\Models\Buttons;
use BuyMeCoffee\Models\Transactions;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class AdminAjaxHandler
{
    public function registerEndpoints()
    {
        add_action('wp_ajax_buymecoffee_admin_ajax', array($this, 'handleEndPoint'));
    }

    public function handleEndPoint()
    {
        if (!isset($_REQUEST['buymecoffee_nonce']) ) {
            wp_send_json_error(array(
                'message' => __("Invalid nonce", 'buy-me-coffee')
            ), 403);
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['buymecoffee_nonce'])), 'buymecoffee_nonce')) {
            wp_send_json_error(array(
                'message' => __("Invalid buymecoffee_nonce", 'buy-me-coffee')
            ), 403);
        }

        $route = isset($_REQUEST['route']) ? sanitize_text_field(wp_unslash($_REQUEST['route'])) : '';

        $validRoutes = array(
            'get_data' => 'getPaymentSettings',
            'save_payment_settings' => 'savePaymentSettings',
            'save_form_design' => 'saveFormDesign',
            'gateways' => 'getAllMethods',

            'save_settings' => 'saveSettings',
            'get_settings' => 'getSettings',
            'reset_template_settings' => 'resetDefaultSettings',

            'get_weekly_revenue' => 'getWeeklyRevenue',
            'get_supporters' => 'getSupporters',
            'get_top_supporters' => 'getTopSupporters',
            'edit_supporter' => 'editSupporter',
            'get_supporter' => 'getSupporter',
            'delete_supporter' => 'deleteSupporter',
            'update_payment_status' => 'updatePaymentStatus',
            'status_report' => 'statusReport',

        );

        if (isset($validRoutes[$route])) {
            do_action('buymecoffee_doing_ajax_forms_' . $route);
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized in sanitizeTextArray method
            $data = isset($_REQUEST['data']) ? $this->sanitizeTextArray(wp_unslash($_REQUEST['data'])) : [];
            return $this->{$validRoutes[$route]}($data);
        }
        do_action('buymecoffee_admin_ajax_handler_catch', $route);
    }

    public function getAllMethods()
    {
        $methods = PaymentHandler::getAllMethods();
        wp_send_json_success($methods, 200);
    }

    public function statusReport()
    {
        $report = (new Supporters())->statusReport();
        wp_send_json_success($report, 200);
    }

    public function updatePaymentStatus($request)
    {
        $id = intval($request['id']);
        $status = sanitize_text_field($request['status']);
        $supporter = (new Supporters())->getQuery()->where('id', $id)->update(['payment_status' => $status]);
        (new Transactions())->getQuery()->where('entry_id', $id)->update(['status' => $status]);
        wp_send_json_success($supporter, 200);
    }

    public function getSupporter($request)
    {
        $id = intval($request['id']);
        $supporter = (new Supporters())->find($id);

        $supporter->supporters_image = get_avatar_url($supporter->supporters_email);

        if ($supporter) {
            wp_send_json_success($supporter, 200);
        }
    }

    public function getSupporters($request)
    {
        return (new Supporters())->index($request);
    }

    public function editSupporter($request)
    {
        $id = Arr::get($request, 'id');
        $supporter = (new Supporters())->find($id);
        if ($supporter) {
            $supporter->name = sanitize_text_field(Arr::get($request, 'name', ''));
            $supporter->email = sanitize_text_field(Arr::get($request, 'email', ''));
            $supporter->amount = sanitize_text_field(Arr::get($request, 'amount'));
            $supporter->save();
            wp_send_json_success($supporter, 200);
        }
        wp_send_json_error();
    }

    public function deleteSupporter($request)
    {
        $id = Arr::get($request, 'id');
        $supporter = (new Supporters());
        $transactions = (new Transactions());
        if ($supporter->find($id)) {
            $supporter->delete($id);
            $transactions->delete($id, 'entry_id');
            wp_send_json_success($supporter, 200);
        }
        wp_send_json_error();
    }

    public function getPaymentSettings($request)
    {
        $method = Arr::get($request, 'method');
        do_action('buymecoffee_get_payment_settings_' . $method);
    }

    public function resetDefaultSettings($request)
    {
        $settings = (new Buttons())->getButton($isDefault = true);
        update_option('buymecoffee_payment_setting', $settings, false);
        do_action('buymecoffee_after_reset_template', $settings);

        wp_send_json_success(array(
            'settings' => $settings,
            'message' => __("Settings successfully updated", 'buy-me-coffee')
        ), 200);

    }

    public function saveSettings($data)
    {
        $data = $data ?: array();

        update_option('buymecoffee_payment_setting', $data, false);
        do_action('buymecoffee_after_save_template', $data);

        wp_send_json_success(array(
            'message' => __("Settings successfully updated", 'buy-me-coffee')
        ), 200);
    }

    public function saveFormDesign($data)
    {
        $settings = (new Buttons())->getButton();
        if (!isset($settings['advanced'])) {
            return;
        }

        if (!empty($data['button_style']) && !empty($data['bg_style']) && !empty($data['border_style'])) {
            $settings['advanced']['button_style'] = $data['button_style'];
            $settings['advanced']['bg_style'] = $data['bg_style'];
            $settings['advanced']['border_style'] = $data['border_style'];
        }

        if (!empty($data['quote'])) {
            $settings['advanced']['quote'] = $data['quote'];
        }

        $this->saveSettings($settings);

    }

    public function sanitizeTextArray($data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $data[$key][$k] = sanitize_text_field($v);
                }
            } else {
                $data[$key] = sanitize_text_field($value);
            }
        }
        return $data;
    }

    public function getSettings()
    {
        $settings = (new Buttons())->getButton();

        wp_send_json_success(
            array(
                'template' => $settings,
                'currencies' => Currencies::all()
            ),
            200
        );
    }

    public function getWeeklyRevenue()
    {
        (new Supporters())->getWeeklyRevenue();
    }


    public function savePaymentSettings($data = array())
    {
        $settings = Arr::get($data, 'settings');
        $method = Arr::get($data, 'method');
        (new PaymentHandler())->saveSettings($method, $settings);
    }
}
