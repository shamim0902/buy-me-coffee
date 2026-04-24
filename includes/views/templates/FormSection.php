<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
?>

<div class="bmc-form-card <?php echo sanitize_text_field(Arr::get($template, 'advanced.formShadow')) == 'yes' ? 'bmc-form-card--shadow' : ''; ?>">
    <?php
    if (isset($template['formTitle']) && sanitize_text_field($template['formTitle']) === 'yes'): ?>
    <h3 class="bmc-form-card__title">
        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public form parameters
        if (isset($_GET['for'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $template['yourName'] = sanitize_text_field(wp_unslash($_GET['for']));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['custom_coffee'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $template['custom_coffee'] = esc_html(sanitize_text_field(wp_unslash($_GET['custom_coffee'])));
        }
        printf(esc_html__('Support %s', 'buy-me-coffee'), esc_html($template['yourName']));
        ?>
    </h3>
    <?php endif; ?>

    <?php
    $form = \BuyMeCoffee\Builder\Render::renderInputElements($template, $args);
    echo wp_kses($form, \BuyMeCoffee\Helpers\SanitizeHelper::allowedTags());
    ?>
</div>
