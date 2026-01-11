<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Ajax Handler Class
 * @since 1.0.0
 */
class Activator
{
    public function migrateDatabases($network_wide = false)
    {
        global $wpdb;
        if ($network_wide) {
            // Retrieve all site IDs from this network (WordPress >= 4.6 provides easy to use functions for that).
            if (function_exists('get_sites') && function_exists('get_current_network_id')) {
                $site_ids = get_sites(array('fields' => 'ids', 'network_id' => get_current_network_id()));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for multisite activation on older WordPress versions
                $site_ids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM %s WHERE site_id = %s;", $wpdb->blogs, $wpdb->siteid ));
            }
            // Install the plugin for all these sites.
            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                $this->migrate();
                restore_current_blog();
            }
        } else {
            $this->migrate();
        }
    }

    private function migrate()
    {
        /*
        * database creation commented out,
        * If you need any database just active this function bellow
        * and write your own query at function
        */

        $this->createSupportersTable();
        $this->createTransactionTable();
    }

    public function createSupportersTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'buymecoffee_supporters';
        $sql = "CREATE TABLE $table_name (
				id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
				supporters_name varchar(255),
				supporters_email varchar(255),
                supporters_message text,
				form_data_raw longtext,
				currency varchar(255),
				payment_status varchar(255),
				entry_hash varchar (255),
				payment_total int(11),
                coffee_count int(11),
				payment_mode varchar(255),
				payment_method varchar(255),
				status varchar(255),
				reference varchar(50),
				ip_address varchar (45),
				other_infos longtext,
				created_at timestamp NULL,
				updated_at timestamp NULL
			) $charset_collate;";

        $this->runSQL($sql, $table_name);
    }

    public function createTransactionTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'buymecoffee_transactions';
        $sql = "CREATE TABLE $table_name (
				id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entry_id int(11),
				entry_hash varchar (255),
				subscription_id int(11) NULL,
				transaction_type varchar(255) DEFAULT 'one_time',
				payment_method varchar(255),
				card_last_4 int(4),
				card_brand varchar(255),
				charge_id varchar(255),
				payment_total int(11) DEFAULT 1,
				status varchar(255),
				currency varchar(255),
				payment_mode varchar(255),
				payment_note longtext,
				created_at timestamp NULL,
				updated_at timestamp NULL
        ) $charset_collate;";

        $this->runSQL($sql, $table_name);
    }

    private function runSQL($sql, $tableName)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required to check table existence during plugin activation
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tableName)) != $tableName) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
}
