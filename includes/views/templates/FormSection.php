<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
use BuyMeCoffee\Helpers\SanitizeHelper;

$bmcAccentColor  = SanitizeHelper::cssColor(Arr::get($template, 'advanced.button_style', ''), 'rgb(13, 148, 136)');
$bmcAccentBg     = SanitizeHelper::cssColor(Arr::get($template, 'advanced.bg_style', ''), 'rgba(13, 148, 136, 5%)');
$bmcAccentBorder = SanitizeHelper::cssColor(Arr::get($template, 'advanced.border_style', ''), 'rgba(13, 148, 136, 25%)');
$bmcAccentSubtle = SanitizeHelper::rgbToRgba($bmcAccentColor, '2%');
$bmcAccentRing   = SanitizeHelper::rgbToRgba($bmcAccentColor, '10%');
$bmcAccentVars   = '--bmc-accent: ' . $bmcAccentColor . '; --bmc-accent-soft: ' . $bmcAccentBg . '; --bmc-accent-subtle: ' . $bmcAccentSubtle . '; --bmc-accent-border: ' . $bmcAccentBorder . '; --bmc-accent-ring: ' . $bmcAccentRing . ';';
?>

<div class="bmc-form-card <?php echo sanitize_text_field(Arr::get($template, 'advanced.formShadow')) == 'yes' ? 'bmc-form-card--shadow' : ''; ?>" style="<?php echo esc_attr($bmcAccentVars); ?>">
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
        /* translators: %s: creator name */
        printf(esc_html__('Support %s', 'buy-me-coffee'), esc_html($template['yourName']));
        ?>
    </h3>
    <?php endif; ?>

    <?php
    $form = \BuyMeCoffee\Builder\Render::renderInputElements($template, $args);
    echo wp_kses($form, \BuyMeCoffee\Helpers\SanitizeHelper::allowedTags());
    ?>
</div>
