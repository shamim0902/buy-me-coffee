<?php

namespace BuyMeCoffee\Builder\Methods\PayPal;

class API
{
    private static $settings;
    private static $testApiUrl = 'https://api-m.sandbox.paypal.com';
    private static $liveApiUrl = 'https://api.paypal.com';
    public function __construct()
    {
        self::$settings = new PayPalSettings();
    }
    public function verifyTransaction($chargeId)
    {
        try {
            $payment_intent = $this->makeRequest('checkout/orders/' . $chargeId, 'v2', 'GET');

            if (is_wp_error($payment_intent)) {
                throw new \Exception($payment_intent->get_error_message(), $payment_intent->get_error_code());
            }
            return $payment_intent;
        } catch (\Exception $e) {
            $message = $e->getMessage() ?: 'Unable to verify PayPal transaction';
            $code = $e->getCode() ?: 500;
            throw new \Exception(esc_html($message), intval($code));
        }

    }

    public static function makeRequest($path, $version = 'v1', $method = 'POST', $args = [])
    {
        if (empty($path)) {
            throw new \Exception(esc_html__('API path is required', 'buy-me-coffee'));
        }

        $paypal_api_url = static::$testApiUrl . '/' . $version . '/' . $path;

        if (static::$settings->getMode() === 'live') {
            $paypal_api_url = static::$liveApiUrl . '/' . $version . '/' . $path;
        }

        try {
            $accessToken = static::getAccessToken();
        } catch (\Exception $e) {
            $message = $e->getMessage() ?: 'Unable to get access token';
            $code = $e->getCode() ?: 500;
            throw new \Exception(esc_html($message), intval($code));
        }

        $headers = array(
            "Authorization" => "Bearer " . $accessToken,
            "Content-Type" => "application/json",
            "Accept" => "application/json"
        );

        if ('GET' === $method) {
            return static::getRequest($paypal_api_url);
        }
        if ('POST' === $method) {
            $headers["Prefer"] = "return=representation";
        }
        $response = wp_safe_remote_request($paypal_api_url, [
            'headers' => $headers,
            'method'  => $method,
            'body'    => json_encode($args)
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('general_error', 'Paypal General Error', $response);
        }
        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code > 299) {
            $message = 'Paypal General Error';
            if (!empty($body['message'])) {
                $message = $body['message'];
                if (isset($body['details'])) {
                    $message = $body['details'][0]['issue'];
                }
            }
            return new \WP_Error($http_code, $message, $body);
        }
        // it's success response with no content
        if ($http_code == 204) {
            return  [
                'status' => 'success',
                'body' => 'No Content',
                'code' => 204
            ];
        }
        return  $body;
    }

    public static function getRequest($url)
    {
        try {
            $accessToken = static::getAccessToken();
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $code = $e->getCode() ?: 500;
            throw new \Exception(esc_html($message), intval($code));
        }
        $headers = array(
            "Authorization" => "Bearer " . $accessToken,
            "Content-Type" => "application/json",
            "Accept" => "application/json"
        );
        $response = wp_safe_remote_get($url, [
            'headers' => $headers
        ]);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            $code = $response->get_error_code() ?: 500;
            throw new \Exception(esc_html($message), intval($code));
        }
        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code == 200) {
            return  $body;
        }

        // it's success response with no content
        if ($http_code == 204) {
            return  [
                'status' => 'success',
                'body' => 'No Content',
                'code' => 204
            ];
        }

        if ($http_code > 299) {
            $code = 'general_error';
            $message = 'PayPal General Error';
            if(!empty($body['message'])) {
                $code = $body['name'];
                $message = $body['message'];
                if (isset($body['details'])) {
                    $message = $body['details'][0]['description'];
                }
            }
            return new \WP_Error($code, $message, $body);
        }
        $message = $body['message'] ?? 'PayPal General Error';
        if (isset($body['details'])) {
            $message = $body['details'][0]['issue'];
        }
        throw new \Exception(esc_html($message), intval($http_code));
    }

    protected static function getAuthAPI($mode = 'test')
    {
        if ($mode === 'live') {
            return static::$liveApiUrl . "/v1/oauth2/token";
        } else {
            return static::$testApiUrl . "/v1/oauth2/token";
        }
    }
    public static function getAccessToken()
    {
        $apiUrl =  static::getAuthAPI(static::$settings->getMode());
        $headers = array(
            "Accept: application/json",
            "Accept-Language: en_US",
        );
        $data = array(
            "grant_type" => "client_credentials",
        );
        $auth = base64_encode(static::$settings->getKeys('public') . ":" . static::$settings->getKeys('secret'));
        $headers[] = "Authorization: Basic " . $auth;
        return static::makeAccessTokenRequest($apiUrl, $headers, $data);
    }

    public static function makeAccessTokenRequest($apiUrl, $headers, $data)
    {
        // Convert headers array to associative array for wp_remote_post
        $wp_headers = array();
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $wp_headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        $response = wp_remote_post($apiUrl, array(
            'headers' => $wp_headers,
            'body'    => http_build_query($data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($http_code == 200) {
            $response_data = json_decode($body, true);
            return $response_data["access_token"];
        } else {
            $error = json_decode($body, true);
            $errorMessage = $error['error_description'] ?? $error['error'];
            // Handle authentication error.
            throw new \Exception(esc_html($errorMessage), intval($http_code));
        }
    }

}
