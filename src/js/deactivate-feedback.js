(function ($) {
    'use strict';

    if (!$) {
        return;
    }

    $(function () {
        var $modal = $('#buymecoffee-deactivate-modal');
        var deactivateUrl = '';
        var config = window.buymecoffee_deactivate || {};
        var pluginBasename = config.plugin_basename || 'buy-me-coffee/buy-me-coffee.php';
        var isSubmitting = false;

        if (!$modal.length) {
            return;
        }

        function isBuyMeCoffeeDeactivateLink(link) {
            var href = link.getAttribute('href') || '';

            if (!href || href.indexOf('action=deactivate') === -1) {
                return false;
            }

            try {
                href = decodeURIComponent(href);
            } catch (error) {
                return false;
            }

            return href.indexOf('plugin=' + pluginBasename) !== -1 || href.indexOf(pluginBasename) !== -1;
        }

        function openModal(url) {
            deactivateUrl = url;
            $modal.fadeIn(120);
            $modal.find('.bmc-deactivate-dialog').attr('tabindex', '-1').trigger('focus');
        }

        function closeModal() {
            $modal.fadeOut(100);
        }

        function continueDeactivate() {
            if (deactivateUrl) {
                window.location.href = deactivateUrl;
            }
        }

        function collectFeedback() {
            return {
                action: 'buymecoffee_deactivation_feedback',
                nonce: config.nonce || '',
                reasons: $modal.find('input[name="bmc_reason[]"]:checked').map(function () {
                    return this.value;
                }).get(),
                feature_missing: $.trim($('#bmc-field-feature').val() || ''),
                other_details: $.trim($('#bmc-field-other').val() || '')
            };
        }

        $('a').filter(function () {
            return isBuyMeCoffeeDeactivateLink(this);
        }).on('click.buymecoffeeDeactivate', function (event) {
            event.preventDefault();
            openModal(this.href);
        });

        $modal.on('change', 'input[name="bmc_reason[]"]', function () {
            var target = $(this).data('show');

            $modal.find('.bmc-deactivate-error').hide();

            if (target) {
                $('#' + target).toggle(this.checked);
            }
        });

        $modal.on('click', '.bmc-deactivate-overlay, #bmc-cancel-deactivate', function (event) {
            event.preventDefault();
            closeModal();
        });

        $modal.on('click', '#bmc-skip-deactivate', function (event) {
            event.preventDefault();
            continueDeactivate();
        });

        $modal.on('click', '#bmc-submit-deactivate', function (event) {
            var $button = $(this);
            var originalText = $button.text();

            event.preventDefault();

            if (!$modal.find('input[name="bmc_reason[]"]:checked').length) {
                $modal.find('.bmc-deactivate-error').show();
                return;
            }

            if (isSubmitting) {
                return;
            }

            isSubmitting = true;
            $button.prop('disabled', true).text('Submitting...');

            $.ajax({
                url: config.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php',
                method: 'POST',
                data: collectFeedback()
            }).always(function () {
                $button.prop('disabled', false).text(originalText);
                continueDeactivate();
            });
        });

        $(document).on('keydown.buymecoffeeDeactivate', function (event) {
            if (event.key === 'Escape' && $modal.is(':visible')) {
                closeModal();
            }
        });
    });
})(window.jQuery);
