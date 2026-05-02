import BmcCoffeeLoader from './utils/coffeeLoader.js';
import { formatAmount } from './utils/formatAmount.js';

class BmcFormHandler {
    constructor(form, config) {
        this.form = form;
        this.selector = '.buymecoffee_form';
        this.customQuantity = false;
        this.config = config;
        this.paymentMethod = '';
        this.generalConfig = window.buymecoffee_general;
        this.$formNoticeWrapper = form.parent().find('.buymecoffee_form_notices');
    }

    $t(stringKey) {
        if (this.generalConfig.i18n[stringKey]) {
            return this.generalConfig.i18n[stringKey];
        }
        return '';
    }

    initForm() {
        // Hooks To get third party payment handler
        this.form.trigger('buymecoffee_single', [this, this.config]);

        // Init Calculate Payments and on change re-calculate
        this.calculatePayments();
        this.form.find('.buymecoffee_payment, .bmc_coffee input[type="radio"]').on('change', (e) => {
            this.calculatePayments();
        });

        // on change select radio button quantity
        this.form.find('.bmc_coffee input[type="radio"]').on('change', (e) => {
            this.toggleCustomQuantity(false);
        });

        // on change custom quantity
        this.form.find('.buymecoffee_custom_quantity').on('change', (e) => {
            this.form.find('.bmc_coffee input[type="radio"]').prop('checked', false);
            this.toggleCustomQuantity(true);
        });

        // Payment Method set and handle change event
        this.maybeSelectFirstPaymentMethod();
        this.setSelectedMethod();
        this.form.find('.buymecoffee_pay_method input').on('change', (e) => {
            this.setSelectedMethod();
            //class add for active methods label
            this.form.find('.buymecoffee_pay_methods label').removeClass('wpm_payment_active');
            jQuery(e.target).parent().addClass('wpm_payment_active');
        });


        // Show/require email when recurring is checked
        this.form.find('.buymecoffee_is_recurring').on('change', (e) => {
            this.toggleRecurringEmail(e.target.checked);
        });

        // Level-locked subscriptions: email is always required
        if (this.form.hasClass('bmc-level-locked')) {
            this.toggleRecurringEmail(true);
        }

        this.form.on('submit', (e) => {
            e.preventDefault();
            this.handleFormSubmit(this.form);
        });
    }


    toggleRecurringEmail(isChecked) {
        const emailField = this.form.find('.bmc_email_recurring_only');
        if (emailField.length) {
            if (isChecked) {
                emailField.slideDown(150);
            } else {
                emailField.slideUp(150);
                emailField.find('input').val('');
            }
        }

        const noSignup = this.form.find('.buymecoffee_no_signup');
        if (noSignup.length) {
            noSignup.text(isChecked ? noSignup.data('recurring') : noSignup.data('default'));
        }
    }

    isRecurring(form) {
        return form.find('.buymecoffee_is_recurring').is(':checked') || form.find('input.buymecoffee_is_recurring[type="hidden"]').val() === 'yes';
    }

    handleFormSubmit(form) {
        // Validate email is present when recurring is selected
        if (this.isRecurring(form)) {
            const emailVal = form.find('input.wpm-supporter-email').val()?.trim();
            if (!emailVal) {
                const emailField = form.find('[data-element_type="email"]');
                emailField.addClass('bmc_field_error');
                emailField.find('input').attr('placeholder', 'Email is required for recurring donations');
                form.find('button.wpm_submit_button').removeAttr('disabled');
                emailField.find('input').one('input', () => emailField.removeClass('bmc_field_error'));
                return;
            }
        }

        form.find('button.wpm_submit_button').attr('disabled', true);
        form.addClass('wpm_submitting_form');
        form.parent().find('.wpm_form_notices').hide();

        const card = form.closest('.bmc-form-card, .buymecoffee_form_preview_wrapper')[0] || form.parent()[0];
        const loader = new BmcCoffeeLoader(card);
        loader.show();

        let isRedirecting = false;
        let hasCustomAction = false;

        const request = jQuery.post(window.buymecoffee_general.ajax_url, {
            action: 'buymecoffee_submit',
            buymecoffee_nonce: window.buymecoffee_general?.buymecoffee_nonce || '',
            payment_total: form.data('wpm_payment_total'),
            coffee_count: form.data('coffee_count'),
            payment_method: form.data('wpm_selected_payment_method'),
            currency: form.data('wpm_currency'),
            form_data: jQuery(form).serializeArray(),
            is_recurring: this.isRecurring(form) ? 'yes' : 'no',
            recurring_interval: form.find('.buymecoffee_recurring_section').data('interval') || 'month',
        });

        request.done((response) => {
                loader.hide();
                if (response.data?.redirectTo) {
                    const safeUrl = this.getSafeRedirectUrl(response.data.redirectTo);
                    if (!safeUrl) {
                        alert('Unsafe redirect blocked. Please contact support.');
                        return;
                    }

                    isRedirecting = true;
                    window.location.href = safeUrl;
                    return;
                }
                if (response.data?.actionName == 'custom') {
                    hasCustomAction = true;
                    this.fireCustomEvent(response.data.nextAction, response);
                    return;
                }

                const message = response?.data?.message || 'Unexpected response from server.';
                alert(message);
            });

        request.fail((error) => {
            loader.hide();
            const message = error?.responseJSON?.data?.message || 'Payment request failed. Please try again.';
            alert(message);
        });

        request.always(() => {
            if (!isRedirecting && !hasCustomAction) {
                form.find('button.wpm_submit_button').removeAttr('disabled');
                form.removeClass('wpm_submitting_form');
            }
        });
    }

    fireCustomEvent(eventName, response) {
        window.dispatchEvent(new CustomEvent('buymecoffee_payment_next_action_' + response?.data?.nextAction, {
            detail: {
                form: this.form, response: response
            }
        }));
    }

    maybeSelectFirstPaymentMethod() {
        let hasFirstMethod = this.form.find("input:radio[name='wpm_payment_method']");
        if (hasFirstMethod.length === 1) {
            hasFirstMethod.closest('.buymecoffee_pay_methods')?.hide();
        }
        if (hasFirstMethod.first().length) {
            hasFirstMethod.first().attr('checked', true);
        } else {
            this.form.find('button.wpm_submit_button').css('cursor', 'not-allowed').attr('disabled', true);
        }
    }

    setSelectedMethod() {
        let paymentMethod = this.form.find('.buymecoffee_pay_method input:checked');
        this.form.data('wpm_selected_payment_method', paymentMethod.val());
        paymentMethod.parent().addClass('wpm_payment_active');

        const isLevelLocked = this.form.hasClass('bmc-level-locked');
        const isStripe = paymentMethod.val() === 'stripe';

        if (isLevelLocked) {
            // Level-locked: recurring section and email are always visible, don't touch them
            return;
        } else {
            // Normal: show recurring option only for Stripe
            this.form.find('.buymecoffee_recurring_section').toggle(isStripe);
            if (!isStripe) {
                this.form.find('.buymecoffee_is_recurring').prop('checked', false);
                this.toggleRecurringEmail(false);
            }
        }
    }

    toggleCustomQuantity(val) {
        this.customQuantity = val;
        const customQuantityInput = this.form.find('.buymecoffee_custom_quantity');
        let quantity;
        if (this.customQuantity) {
            customQuantityInput.addClass('custom_quantity_active');
            quantity = this.form.find('.buymecoffee_custom_quantity').val();
        } else {
            quantity = this.form.find('.bmc_coffee input[type="radio"]:checked')?.val();
            customQuantityInput.removeClass('custom_quantity_active');
        }
        quantity = quantity ? parseInt(quantity, 10) : 1;
        this.form.data('coffee_count', quantity);
        this.form.find('input[name="buymecoffee_quantity"]').val(quantity);

        this.calculatePayments();
    }

    calculatePayments() {
        const amount = parseFloat(this.form.find('.buymecoffee_payment').val() || 0);
        const quantity = parseInt(this.form.data('coffee_count') || 1, 10);
        const amountCents = Math.max(0, Math.round(amount * 100 * quantity));
        this.form.data('wpm_payment_total', amountCents);
        this.form.find('input[name="buymecoffee_amount"]').val(amount);
        this.form.find('input[name="buymecoffee_quantity"]').val(quantity);

        const currency = this.form.data('wpm_currency') || window.buymecoffee_general?.default_currency || 'USD';
        const displayTotal = Number.isFinite(amount) ? formatAmount(amountCents, currency) : formatAmount(0, currency);
        this.form.find('.wpm_submit_button .wpm_payment_total_amount').text(displayTotal);
    }

    getSafeRedirectUrl(url) {
        if (!url) {
            return null;
        }

        try {
            const parsed = new URL(url, window.location.origin);
            if (!['http:', 'https:'].includes(parsed.protocol)) {
                return null;
            }

            const allowedExternalHosts = new Set([
                'www.paypal.com',
                'www.sandbox.paypal.com'
            ]);

            if (parsed.origin !== window.location.origin && !allowedExternalHosts.has(parsed.hostname.toLowerCase())) {
                return null;
            }

            return parsed.toString();
        } catch (e) {
            return null;
        }
    }

}

export default BmcFormHandler;
