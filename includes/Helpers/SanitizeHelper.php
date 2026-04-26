<?php

namespace BuyMeCoffee\Helpers;


if (!defined('ABSPATH')) exit; // Exit if accessed directly


class SanitizeHelper
{
    public static function sanitizeText($dataArray) :array
    {
        $sanitizedData = [];
        foreach ($dataArray as $key => $value) {
            if (is_array($value)) {
                self::sanitizeText($value);
            } else {
                $sanitizedData[$key] = sanitize_text_field($value);
            }
        }
        return $sanitizedData;
    }

    public static function allowedTags() : array
    {
        $allowedTags = [
            'img' => [
                'title' => [],
                'src'	=> [],
                'alt'	=> [],
                'width' => []
            ],
            'svg' => [
                'class' => true,
                'aria-hidden' => true,
                'aria-labelledby' => true,
                'role' => true,
                'xmlns' => true,
                'width' => true,
                'height' => true,
                'viewbox' => true,
                'fill' => true
            ],
            'g' => [
                'fill' => true
            ],
            'title' => ['title' => true],
            'path' => [
                'd' => true,
                'fill' => true,
                'stroke' => true
            ],
        ];

        foreach (['input', 'label', 'div', 'span', 'p', 'select', 'option', 'textarea', 'button', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $allowedTags[$tag] = [
                'type' => [],
                'name' => [],
                'value' => [],
                'autocomplete' => [],
                'placeholder' => [],
                'data-required' => [],
                'data-type' => [],
                'id' => [],
                'class' => [],
                'required' => [],
                'disabled' => [],
                'for' => [],
                'style' => [],
                'data-id' => [],
                'data-wpm_currency' => [],
                'data-quantity' => [],
                'data-price' => [],
                'data-element_type' => [],
                'data-payment_selected' => [],
                'data-default' => [],
                'data-recurring' => [],
                'data-interval' => [],
            ];
        }

        return $allowedTags;
    }

}