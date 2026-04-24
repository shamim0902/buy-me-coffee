<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Models\Supporters;

if (!defined('ABSPATH')) exit;

class EmailNotifications
{
    const OPTION_KEY = 'buymecoffee_email_notifications';

    /**
     * Default notification configs
     */
    public static function getDefaults()
    {
        return [
            'donor' => [
                'id'      => 'donor',
                'label'   => 'Donor Confirmation',
                'enabled' => true,
                'subject' => 'Thank you for your support, {{donor_name}}!',
                'body'    => "Hi {{donor_name}},\n\nThank you so much for your generous support of {{amount}}! Your contribution means the world to us.\n\nWe received your donation via {{payment_method}}.\n\nWarm regards,\n{{site_name}}",
            ],
            'admin' => [
                'id'      => 'admin',
                'label'   => 'Admin Notification',
                'enabled' => true,
                'subject' => 'New donation received: {{amount}} from {{donor_name}}',
                'body'    => "Hello,\n\nA new donation has been received.\n\nDonor: {{donor_name}}\nEmail: {{donor_email}}\nAmount: {{amount}}\nMethod: {{payment_method}}\n\nManage supporters: {{site_url}}\n\n{{site_name}}",
            ],
        ];
    }

    /**
     * Get saved notifications (merged with defaults so new keys always exist)
     */
    public static function getNotifications()
    {
        $saved    = get_option(self::OPTION_KEY, []);
        $defaults = self::getDefaults();

        foreach ($defaults as $id => $default) {
            if (!isset($saved[$id])) {
                $saved[$id] = $default;
            } else {
                // Keep default structure, overlay saved values
                $saved[$id] = array_merge($default, $saved[$id]);
            }
        }

        return $saved;
    }

    /**
     * Save a single notification config
     */
    public static function saveNotification($id, $data)
    {
        $notifications = get_option(self::OPTION_KEY, []);
        $defaults      = self::getDefaults();

        $allowed = array_keys($defaults);
        if (!in_array($id, $allowed, true)) {
            return false;
        }

        $notifications[$id] = [
            'id'      => $id,
            'label'   => isset($defaults[$id]['label']) ? $defaults[$id]['label'] : $id,
            'enabled' => !empty($data['enabled']),
            'subject' => sanitize_text_field($data['subject'] ?? ''),
            'body'    => sanitize_textarea_field($data['body'] ?? ''),
        ];

        update_option(self::OPTION_KEY, $notifications, false);
        return true;
    }

    /**
     * Parse shortcodes in a string
     */
    public static function parse($text, $vars)
    {
        foreach ($vars as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        return $text;
    }

    /**
     * Build template vars from transaction + supporter
     */
    public static function buildVars($transaction, $supporter)
    {
        $currency = strtoupper($transaction->currency ?? 'USD');
        $amount   = number_format($transaction->payment_total / 100, 2) . ' ' . $currency;

        return [
            'donor_name'     => $supporter->supporters_name ?: 'Anonymous',
            'donor_email'    => $supporter->supporters_email ?: '',
            'amount'         => $amount,
            'payment_method' => ucfirst($transaction->payment_method ?? ''),
            'site_name'      => get_bloginfo('name'),
            'site_url'       => site_url(),
            'admin_email'    => get_option('admin_email'),
        ];
    }

    /**
     * Send an email notification
     */
    public static function send($to, $subject, $body)
    {
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Hook: fires when a Stripe/PayPal payment status changes.
     * $transactionId = transactions table row id, $status = 'paid'|'failed'|etc.
     */
    public function onPaymentStatusUpdated($transactionId, $status)
    {
        if ($status !== 'paid') {
            return;
        }

        $transaction = (new Transactions())->find($transactionId);
        if (!$transaction) {
            return;
        }

        $supporter = (new Supporters())->find($transaction->entry_id);
        if (!$supporter) {
            return;
        }

        $vars          = self::buildVars($transaction, $supporter);
        $notifications = self::getNotifications();

        // Donor email
        $donor = $notifications['donor'] ?? [];
        if (!empty($donor['enabled']) && !empty($supporter->supporters_email)) {
            $subject = self::parse($donor['subject'], $vars);
            $body    = self::parse($donor['body'], $vars);
            self::send($supporter->supporters_email, $subject, $body);
        }

        // Admin email
        $admin = $notifications['admin'] ?? [];
        if (!empty($admin['enabled'])) {
            $subject = self::parse($admin['subject'], $vars);
            $body    = self::parse($admin['body'], $vars);
            self::send(get_option('admin_email'), $subject, $body);
        }
    }

    /**
     * Register WordPress hooks
     */
    public function register()
    {
        add_action('buymecoffee_payment_status_updated', [$this, 'onPaymentStatusUpdated'], 10, 2);
    }
}
