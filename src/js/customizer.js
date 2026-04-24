/**
 * Buy Me Coffee — Inline Page Editor (admin only)
 *
 * Provides in-place editing of banner, profile image, name, bio,
 * and accent color on the public ?share_coffee page.
 */
jQuery(document).ready(function ($) {
    const ajaxUrl = window.buymecoffee_customizer_data?.ajax_url || window.buymecoffee_general?.ajax_url;
    const nonce = window.buymecoffee_customizer_data?.buymecoffee_nonce || window.buymecoffee_general?.buymecoffee_nonce;

    if (!ajaxUrl || !nonce) return;

    let editing = false;
    let dirty = false;

    // Track changes
    const state = {};

    const presetColors = [
        'rgb(13, 148, 136)',   // teal
        'rgb(255, 129, 63)',   // orange
        'rgb(255, 95, 95)',    // red
        'rgb(95, 127, 255)',   // blue
        'rgb(189, 95, 255)',   // purple
        'rgb(244, 113, 255)',  // pink
        'rgb(38, 176, 161)',   // green
        'rgb(253, 181, 0)',    // amber
    ];

    // ── Build UI ───────────────────────────────
    const $wrapper = $('#bmc-page-wrapper');

    // Edit toggle FAB
    const $fab = $('<button class="bmc-edit-fab">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>' +
        '<span>Edit Page</span></button>');
    $('body').append($fab);

    // Edit panel
    let colorsHtml = '';
    presetColors.forEach(function (c) {
        colorsHtml += '<li class="bmc-edit-panel__color" data-color="' + c + '" style="background:' + c + '"></li>';
    });

    const $panel = $('<div class="bmc-edit-panel">' +
        '<p class="bmc-edit-panel__label">Accent Color</p>' +
        '<ul class="bmc-edit-panel__colors">' + colorsHtml + '</ul>' +
        '<button class="bmc-edit-panel__save">Save Changes</button>' +
        '</div>');
    $('body').append($panel);

    // Edit hints (injected into DOM)
    const cameraIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/>' +
        '<circle cx="12" cy="13" r="3"/></svg>';

    $wrapper.find('.bmc-banner').append('<div class="bmc-banner__edit-hint">' + cameraIcon + ' Change cover</div>');
    $wrapper.find('.bmc-profile__avatar-wrap').append('<div class="bmc-profile__avatar-hint">' + cameraIcon + '</div>');

    // Toast
    const $toast = $('<div class="bmc-toast"></div>');
    $('body').append($toast);

    // ── Toggle edit mode ───────────────────────
    $fab.on('click', function () {
        if (editing) {
            exitEdit();
        } else {
            enterEdit();
        }
    });

    function enterEdit() {
        editing = true;
        $wrapper.addClass('bmc-editing');
        $fab.addClass('bmc-edit-fab--active').find('span').text('Done Editing');
        $panel.addClass('bmc-edit-panel--visible');

        // Make text editable
        const $name = $wrapper.find('.bmc-profile__name');
        const $bio = $wrapper.find('.bmc-profile__bio');

        $name.attr('contenteditable', 'true').attr('data-placeholder', 'Your name');
        if ($bio.length) {
            $bio.attr('contenteditable', 'true').attr('data-placeholder', 'Write a short bio...');
        }

        // Track text changes
        $name.on('input.bmc-edit', function () {
            state.yourName = $(this).text().trim();
            dirty = true;
        });

        $bio.on('input.bmc-edit', function () {
            state.quote = $(this).text().trim();
            dirty = true;
        });

        // Banner click → media picker
        $wrapper.find('.bmc-banner').on('click.bmc-edit', function () {
            openMediaPicker('Select Cover Image', function (url) {
                state.banner_image = url;
                dirty = true;
                const $banner = $wrapper.find('.bmc-banner');
                $banner.css('background-image', 'url(' + url + ')');
                $banner.find('.bmc-banner__gradient').hide();
            });
        });

        // Avatar click → media picker
        $wrapper.find('.bmc-profile__avatar-wrap').on('click.bmc-edit', function () {
            openMediaPicker('Select Profile Image', function (url) {
                state.image = url;
                dirty = true;
                $wrapper.find('.bmc-profile__avatar').attr('src', url);
            });
        });
    }

    function exitEdit() {
        editing = false;
        $wrapper.removeClass('bmc-editing');
        $fab.removeClass('bmc-edit-fab--active').find('span').text('Edit Page');
        $panel.removeClass('bmc-edit-panel--visible');

        // Remove editable
        $wrapper.find('[contenteditable]').removeAttr('contenteditable').off('.bmc-edit');
        $wrapper.find('.bmc-banner').off('.bmc-edit');
        $wrapper.find('.bmc-profile__avatar-wrap').off('.bmc-edit');

        if (dirty) {
            save();
        }
    }

    // ── Color picker ───────────────────────────
    $panel.find('.bmc-edit-panel__color').on('click', function () {
        $panel.find('.bmc-edit-panel__color').removeClass('bmc-edit-panel__color--active');
        $(this).addClass('bmc-edit-panel__color--active');

        const color = $(this).data('color');
        state.button_style = color;
        state.border_style = rgbToRgba(color, '25%');
        state.bg_style = rgbToRgba(color, '5%');
        dirty = true;

        // Live preview
        $('.buymecoffee_payment_input_content').css({
            'background-color': state.bg_style,
            'border-color': state.border_style,
        });
        $('.buymecoffee_currency_prefix').css('background-color', color);
        $('button.wpm_submit_button').css('background-color', color);
    });

    // ── Save button ────────────────────────────
    $panel.find('.bmc-edit-panel__save').on('click', function () {
        save();
    });

    // ── Save via AJAX ──────────────────────────
    function save() {
        if (!dirty) return;

        const $btn = $panel.find('.bmc-edit-panel__save');
        $btn.text('Saving...').prop('disabled', true);

        $.post(ajaxUrl, {
            action: 'buymecoffee_admin_ajax',
            route: 'save_form_design',
            buymecoffee_nonce: nonce,
            data: state,
        })
        .then(function () {
            dirty = false;
            $btn.text('Saved!').addClass('bmc-edit-panel__save--saved');
            showToast('Changes saved');
            setTimeout(function () {
                $btn.text('Save Changes').removeClass('bmc-edit-panel__save--saved').prop('disabled', false);
            }, 2000);
        })
        .catch(function (err) {
            $btn.text('Save Changes').prop('disabled', false);
            showToast(err?.responseJSON?.data?.message || 'Failed to save');
        });
    }

    // ── WP Media Picker ────────────────────────
    function openMediaPicker(title, callback) {
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            showToast('Media library not available');
            return;
        }

        const frame = wp.media({
            title: title,
            button: { text: 'Use this image' },
            multiple: false,
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            if (attachment && attachment.url) {
                callback(attachment.url);
            }
        });

        frame.open();
    }

    // ── Helpers ────────────────────────────────
    function rgbToRgba(rgb, alpha) {
        const values = rgb.replace('rgb(', '').replace(')', '').split(',');
        return 'rgba(' + values[0].trim() + ', ' + values[1].trim() + ', ' + values[2].trim() + ', ' + alpha + ')';
    }

    function showToast(msg) {
        $toast.text(msg).addClass('bmc-toast--visible');
        setTimeout(function () {
            $toast.removeClass('bmc-toast--visible');
        }, 2500);
    }
});
