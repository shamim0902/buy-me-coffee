<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class AccessControl
{
    public static function hasCapability($capabilities)
    {
        foreach ((array) $capabilities as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }

        return false;
    }

    public static function hasTopLevelMenuPermission()
    {
        $menuPermissions = array(
            'manage_options',
            'buy-me-coffee_full_access',
            'buy-me-coffee_can_view_menus',
            'buy-me-coffee_view_reports',
            'buy-me-coffee_view_supporters',
            'buy-me-coffee_view_payments',
            'buy-me-coffee_manage_settings'
        );

        return self::hasCapability($menuPermissions);
    }

    public static function hasReportsPermission()
    {
        return self::hasCapability([
            'manage_options',
            'buy-me-coffee_full_access',
            'buy-me-coffee_view_reports'
        ]);
    }

    public static function hasSupporterDataPermission()
    {
        return self::hasCapability([
            'manage_options',
            'buy-me-coffee_full_access',
            'buy-me-coffee_view_supporters'
        ]);
    }

    public static function hasPaymentDataPermission()
    {
        return self::hasCapability([
            'manage_options',
            'buy-me-coffee_full_access',
            'buy-me-coffee_view_payments'
        ]);
    }

    public static function hasSettingsPermission()
    {
        return self::hasCapability([
            'manage_options',
            'buy-me-coffee_full_access',
            'buy-me-coffee_manage_settings'
        ]);
    }

    public static function hasFinancialPermission()
    {
        return self::hasCapability([
            'manage_options',
            'buy-me-coffee_full_access'
        ]);
    }
}
