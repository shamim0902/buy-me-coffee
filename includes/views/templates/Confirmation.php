<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<?php use BuyMeCoffee\Classes\Vite;

if ($paymentData): ?>
<div class="bmc-receipt">
    <div class="bmc-receipt__header">
        <img class="bmc-receipt__icon" width="80" src="<?php echo esc_url(Vite::staticPath() . 'images/coffee.gif'); ?>" alt="">
        <h2 class="bmc-receipt__title"><?php esc_html_e('Payment Receipt', 'buy-me-coffee'); ?></h2>
        <p class="bmc-receipt__hash">#<?php echo esc_html(substr($paymentData->entry_hash ?? '', 8)); ?></p>
        <p class="bmc-receipt__date"><?php echo esc_html(gmdate("jS F Y \a\\t g:i A", strtotime($paymentData->created_at ?? ''))); ?></p>
    </div>

    <hr class="bmc-receipt__divider" />

    <div class="bmc-receipt__body">
        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Coffee Donated', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value bmc-receipt__coffee-count"><?php echo esc_html($paymentData->coffee_count ?? ''); ?></span>
        </div>
        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Name', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value"><?php echo esc_html($paymentData->supporters_name ?? ''); ?></span>
        </div>
        <?php if ($paymentData->supporters_email): ?>
        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Email', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value"><?php echo esc_html($paymentData->supporters_email); ?></span>
        </div>
        <?php endif; ?>
        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Status', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value bmc-receipt__status bmc-receipt__status--<?php echo esc_attr($paymentData->payment_status ?? ''); ?>">
                <?php echo esc_html($paymentData->payment_status ?? ''); ?>
            </span>
        </div>
        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Method', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value"><?php echo esc_html($paymentData->payment_method ?? ''); ?></span>
        </div>
        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Amount', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value bmc-receipt__amount">
                <?php echo esc_html($paymentData->currency ?? '') . ' ' . esc_html(floatval($paymentData->payment_total ? $paymentData->payment_total / 100 : 0)); ?>
            </span>
        </div>
    </div>

    <p class="bmc-receipt__thanks"><?php esc_html_e('Thanks for your contribution', 'buy-me-coffee'); ?> &#x1F49B;</p>
</div>
<?php else: ?>
<div class="bmc-receipt">
    <p class="bmc-receipt__thanks"><?php esc_html_e('Thanks for your contribution', 'buy-me-coffee'); ?> &#x1F49B;</p>
</div>
<?php endif; ?>
