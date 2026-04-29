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

        $sessionResponse = wp_remote_request($url, $requestData);

        if (is_wp_error($sessionResponse)) {
            return $sessionResponse;
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

    /**
     * Make a GET request that returns a Stripe list object (e.g. invoices, charges).
     *
     * Unlike makeRequest(), this does not require an 'id' field in the response,
     * since list endpoints return {object: "list", data: [...]}.
     *
     * @param string $path    API path (e.g. 'invoices')
     * @param array  $params  Query parameters
     * @param string $apiKey  Stripe secret key
     * @return array|\WP_Error
     */
    public function getList($path, $params, $apiKey)
    {
        $url = $this->apiUrl . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_request($url, [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'method'  => 'GET',
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['error'])) {
            $message = Arr::get($body, 'error.message', 'Unknown Stripe API error');
            return new \WP_Error('stripe_error', $message, $body);
        }

        return $body;
    }

    /**
     * Fetch a Stripe event by ID.
     *
     * Used to verify webhook authenticity: re-fetching the event from Stripe
     * confirms it is genuine and returns untampered data.
     *
     * @param string $eventId  Stripe event ID (evt_…)
     * @return array|\WP_Error
     */
    public function getEvent($eventId)
    {
        return $this->makeRequest(
            'events/' . sanitize_text_field($eventId),
            [],
            StripeSettings::getKeys('secret'),
            'GET'
        );
    }
}
