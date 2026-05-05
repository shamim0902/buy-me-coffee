<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Ajax Handler Class
 * @since 1.0.0
 */
class Activator
{
    const INSTALLED_AT_OPTION = 'buymecoffee_installed_at';
    const DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION = 'buymecoffee_default_membership_level_seeded';

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
                $this->activateSite();
                restore_current_blog();
            }
        } else {
            $this->activateSite();
        }
    }

    public function maybeRunMigrations()
    {
        $installedVersion = get_option('buymecoffee_db_version', '1.0');
        if (version_compare($installedVersion, BUYMECOFFEE_DB_VERSION, '<')) {
            $this->migrate();
            update_option('buymecoffee_db_version', BUYMECOFFEE_DB_VERSION);
        }

        if (get_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION) !== 'yes') {
            $this->seedDefaultMembershipLevel();
        }
    }

    private function migrate()
    {
        $this->createSupportersTable();
        $this->createTransactionTable();
        $this->createSubscriptionsTable();
        $this->createActivitiesTable();
        $this->createMembershipLevelsTable();
        $this->createSupportersMetaTable();
        $this->seedDefaultMembershipLevel();
    }

    private function activateSite()
    {
        $isFreshInstall = $this->isFreshInstall();

        $this->migrate();

        if (!get_option(self::INSTALLED_AT_OPTION)) {
            update_option(self::INSTALLED_AT_OPTION, current_time('mysql'), false);
        }

        if ($isFreshInstall && !class_exists('\BuyMeCoffee\Classes\GuidedTour')) {
            require_once BUYMECOFFEE_DIR . 'includes/Classes/GuidedTour.php';
        }

        if ($isFreshInstall && class_exists('\BuyMeCoffee\Classes\GuidedTour')) {
            GuidedTour::enableForFreshInstall();
        }
    }

    private function isFreshInstall()
    {
        return !get_option(self::INSTALLED_AT_OPTION) && !get_option('buymecoffee_db_version');
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
				updated_at timestamp NULL,
				wp_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
                KEY bmc_sup_email (supporters_email(191)),
                KEY bmc_sup_wp_user (wp_user_id),
                KEY bmc_sup_status (payment_status),
                KEY bmc_sup_hash (entry_hash(191)),
                KEY bmc_sup_created (created_at)
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
				updated_at timestamp NULL,
                KEY bmc_tx_entry (entry_id),
                KEY bmc_tx_hash (entry_hash(191)),
                KEY bmc_tx_charge (charge_id(191)),
                KEY bmc_tx_status (status),
                KEY bmc_tx_sub (subscription_id),
                KEY bmc_tx_created (created_at)
        ) $charset_collate;";

        $this->runSQL($sql, $table_name);
    }

    public function createSubscriptionsTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'buymecoffee_subscriptions';
        $sql = "CREATE TABLE $table_name (
                id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                supporter_id int(11) NOT NULL,
                stripe_subscription_id varchar(255),
                stripe_customer_id varchar(255),
                interval_type varchar(50) DEFAULT 'month',
                amount int(11) DEFAULT 0,
                currency varchar(10),
                status varchar(50) DEFAULT 'incomplete',
                payment_mode varchar(20) DEFAULT 'test',
                current_period_end timestamp NULL,
                cancelled_at timestamp NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                level_id int(11) DEFAULT NULL,
                UNIQUE KEY bmc_sub_stripe_sub (stripe_subscription_id(191)),
                KEY bmc_sub_supporter (supporter_id),
                KEY bmc_sub_status (status),
                KEY bmc_sub_created (created_at),
                KEY bmc_sub_lvl (level_id)
        ) $charset_collate;";

        $this->runSQL($sql, $table_name);
    }

    public function createActivitiesTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'buymecoffee_activities';
        $sql = "CREATE TABLE $table_name (
                id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                object_type varchar(30) NOT NULL DEFAULT '',
                object_id int(11) NOT NULL DEFAULT 0,
                event varchar(80) NOT NULL DEFAULT '',
                status varchar(20) NOT NULL DEFAULT 'info',
                title varchar(255) NOT NULL DEFAULT '',
                description longtext,
                context longtext,
                created_by varchar(80) NOT NULL DEFAULT 'system',
                created_at timestamp NULL,
                KEY bmc_act_obj (object_type, object_id),
                KEY bmc_act_time (created_at)
        ) $charset_collate;";

        $this->runSQL($sql, $table_name);
    }

    public function createMembershipLevelsTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'buymecoffee_membership_levels';
        $sql = "CREATE TABLE $table_name (
                id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name varchar(255) NOT NULL,
                description text,
                price int(11) NOT NULL DEFAULT 0,
                interval_type varchar(50) NOT NULL DEFAULT 'month',
                status varchar(50) NOT NULL DEFAULT 'active',
                rewards longtext,
                access_rules longtext,
                sort_order int(11) NOT NULL DEFAULT 0,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                KEY bmc_lvl_status (status),
                KEY bmc_lvl_sort (sort_order)
        ) $charset_collate;";

        $this->runSQL($sql, $table_name);
    }

    private function seedDefaultMembershipLevel()
    {
        global $wpdb;

        if (get_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION) === 'yes') {
            return;
        }

        $tableName = $wpdb->prefix . 'buymecoffee_membership_levels';
        $tableLike = $wpdb->esc_like($tableName);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation/migration table existence check.
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableLike));
        if ($tableExists !== $tableName) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seed guard during migration.
        $levelCount = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$tableName}");
        if ($levelCount > 0) {
            update_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION, 'yes', false);
            return;
        }

        $now = current_time('mysql');
        $inserted = $wpdb->insert(
            $tableName,
            [
                'name'          => 'Supporter',
                'description'   => 'A sample $10 monthly membership for supporters who want access to premium updates and bonus content.',
                'price'         => 1000,
                'interval_type' => 'month',
                'status'        => 'active',
                'rewards'       => wp_json_encode([
                    'Access to members-only posts',
                    'Monthly behind-the-scenes update',
                    'Supporter badge on your account',
                ]),
                'access_rules'  => wp_json_encode([
                    'post_types'    => [],
                    'categories'    => [],
                    'preview_words' => 50,
                    'access_level'  => 'full',
                ]),
                'sort_order'    => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
            ]
        );

        if ($inserted) {
            update_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION, 'yes', false);
        }
    }

    public function createSupportersMetaTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'buymecoffee_supporters_meta';
        $sql = "CREATE TABLE $table_name (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                supporter_id BIGINT(20) UNSIGNED NOT NULL,
                meta_key varchar(255) NOT NULL,
                meta_value longtext,
                KEY bmc_sm_supporter (supporter_id),
                KEY bmc_sm_key (meta_key(191)),
                KEY bmc_sm_supporter_key (supporter_id, meta_key(191))
        ) $charset_collate;";

        $this->runSQL($sql, $table_name);
    }

    private function runSQL($sql, $tableName)
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
