<?php

namespace BuyMeCoffee\Builder\Methods\Stripe;

use BuyMeCoffee\Helpers\ArrayHelper as Arr;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class API
{
    private $createSessionUrl;
    private $apiUrl = 'https://api.stripe.com/v1/';

    public function makeRequest($path, $data, $apiKey, $method = 'GET')
    {
        $stripeApiKey = $apiKey;
        $sessionHeaders = array(
            'Authorization' => 'Bearer ' . $stripeApiKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        );

        $requestData = array(
            'headers' => $sessionHeaders,
            'body' => http_build_query($data),
            'method' => $method,
        );

        $url = $this->apiUrl . $path;

        $sessionResponse = wp_remote_post($url, $requestData);

        if (is_wp_error($sessionResponse)) {
            echo "API Error: " . esc_html($sessionResponse->get_error_message());
            exit;
        }

        $sessionResponseData = wp_remote_retrieve_body($sessionResponse);

        $sessionData = json_decode($sessionResponseData, true);

        if (empty($sessionData['id'])) {
            $message = Arr::get($sessionData, 'detail');
            if (!$message) {
                $message = Arr::get($sessionData, 'error.message');
            }
            if (!$message) {
                $message = 'Unknown Stripe API request error';
            }

            return new \WP_Error(423, $message, $sessionData);
        }

        return $sessionData;
    }


    public function verifyIPN()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe webhook listener
        if (!isset($_REQUEST['buymecoffee_stripe_listener'])) {
            return;
        }

        $post_data = '';
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
            // phpcs:ignore WordPress.PHP.IniSet.post_max_size_Blacklisted, Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for webhook processing
            ini_set('post_max_size', '12M');
        }

        $data =  json_decode($post_data);

        if ($data->id) {
            status_header(200);
            return $data;
        } else {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required for webhook debugging
            error_log("specific event");
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Required for webhook debugging
            error_log(print_r($data));
            return false;
        }

        exit(200);
    }

    public function getInvoice($eventId)
    {
        $api = new ApiRequest();
        $api::set_secret_key((new StripeSettings())->getApiKey());
        return $api::request([], 'events/' . $eventId, 'GET');
    }
}
