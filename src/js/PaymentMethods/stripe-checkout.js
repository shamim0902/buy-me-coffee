import BmcCoffeeLoader from '../utils/coffeeLoader.js';
import { formatAmount } from '../utils/formatAmount.js';

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
                    const confirmReturnUrl = self.form.find('input[name="bmc_return_url"]').val();
                    if (confirmReturnUrl) {
                        confirmPayload.bmc_return_url = confirmReturnUrl;
                    }

                    const confirmation = await jQuery.post(window.buymecoffee_general.ajax_url, confirmPayload);
                    self.afterPaymentSuccess(confirmation?.data || {});
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
        const currency = this.form.data('wpm_currency') || window.buymecoffee_general?.default_currency || 'USD';
        let buttonText = "Pay " + formatAmount(this.intent.amount, currency) + " Now";
        return jQuery("<button type='submit' class='buymecoffee_pay_now'></button>").text(buttonText);
    }

    startPaymentProcessing() {
        this.form.find('.buymecoffee_input_content, .buymecoffee_pay_methods, .buymecoffee_payment_item, .buymecoffee_form_submit_wrapper, .buymecoffee_no_signup, .buymecoffee_recurring_section').hide();
        this.loader.show('Setting up payment...');
    }

    afterPaymentSuccess(confirmation = {}) {
        const isSubscription = !!this.data?.is_subscription;
        const isMembership = !!this.data?.is_membership;
        const isPaid = confirmation.payment_status === 'paid';
        const accessActive = confirmation.access_active === true || confirmation.access_active === '1';
        const isActive = isPaid && (!isMembership || accessActive);
        // The buyer's email already has an account here and they are not logged
        // in: the server never auto-logs-in an existing account, so they must
        // sign in before the content unlocks.
        const loginUrl = (isMembership && confirmation.login_required) ? this.getSafeReceiptUrl(confirmation.login_url) : null;
        const messageTitle = isActive
            ? (isSubscription ? "Recurring donation set up successfully" : (isMembership ? "Membership activated" : "Thanks for your contribution"))
            : "Payment is processing";
        const messageSubtitle = isActive
            ? (isSubscription ? "Your subscription is active. You can manage everything from your account." : (isMembership ? "Your membership access is active. You can manage everything from your account." : "Your payment was successful. You can view your receipt below."))
            : "Stripe is still confirming this payment. Your access will be available as soon as the payment is completed.";

        const receiptContainer = jQuery("<div class='buymecoffee_form_receipt'></div>");
        const receiptCard = jQuery("<div class='bmc-receipt-success'></div>");
        const receiptHeader = jQuery("<div class='bmc-receipt-success__header'></div>");
        const receiptBadge = jQuery("<span class='bmc-receipt-success__badge' aria-hidden='true'>✓</span>");
        const receiptText = jQuery("<div class='bmc-receipt-success__text'></div>");
        receiptText.append(jQuery("<p class='bmc-receipt-success__title'></p>").text(messageTitle));
        receiptText.append(jQuery("<p class='bmc-receipt-success__subtitle'></p>").text(messageSubtitle));
        receiptHeader.append(receiptBadge, receiptText);
        receiptCard.append(receiptHeader);

        if (loginUrl) {
            const loginNotice = jQuery("<div class='bmc-receipt-success__notice'></div>");
            loginNotice.append(jQuery('<p></p>').text("This email already has an account here, so we did not sign you in automatically. Log in to unlock your member content."));
            loginNotice.append(
                jQuery('<a></a>')
                    .addClass('bmc-receipt-success__link bmc-receipt-success__link--primary')
                    .attr('href', loginUrl)
                    .text('Log in to unlock')
            );
            receiptCard.append(loginNotice);
        }

        const actions = jQuery("<div class='bmc-receipt-success__actions'></div>");

        const safeReceiptUrl = this.getSafeReceiptUrl(this.data?.order_items?.payment_args?.success_url);
        if (safeReceiptUrl) {
            const receiptLink = jQuery('<a></a>')
                .addClass('bmc-receipt-success__link')
                .attr('href', safeReceiptUrl)
                .text('View Receipt');
            actions.append(receiptLink);
        }

        if ((isSubscription || isMembership) && isActive) {
            const accountPageUrl = this.getSafeReceiptUrl(window.buymecoffee_general?.account_page_url);
            if (accountPageUrl) {
                const accountLink = jQuery('<a></a>')
                    .addClass('bmc-receipt-success__link')
                    .attr('href', accountPageUrl)
                    .text('View subscriptions');
                actions.append(accountLink);
            }
        }

        // If coming from a gated post, offer a link back and auto-redirect
        const returnUrl = this.form.find('input[name="bmc_return_url"]').val();
        if (returnUrl) {
            const safeReturnUrl = this.getSafeReceiptUrl(returnUrl);
            if (safeReturnUrl) {
                const returnLink = jQuery('<a></a>')
                    .addClass('bmc-receipt-success__link bmc-receipt-success__link--primary')
                    .attr('href', safeReturnUrl)
                    .text('Return to article');
                actions.prepend(returnLink);
                // Auto-redirect after 3 seconds — unless the buyer must log in
                // first, in which case sending them back would only show the
                // paywall again; the login link carries them back instead.
                if (!loginUrl) {
                    setTimeout(() => { window.location.href = safeReturnUrl; }, 3000);
                }
            }
        }

        if (actions.children().length) {
            receiptCard.append(actions);
        }

        receiptContainer.append(receiptCard);
        this.parentWrapper.append(receiptContainer);
        this.parentWrapper.find('.buymecoffee_form').hide();
        this.parentWrapper.find('.buymecoffee_form_to, .bmc-form-card__title').hide();
    }

    afterPaymentProcessorReady(payButton) {
        this.loader.hide();
        this.form.prepend("<p class='complete_payment_instruction'>Please complete your donation with Stripe 👇</p>");
        this.form.find('.buymecoffee_payment_processor').append(payButton);
        // Hide level info when card element is shown
        this.form.find('[data-bmc-level-info]').hide();
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
