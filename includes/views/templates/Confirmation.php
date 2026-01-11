<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   ?>

<style>
    //confirmation
    .buymecoffee_confirmation{
        min-width: 300px;
        box-shadow: #959da533 0 8px 24px;
        border-left: 5px solid #00b574;
        margin: 0!important;
        align-items: center;
    }

    .buymecoffee_preview_body {
        max-width: 500px;
        flex-direction: column;
    }
    .receipt {
        margin: 0 auto;
        padding: 50px;
        min-height: 400px;
        min-width: 330px;
        display: flex;
        flex-direction: column;
        align-content: space-around;
        justify-content: center;
        background-image: linear-gradient(84deg, #f5fffe54, #affbf054);
        box-shadow: rgb(0 150 136 / 38%) 0px 2px 8px;
        max-width: 460px;
    }
    span.buymecoffee_status_paid {
        background: #e3fff0;
        padding: 0px 12px;
        border-radius: 5px;
    }
    span.buymecoffee_status_pending {
        background: #ffefe3;
        padding: 0px 12px;
        border-radius: 5px;
    }

    .header h2{
        font-size:23px;
        text-align: center;
        color: #6d6d6d;
    }
    .header img {
        margin: 0 auto;
        display: block;
    }
    .content {
        margin-top: 10px;
    }
    .buymecoffee_receipt_row {
        font-family: monospace;
        display: flex;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    .buymecoffee_receipt_row strong {
        font-weight: bold;
    }
    .buymecoffee_confirmation_thanks {
        position: relative;
        bottom: -52px;
        text-align: center;
        font-size: 12px;
        font-family: cursive;
    }
    .buymecoffee_receipt_coffee {
        height: 30px;
        width: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #ffeb91;
        border-radius: 50%;
    }
</style>

<?php use BuyMeCoffee\Classes\Vite;

if ($paymentData): ?> <div class="buymecoffee_confirmation">
    <div class='receipt'>
        <div class='header'>
            <img width="100" src="<?php echo esc_url(Vite::staticPath() . 'images/coffee.gif'); ?>">
            <h2>Payment Receipt</h2>
            <div style="text-align: center;font-size: 12px;margin: 8px;font-family: monospace;">
                <strong>
                    #<?php echo esc_html(substr($paymentData->entry_hash??'', 8)); ?>
                </strong>
            </div>
                <!--                <strong>Date:</strong>-->
                <p style="text-align: center;font-size: 12px;margin: 8px;font-family: monospace;"><?php echo esc_html(gmdate("jS F Y \a\\t g:i A", strtotime($paymentData->created_at ?? ''))); ?></p>
        </div>
        <hr/>
        <div class='content'>
            <div class='buymecoffee_receipt_row'>
                <strong>Coffee Donated:</strong>
                <span class="buymecoffee_receipt_coffee"><?php echo esc_html($paymentData->coffee_count??''); ?></span>
            </div>
            <div class='buymecoffee_receipt_row'>
                <strong>Name:</strong>
                <span><?php echo esc_html($paymentData->supporters_name??''); ?></span>
            </div>
            <?php if($paymentData->supporters_email): ?>
            <div class='buymecoffee_receipt_row'>
                <strong>Email:</strong>
                <span><?php echo esc_html($paymentData->supporters_email); ?></span>
            </div>
            <?php endif; ?>
            <div class='buymecoffee_receipt_row'>
                <strong>Pay Status:</strong>
               <span class="<?php echo 'buymecoffee_status_' . esc_html($paymentData->payment_status??''); ?>"><?php echo esc_html($paymentData->payment_status??''); ?></span>
            </div>
            <div class='buymecoffee_receipt_row'>
                <strong>Payment Method:</strong>
               <?php echo esc_html($paymentData->payment_method??''); ?>
            </div>
            <div class='buymecoffee_receipt_row'>
                <strong>Amount Paid:</strong>
               <?php echo esc_html($paymentData->currency??'') .' '. esc_html(floatval($paymentData->payment_total ? $paymentData->payment_total/ 100 : 0)); ?>
            </div>
        </div>
        <p class="buymecoffee_confirmation_thanks">Thanks for your contribution 🖤</p><br/>
    </div>
</div>
<?php else: ?>
    <div class="buymecoffee_confirmation">
        <p>Thanks for your contribution 🖤</p><br/>
    </div>
<?php endif; ?>

