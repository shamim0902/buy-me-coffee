<?php

namespace BuyMeCoffee\Controllers;

use BuyMeCoffee\Models\MembershipLevel;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class MonetizationController
{
    public function register()
    {
        add_filter('the_content', [$this, 'filterContent'], 10, 1);
        add_filter('buymecoffee_form_render_args', [$this, 'prePopulateFormFromLevel']);
    }

    public function filterContent($content)
    {
        if (is_admin() || !is_singular()) {
            return $content;
        }

        $postId = get_the_ID();
        if (!$postId) {
            return $content;
        }

        $access = get_post_meta($postId, '_buymecoffee_access', true);
        if ($access !== 'paid') {
            return $content;
        }

        $userId = get_current_user_id();
        if ($userId && $this->userHasAccess($userId, $postId)) {
            return $content;
        }

        $wordCount = $this->getPreviewWordCount($postId);
        $teaser    = wp_trim_words(wp_strip_all_tags($content, true), $wordCount, '');
        $teaser    = '<p>' . esc_html($teaser) . '&hellip;</p>';

        $paywall = $this->renderPaywallCta($postId);

        return '<div class="bmc-gated-content">' . $teaser . '</div>' . $paywall;
    }

    private function userHasAccess($userId, $postId)
    {
        $allowedLevelIds = get_post_meta($postId, '_buymecoffee_level_ids', true);
        if (empty($allowedLevelIds) || !is_array($allowedLevelIds)) {
            return false;
        }

        $allowedLevelIds = array_map('absint', $allowedLevelIds);
        $userLevelIds    = buymecoffee_user_get_active_level_ids($userId);

        return count(array_intersect($allowedLevelIds, $userLevelIds)) > 0;
    }

    private function getPreviewWordCount($postId)
    {
        $postOverride = get_post_meta($postId, '_buymecoffee_preview_words', true);
        if ($postOverride !== '' && $postOverride !== false && is_numeric($postOverride)) {
            return max(1, (int) $postOverride);
        }

        $settings = self::getGlobalSettings();
        return max(1, (int) ($settings['default_preview_words'] ?? 50));
    }

    private function renderPaywallCta($postId)
    {
        $allLevels   = self::getActiveLevels();
        $levels      = $this->filterLevelsForPost($postId, $allLevels);
        $settings    = self::getGlobalSettings();
        $membershipPaused = !self::isMembershipActive();
        $redirectUrl = !empty($settings['redirect_url'])
            ? esc_url($settings['redirect_url'])
            : esc_url(home_url('/?share_coffee'));

        ob_start();
        include BUYMECOFFEE_DIR . 'includes/views/templates/PaywallCta.php';
        return ob_get_clean();
    }

    private function filterLevelsForPost($postId, $levels)
    {
        if (empty($levels)) {
            return $levels;
        }

        $postType       = get_post_type($postId);
        $postCategories = wp_get_post_categories($postId, ['fields' => 'ids']);

        $filtered = [];
        foreach ($levels as $level) {
            $rules     = $level->access_rules ?: [];
            $types     = !empty($rules['post_types']) ? (array) $rules['post_types'] : [];
            $cats      = !empty($rules['categories']) ? array_map('absint', (array) $rules['categories']) : [];

            // If post_types is set and current post_type not in list, skip
            if (!empty($types) && !in_array($postType, $types, true)) {
                continue;
            }

            // If categories is set and post shares none, skip
            if (!empty($cats) && empty(array_intersect($cats, (array) $postCategories))) {
                continue;
            }

            $filtered[] = $level;
        }

        // Fall back to all levels if none match (show all options on paywall)
        return !empty($filtered) ? $filtered : $levels;
    }

    public function prePopulateFormFromLevel($args)
    {
        if (!self::isMembershipActive()) {
            return $args;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL param, no state change
        if (!isset($_GET['bmc_level_id'])) {
            return $args;
        }

        $levelId = absint($_GET['bmc_level_id']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$levelId) {
            return $args;
        }

        $level = (new MembershipLevel())->find($levelId);
        if (!$level || $level->status !== 'active') {
            return $args;
        }

        $args['bmc_level']       = $level;
        $args['force_recurring'] = true;

        return $args;
    }

    public static function getGlobalSettings()
    {
        $defaults = [
            'default_preview_words'  => 50,
            'cta_heading'            => __('This content is for members only', 'buy-me-coffee'),
            'cta_subtext'            => __('Join to get full access to all posts and exclusive content.', 'buy-me-coffee'),
            'redirect_url'           => '',
            'accept_annual'          => true,
            'display_member_count'   => false,
            'display_earnings'       => false,
            'membership_active'      => true,
            'recovery_modal_enabled' => true,
            'recovery_modal_title'   => __("Don't lose your benefits", 'buy-me-coffee'),
            'recovery_modal_body'    => '',
        ];

        $saved = get_option('buymecoffee_membership_settings', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    public static function isMembershipActive()
    {
        $settings = self::getGlobalSettings();
        return !empty($settings['membership_active']);
    }

    public static function getActiveLevels()
    {
        return (new MembershipLevel())->getActive();
    }
}
