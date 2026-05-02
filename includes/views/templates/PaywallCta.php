<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Paywall CTA Template
 *
 * Available variables (injected by MonetizationController::renderPaywallCta()):
 *   $levels      — array of active MembershipLevel objects (rewards & access_rules already decoded)
 *   $settings    — array from MonetizationController::getGlobalSettings()
 *   $postId      — int, current post ID
 *   $redirectUrl — string, the subscription form URL
 */

use BuyMeCoffee\Helpers\PaymentHelper;

$ctaHeading = !empty($settings['cta_heading'])
    ? sanitize_text_field($settings['cta_heading'])
    : __('This content is for members only', 'buy-me-coffee');

$ctaSubtext = !empty($settings['cta_subtext'])
    ? sanitize_text_field($settings['cta_subtext'])
    : __('Join to get full access to all posts and exclusive content.', 'buy-me-coffee');
?>
<div class="bmc-paywall">
    <div class="bmc-paywall__fade"></div>

    <div class="bmc-paywall__box">
        <div class="bmc-paywall__lock">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>

        <h3 class="bmc-paywall__title"><?php echo esc_html($ctaHeading); ?></h3>
        <p class="bmc-paywall__subtext"><?php echo esc_html($ctaSubtext); ?></p>

        <?php if (!empty($levels)): ?>
        <div class="bmc-paywall__levels">
            <?php foreach ($levels as $level):
                $price    = (int) $level->price;
                $interval = ($level->interval_type === 'year') ? __('year', 'buy-me-coffee') : __('month', 'buy-me-coffee');
                $rewards  = !empty($level->rewards) ? (array) $level->rewards : [];
                $levelUrl = add_query_arg('bmc_level_id', absint($level->id), $redirectUrl);
                $levelUrl = add_query_arg('bmc_return_url', rawurlencode(get_permalink($postId)), $levelUrl);
                $levelUrl = esc_url($levelUrl);
            ?>
            <div class="bmc-paywall__level">
                <div class="bmc-paywall__level-header">
                    <h4 class="bmc-paywall__level-name"><?php echo esc_html($level->name); ?></h4>
                    <div class="bmc-paywall__level-price">
                        <?php echo esc_html(PaymentHelper::getFormattedAmount($price, 'USD')); ?>
                        <span class="bmc-paywall__level-interval">/ <?php echo esc_html($interval); ?></span>
                    </div>
                </div>

                <?php if (!empty($level->description)): ?>
                <p class="bmc-paywall__level-desc"><?php echo esc_html($level->description); ?></p>
                <?php endif; ?>

                <?php if (!empty($rewards)): ?>
                <ul class="bmc-paywall__rewards">
                    <?php foreach ($rewards as $reward): ?>
                    <li class="bmc-paywall__reward">
                        <svg class="bmc-paywall__reward-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <?php echo esc_html($reward); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <a href="<?php echo esc_url($levelUrl); ?>" class="bmc-paywall__btn">
                    <?php esc_html_e('Join', 'buy-me-coffee'); ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="bmc-paywall__no-levels">
            <a href="<?php echo esc_url($redirectUrl); ?>" class="bmc-paywall__btn">
                <?php esc_html_e('Become a Member', 'buy-me-coffee'); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<style>
.bmc-paywall{position:relative;margin:1.5em 0;padding-top:1px}
.bmc-paywall__fade{position:absolute;top:-60px;left:0;right:0;height:60px;background:linear-gradient(to bottom,transparent,var(--bmc-paywall-bg,#fff));pointer-events:none}
.bmc-paywall__box{background:var(--bmc-paywall-bg,#fff);border:1px solid #e5e7eb;border-radius:16px;padding:2rem;text-align:center}
.bmc-paywall__lock{display:flex;align-items:center;justify-content:center;width:48px;height:48px;background:#f3f4f6;border-radius:50%;margin:0 auto 1rem;color:#6b7280}
.bmc-paywall__lock svg{width:22px;height:22px}
.bmc-paywall__title{font-size:1.25rem;font-weight:700;margin:0 0 .5rem;color:#111}
.bmc-paywall__subtext{color:#6b7280;margin:0 0 1.5rem;font-size:.95rem}
.bmc-paywall__levels{display:flex;flex-wrap:wrap;gap:1rem;justify-content:center}
.bmc-paywall__level{flex:1;min-width:200px;max-width:280px;border:1px solid #e5e7eb;border-radius:12px;padding:1.25rem;text-align:left}
.bmc-paywall__level-header{margin-bottom:.75rem}
.bmc-paywall__level-name{font-size:1rem;font-weight:700;margin:0 0 .25rem;color:#111}
.bmc-paywall__level-price{font-size:1.35rem;font-weight:800;color:#111}
.bmc-paywall__level-interval{font-size:.85rem;font-weight:400;color:#6b7280}
.bmc-paywall__level-desc{font-size:.875rem;color:#6b7280;margin:0 0 .75rem}
.bmc-paywall__rewards{list-style:none;margin:0 0 1rem;padding:0;display:flex;flex-direction:column;gap:.4rem}
.bmc-paywall__reward{display:flex;align-items:flex-start;gap:.5rem;font-size:.875rem;color:#374151}
.bmc-paywall__reward-icon{width:15px;height:15px;flex-shrink:0;margin-top:1px;color:#22c55e}
.bmc-paywall__btn{display:block;width:100%;padding:.65rem 1rem;background:#111;color:#fff;border-radius:8px;text-align:center;text-decoration:none;font-size:.9rem;font-weight:600;transition:background .15s}
.bmc-paywall__btn:hover{background:#374151;color:#fff}
.bmc-paywall__no-levels .bmc-paywall__btn{display:inline-block;width:auto;padding:.75rem 2rem}
</style>
