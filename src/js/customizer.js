/**
 * Buy Me Coffee — Inline Page Editor (admin only)
 *
 * Banner: "Edit cover" button in corner -> upload, drag crop, and zoom.
 * Avatar: hover overlay → click to upload or drag image.
 * Name & bio: click to edit inline.
 * Color panel: palette FAB toggle.
 * All changes auto-save via AJAX.
 */
jQuery(document).ready(function ($) {
    const ajaxUrl = window.buymecoffee_customizer_data?.ajax_url || window.buymecoffee_general?.ajax_url;
    const nonce = window.buymecoffee_customizer_data?.buymecoffee_nonce || window.buymecoffee_general?.buymecoffee_nonce;

    if (!ajaxUrl || !nonce) return;

    const state = {};
    let saveTimer = null;

    const presetColors = [
        'rgb(13, 148, 136)',
        'rgb(255, 129, 63)',
        'rgb(255, 95, 95)',
        'rgb(95, 127, 255)',
        'rgb(189, 95, 255)',
        'rgb(244, 113, 255)',
        'rgb(38, 176, 161)',
        'rgb(253, 181, 0)',
    ];

    const $wrapper = $('#bmc-page-wrapper');
    $wrapper.addClass('bmc-editing');

    const cameraSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/>' +
        '<circle cx="12" cy="13" r="3"/></svg>';

    const pencilSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>';

    // ── Banner: "Edit cover" button ────────────
    const $bannerBtn = $('<button class="bmc-banner__edit-btn">' + pencilSvg + ' Edit cover</button>');
    const $bannerOverlay = $('<div class="bmc-banner__overlay">' +
        '<div class="bmc-banner__overlay-content">' +
        cameraSvg +
        '<span>Drag to position cover</span>' +
        '<span class="bmc-banner__overlay-sub">Drop an image here or use Change</span>' +
        '</div></div>');
    const $bannerControls = $('<div class="bmc-banner__controls">' +
        '<button type="button" class="bmc-banner__control-btn" data-bmc-banner-change>Change</button>' +
        '<label class="bmc-banner__zoom"><span>Zoom</span><input type="range" min="1" max="3" step="0.01"></label>' +
        '<button type="button" class="bmc-banner__control-btn" data-bmc-banner-reset>Reset</button>' +
        '</div>');

    const $banner = $wrapper.find('.bmc-banner');
    $banner.append($bannerBtn).append($bannerOverlay).append($bannerControls);

    let bannerEditActive = false;
    let bannerDrag = null;
    const bannerCrop = {
        x: clampNumber(parseFloat($banner.data('bannerPositionX')), 0, 100, 50),
        y: clampNumber(parseFloat($banner.data('bannerPositionY')), 0, 100, 50),
        zoom: clampNumber(parseFloat($banner.data('bannerZoom')), 1, 3, 1),
    };
    const $bannerZoomInput = $bannerControls.find('input[type="range"]');
    applyBannerCrop(false);

    $bannerBtn.on('click', function (e) {
        e.stopPropagation();
        if (bannerEditActive) {
            closeBannerEdit();
        } else {
            openBannerEdit();
        }
    });

    function openBannerEdit() {
        bannerEditActive = true;
        $banner.addClass('bmc-banner--edit-active');
        $bannerBtn.addClass('bmc-banner__edit-btn--active').html(pencilSvg + ' Done');
        $bannerZoomInput.val(String(bannerCrop.zoom));
    }

    function closeBannerEdit() {
        bannerEditActive = false;
        $banner.removeClass('bmc-banner--edit-active');
        $bannerBtn.removeClass('bmc-banner__edit-btn--active').html(pencilSvg + ' Edit cover');
    }

    // Click the empty placeholder -> open media picker.
    $bannerOverlay.on('click', function (e) {
        if (hasBannerImage()) return;
        e.stopPropagation();
        pickBannerImage();
    });

    $bannerControls.find('[data-bmc-banner-change]').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        pickBannerImage();
    });

    $bannerControls.find('[data-bmc-banner-reset]').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        bannerCrop.x = 50;
        bannerCrop.y = 50;
        bannerCrop.zoom = 1;
        applyBannerCrop(true);
        debounceSave();
        showToast('Cover reset');
    });

    $bannerZoomInput.on('input', function () {
        bannerCrop.zoom = clampNumber(parseFloat(this.value), 1, 3, 1);
        applyBannerCrop(true);
        debounceSave();
    });

    // Banner drag-and-drop (works when edit active)
    $banner.on('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!bannerEditActive) openBannerEdit();
        $(this).addClass('bmc-banner--dragover');
    });

    $banner.on('dragleave', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('bmc-banner--dragover');
    });

    $banner.on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('bmc-banner--dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length && files[0].type.startsWith('image/')) {
            uploadFile(files[0], function (url) {
                setBannerImage(url);
                debounceSave();
                showToast('Cover updated');
            });
        }
    });

    $banner.on('pointerdown', function (e) {
        if (!bannerEditActive || !hasBannerImage()) return;
        if ($(e.target).closest('.bmc-banner__edit-btn, .bmc-banner__controls').length) return;

        e.preventDefault();
        e.stopPropagation();

        const rect = $banner[0].getBoundingClientRect();
        bannerDrag = {
            pointerId: e.originalEvent.pointerId,
            startX: e.originalEvent.clientX,
            startY: e.originalEvent.clientY,
            startCropX: bannerCrop.x,
            startCropY: bannerCrop.y,
            width: rect.width || 1,
            height: rect.height || 1,
        };
        $banner.addClass('bmc-banner--repositioning');
        $banner[0].setPointerCapture(bannerDrag.pointerId);
    });

    $banner.on('pointermove', function (e) {
        if (!bannerDrag) return;

        e.preventDefault();
        const dx = e.originalEvent.clientX - bannerDrag.startX;
        const dy = e.originalEvent.clientY - bannerDrag.startY;
        const zoomDragFactor = Math.max(1, bannerCrop.zoom);

        bannerCrop.x = clampNumber(bannerDrag.startCropX - ((dx / bannerDrag.width) * 100 / zoomDragFactor), 0, 100, 50);
        bannerCrop.y = clampNumber(bannerDrag.startCropY - ((dy / bannerDrag.height) * 100 / zoomDragFactor), 0, 100, 50);
        applyBannerCrop(true);
    });

    $banner.on('pointerup pointercancel', function () {
        if (!bannerDrag) return;

        $banner.removeClass('bmc-banner--repositioning');
        try {
            $banner[0].releasePointerCapture(bannerDrag.pointerId);
        } catch (err) {
            // Pointer capture can already be released by the browser.
        }
        bannerDrag = null;
        flushSave();
    });

    // ── Avatar: hover overlay ──────────────────
    const $avatarOverlay = $('<div class="bmc-avatar__overlay">' + cameraSvg + '</div>');
    const $avatarWrap = $wrapper.find('.bmc-profile__avatar-wrap');
    $avatarWrap.append($avatarOverlay);

    $avatarOverlay.on('click', function (e) {
        e.stopPropagation();
        openMediaPicker('Select Profile Image', function (url) {
            setProfileImage(url);
            debounceSave();
        });
    });

    $avatarWrap.on('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('bmc-avatar-wrap--dragover');
    });

    $avatarWrap.on('dragleave drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('bmc-avatar-wrap--dragover');
    });

    $avatarWrap.on('drop', function (e) {
        const files = e.originalEvent.dataTransfer.files;
        if (files.length && files[0].type.startsWith('image/')) {
            uploadFile(files[0], function (url) {
                setProfileImage(url);
                debounceSave();
                showToast('Photo updated');
            });
        }
    });

    // ── Name & bio inline edit ─────────────────
    const $name = $wrapper.find('.bmc-profile__name');
    const $bio = $wrapper.find('.bmc-about-card__bio').not('.bmc-about-card__bio--empty');
    const $navName = $wrapper.find('.bmc-nav__name');
    const $aboutTitle = $wrapper.find('.bmc-about-card__title');

    $name.attr('contenteditable', 'true').attr('data-placeholder', 'Your name');
    if ($bio.length) {
        $bio.attr('contenteditable', 'true').attr('data-placeholder', 'Write a short bio...');
    }

    $name.on('input', function () {
        state.yourName = $(this).text().trim();
        $navName.text(state.yourName);
        $aboutTitle.text('About ' + state.yourName);
        debounceSave();
    });

    $bio.on('input', function () {
        state.quote = $(this).text().trim();
        debounceSave();
    });

    $name.add($bio).on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).blur();
        }
    });

    // ── Color FAB + Panel ──────────────────────
    let colorsHtml = '';
    presetColors.forEach(function (c) {
        colorsHtml += '<li class="bmc-edit-panel__color" data-color="' + c + '" style="background:' + c + '"></li>';
    });

    const $fab = $('<button class="bmc-edit-fab" title="Accent color">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/>' +
        '<circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>' +
        '</svg></button>');

    const $panel = $('<div class="bmc-edit-panel">' +
        '<p class="bmc-edit-panel__label">Accent Color</p>' +
        '<ul class="bmc-edit-panel__colors">' + colorsHtml + '</ul>' +
        '</div>');

    $('body').append($fab).append($panel);

    $fab.on('click', function () {
        $panel.toggleClass('bmc-edit-panel--visible');
        $fab.toggleClass('bmc-edit-fab--active');
    });

    $panel.find('.bmc-edit-panel__color').on('click', function () {
        $panel.find('.bmc-edit-panel__color').removeClass('bmc-edit-panel__color--active');
        $(this).addClass('bmc-edit-panel__color--active');

        const color = $(this).data('color');
        state.button_style = color;
        state.border_style = rgbToRgba(color, '25%');
        state.bg_style = rgbToRgba(color, '5%');

        $('#bmc-page-wrapper, .bmc-form-card').css({
            '--bmc-accent': state.button_style,
            '--bmc-accent-soft': state.bg_style,
            '--bmc-accent-subtle': rgbToRgba(color, '2%'),
            '--bmc-accent-border': state.border_style,
            '--bmc-accent-ring': rgbToRgba(color, '10%'),
        });
        $('.buymecoffee_payment_input_content').css({
            'background-color': state.bg_style,
            'border-color': state.border_style,
        });
        $('.buymecoffee_currency_prefix').css('background-color', color);
        $('button.wpm_submit_button').css('background-color', color);
        debounceSave();
    });

    // ── Toast ──────────────────────────────────
    const $toast = $('<div class="bmc-toast"></div>');
    $('body').append($toast);

    // ── Upload file via WP AJAX ────────────────
    function uploadFile(file, callback) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'upload-attachment');
        formData.append('_wpnonce', window._wpMediaSettings?.nonce || '');

        showToast('Uploading...');

        $.ajax({
            url: window.ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (res) {
                if (res.success && res.data && res.data.url) {
                    callback(res.data.url);
                } else {
                    showToast('Upload failed — try clicking to upload');
                }
            },
            error: function () {
                showToast('Upload failed');
            },
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

    function pickBannerImage() {
        openMediaPicker('Select Cover Image', function (url) {
            setBannerImage(url);
            debounceSave();
            openBannerEdit();
        });
    }

    function setBannerImage(url) {
        state.banner_image = url;
        bannerCrop.x = 50;
        bannerCrop.y = 50;
        bannerCrop.zoom = 1;
        $banner.find('.bmc-banner__gradient').remove();

        let $image = $banner.find('.bmc-banner__image');
        if (!$image.length) {
            $image = $('<img class="bmc-banner__image" alt="" draggable="false">');
            $banner.prepend($image);
        }

        $image.attr('src', url);
        applyBannerCrop(true);
    }

    function setProfileImage(url) {
        state.image = url;
        $wrapper.find('.bmc-profile__avatar').attr('src', url);

        const name = $wrapper.find('.bmc-nav__name').first().text().trim();
        const $navImage = $wrapper.find('.bmc-nav__avatar');
        if ($navImage.length) {
            $navImage.attr('src', url);
        } else {
            $wrapper.find('.bmc-nav__avatar-fallback').replaceWith($('<img>', {
                class: 'bmc-nav__avatar',
                src: url,
                alt: name,
                'data-field': 'image',
            }));
        }
    }

    function hasBannerImage() {
        return Boolean($banner.find('.bmc-banner__image').attr('src') || state.banner_image);
    }

    function applyBannerCrop(shouldPersist) {
        $banner.css({
            '--bmc-banner-position-x': bannerCrop.x + '%',
            '--bmc-banner-position-y': bannerCrop.y + '%',
            '--bmc-banner-zoom': bannerCrop.zoom,
        });
        $banner.attr({
            'data-banner-position-x': bannerCrop.x,
            'data-banner-position-y': bannerCrop.y,
            'data-banner-zoom': bannerCrop.zoom,
        });
        $bannerZoomInput.val(String(bannerCrop.zoom));

        if (shouldPersist) {
            state.banner_position_x = roundCropValue(bannerCrop.x);
            state.banner_position_y = roundCropValue(bannerCrop.y);
            state.banner_zoom = roundCropValue(bannerCrop.zoom);
        }
    }

    function clampNumber(value, min, max, fallback) {
        const number = Number.isFinite(value) ? value : fallback;
        return Math.min(max, Math.max(min, number));
    }

    function roundCropValue(value) {
        return Math.round(value * 100) / 100;
    }

    // ── Auto-save ──────────────────────────────
    function debounceSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(save, 1500);
    }

    function flushSave() {
        clearTimeout(saveTimer);
        save();
    }

    function save() {
        if (Object.keys(state).length === 0) return;
        const payload = { ...state };

        $.post(ajaxUrl, {
            action: 'buymecoffee_admin_ajax',
            route: 'save_form_design',
            buymecoffee_nonce: nonce,
            data: payload,
        })
        .then(function () {
            Object.keys(payload).forEach(function (key) {
                if (state[key] === payload[key]) {
                    delete state[key];
                }
            });
            showToast('Saved');
        })
        .catch(function (err) {
            showToast(err?.responseJSON?.data?.message || 'Save failed');
        });
    }

    function rgbToRgba(rgb, alpha) {
        const values = rgb.replace('rgb(', '').replace(')', '').split(',');
        return 'rgba(' + values[0].trim() + ', ' + values[1].trim() + ', ' + values[2].trim() + ', ' + alpha + ')';
    }

    function showToast(msg) {
        $toast.text(msg).addClass('bmc-toast--visible');
        clearTimeout($toast.data('timer'));
        $toast.data('timer', setTimeout(function () {
            $toast.removeClass('bmc-toast--visible');
        }, 2500));
    }
});
