<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
use BuyMeCoffee\Classes\Vite;
use BuyMeCoffee\Helpers\SanitizeHelper;
$isAdmin = current_user_can('manage_options');

// Subscriber account nav link — always visible for logged-in subscribers on share page.
$bmcNavAccountUrl = '';
$showSubscriberNavProfile = false;
if (!$isAdmin && is_user_logged_in()) {
    $currentUser = wp_get_current_user();
    $roles = is_array($currentUser->roles ?? null) ? $currentUser->roles : [];
    $showSubscriberNavProfile = in_array('subscriber', $roles, true);

    if ($showSubscriberNavProfile) {
        $bmcNavSettings = get_option('buymecoffee_payment_setting', []);
        $bmcNavEnabled  = !empty($bmcNavSettings['enable_account']) && $bmcNavSettings['enable_account'] === 'yes';
        $bmcNavPageId   = $bmcNavEnabled && !empty($bmcNavSettings['account_page_id']) ? (int) $bmcNavSettings['account_page_id'] : 0;

        // Prefer dedicated account page when configured, otherwise fallback to WP profile.
        $bmcNavAccountUrl = $bmcNavPageId ? get_permalink($bmcNavPageId) : admin_url('profile.php');
    }
}
$recentSupporters = [];
if (class_exists('\BuyMeCoffee\Models\Supporters')) {
    $supportersModel = new \BuyMeCoffee\Models\Supporters();
    $recentSupporters = $supportersModel->getLatest(5);
}

$bmcAccentColor  = SanitizeHelper::cssColor(Arr::get($template, 'advanced.button_style', ''), 'rgb(13, 148, 136)');
$bmcAccentBg     = SanitizeHelper::cssColor(Arr::get($template, 'advanced.bg_style', ''), 'rgba(13, 148, 136, 5%)');
$bmcAccentBorder = SanitizeHelper::cssColor(Arr::get($template, 'advanced.border_style', ''), 'rgba(13, 148, 136, 25%)');
$bmcAccentSubtle = SanitizeHelper::rgbToRgba($bmcAccentColor, '2%');
$bmcAccentRing   = SanitizeHelper::rgbToRgba($bmcAccentColor, '10%');
$bmcAccentVars   = '--bmc-accent: ' . $bmcAccentColor . '; --bmc-accent-soft: ' . $bmcAccentBg . '; --bmc-accent-subtle: ' . $bmcAccentSubtle . '; --bmc-accent-border: ' . $bmcAccentBorder . '; --bmc-accent-ring: ' . $bmcAccentRing . ';';
$bmcBannerPositionX = max(0, min(100, (float) Arr::get($template, 'advanced.banner_position_x', 50)));
$bmcBannerPositionY = max(0, min(100, (float) Arr::get($template, 'advanced.banner_position_y', 50)));
$bmcBannerZoom      = max(1, min(3, (float) Arr::get($template, 'advanced.banner_zoom', 1)));
$bmcBannerVars      = '--bmc-banner-position-x: ' . $bmcBannerPositionX . '%; --bmc-banner-position-y: ' . $bmcBannerPositionY . '%; --bmc-banner-zoom: ' . $bmcBannerZoom . ';';
?>

<div id="bmc-page-wrapper" class="bmc-page-wrapper" style="<?php echo esc_attr($bmcAccentVars); ?>">

    <!-- ── Top Nav ── -->
    <nav class="bmc-nav">
        <div class="bmc-nav__left">
            <?php if (!empty($profile_image)) : ?>
            <img class="bmc-nav__avatar"
                 src="<?php echo esc_url($profile_image); ?>"
                 alt="<?php echo esc_attr($name); ?>"
                 data-field="image"
                 onerror="this.style.display='none'">
            <?php else : ?>
            <span class="bmc-nav__avatar-fallback"><?php echo esc_html(mb_strtoupper(mb_substr($name, 0, 1))); ?></span>
            <?php endif; ?>
            <span class="bmc-nav__name"><?php echo esc_html($name); ?></span>
        </div>
        <div class="bmc-nav__center"></div>
        <div class="bmc-nav__right">
            <button class="bmc-nav__icon-btn" onclick="if(navigator.share){navigator.share({title:document.title,url:window.location.href})}else{navigator.clipboard.writeText(window.location.href).then(function(){var b=document.querySelector('.bmc-nav__icon-btn');b.classList.add('bmc-nav__icon-btn--active');setTimeout(function(){b.classList.remove('bmc-nav__icon-btn--active')},1500)})}" title="<?php esc_attr_e('Share', 'buy-me-coffee'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/></svg>
            </button>
            <?php if ($isAdmin): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=buy-me-coffee.php#/settings?tab=appearance')); ?>"
               class="bmc-nav__icon-btn" title="<?php esc_attr_e('Settings', 'buy-me-coffee'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($showSubscriberNavProfile && $bmcNavAccountUrl): ?>
            <a href="<?php echo esc_url($bmcNavAccountUrl); ?>"
               class="bmc-nav__icon-btn" title="<?php esc_attr_e('My account', 'buy-me-coffee'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- ── Banner ── -->
    <div class="bmc-banner"
         style="<?php echo esc_attr($bmcBannerVars); ?>"
         data-banner-position-x="<?php echo esc_attr($bmcBannerPositionX); ?>"
         data-banner-position-y="<?php echo esc_attr($bmcBannerPositionY); ?>"
         data-banner-zoom="<?php echo esc_attr($bmcBannerZoom); ?>"
         data-field="banner_image">
        <?php if (!empty($banner_image)): ?>
        <img class="bmc-banner__image"
             src="<?php echo esc_url($banner_image); ?>"
             alt=""
             draggable="false">
        <?php else: ?>
        <div class="bmc-banner__gradient"></div>
        <?php endif; ?>
    </div>

    <!-- ── Main Content ── -->
    <div class="bmc-main">
        <?php
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public receipt page display
        if (isset($_REQUEST['buymecoffee_success']) && isset($_REQUEST['hash'])):
            $hash = sanitize_text_field(wp_unslash($_REQUEST['hash']));
            $paymentData = (new \BuyMeCoffee\Models\Supporters())->getByHash($hash);
        ?>
            <div class="bmc-content bmc-content--single">
                <?php include BUYMECOFFEE_DIR . 'includes/views/templates/Confirmation.php'; ?>
            </div>
        <?php else: ?>

        <!-- Left column: About + Supporters -->
        <div class="bmc-sidebar-col">
            <div class="bmc-about-card">
                <div class="bmc-about-card__header">
                    <?php /* translators: %s: creator name */ ?>
                    <h3 class="bmc-about-card__title"><?php printf(esc_html__('About %s', 'buy-me-coffee'), esc_html($name)); ?></h3>
                </div>
                <?php if ($quote): ?>
                <p class="bmc-about-card__bio" data-field="quote"><?php echo esc_html($quote); ?></p>
                <?php else: ?>
                <p class="bmc-about-card__bio bmc-about-card__bio--empty"><?php esc_html_e('No bio yet.', 'buy-me-coffee'); ?></p>
                <?php endif; ?>

                <?php if (Arr::get($template, 'show_recent_supporters', 'yes') === 'yes'): ?>
                <div class="bmc-about-card__divider"></div>

                <h4 class="bmc-about-card__section-title"><?php esc_html_e('Recent supporters', 'buy-me-coffee'); ?></h4>
                <?php if (!empty($recentSupporters)): ?>
                <ul class="bmc-supporters-list">
                    <?php foreach ($recentSupporters as $s): ?>
                    <li class="bmc-supporters-list__item">
                        <span class="bmc-supporters-list__avatar">
                            <?php echo esc_html(mb_strtoupper(mb_substr($s->supporters_name ?: 'A', 0, 1))); ?>
                        </span>
                        <div class="bmc-supporters-list__info">
                            <span class="bmc-supporters-list__name"><?php echo esc_html($s->supporters_name ?: __('Anonymous', 'buy-me-coffee')); ?></span>
                            <span class="bmc-supporters-list__meta">
                                <?php echo esc_html($s->coffee_count); ?> <?php echo $s->coffee_count > 1 ? esc_html__('coffees', 'buy-me-coffee') : esc_html__('coffee', 'buy-me-coffee'); ?>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="bmc-about-card__empty">
                    <span class="bmc-about-card__empty-icon">💛</span>
                    <p><?php esc_html_e('Be the first to support!', 'buy-me-coffee'); ?></p>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right column: Form -->
        <div class="bmc-form-col">
            <?php include BUYMECOFFEE_DIR . 'includes/views/templates/FormSection.php'; ?>
        </div>

        <?php endif; ?>
        <?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>
    </div>

    <!-- ── Footer ── -->
    <div class="bmc-footer">
        <p><?php esc_html_e('Powered by', 'buy-me-coffee'); ?>
            <a href="https://wpminers.com/buymecoffee/" target="_blank" rel="noopener">Buy Me Coffee</a>
        </p>
    </div>
</div>
