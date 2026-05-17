import BmcFormHandler from './BmcFormHandler.js';

let BuyMeCoffeeApp = {};

(function ($) {
    BuyMeCoffeeApp = {
        forms: {},
        general: window.buymecoffee_general,
        formData: {},
        initiated: false,
        init() {
            this.forms = $('.buymecoffee_form');

            $.each(this.forms, function ($form) {
                let form = $(this);
                // let formInstance = form.attr('buymecoffee_form');
                let formSettings = window['buymecoffee_settings'];
                let formHandler = new BmcFormHandler(form, formSettings);
                formHandler.initForm();
            });

            this.initOther();
            this.initiated = true;
        },
        initOther() {
            $('.buymecoffee_form input').on('keypress', function (e) {
                return e?.which !== 13;
            });
            let $inputs = $('.buymecoffee_form').find('input[data-required="yes"][data-type="input"],textarea[data-required="yes"],select[data-required="yes"]');
            $inputs.on('keypress blur', function (e) {
                if ($(this).val()) {
                    $(this).removeClass('wpf_has_error');
                }
            });
        }
    };

    $(window).on('load', function () {
        if (!BuyMeCoffeeApp?.initiated) {
            BuyMeCoffeeApp.init();
        }
    });

    $(document).ready(function() {
        let modal = $('#bmc_modal_wrapper');
        let btn = $('#bmc_open_modal_btn');
        let span = $('.close');
        btn.click(function() {
            modal.show();
        });
        span.click(function() {
            modal.hide();
        });
        // When the templates clicks anywhere outside the modal, close it
        $(window).click(function(event) {
            if (event.target == modal[0]) {
                modal.hide();
            }
        });
    });
      

}(jQuery));
