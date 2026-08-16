<?php

namespace BuyMeCoffee\Controllers;

use BuyMeCoffee\Models\MembershipLevel;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class MonetizationController
{
    public function register()
    {
        add_filter('the_content', [$this, 'filterContent'], 10, 1);
        add_filter('get_the_excerpt', [$this, 'filterExcerpt'], PHP_INT_MAX, 2);
        add_filter('the_content_feed', [$this, 'filterFeedContent'], PHP_INT_MAX, 2);
        add_filter('the_excerpt_rss', [$this, 'filterFeedExcerpt'], PHP_INT_MAX, 1);
        add_filter('buymecoffee_form_render_args', [$this, 'prePopulateFormFromLevel']);

        // Public post types registered by themes/plugins are not all known on
        // plugins_loaded. Register their dynamic REST response filters only
        // after all ordinary post-type registration has completed, while still
        // supporting late-boot environments where REST initialization ran.
        if (did_action('rest_api_init')) {
            $this->registerRestFilters();
        } else {
            add_action('rest_api_init', [$this, 'registerRestFilters'], PHP_INT_MAX);
        }
    }

    public function filterContent($content)
    {
        $post = get_post();

        if (!$post) {
            return $content;
        }

        return $this->protectContent($content, $post, true);
    }

    /**
     * Protect custom and generated excerpts in archives, search, feeds and REST.
     *
     * WordPress may build an automatic excerpt by running `the_content` first,
     * but a hand-written excerpt bypasses that path. Always derive an
     * unauthorized preview from the protected post body instead of trusting the
     * supplied excerpt.
     *
     * @param string       $excerpt Current excerpt.
     * @param \WP_Post|null $post    Post being rendered.
     * @return string
     */
    public function filterExcerpt($excerpt, $post = null)
    {
        $post = get_post($post);

        if (!$post) {
            return $excerpt;
        }

        return $this->protectContent($excerpt, $post, true);
    }

    /**
     * Keep full paid content out of full-content feeds even when another plugin
     * supplies feed content without going through the ordinary content filter.
     *
     * @param string $content  Feed content.
     * @param string $feedType Feed type.
     * @return string
     */
    public function filterFeedContent($content, $feedType = '')
    {
        $post = get_post();

        if (!$post) {
            return $content;
        }

        return $this->protectContent($content, $post, false);
    }

    /**
     * Protect excerpt-only feeds as well as full-content feeds.
     *
     * @param string $excerpt Feed excerpt.
     * @return string
     */
    public function filterFeedExcerpt($excerpt)
    {
        $post = get_post();

        if (!$post) {
            return $excerpt;
        }

        return $this->protectContent($excerpt, $post, false);
    }

    /**
     * Register the dynamic rest_prepare_{post_type} filters for every public
     * REST-enabled post type, including custom types.
     *
     * @return void
     */
    public function registerRestFilters()
    {
        $postTypes = get_post_types([
            'public'       => true,
            'show_in_rest' => true,
        ], 'names');

        foreach ($postTypes as $postType) {
            add_filter('rest_prepare_' . $postType, [$this, 'filterRestResponse'], PHP_INT_MAX, 3);
        }
    }

    /**
     * Remove raw paid content from public REST response fields. Editors retain
     * the ordinary edit-context representation through edit_post capability.
     *
     * @param \WP_REST_Response $response REST response.
     * @param \WP_Post          $post     Post represented by the response.
     * @param \WP_REST_Request  $request  REST request.
     * @return \WP_REST_Response
     */
    public function filterRestResponse($response, $post, $request)
    {
        if (!$post instanceof \WP_Post
            || !$this->isPaidPost((int) $post->ID)
            || $this->canViewFullContent((int) $post->ID)
            || !is_object($response)
            || !method_exists($response, 'get_data')
            || !method_exists($response, 'set_data')) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $teaserText = $this->getTeaserText($post->post_content, (int) $post->ID);
        $teaserHtml = $this->renderTeaser($teaserText);

        if (isset($data['content']) && is_array($data['content'])) {
            if (array_key_exists('raw', $data['content'])) {
                $data['content']['raw'] = $teaserText;
            }
            if (array_key_exists('rendered', $data['content'])) {
                $data['content']['rendered'] = $teaserHtml . $this->renderPaywallCta((int) $post->ID);
            }
        }

        if (isset($data['excerpt']) && is_array($data['excerpt'])) {
            if (array_key_exists('raw', $data['excerpt'])) {
                $data['excerpt']['raw'] = $teaserText;
            }
            if (array_key_exists('rendered', $data['excerpt'])) {
                $data['excerpt']['rendered'] = $teaserHtml;
            }
        }

        $response->set_data($data);

        return $response;
    }

    /**
     * Apply the common paid-content policy to a known post.
     *
     * @param string   $content Current representation.
     * @param \WP_Post $post    Post being represented.
     * @param bool     $withCta Whether the interactive membership CTA is useful.
     * @return string
     */
    private function protectContent($content, $post, $withCta)
    {
        $postId = (int) $post->ID;

        if (!$this->isPaidPost($postId) || $this->canViewFullContent($postId)) {
            return $content;
        }

        $teaserText = $this->getTeaserText($post->post_content, $postId);
        $protected  = $this->renderTeaser($teaserText);

        if ($withCta) {
            $protected .= $this->renderPaywallCta($postId);
        }

        return $protected;
    }

    private function isPaidPost($postId)
    {
        return get_post_meta($postId, '_buymecoffee_access', true) === 'paid';
    }

    private function canViewFullContent($postId)
    {
        if (current_user_can('edit_post', $postId)) {
            return true;
        }

        $userId = get_current_user_id();

        return $userId && $this->userHasAccess($userId, $postId);
    }

    private function getTeaserText($content, $postId)
    {
        $wordCount = $this->getPreviewWordCount($postId);

        return wp_trim_words(wp_strip_all_tags(strip_shortcodes($content), true), $wordCount, '');
    }

    private function renderTeaser($teaserText)
    {
        return '<div class="bmc-gated-content"><p>' . esc_html($teaserText) . '&hellip;</p></div>';
    }

    private function userHasAccess($userId, $postId)
    {
        $userLevelIds = buymecoffee_user_get_active_level_ids($userId);
        if (empty($userLevelIds)) {
            return false;
        }

        $allowedLevelIds = get_post_meta($postId, '_buymecoffee_level_ids', true);
        if (!empty($allowedLevelIds) && is_array($allowedLevelIds)) {
            $allowedLevelIds = array_map('absint', $allowedLevelIds);
            return count(array_intersect($allowedLevelIds, $userLevelIds)) > 0;
        }

        $matchingLevels = $this->getMatchingLevelsForPost($postId, self::getActiveLevels(), true);
        foreach ($matchingLevels as $level) {
            if (in_array((int) $level->id, $userLevelIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function getPreviewWordCount($postId)
    {
        $postOverride = get_post_meta($postId, '_buymecoffee_preview_words', true);
        if ($postOverride !== '' && $postOverride !== false && is_numeric($postOverride) && (int) $postOverride > 0) {
            return (int) $postOverride;
        }

        $settings = self::getGlobalSettings();
        return max(1, (int) ($settings['default_preview_words'] ?? 50));
    }

    private function renderPaywallCta($postId)
    {
        $allLevels   = (new MembershipLevel())->getForAdmin();
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

        return $this->getMatchingLevelsForPost($postId, $levels, false);
    }

    private function getMatchingLevelsForPost($postId, $levels, $fullAccessOnly = false)
    {
        $postType       = get_post_type($postId);
        $postCategories = wp_get_post_categories($postId, ['fields' => 'ids']);

        $filtered = [];
        foreach ($levels as $level) {
            $rules     = $level->access_rules ?: [];
            $types     = !empty($rules['post_types']) ? (array) $rules['post_types'] : [];
            $cats      = !empty($rules['categories']) ? array_map('absint', (array) $rules['categories']) : [];
            $access    = !empty($rules['access_level']) ? sanitize_text_field($rules['access_level']) : 'full';

            if ($fullAccessOnly && $access !== 'full') {
                continue;
            }

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

        return $filtered;
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
        $args['force_recurring'] = empty($level->payment_type) || $level->payment_type === 'subscription';

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
