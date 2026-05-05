<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit;

class GuidedTour
{
    const ENABLED_OPTION = 'buymecoffee_guided_tour_enabled';
    const REDIRECT_OPTION = 'buymecoffee_activation_redirect';
    const COMPLETED_USER_META = 'buymecoffee_guided_tour_completed';

    public function register()
    {
        add_action('admin_init', [$this, 'maybeRedirectAfterActivation']);
    }

    public function maybeRedirectAfterActivation()
    {
        if (get_option(self::REDIRECT_OPTION) !== 'yes') {
            return;
        }

        if (wp_doing_ajax() || wp_doing_cron() || is_network_admin()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect suppression only.
        if (isset($_GET['activate-multi'])) {
            delete_option(self::REDIRECT_OPTION);
            return;
        }

        if (!AccessControl::hasTopLevelMenuPermission()) {
            return;
        }

        delete_option(self::REDIRECT_OPTION);

        if (self::isCompletedForCurrentUser()) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=buy-me-coffee.php'));
        exit;
    }

    public static function enableForFreshInstall()
    {
        update_option(self::ENABLED_OPTION, 'yes', false);
        update_option(self::REDIRECT_OPTION, 'yes', false);
    }

    public static function isCompletedForCurrentUser()
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return false;
        }

        return get_user_meta($userId, self::COMPLETED_USER_META, true) === 'yes';
    }

    public static function markCompletedForCurrentUser()
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return false;
        }

        update_user_meta($userId, self::COMPLETED_USER_META, 'yes');
        return true;
    }

    public static function shouldShowForCurrentUser($setupCompleted = false)
    {
        if (self::isForcedForCurrentRequest()) {
            return true;
        }

        if ($setupCompleted || get_option(self::ENABLED_OPTION) !== 'yes') {
            return false;
        }

        return !self::isCompletedForCurrentUser();
    }

    public static function isForcedForCurrentRequest()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin UI flag.
        return isset($_GET['bmc_force_guided_tour']) && sanitize_text_field(wp_unslash($_GET['bmc_force_guided_tour'])) === '1';
    }
}
