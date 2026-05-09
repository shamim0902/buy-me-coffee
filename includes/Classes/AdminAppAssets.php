<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Helpers\PaymentHelper;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class AdminAppAssets
{
    public function enqueue($args = [])
    {
        $args = wp_parse_args($args, [
            'cleanup_admin_chrome'         => false,
            'legacy_upgrade_only_whats_new' => true,
        ]);

        wp_enqueue_media();

        if (!empty($args['cleanup_admin_chrome'])) {
            $this->enqueueAdminChromeCleanup();
        }

        do_action('buymecoffee_render_admin_app');

        wp_enqueue_style(
            'buy-me-coffee-inter-font',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            array(),
            BUYMECOFFEE_VERSION
        );

        Vite::enqueueScript(
            'buy-me-coffee_boot',
            'js/boot.js',
            array(),
            BUYMECOFFEE_VERSION,
            true
        );

        if (!empty($args['cleanup_admin_chrome'])) {
            $this->addAdminNoticeCleanupScript();
        }

        do_action('buymecoffee_booting_admin_app');

        Vite::enqueueScript(
            'buy-me-coffee_js',
            'js/main.js',
            array('jquery'),
            BUYMECOFFEE_VERSION,
            true
        );

        Vite::enqueueStyle(
            'buy-me-coffee_admin_css',
            'scss/admin/app.scss',
            array(),
            BUYMECOFFEE_VERSION
        );

        wp_localize_script('buy-me-coffee_boot', 'BuyMeCoffeeAdmin', $this->getAdminVars((bool) $args['legacy_upgrade_only_whats_new']));
    }

    public function isSetupCompleted()
    {
        $templateSettings = get_option('buymecoffee_payment_setting', []);
        if (is_array($templateSettings) && !empty($templateSettings)) {
            return true;
        }

        $stripe = get_option('buymecoffee_payment_settings_stripe', []);
        if (is_array($stripe) && ($stripe['enable'] ?? '') === 'yes') {
            return !empty($stripe['test_secret_key']) || !empty($stripe['live_secret_key']);
        }

        $paypal = get_option('buymecoffee_payment_settings_paypal', []);
        if (is_array($paypal) && ($paypal['enable'] ?? '') === 'yes') {
            return !empty($paypal['paypal_email'])
                || !empty($paypal['test_secret_key'])
                || !empty($paypal['live_secret_key']);
        }

        return false;
    }

    private function getAdminVars($legacyUpgradeOnlyWhatsNew)
    {
        $setupCompleted = $this->isSetupCompleted();
        $forceGuidedTour = GuidedTour::isForcedForCurrentRequest();
        $showGuidedTour = GuidedTour::shouldShowForCurrentUser($setupCompleted);
        $seenVersion = get_user_meta(get_current_user_id(), 'buymecoffee_whats_new_seen', true);
        $hasSeenOlderVersion = !empty($seenVersion) && version_compare((string) $seenVersion, BUYMECOFFEE_VERSION, '<');
        $isUnseenInstall = empty($seenVersion) && (!$legacyUpgradeOnlyWhatsNew || $this->isLegacyUpgradeInstall());
        $showWhatsNew = ($hasSeenOlderVersion || $isUnseenInstall) && !$showGuidedTour;

        return apply_filters('buymecoffee_admin_app_vars', array_merge(array(
            'assets_url'        => Vite::staticPath(),
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'preview_url'       => site_url('?share_coffee'),
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'wp_admin_url'      => admin_url(),
            'is_wp_admin'       => is_admin(),
            'admin_email'       => get_option('admin_email'),
            'show_whats_new'    => $showWhatsNew,
            'plugin_version'    => BUYMECOFFEE_VERSION,
            'user_name'         => wp_get_current_user()->display_name,
            'setup_completed'   => $setupCompleted,
            'guided_tour_completed' => $forceGuidedTour ? false : GuidedTour::isCompletedForCurrentUser(),
            'force_guided_tour' => $forceGuidedTour,
            'show_guided_tour'  => $showGuidedTour,
        ), PaymentHelper::getFrontendFormattingConfig()));
    }

    private function isLegacyUpgradeInstall()
    {
        return !get_option(Activator::INSTALLED_AT_OPTION)
            && (bool) get_option('buymecoffee_db_version');
    }

    private function enqueueAdminChromeCleanup()
    {
        wp_add_inline_style('wp-admin', '
            body.bmc-admin-page #wpcontent {
                padding-left: 0 !important;
            }
            body.bmc-admin-page #wpbody-content {
                padding-bottom: 0 !important;
            }
            body.bmc-admin-page #wpbody {
                padding-top: 0 !important;
            }
            body.bmc-admin-page .wrap {
                margin: 0 !important;
                padding: 0 !important;
                max-width: none !important;
            }
            body.bmc-admin-page #buy-me-coffee_app {
                margin: 0 !important;
                padding: 0 !important;
            }
            body.bmc-admin-page #wpbody-content > .error {
                display: none !important;
            }
        ');
    }

    private function addAdminNoticeCleanupScript()
    {
        wp_add_inline_script('buy-me-coffee_boot', "
            (function () {
                var app = document.getElementById('buy-me-coffee_app');
                var wpBodyContent = document.getElementById('wpbody-content');

                if (!app || !wpBodyContent || !wpBodyContent.contains(app)) {
                    return;
                }

                Array.prototype.slice.call(wpBodyContent.children).forEach(function (child) {
                    if (child === app || child.contains(app)) {
                        return;
                    }

                    if (child.classList && child.classList.contains('error')) {
                        child.remove();
                    }
                });
            })();
        ", 'before');
    }
}
