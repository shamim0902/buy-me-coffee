import BmcCoffeeLoader from '../utils/coffeeLoader.js';

class StripeCheckout {
    constructor ($form, $response) {
        this.form           = $form;
        this.data           = $response.data;
        this.intent         = $response.data?.intent;
        this.subscriptionId = $response.data?.subscription_id || null;
        this.parentWrapper  = this.form.parents('.bmc-form-card, .buymecoffee_form_preview_wrapper').first();
        this.loader         = new BmcCoffeeLoader(this.parentWrapper[0]);
    }

    init () {
        this.startPaymentProcessing();
        const payButton = this.generatePayButton();

        var stripe = Stripe(this.data?.order_items?.payment_args?.public_key);
        const elements = stripe.elements({
            clientSecret: this.intent.client_secret
        });
        const paymentElement = elements.create('payment', {});

        const formSelector = '#' + this.form.attr('id') + ' .buymecoffee_payment_processor';
        paymentElement.mount(formSelector);

        let self= this;
        paymentElement.on('ready', () => {
            this.afterPaymentProcessorReady(payButton);
            this.form.find('#buymecoffee_pay_now').on('click', function(e) {
                e.preventDefault();
                jQuery(this).attr('disabled', true);
                self.loader.show('Processing your payment...');
                elements.submit().then(() => {
                    stripe.confirmPayment({
                        elements,
                        confirmParams: {},
                        redirect: 'if_required'
                    }).then((result) => {
                        if (result.error) {
                            self.loader.hide();
                            jQuery('#buymecoffee_pay_now').removeAttr('disabled');
                            return;
                        }
                        self.afterPaymentSuccess();
                        if (result?.paymentIntent?.id) {
                            const confirmPayload = {
                                action: 'buymecoffee_payment_confirmation_stripe',
                                buymecoffee_nonce: window.buymecoffee_general?.buymecoffee_nonce || '',
                                intentId: result.paymentIntent.id,
                            };
                            if (self.subscriptionId) {
                                confirmPayload.subscriptionId = self.subscriptionId;
                            }
                            jQuery.post(window.buymecoffee_general.ajax_url, confirmPayload);
                        }
                    });
                }).catch(() => {
                    self.loader.hide();
                    jQuery('#buymecoffee_pay_now').removeAttr('disabled');
                });
            });
        });
    }
    generatePayButton() {
        let amounPrefix = this.form.find('.wpm_payment_total_amount_prefix').text();
        let buttonText = "Pay " + amounPrefix + (parseInt(this.intent.amount) / 100) + " Now"
        return "<button id='buymecoffee_pay_now' type='submit'>" + buttonText + "</button>";
    }
    startPaymentProcessing() {
        this.form.find('.buymecoffee_input_content, .buymecoffee_pay_methods, .buymecoffee_payment_item, .buymecoffee_form_submit_wrapper, .buymecoffee_no_signup, .buymecoffee_recurring_section').hide();
        this.loader.show('Setting up payment...');
    }

    afterPaymentSuccess() {
        this.loader.hide();
        const isSubscription = !!this.data?.is_subscription;
        const message = isSubscription
            ? "Recurring donation set up successfully 🖤"
            : "Thanks for your contribution 🖤";
        const receipt = "<a href='" + this.data?.order_items?.payment_args?.success_url + "'>View Receipt</a>";
        this.parentWrapper.append("<div class='buymecoffee_form_receipt'>" + message + "<br/>" + receipt + "</div>");
        this.parentWrapper.find('.buymecoffee_form').hide();
        this.parentWrapper.find('.buymecoffee_form_to, .bmc-form-card__title').hide();
    }

    afterPaymentProcessorReady(payButton) {
        this.loader.hide();
        this.form.prepend("<p class='complete_payment_instruction'>Please complete your donation with Stripe 👇</p>");
        this.form.find('.buymecoffee_payment_processor').append(payButton);
    }
  }
  
  window.addEventListener("buymecoffee_payment_next_action_stripe", function (e) {
    new StripeCheckout(e.detail.form, e.detail.response).init();
  });