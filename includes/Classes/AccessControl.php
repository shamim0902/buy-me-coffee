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
            'buy-me-coffee_can_view_menus'
        );

        return self::hasCapability($menuPermissions);
    }

    public static function hasFinancialPermission()
    {
        return self::hasCapability([
            'manage_options',
            'buy-me-coffee_full_access'
        ]);
    }
}
