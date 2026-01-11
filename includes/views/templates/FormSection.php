<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   ?>
<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
use BuyMeCoffee\Helpers\ArrayHelper as Arr ;?>

<div class="buymecoffee_form_preview_wrapper <?php echo sanitize_text_field(Arr::get($template, 'advanced.formShadow')) == 'yes' ? 'buymecoffee_form_preview_shadow' : '' ;?>">
    <style>
        <?php
            $borderStyle = Arr::get($template, 'advanced.border_style');
            $buttonStyle = Arr::get($template, 'advanced.button_style');
        ?>
        .buymecoffee_payment_input_content {
            background: <?php echo esc_attr(Arr::get($template, 'advanced.bg_style')); ?>;
            border: 1px solid <?php echo esc_attr($borderStyle); ?>;
        }
        .wpm_submit_button {
            background-color: <?php echo esc_attr($buttonStyle); ?> !important;
        }
        .wpm_submit_button:hover {
            background: <?php echo esc_attr($borderStyle); ?> !important;
        }
        .buymecoffee_currency_prefix {
            background: <?php echo esc_attr($buttonStyle); ?>;
        }

    </style>
    <?php
    if (isset($template['formTitle']) && sanitize_text_field($template['formTitle']) === 'yes'): ?>
    <h3 class="buymecoffee_form_to">
        Buy
        <span class="buymecoffee_form_to"><?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form parameters
            if (isset($_GET['for'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form parameters
                $template['yourName'] = sanitize_text_field(wp_unslash($_GET['for']));
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form parameters
            if (isset($_GET['custom_coffee'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form parameters
                $template['custom_coffee'] = esc_html(sanitize_text_field(wp_unslash($_GET['custom_coffee'])));
            }
            echo esc_html($template['yourName'])
            ?></span> <br/>
        a coffee
    </h3>
    <?php
    endif;
    $form = \BuyMeCoffee\Builder\Render::renderInputElements($template, $args);
    echo wp_kses($form, \BuyMeCoffee\Helpers\SanitizeHelper::allowedTags());
    ?>
</div>