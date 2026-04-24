<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
use BuyMeCoffee\Helpers\ArrayHelper as Arr;
use BuyMeCoffee\Classes\Vite;
$isAdmin = current_user_can('manage_options');
$recentSupporters = [];
if (class_exists('\BuyMeCoffee\Models\Supporters')) {
    $supportersModel = new \BuyMeCoffee\Models\Supporters();
    $recentSupporters = $supportersModel->getLatest(5);
}
?>

<div id="bmc-page-wrapper" class="bmc-page-wrapper">

    <!-- ── Top Nav ── -->
    <nav class="bmc-nav">
        <div class="bmc-nav__left">
            <img class="bmc-nav__avatar"
                 src="<?php echo esc_url($profile_image); ?>"
                 alt="<?php echo esc_attr($name); ?>"
                 data-field="image">
            <span class="bmc-nav__name"><?php echo esc_html($name); ?></span>
        </div>
        <div class="bmc-nav__center">
            <a href="#" class="bmc-nav__link bmc-nav__link--active"><?php esc_html_e('Home', 'buy-me-coffee'); ?></a>
        </div>
        <div class="bmc-nav__right">
            <?php if ($isAdmin): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=buy-me-coffee.php#/settings?tab=appearance')); ?>"
               class="bmc-nav__edit-btn"><?php esc_html_e('Settings', 'buy-me-coffee'); ?></a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- ── Banner ── -->
    <div class="bmc-banner"
         <?php if (!empty($banner_image)): ?>
         style="background-image: url('<?php echo esc_url($banner_image); ?>')"
         <?php endif; ?>
         data-field="banner_image">
        <?php if (empty($banner_image)): ?>
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
                    <h3 class="bmc-about-card__title"><?php printf(esc_html__('About %s', 'buy-me-coffee'), esc_html($name)); ?></h3>
                    <?php if ($isAdmin): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=buy-me-coffee.php#/settings?tab=appearance')); ?>"
                       class="bmc-about-card__edit"><?php esc_html_e('Edit', 'buy-me-coffee'); ?></a>
                    <?php endif; ?>
                </div>
                <?php if ($quote): ?>
                <p class="bmc-about-card__bio" data-field="quote"><?php echo esc_html($quote); ?></p>
                <?php else: ?>
                <p class="bmc-about-card__bio bmc-about-card__bio--empty"><?php esc_html_e('No bio yet.', 'buy-me-coffee'); ?></p>
                <?php endif; ?>

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
