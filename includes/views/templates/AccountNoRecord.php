<?php if (!defined('ABSPATH')) exit; ?>
<div class="bmc-account-wrap">
    <div class="bmc-account-empty">
        <div class="bmc-account-empty__icon">☕</div>
        <h2 class="bmc-account-empty__title"><?php esc_html_e('No Subscription Found', 'buy-me-coffee'); ?></h2>
        <p class="bmc-account-empty__desc">
            <?php esc_html_e("We couldn't find a subscription linked to your account. If you recently subscribed using a different email, please contact support.", 'buy-me-coffee'); ?>
        </p>
    </div>
</div>
<style>
.bmc-account-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 480px; margin: 40px auto; padding: 0 16px; }
.bmc-account-empty { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 48px 32px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.bmc-account-empty__icon { font-size: 48px; margin-bottom: 16px; }
.bmc-account-empty__title { font-size: 20px; font-weight: 700; color: #111827; margin: 0 0 10px; }
.bmc-account-empty__desc { font-size: 14px; color: #6b7280; margin: 0; line-height: 1.6; }
</style>
