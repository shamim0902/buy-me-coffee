class PaypalCheckout {
    constructor($form, $response) {
        this.form = $form
        this.data = $response.data
    }

    init() {
        // Check if PayPal SDK is loaded
        if (typeof paypal === 'undefined') {

            // Check if SDK script exists in the page
            const sdkScript = document.querySelector('script[src*="paypal.com/sdk/js"]');
            if (sdkScript) {
                // Wait for the script to load and try again
                setTimeout(() => this.init(), 200);
            } else {
                console.error('PayPal SDK script not found in DOM. Please check PayPal configuration.');
                this.form.find('.buymecoffee_pay_methods')?.parent().prepend("<p style='color: red;'>PayPal SDK failed to load. Please check your PayPal Client ID configuration.</p>");
            }
            return;
        }

        this.form.find('.wpm_submit_button, .buymecoffee_pay_method').hide()

        let paypalButtonContainer = jQuery("<div class='buymecoffee_paypal_button_wrap' style='padding: 0px;'></div>")
        paypal
            .Buttons({
                fundingSource: paypal.FUNDING.PAYPAL,
                style: {
                    shape: 'pill', layout: 'vertical', label: 'paypal', // tagline: 'false',
                    size: 'responsive', disableMaxWidth: true
                }, createOrder: (data, actions) => {
                    return actions.order.create({
                        purchase_units: [this.data.purchase_units]
                    })
                }, onApprove: (data) => {
                    this.form.find('.buymecoffee_paypal_button_wrap').hide();
                    this.form.find('.complete_payment_instruction').html('Please wait, payment is being confirmed...');

                    return jQuery.post(window.buymecoffee_general.ajax_url, {
                        action: 'buymecoffee_payment_confirmation_paypal',
                        buymecoffee_nonce: window.buymecoffee_general?.buymecoffee_nonce || '',
                        hash: this.data.hash,
                        charge_id: data.orderID,
                    })
                        .then(() => {
                            window.location = this.data?.confirmation_url;
                        }).catch((err) => {
                            const message = err?.responseJSON?.data?.message || 'Payment could not be confirmed. Please try again.';
                            alert(message);
                            this.form.find('.buymecoffee_paypal_button_wrap').show();
                            this.form.find('.complete_payment_instruction').html('Please complete your donation with PayPal 👇');
                        });
                }, onError: function (err) {
                    alert('An error occurred: ' + err)
                }
            })
            .render(paypalButtonContainer[0])

        this.form.find('.buymecoffee_form_submit_wrapper, .buymecoffee_no_signup, .buymecoffee_input_content, .buymecoffee_payment_input_content, .buymecoffee_payment_item').hide();
        this.form.find('.buymecoffee_pay_methods')?.parent().append(paypalButtonContainer);
        this.form.prepend("<p class='complete_payment_instruction'>Please complete your donation with PayPal 👇</p>");
    }
}

window.addEventListener('buymecoffee_payment_next_action_paypal', function (e) {
    new PaypalCheckout(e.detail.form, e.detail.response).init()
})