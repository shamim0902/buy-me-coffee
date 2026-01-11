<?php

namespace buyMeCoffee\Builder\Methods\Stripe;

use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class IPN
{
    public function IPNData()
    {
        $post_data = '';
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for Stripe webhook to handle large POST data
            ini_set('post_max_size', '12M');
        }

        $data =  json_decode($post_data);

        if ($data->id) {
            status_header(200);
            return $data;
        } else {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging for Stripe webhook failures
            error_log(print_r($data));
            return false;
        }
        exit(200);
    }

}