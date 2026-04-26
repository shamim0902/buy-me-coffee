<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<?php
use BuyMeCoffee\Classes\Vite;
use BuyMeCoffee\Helpers\PaymentHelper;

if ($paymentData):
    $amount = floatval($paymentData->payment_total ? $paymentData->payment_total / 100 : 0);
    $currencySymbol = html_entity_decode(PaymentHelper::currencySymbol($paymentData->currency ?? 'USD'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $isRecurring = isset($paymentData->transaction) && ($paymentData->transaction->transaction_type ?? '') === 'recurring';
    $recurringInterval = '';
    if ($isRecurring && isset($paymentData->subscription)) {
        $recurringInterval = ($paymentData->subscription->interval_type ?? 'month') === 'year'
            ? __('Yearly', 'buy-me-coffee')
            : __('Monthly', 'buy-me-coffee');
    }
?>
<div class="bmc-receipt">
    <!-- Success header -->
    <div class="bmc-receipt__success">
        <div class="bmc-receipt__check">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        <h2 class="bmc-receipt__title">
            <?php echo $isRecurring ? esc_html__('Subscription Active!', 'buy-me-coffee') : esc_html__('Payment Successful!', 'buy-me-coffee'); ?>
        </h2>
        <p class="bmc-receipt__subtitle">
            <?php echo $isRecurring
                ? esc_html__('Thank you for your recurring support', 'buy-me-coffee')
                : esc_html__('Thank you for your generous support', 'buy-me-coffee'); ?>
        </p>
        <?php if ($isRecurring): ?>
        <span class="bmc-receipt__recurring-badge">
            ↻ <?php echo $recurringInterval ? esc_html($recurringInterval . ' ' . __('Recurring Donation', 'buy-me-coffee')) : esc_html__('Recurring Donation', 'buy-me-coffee'); ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- Amount -->
    <div class="bmc-receipt__amount-box">
        <span class="bmc-receipt__amount-label">
            <?php echo $isRecurring ? esc_html__('Amount per Cycle', 'buy-me-coffee') : esc_html__('Amount Paid', 'buy-me-coffee'); ?>
        </span>
        <span class="bmc-receipt__amount-value"><?php echo esc_html($currencySymbol . number_format($amount, 2)); ?></span>
        <span class="bmc-receipt__amount-coffees">
            <?php echo esc_html($paymentData->coffee_count ?? 1); ?>
            <?php echo ($paymentData->coffee_count ?? 1) > 1 ? esc_html__('coffees', 'buy-me-coffee') : esc_html__('coffee', 'buy-me-coffee'); ?>
            ☕
        </span>
    </div>

    <!-- Details -->
    <div class="bmc-receipt__details">
        <h4 class="bmc-receipt__details-title"><?php esc_html_e('Transaction Details', 'buy-me-coffee'); ?></h4>

        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Reference', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value bmc-receipt__mono">#<?php echo esc_html(substr($paymentData->entry_hash ?? '', 0, 12)); ?></span>
        </div>

        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Date', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value"><?php echo esc_html(gmdate("M j, Y \a\\t g:i A", strtotime($paymentData->created_at ?? ''))); ?></span>
        </div>

        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Name', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value"><?php echo esc_html($paymentData->supporters_name ?? '—'); ?></span>
        </div>

        <?php if (!empty($paymentData->supporters_email)): ?>
        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Email', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value"><?php echo esc_html($paymentData->supporters_email); ?></span>
        </div>
        <?php endif; ?>

        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Payment Method', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value" style="text-transform: capitalize"><?php echo esc_html($paymentData->payment_method ?? ''); ?></span>
        </div>

        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Donation Type', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__value">
                <?php if ($isRecurring): ?>
                    <span class="bmc-receipt__type-badge bmc-receipt__type-badge--recurring">
                        ↻ <?php echo $recurringInterval ? esc_html($recurringInterval) : esc_html__('Recurring', 'buy-me-coffee'); ?>
                    </span>
                <?php else: ?>
                    <?php esc_html_e('One-time', 'buy-me-coffee'); ?>
                <?php endif; ?>
            </span>
        </div>

        <div class="bmc-receipt__row">
            <span class="bmc-receipt__label"><?php esc_html_e('Status', 'buy-me-coffee'); ?></span>
            <span class="bmc-receipt__status bmc-receipt__status--<?php echo esc_attr($paymentData->payment_status ?? ''); ?>">
                <?php echo esc_html(ucfirst($paymentData->payment_status ?? '')); ?>
            </span>
        </div>

        <?php if (!empty($paymentData->supporters_message)): ?>
        <div class="bmc-receipt__message">
            <span class="bmc-receipt__label"><?php esc_html_e('Your Message', 'buy-me-coffee'); ?></span>
            <p class="bmc-receipt__message-text"><?php echo esc_html($paymentData->supporters_message); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="bmc-receipt__footer">
        <img class="bmc-receipt__gif" width="60" src="<?php echo esc_url(Vite::staticPath() . 'images/coffee.gif'); ?>" alt="">
        <p class="bmc-receipt__thanks"><?php esc_html_e('Your support means the world!', 'buy-me-coffee'); ?></p>
    </div>

    <?php
    if ($isRecurring) {
        $bmcSettings      = get_option('buymecoffee_payment_setting', []);
        $accountEnabled   = !empty($bmcSettings['enable_account']) && $bmcSettings['enable_account'] === 'yes';
        $accountPageId    = $accountEnabled && !empty($bmcSettings['account_page_id']) ? (int) $bmcSettings['account_page_id'] : 0;
        $accountPageUrl   = $accountPageId ? get_permalink($accountPageId) : '';
        if ($accountEnabled && $accountPageUrl) :
    ?>
    <div class="bmc-receipt__account-link">
        <a href="<?php echo esc_url($accountPageUrl); ?>"><?php esc_html_e('View your subscriptions →', 'buy-me-coffee'); ?></a>
    </div>
    <?php endif; } ?>
</div>
<?php else: ?>
<div class="bmc-receipt">
    <div class="bmc-receipt__success">
        <div class="bmc-receipt__check">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        <h2 class="bmc-receipt__title"><?php esc_html_e('Thank You!', 'buy-me-coffee'); ?></h2>
        <p class="bmc-receipt__subtitle"><?php esc_html_e('Your contribution has been received', 'buy-me-coffee'); ?></p>
    </div>
</div>
<?php endif; ?>
