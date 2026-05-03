<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
if (!defined('ABSPATH')) exit;

use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Models\Subscriptions;

$statusLabels = [
    'active'     => __('Active', 'buy-me-coffee'),
    'cancelled'  => __('Cancelled', 'buy-me-coffee'),
    'past_due'   => __('Past Due', 'buy-me-coffee'),
    'incomplete' => __('Incomplete', 'buy-me-coffee'),
];
?>
<div class="bmc-account-wrap">

    <!-- Header -->
    <div class="bmc-account-header">
        <div class="bmc-account-header__left">
            <div class="bmc-account-avatar"><?php echo esc_html(mb_strtoupper(mb_substr($user->display_name, 0, 1))); ?></div>
            <div>
                <h2 class="bmc-account-name"><?php echo esc_html($user->display_name); ?></h2>
                <p class="bmc-account-email"><?php echo esc_html($user->user_email); ?></p>
            </div>
        </div>
        <a href="<?php echo esc_url(site_url('?share_coffee')); ?>" class="bmc-account-back-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            <span><?php esc_html_e('Contribute', 'buy-me-coffee'); ?></span>
        </a>
    </div>

    <!-- Subscriptions -->
    <div class="bmc-account-card">
        <h3 class="bmc-account-card__title"><?php esc_html_e('Your Subscriptions', 'buy-me-coffee'); ?></h3>

        <?php if (!empty($subscriptions)) : ?>
            <div class="bmc-account-table-wrap">
                <table class="bmc-account-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Amount', 'buy-me-coffee'); ?></th>
                            <th><?php esc_html_e('Interval', 'buy-me-coffee'); ?></th>
                            <th><?php esc_html_e('Status', 'buy-me-coffee'); ?></th>
                            <th><?php esc_html_e('Next Renewal', 'buy-me-coffee'); ?></th>
                            <th><?php esc_html_e('Started', 'buy-me-coffee'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscriptions as $sub) :
                            $amount = $sub->amount ? html_entity_decode(PaymentHelper::getFormattedAmount($sub->amount, $sub->currency), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '--';
                            $interval = ($sub->interval_type === 'year') ? __('Yearly', 'buy-me-coffee') : __('Monthly', 'buy-me-coffee');
                            $status = isset($statusLabels[$sub->status]) ? $statusLabels[$sub->status] : ucfirst($sub->status);
                            $hasFutureAccess = $sub->status === 'cancelled' && Subscriptions::hasAccessValidity($sub);
                            $periodEnd = (!empty($sub->current_period_end) && $sub->current_period_end !== '0000-00-00 00:00:00')
                                ? date_i18n(get_option('date_format'), strtotime($sub->current_period_end))
                                : '--';
                            $startedAt = (!empty($sub->created_at) && $sub->created_at !== '0000-00-00 00:00:00')
                                ? date_i18n(get_option('date_format'), strtotime($sub->created_at))
                                : '--';
                        ?>
                        <tr>
                            <td><?php echo esc_html($amount); ?></td>
                            <td><?php echo esc_html($interval); ?></td>
                            <td>
                                <span class="bmc-sub-status bmc-sub-status--<?php echo esc_attr($sub->status); ?>"><?php echo esc_html($status); ?></span>
                                <?php if ($hasFutureAccess) : ?>
                                <span class="bmc-sub-access-hint"><?php echo esc_html(sprintf(/* translators: %s: date */ __('Access until %s', 'buy-me-coffee'), $periodEnd)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($sub->status === 'active' ? $periodEnd : '--'); ?></td>
                            <td><?php echo esc_html($startedAt); ?></td>
                            <td>
                                <?php if ($sub->status === 'active') : ?>
                                <div class="bmc-sub-actions">
                                    <button type="button" class="bmc-sub-actions__trigger" aria-label="<?php esc_attr_e('Actions', 'buy-me-coffee'); ?>">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                    </button>
                                    <div class="bmc-sub-actions__menu">
                                        <button type="button" class="bmc-sub-actions__item bmc-sub-actions__item--danger" data-bmc-cancel-sub="<?php echo absint($sub->id); ?>">
                                            <?php esc_html_e('Cancel subscription', 'buy-me-coffee'); ?>
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="bmc-account-empty-msg"><?php esc_html_e('No active subscriptions found.', 'buy-me-coffee'); ?></p>
        <?php endif; ?>
    </div>

    <!-- Recent Transactions -->
    <?php if (!empty($transactions)) : ?>
    <div class="bmc-account-card">
        <h3 class="bmc-account-card__title"><?php esc_html_e('Recent Transactions', 'buy-me-coffee'); ?></h3>
        <div class="bmc-account-table-wrap">
            <table class="bmc-account-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'buy-me-coffee'); ?></th>
                        <th><?php esc_html_e('Amount', 'buy-me-coffee'); ?></th>
                        <th><?php esc_html_e('Method', 'buy-me-coffee'); ?></th>
                        <th><?php esc_html_e('Status', 'buy-me-coffee'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx) :
                        $txAmount = $tx->payment_total ? html_entity_decode(PaymentHelper::getFormattedAmount($tx->payment_total, $tx->currency), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '--';
                        $txDate = (!empty($tx->created_at) && $tx->created_at !== '0000-00-00 00:00:00')
                            ? date_i18n(get_option('date_format'), strtotime($tx->created_at))
                            : '--';
                        $receiptUrl = !empty($tx->entry_hash)
                            ? add_query_arg(['buymecoffee_success' => '1', 'hash' => $tx->entry_hash], site_url('?share_coffee'))
                            : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html($txDate); ?></td>
                        <td><?php echo esc_html($txAmount); ?></td>
                        <td style="text-transform: capitalize"><?php echo esc_html($tx->payment_method ?? '--'); ?></td>
                        <td><span class="bmc-sub-status bmc-sub-status--<?php echo esc_attr($tx->status); ?>"><?php echo esc_html(ucfirst($tx->status ?? '')); ?></span></td>
                        <td>
                            <?php if ($receiptUrl) : ?>
                            <a href="<?php echo esc_url($receiptUrl); ?>" class="bmc-receipt-link">
                                <?php esc_html_e('View receipt', 'buy-me-coffee'); ?>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>
<style>
#wpadminbar { display: none !important; }
html { margin-top: 0 !important; }
body { margin-top: 0 !important; }
.bmc-account-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 780px; margin: 32px auto; padding: 0 16px; }
.bmc-account-header { display: flex; align-items: center; gap: 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.bmc-account-header__left { display: flex; align-items: center; gap: 16px; min-width: 0; }
.bmc-account-avatar { width: 56px; height: 56px; border-radius: 9999px; background: #fef3c7; color: #d97706; font-size: 22px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bmc-account-name { font-size: 18px; font-weight: 700; color: #111827; margin: 0 0 2px; }
.bmc-account-email { font-size: 13px; color: #6b7280; margin: 0; }
.bmc-account-back-link { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; color: #2563eb; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; }
.bmc-account-back-link:hover { color: #1d4ed8; text-decoration: underline; }
.bmc-account-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.bmc-account-card__title { font-size: 15px; font-weight: 700; color: #111827; margin: 0 0 16px; }
.bmc-account-table-wrap { overflow: visible; }
.bmc-account-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.bmc-account-table th { text-align: left; padding: 8px 12px; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
.bmc-account-table td { padding: 10px 12px; color: #374151; border-bottom: 1px solid #f3f4f6; }
.bmc-account-table tbody tr:last-child td { border-bottom: none; }
.bmc-account-empty-msg { font-size: 14px; color: #9ca3af; margin: 0; }
.bmc-sub-status { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 500; background: #f3f4f6; color: #6b7280; }
.bmc-sub-status--active    { background: #dcfce7; color: #166534; }
.bmc-sub-status--cancelled { background: #fee2e2; color: #991b1b; }
.bmc-sub-status--past_due  { background: #ffedd5; color: #9a3412; }
.bmc-sub-status--incomplete{ background: #fef9c3; color: #854d0e; }
.bmc-sub-status--paid      { background: #dcfce7; color: #166534; }
.bmc-sub-status--pending   { background: #fef9c3; color: #854d0e; }
.bmc-sub-status--failed    { background: #fee2e2; color: #991b1b; }
.bmc-sub-access-hint { display: block; font-size: 11px; color: #d97706; margin-top: 3px; }
.bmc-receipt-link { font-size: 12px; font-weight: 500; color: #2563eb; text-decoration: none; white-space: nowrap; }
.bmc-receipt-link:hover { text-decoration: underline; }

/* Actions dropdown */
.bmc-sub-actions { position: relative; display: inline-block; }
.bmc-sub-actions__trigger { background: none; border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px 6px; cursor: pointer; color: #9ca3af; display: flex; align-items: center; transition: color .15s, border-color .15s; }
.bmc-sub-actions__trigger:hover { color: #374151; border-color: #d1d5db; }
.bmc-sub-actions__menu { display: none; position: absolute; right: 0; top: calc(100% + 4px); background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,.1); min-width: 180px; z-index: 9999; padding: 4px; }
.bmc-account-table { position: relative; }
.bmc-account-table td { position: relative; }
.bmc-sub-actions.bmc-sub-actions--open .bmc-sub-actions__menu { display: block; }
.bmc-sub-actions__item { display: block; width: 100%; text-align: left; padding: 8px 12px; border: none; background: none; font-size: 13px; color: #374151; cursor: pointer; border-radius: 6px; }
.bmc-sub-actions__item:hover { background: #f3f4f6; }
.bmc-sub-actions__item--danger { color: #dc2626; }
.bmc-sub-actions__item--danger:hover { background: #fef2f2; }
</style>

<script>
(function(){
    // Toggle dropdown
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.bmc-sub-actions__trigger');
        var allMenus = document.querySelectorAll('.bmc-sub-actions');

        if (trigger) {
            e.stopPropagation();
            var parent = trigger.closest('.bmc-sub-actions');
            var isOpen = parent.classList.contains('bmc-sub-actions--open');
            allMenus.forEach(function(m) { m.classList.remove('bmc-sub-actions--open'); });
            if (!isOpen) parent.classList.add('bmc-sub-actions--open');
            return;
        }

        allMenus.forEach(function(m) { m.classList.remove('bmc-sub-actions--open'); });
    });

    // Cancel subscription
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-bmc-cancel-sub]');
        if (!btn) return;

        var subId = btn.getAttribute('data-bmc-cancel-sub');
        if (!subId) return;

        // phpcs:ignore -- inline JS template
        if (!confirm('<?php echo esc_js(__('Are you sure you want to cancel this subscription? You will lose access to member-only content.', 'buy-me-coffee')); ?>')) {
            return;
        }

        btn.disabled = true;
        btn.textContent = '<?php echo esc_js(__('Cancelling...', 'buy-me-coffee')); ?>';

        var formData = new FormData();
        formData.append('action', 'buymecoffee_cancel_subscription');
        formData.append('subscription_id', subId);
        formData.append('buymecoffee_nonce', '<?php echo esc_js(wp_create_nonce('buymecoffee_nonce')); ?>');

        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.data?.message || '<?php echo esc_js(__('Failed to cancel subscription.', 'buy-me-coffee')); ?>');
                btn.disabled = false;
                btn.textContent = '<?php echo esc_js(__('Cancel subscription', 'buy-me-coffee')); ?>';
            }
        })
        .catch(function() {
            alert('<?php echo esc_js(__('Request failed. Please try again.', 'buy-me-coffee')); ?>');
            btn.disabled = false;
            btn.textContent = '<?php echo esc_js(__('Cancel subscription', 'buy-me-coffee')); ?>';
        });
    });
})();
</script>
