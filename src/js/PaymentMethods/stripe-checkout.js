import BmcCoffeeLoader from '../utils/coffeeLoader.js';

class StripeCheckout {
    constructor ($form, $response) {
        this.form           = $form;
        this.data           = $response.data;
        this.intent         = $response.data?.intent;
        this.subscriptionId = $response.data?.subscription_id || null;
        this.parentWrapper  = this.form.parents('.bmc-form-card, .buymecoffee_form_preview_wrapper').first();
        this.loader         = new BmcCoffeeLoader(this.parentWrapper[0]);
        this.payButton      = null;
    }

    init () {
        this.startPaymentProcessing();
        this.payButton = this.generatePayButton();

        var stripe = Stripe(this.data?.order_items?.payment_args?.public_key);
        const elements = stripe.elements({
            clientSecret: this.intent.client_secret
        });
        const paymentElement = elements.create('payment', {});

        const formSelector = '#' + this.form.attr('id') + ' .buymecoffee_payment_processor';
        paymentElement.mount(formSelector);

        let self= this;
        paymentElement.on('ready', () => {
            this.afterPaymentProcessorReady(this.payButton);
            this.payButton.on('click', async function(e) {
                e.preventDefault();
                self.payButton.attr('disabled', true);
                self.loader.show('Processing your payment...');

                try {
                    await elements.submit();

                    const result = await stripe.confirmPayment({
                        elements,
                        confirmParams: {},
                        redirect: 'if_required'
                    });

                    if (result.error) {
                        self.showError(result.error.message || 'Stripe could not confirm your payment. Please try again.');
                        return;
                    }

                    const intentId = result?.paymentIntent?.id;
                    if (!intentId) {
                        self.showError('Stripe did not return a payment intent. Please try again.');
                        return;
                    }

                    const confirmPayload = {
                        action: 'buymecoffee_payment_confirmation_stripe',
                        buymecoffee_nonce: window.buymecoffee_general?.buymecoffee_nonce || '',
                        intentId,
                    };
                    if (self.subscriptionId) {
                        confirmPayload.subscriptionId = self.subscriptionId;
                    }

                    await jQuery.post(window.buymecoffee_general.ajax_url, confirmPayload);
                    self.afterPaymentSuccess();
                } catch (error) {
                    const message = error?.responseJSON?.data?.message || error?.message || 'Payment could not be confirmed. Please try again.';
                    self.showError(message);
                } finally {
                    if (self.payButton) {
                        self.payButton.removeAttr('disabled');
                    }
                    self.loader.hide();
                }
            });
        });
    }

    generatePayButton() {
        let amounPrefix = this.form.find('.wpm_payment_total_amount_prefix').text();
        let buttonText = "Pay " + amounPrefix + (parseInt(this.intent.amount) / 100) + " Now";
        return jQuery("<button type='submit' class='buymecoffee_pay_now'></button>").text(buttonText);
    }

    startPaymentProcessing() {
        this.form.find('.buymecoffee_input_content, .buymecoffee_pay_methods, .buymecoffee_payment_item, .buymecoffee_form_submit_wrapper, .buymecoffee_no_signup, .buymecoffee_recurring_section').hide();
        this.loader.show('Setting up payment...');
    }

    afterPaymentSuccess() {
        const isSubscription = !!this.data?.is_subscription;
        const message = isSubscription
            ? "Recurring donation set up successfully 🖤"
            : "Thanks for your contribution 🖤";

        const receiptContainer = jQuery("<div class='buymecoffee_form_receipt'></div>");
        receiptContainer.append(document.createTextNode(message));
        receiptContainer.append('<br/>');

        const safeReceiptUrl = this.getSafeReceiptUrl(this.data?.order_items?.payment_args?.success_url);
        if (safeReceiptUrl) {
            const receiptLink = jQuery('<a></a>')
                .attr('href', safeReceiptUrl)
                .text('View Receipt');
            receiptContainer.append(receiptLink);
        }

        if (isSubscription) {
            const accountPageUrl = this.getSafeReceiptUrl(window.buymecoffee_general?.account_page_url);
            if (accountPageUrl) {
                if (safeReceiptUrl) {
                    receiptContainer.append(document.createTextNode(' · '));
                }
                const accountLink = jQuery('<a></a>')
                    .attr('href', accountPageUrl)
                    .text('View subscriptions');
                receiptContainer.append(accountLink);
            }
        }

        this.parentWrapper.append(receiptContainer);
        this.parentWrapper.find('.buymecoffee_form').hide();
        this.parentWrapper.find('.buymecoffee_form_to, .bmc-form-card__title').hide();
    }

    afterPaymentProcessorReady(payButton) {
        this.loader.hide();
        this.form.prepend("<p class='complete_payment_instruction'>Please complete your donation with Stripe 👇</p>");
        this.form.find('.buymecoffee_payment_processor').append(payButton);
    }

    getSafeReceiptUrl(url) {
        if (!url) {
            return null;
        }

        try {
            const parsed = new URL(url, window.location.origin);
            if (!['http:', 'https:'].includes(parsed.protocol)) {
                return null;
            }

            if (parsed.origin !== window.location.origin) {
                return null;
            }

            return parsed.toString();
        } catch (e) {
            return null;
        }
    }

    showError(message) {
        if (message) {
            alert(message);
        }
    }
  }
  
  window.addEventListener("buymecoffee_payment_next_action_stripe", function (e) {
    new StripeCheckout(e.detail.form, e.detail.response).init();
  });