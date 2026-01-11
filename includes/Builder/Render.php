<?php

namespace BuyMeCoffee\Builder;

use BuyMeCoffee\Classes\Vite;
use BuyMeCoffee\Controllers\PaymentHandler;
use BuyMeCoffee\Helpers\BuilderHelper;
use BuyMeCoffee\Models\Buttons;
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
use BuyMeCoffee\Classes\DemoPage;
use BuyMeCoffee\Helpers\PaymentHelper;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Render
{

    public function render($args = [])
    {
        $template = (new Buttons())->getButton();

        $buttonText = Arr::get($template, 'buttonText');
        $bgColor = esc_attr(Arr::get($template, 'advanced.bgColor', '#fad400'));
        $color = esc_attr(Arr::get($template, 'advanced.color', '#000000'));
        $minWidth = esc_attr(Arr::get($template, 'advanced.minWidth'));
        $fontSize = esc_attr(Arr::get($template, 'advanced.fontSize'));
        $radius = esc_attr(Arr::get($template, 'advanced.radius', '5'));

        $paymentPageUrl = site_url('?share_coffee');
        $openMode = Arr::get($template, 'openMode');

        $styling = "<style>.wpm-buymecoffee-container .wpm-buymecoffee-button{
            outline: none;
            box-shadow: none;
            padding: 10px 23px;
            line-height: 0px;
            background-color: $bgColor;
            color: $color;
            min-width: {$minWidth}px;
            font-size: {$fontSize}px;
            border-radius: {$radius}px;
            cursor: pointer;
            text-decoration: none;
        }
        button.wpm-buymecoffee-button {
            height: 50px;
        }
        .wpm-buymecoffee-button:hover {
            box-shadow: 4px 3px 6px 2px #ccc;
        }
        </style>";

        ob_start();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo wp_kses($styling, ['style' => []]);
        ?>
        <div class="wpm-buymecoffee-container">
            <!--  The Modal button -->
            <?php
            if (sanitize_text_field($openMode) === 'modal') {
                ?>
                <button class="wpm-buymecoffee-button" id="bmc_open_modal_btn"><?php echo esc_html($buttonText); ?></button>
                <div id="bmc_modal_wrapper" class="modal">
                    <div class="bmc_modal_content">
                        <span class="close">&times;</span>
                        <?php (new DemoPage())->loadModalContent(); ?>
                    </div>
                </div>
                <?php
            } else {
                ?>
                <a class="wpm-buymecoffee-button" target="_blank"
                   href="<?php echo esc_url($paymentPageUrl); ?>"><?php echo esc_html($buttonText); ?></a>
                <?php
            } ?>
        </div>
        <?php
        $content = ob_get_clean();
        return $content;
    }

    public static function renderInputElements($template = [], $args = [])
    {
        static::addAssets();

        $nameAttributes = array(
            'data-required' => 'no',
            'data-type' => 'text',
            'name' => 'wpm-supporter-name',
            'placeholder' => 'John Doe',
            'class' => 'wpm-supporter-name',
            'id' => 'wpm-supporter-name',
        );

        $emailAttributes = array(
            'data-required' => 'no',
            'data-type' => 'email',
            'name' => 'wpm-supporter-email',
            'placeholder' => 'example@domain.com (optional)',
            'class' => 'wpm-supporter-email',
            'id' => 'wpm-supporter-email',
        );

        $msgAttributes = array(
            'data-required' => 'yes',
            'data-type' => 'textarea',
            'name' => 'wpm-supporter-message',
            'placeholder' => 'Love to hear from you! (optional)',
            'class' => 'wpm-supporter-message',
            'id' => 'wpm-supporter-message',
        );

        $btnAttributes = array(
            'data-required' => 'no',
            'data-type' => 'submit',
            'name' => 'wpm_submit_button',
            'placeholder' => 'Submit',
            'class' => 'wpm_submit_button',
            'id' => 'wpm_submit_button',
        );
        if (empty($template)) {
            $template = (new Buttons())->getButton();
        }
        $enableName = Arr::get($template, 'enableName') == 'yes';
        $enableEmail = Arr::get($template, 'enableEmail') == 'yes';
        $enableMsg = Arr::get($template, 'enableMessage') == 'yes';
        $defaultAmount = intval(Arr::get($template, 'defaultAmount', '5'));
        $customCoffeeDefault = intval(Arr::get($template, 'custom_coffee', '5'));

        $currency = Arr::get($template, 'currency', 'USD');
        $symbool = PaymentHelper::currencySymbol();
        $formDynamicClass = BuilderHelper::getFormDynamicClass();

        $isCustomPay = false;
        $customAmount = $defaultAmount;

        if (isset($args['custom'])) {
            $isCustomPay = true;
            $customAmount = intval(Arr::get($args, 'custom', $defaultAmount));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form parameter
        if (isset($_GET['custom'])) {
            $isCustomPay = true;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form parameter
            $customAmount = intval($_GET['custom']);
        }

        ob_start();
        ?>
        <form id="<?php echo esc_attr($formDynamicClass . '_main_wrapper');  ?>" class="buymecoffee_form" data-wpm_currency="<?php echo esc_html($currency); ?>">
            <input type="hidden" name="__buymecoffee_ref" value="<?php echo esc_html($template['yourName']); ?>"/>
            <div class="buymecoffee_payment_processor"></div>
            <?php if (!$isCustomPay): ?>
            <div class="buymecoffee_input_content">
                <input type="hidden" style="display: none!important;" type="number" class="buymecoffee_payment" value="<?php echo esc_attr($defaultAmount); ?>"
                       data-price="<?php echo esc_attr($defaultAmount * 100); ?>" type="text"/>
            </div>
            <div class="buymecoffee_payment_item">
                <div class="buymecoffee_payment_input_content">
                    <div class="buymecoffee_coffee_selector">
                        <img width="50" src="<?php echo esc_url(Vite::staticPath() . 'images/coffee.png'); ?>">
                        <span>x</span>

                        <div class="bmc_coffee">
                            <input id="one_coffee_select_radio_<?php echo esc_attr($formDynamicClass); ?>" value="1" class="coffee-select"
                                   name="radio-group" type="radio" checked>
                            <label for="one_coffee_select_radio_<?php echo esc_attr($formDynamicClass); ?>">1</label>

                            <input id="two_coffee_select_radio_<?php echo esc_attr($formDynamicClass); ?>" value="2" class="coffee-select"
                                   name="radio-group" type="radio">
                            <label for="two_coffee_select_radio_<?php echo esc_attr($formDynamicClass); ?>">2</label>

                            <input id="three_coffee_select_radio_<?php echo esc_attr($formDynamicClass); ?>" value="3" class="coffee-select"
                                   name="radio-group" type="radio">
                            <label for="three_coffee_select_radio_<?php echo esc_attr($formDynamicClass); ?>">3</label>
                        </div>
                        <input class="buymecoffee_custom_quantity" type="number" value="5" data-quantity="5">

                    </div>
                </div>
            </div>

            <?php endif; ?>
            <?php if ($isCustomPay): ?>
            <!--   This custom quantity will update in any future feature       /-->
            <div class="buymecoffee_payment_item" style="align-items: center;">
                <div class="buymecoffee_input_content">
                    <div class="buymecoffee_input_content_custom_pay">
                        <span class="buymecoffee_currency_prefix"><?php echo esc_html($symbool); ?></span>
                        <input type="number" class="buymecoffee_payment"
                               value="<?php echo esc_attr($customAmount); ?>"
                               data-price="<?php echo esc_attr($customAmount * 100); ?>"
                               type="text">
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($enableName): ?>
                <div data-element_type="input" class="buymecoffee_form_item">
                    <div class="buymecoffee_input_content">
                        <input <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo static::builtAttributes($nameAttributes); ?>></input>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($enableEmail): ?>
                <div data-element_type="email" class="buymecoffee_form_item">
                    <div class="buymecoffee_input_content">
                        <input <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo static::builtAttributes($emailAttributes); ?>></input>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($enableMsg): ?>
                <div data-element_type="textarea" class="buymecoffee_form_item">
                    <div class="buymecoffee_input_content">
                        <textarea rows="2" <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo static::builtAttributes($msgAttributes); ?>></textarea>
                    </div>
                </div>
            <?php endif; ?>

            <div class="buymecoffee_form_item buymecoffee_pay_methods" id="buymecoffee_pay_methods">
                <div class="buymecoffee_pay_method" data-payment_selected="none">
                    <?php echo esc_html(static::payMethod($template)); ?>
                </div>
            </div>

            <div data-element_type="submit" class="buymecoffee_form_item buymecoffee_form_submit_wrapper">
                <div class="buymecoffee_input_content">
                    <button <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo static::builtAttributes($btnAttributes); ?>>Support
                        <div class="wpm_loading_svg">
                            <svg version="1.1" id="loader-1" xmlns="http://www.w3.org/2000/svg"
                                 xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="30px" height="30px"
                                 viewBox="0 0 40 40" enable-background="new 0 0 40 40" xml:space="preserve">
                                <path opacity="0.2" fill="#000"
                                      d="M20.201,5.169c-8.254,0-14.946,6.692-14.946,14.946c0,8.255,6.692,14.946,14.946,14.946 s14.946-6.691,14.946-14.946C35.146,11.861,28.455,5.169,20.201,5.169z M20.201,31.749c-6.425,0-11.634-5.208-11.634-11.634 c0-6.425,5.209-11.634,11.634-11.634c6.425,0,11.633,5.209,11.633,11.634C31.834,26.541,26.626,31.749,20.201,31.749z"/>
                                <path fill="#000"
                                      d="M26.013,10.047l1.654-2.866c-2.198-1.272-4.743-2.012-7.466-2.012h0v3.312h0 C22.32,8.481,24.301,9.057,26.013,10.047z">
                                    <animateTransform attributeType="xml" attributeName="transform" type="rotate"
                                                      from="0 20 20"
                                                      to="360 20 20" dur="0.5s" repeatCount="indefinite"/>
                                </path></svg>
                        </div>
                            <span class="wpm_payment_total_amount_prefix"><?php echo esc_html($symbool); ?></span>
                            <span class="wpm_payment_total_amount"><?php echo esc_html($defaultAmount); ?></span>
                    </button>
                </div>
            </div>
            <p class="buymecoffee_no_signup">No signup required</p>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function payMethod($template)
    {
        $methods = PaymentHandler::getAllMethods();
        $hasActiveMethod = false;

        foreach ($methods as $method) {
            if (isset($method['status']) && $method['status'] == 'yes') {
                $hasActiveMethod = true;
                do_action('buymecoffee_render_component_' . $method['route'], $template);
            }
        }
        if (!$hasActiveMethod) {
            return __("Please activate at least one payment method!", 'buy-me-coffee');
        }
    }

    public static function addAssets()
    {
        Vite::enqueueStyle('buymecoffee_css', 'scss/public/public-style.scss', array(), BUYMECOFFEE_VERSION);
        Vite::enqueueScript('buymecoffee_public_js',  'js/BmcPublic.js', array('jquery'), BUYMECOFFEE_VERSION, true);
        wp_localize_script('buymecoffee_public_js', 'buymecoffee_general', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
        ));
    }

    public static function builtAttributes($attributes)
    {
        $atts = "";
        foreach ($attributes as $attributeKey => $attribute) {
            if (is_array($attribute)) {
                $attribute = wp_json_encode($attribute);
            }
            $atts .= esc_attr($attributeKey) . "='" . esc_attr(htmlspecialchars($attribute, ENT_QUOTES)) . "' ";

        }
        return $atts;
    }

    public function confirmation($hash)
    {
        echo 'Thanks for your contribution!';
    }
}
