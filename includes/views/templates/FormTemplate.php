<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables

    use BuyMeCoffee\Helpers\ArrayHelper as Arr;
 ?>

<div id="buymecoffee_preview_top">
    <div class="buymecoffee_profile_section">
        <style>
            <?php
                $borderStyle = Arr::get($template, 'advanced.border_style');
            ?>
            .buymecoffee_profile_hr {
                background: <?php echo esc_attr($borderStyle); ?>;
            }
        </style>
        <div class="buymecoffee_profile_hr">
        </div>
        <div class="buymecoffee_profile_section_image">
            <img src="<?php echo esc_url($profile_image); ?>"
                 alt="Profile Image">
        </div>
    </div>
    <div class="buymecoffee_preview_header">
    </div>
    <div class="buymecoffee_preview_body">
        <?php

        // check if receipt page
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public receipt page display
        if (isset($_REQUEST['buymecoffee_success']) && isset($_REQUEST['hash'])) {
            $hash = sanitize_text_field(wp_unslash($_REQUEST['hash']));
            $paymentData = (new \BuyMeCoffee\Models\Supporters())->getByHash($hash);
            include BUYMECOFFEE_DIR . 'includes/views/templates/Confirmation.php';
        } else {
            include BUYMECOFFEE_DIR . 'includes/views/templates/FormSection.php';
        }

        if ($quote && !isset($_REQUEST['buymecoffee_success'])):
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
            ?>
            <div class="buymecoffee_your_content_wrapper">
                <div class="buymecoffee_your_content">
                    <div class="buymecoffee_your_content_title buymecoffee_quote_section">
                        <div style="margin:23px;">
                            <?php if (current_user_can('manage_options')): ?>
                                <div class="buymecoffee_edit_action_wrapper" style="display: none;">
                                    <div class="buymecoffee_edit_action">✏️ Edit quotes</div>
                                    <blockquote style="display: none;">
                                        <input type="textarea" value="<?php echo esc_html($quote); ?>">
                                    </blockquote>
                                </div>
                            <?php endif; ?>
                            <blockquote class="buymecoffee_main_quote">
                                <p class="bmc_appreciation_title"><?php echo esc_html($quote); ?></p>
                            </blockquote>
                        </div>
                    </div>
                    <div class="buymecoffee_your_content_body">
                        <p></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>