<?php if (!defined('ABSPATH')) exit; ?>
<div class="bmc-account-wrap">
    <div class="bmc-account-login">
        <div class="bmc-account-login__icon">☕</div>
        <h2 class="bmc-account-login__title"><?php esc_html_e('Supporter Account', 'buy-me-coffee'); ?></h2>
        <p class="bmc-account-login__desc"><?php esc_html_e('Log in to view your subscriptions and manage your supporter profile.', 'buy-me-coffee'); ?></p>
        <p class="bmc-account-login__notice"><?php esc_html_e('After subscribing, check your email for your account credentials and password setup link.', 'buy-me-coffee'); ?></p>
        <?php
        wp_login_form([
            'redirect'       => $redirect ?? get_permalink(),
            'label_username' => esc_html__('Email or Username', 'buy-me-coffee'),
            'label_password' => esc_html__('Password', 'buy-me-coffee'),
            'label_log_in'   => esc_html__('Log In', 'buy-me-coffee'),
            'remember'       => true,
        ]);
        ?>
    </div>
</div>
<style>
.bmc-account-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 440px; margin: 40px auto; padding: 0 16px; }
.bmc-account-login { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 40px 32px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.bmc-account-login__icon { font-size: 40px; margin-bottom: 12px; }
.bmc-account-login__title { font-size: 22px; font-weight: 700; color: #111827; margin: 0 0 8px; }
.bmc-account-login__desc { font-size: 14px; color: #6b7280; margin: 0 0 12px; }
.bmc-account-login__notice { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; color: #9a3412; font-size: 13px; line-height: 1.5; margin: 0 0 24px; padding: 10px 12px; }
.bmc-account-login #loginform { text-align: left; }
.bmc-account-login #loginform p { margin-bottom: 14px; }
.bmc-account-login #loginform label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px; }
.bmc-account-login #loginform input[type="text"],
.bmc-account-login #loginform input[type="password"] { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
.bmc-account-login #loginform input[type="submit"] { width: 100%; padding: 10px; background: #f59e0b; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; color: #fff; cursor: pointer; margin-top: 6px; }
.bmc-account-login #loginform input[type="submit"]:hover { background: #d97706; }
</style>
