<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Controllers\MonetizationController;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class PostAccessMetaBox
{
    public function register()
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post', [$this, 'saveMetaBox'], 10, 2);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueGutenbergPanel']);
        add_action('init', [$this, 'registerPostMeta']);
    }

    public function registerPostMeta()
    {
        $publicTypes = get_post_types(['public' => true], 'names');
        foreach ($publicTypes as $postType) {
            register_post_meta($postType, '_buymecoffee_access', [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'default'       => 'free',
                'auth_callback' => function ($allowed, $metaKey, $postId) {
                    return current_user_can('edit_post', (int) $postId);
                },
            ]);
            register_post_meta($postType, '_buymecoffee_level_ids', [
                'show_in_rest'  => [
                    'schema' => [
                        'type'  => 'array',
                        'items' => ['type' => 'integer'],
                    ],
                ],
                'single'        => true,
                'type'          => 'array',
                'default'       => [],
                'auth_callback' => function ($allowed, $metaKey, $postId) {
                    return current_user_can('edit_post', (int) $postId);
                },
            ]);
            register_post_meta($postType, '_buymecoffee_preview_words', [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'integer',
                'default'       => 0,
                'auth_callback' => function ($allowed, $metaKey, $postId) {
                    return current_user_can('edit_post', (int) $postId);
                },
            ]);
        }
    }

    public function addMetaBox()
    {
        $publicTypes = get_post_types(['public' => true], 'names');
        foreach ($publicTypes as $postType) {
            if ($postType === 'attachment') {
                continue;
            }
            add_meta_box(
                'buymecoffee_post_access',
                __('Content Access (Buy Me Coffee)', 'buy-me-coffee'),
                [$this, 'renderMetaBox'],
                $postType,
                'side',
                'default'
            );
        }
    }

    public function renderMetaBox($post)
    {
        wp_nonce_field('buymecoffee_post_access_nonce', 'buymecoffee_post_access_nonce');

        $access        = get_post_meta($post->ID, '_buymecoffee_access', true) ?: 'free';
        $levelIds      = get_post_meta($post->ID, '_buymecoffee_level_ids', true) ?: [];
        $previewWords  = get_post_meta($post->ID, '_buymecoffee_preview_words', true);
        $activeLevels  = MonetizationController::getActiveLevels();

        if (!is_array($levelIds)) {
            $levelIds = [];
        }
        $levelIds = array_map('absint', $levelIds);
        ?>
        <p>
            <strong><?php esc_html_e('Access Level', 'buy-me-coffee'); ?></strong>
        </p>
        <label style="display:block;margin-bottom:6px">
            <input type="radio" name="buymecoffee_access" value="free" <?php checked($access, 'free'); ?>>
            <?php esc_html_e('Free (everyone)', 'buy-me-coffee'); ?>
        </label>
        <label style="display:block;margin-bottom:12px">
            <input type="radio" name="buymecoffee_access" value="paid" <?php checked($access, 'paid'); ?>>
            <?php esc_html_e('Paid (members only)', 'buy-me-coffee'); ?>
        </label>

        <div id="bmc_level_section" style="<?php echo $access === 'paid' ? '' : 'display:none'; ?>">
            <?php if (!empty($activeLevels)): ?>
            <p><strong><?php esc_html_e('Allowed Levels', 'buy-me-coffee'); ?></strong></p>
            <?php foreach ($activeLevels as $level): ?>
            <label style="display:block;margin-bottom:4px">
                <input type="checkbox"
                    name="buymecoffee_level_ids[]"
                    value="<?php echo absint($level->id); ?>"
                    <?php checked(in_array((int) $level->id, $levelIds, true)); ?>>
                <?php echo esc_html($level->name); ?>
            </label>
            <?php endforeach; ?>
            <?php else: ?>
            <p style="color:#888;font-size:12px"><?php esc_html_e('No active membership levels. Create one in Memberships.', 'buy-me-coffee'); ?></p>
            <?php endif; ?>

            <p style="margin-top:12px">
                <label>
                    <strong><?php esc_html_e('Preview words', 'buy-me-coffee'); ?></strong><br>
                    <input type="number" name="buymecoffee_preview_words"
                        value="<?php echo absint($previewWords ?: 0); ?>"
                        min="0" style="width:80px">
                    <span style="color:#888;font-size:11px"><?php esc_html_e('(0 = use global default)', 'buy-me-coffee'); ?></span>
                </label>
            </p>
        </div>

        <script>
        (function(){
            var radios = document.querySelectorAll('input[name="buymecoffee_access"]');
            var section = document.getElementById('bmc_level_section');
            radios.forEach(function(r){
                r.addEventListener('change', function(){
                    section.style.display = r.value === 'paid' ? '' : 'none';
                });
            });
        })();
        </script>
        <?php
    }

    public function saveMetaBox($postId, $post)
    {
        if (!isset($_POST['buymecoffee_post_access_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['buymecoffee_post_access_nonce'])), 'buymecoffee_post_access_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $access = isset($_POST['buymecoffee_access']) && $_POST['buymecoffee_access'] === 'paid' ? 'paid' : 'free';
        update_post_meta($postId, '_buymecoffee_access', $access);

        $levelIds = [];
        if (isset($_POST['buymecoffee_level_ids']) && is_array($_POST['buymecoffee_level_ids'])) {
            $levelIds = array_map('absint', $_POST['buymecoffee_level_ids']);
        }
        update_post_meta($postId, '_buymecoffee_level_ids', $levelIds);

        $previewWords = isset($_POST['buymecoffee_preview_words']) ? absint($_POST['buymecoffee_preview_words']) : 0;
        update_post_meta($postId, '_buymecoffee_preview_words', $previewWords);
    }

    public function enqueueGutenbergPanel()
    {
        $vite = new Vite();

        Vite::enqueueScript(
            'buymecoffee_post_access_panel',
            'js/Editor/postAccessPanel.jsx',
            ['wp-plugins', 'wp-edit-post', 'wp-components', 'wp-data', 'wp-i18n', 'wp-element'],
            BUYMECOFFEE_VERSION,
            true
        );

        wp_localize_script('buymecoffee_post_access_panel', 'BuymecoffeePostAccess', [
            'levels' => MonetizationController::getActiveLevels(),
            'nonce'  => wp_create_nonce('buymecoffee_nonce'),
        ]);
    }
}
