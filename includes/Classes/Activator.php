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
    const SCHEMA_VERIFIED_DB_VERSION_OPTION = 'buymecoffee_schema_verified_db_version';

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
        $schemaVerifiedVersion = get_option(self::SCHEMA_VERIFIED_DB_VERSION_OPTION, '');
        $needsVersionMigration = version_compare($installedVersion, BUYMECOFFEE_DB_VERSION, '<');
        $needsSchemaVerification = !$needsVersionMigration && $schemaVerifiedVersion !== BUYMECOFFEE_DB_VERSION;

        if ($needsVersionMigration) {
            if ($this->migrate()) {
                $this->markMigrationStateCurrent();
            }
        } elseif ($needsSchemaVerification) {
            if ($this->verifyMigrationState()) {
                update_option(self::SCHEMA_VERIFIED_DB_VERSION_OPTION, BUYMECOFFEE_DB_VERSION, false);
            } elseif ($this->migrate()) {
                $this->markMigrationStateCurrent();
            }
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
        $this->normalizeEmptySubscriptionStripeIds();
        $this->createActivitiesTable();
        $this->createMembershipLevelsTable();
        $this->createMembershipAccessTable();
        $this->createSupportersMetaTable();
        $this->backfillMembershipAccessTable();
        $this->seedDefaultMembershipLevel();

        return $this->verifyMigrationState();
    }

    private function activateSite()
    {
        $isFreshInstall = $this->isFreshInstall();

        if (!$this->migrate()) {
            return;
        }

        $this->markMigrationStateCurrent();

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

    private function markMigrationStateCurrent()
    {
        update_option('buymecoffee_db_version', BUYMECOFFEE_DB_VERSION);
        update_option(self::SCHEMA_VERIFIED_DB_VERSION_OPTION, BUYMECOFFEE_DB_VERSION, false);
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

    private function normalizeEmptySubscriptionStripeIds()
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'buymecoffee_subscriptions';
        $tableLike = $wpdb->esc_like($tableName);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration table existence check.
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableLike));
        if ($tableExists !== $tableName) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time/manual membership access rows must not collide with the Stripe subscription unique key.
        $wpdb->query("UPDATE {$tableName} SET stripe_subscription_id = NULL WHERE stripe_subscription_id = ''");
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
                payment_type varchar(20) NOT NULL DEFAULT 'subscription',
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

    public function createMembershipAccessTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'buymecoffee_membership_access';
        $sql = "CREATE TABLE $table_name (
                id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                supporter_id int(11) NOT NULL,
                wp_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
                level_id int(11) NOT NULL,
                transaction_id int(11) DEFAULT NULL,
                subscription_id int(11) DEFAULT NULL,
                access_type varchar(20) NOT NULL DEFAULT 'subscription',
                status varchar(50) NOT NULL DEFAULT 'incomplete',
                starts_at timestamp NULL,
                expires_at timestamp NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                UNIQUE KEY bmc_ma_tx (transaction_id),
                UNIQUE KEY bmc_ma_sub (subscription_id),
                KEY bmc_ma_supporter (supporter_id),
                KEY bmc_ma_wp_user (wp_user_id),
                KEY bmc_ma_level (level_id),
                KEY bmc_ma_status (status),
                KEY bmc_ma_expires (expires_at),
                KEY bmc_ma_type_status (access_type, status)
        ) $charset_collate;";

        $this->runSQL($sql, $table_name);
    }

    private function backfillMembershipAccessTable()
    {
        global $wpdb;

        $accessTable = $wpdb->prefix . 'buymecoffee_membership_access';
        $subscriptionsTable = $wpdb->prefix . 'buymecoffee_subscriptions';
        $supportersTable = $wpdb->prefix . 'buymecoffee_supporters';
        $supportersMetaTable = $wpdb->prefix . 'buymecoffee_supporters_meta';
        $transactionsTable = $wpdb->prefix . 'buymecoffee_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration table existence checks.
        $accessExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($accessTable)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration table existence checks.
        $subscriptionsExist = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($subscriptionsTable)));
        if ($accessExists !== $accessTable || $subscriptionsExist !== $subscriptionsTable) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Normalize older backfills so only recurring access remains linked to subscription rows.
        $wpdb->query("UPDATE {$accessTable} SET subscription_id = NULL WHERE access_type IN ('one_time', 'manual')");

        $lastId = 0;
        $batchSize = 500;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotent migration reads a bounded batch of membership-bearing subscription rows.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, sup.wp_user_id
                 FROM {$subscriptionsTable} s
                 LEFT JOIN {$supportersTable} sup ON s.supporter_id = sup.id
                 WHERE s.id > %d AND s.level_id IS NOT NULL AND s.level_id > 0
                 ORDER BY s.id ASC
                 LIMIT %d",
                $lastId,
                $batchSize
            ));

            if (empty($rows)) {
                break;
            }

            $subscriptionIds = array_map('absint', wp_list_pluck($rows, 'id'));
            $transactionMap = $this->getFirstTransactionIdsForSubscriptions($subscriptionIds, $transactionsTable);

            foreach ((array) $rows as $row) {
                $lastId = max($lastId, (int) $row->id);
                $row->transaction_id = $transactionMap[(int) $row->id] ?? null;

                $this->backfillMembershipAccessRow($row, $accessTable);
            }
        } while (count($rows) === $batchSize);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Access source of truth moved to membership_access; cached level IDs must be recalculated.
        $wpdb->delete($supportersMetaTable, ['meta_key' => 'active_level_ids']);
    }

    private function getFirstTransactionIdsForSubscriptions(array $subscriptionIds, $transactionsTable)
    {
        global $wpdb;

        $subscriptionIds = array_values(array_filter(array_map('absint', $subscriptionIds)));
        if (empty($subscriptionIds)) {
            return [];
        }

        $ids = implode(',', $subscriptionIds);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Subscription IDs are absint-cast and imploded into a fixed IN list for migration batching.
        $rows = $wpdb->get_results("SELECT subscription_id, MIN(id) AS transaction_id FROM {$transactionsTable} WHERE subscription_id IN ({$ids}) GROUP BY subscription_id");

        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row->subscription_id] = (int) $row->transaction_id;
        }

        return $map;
    }

    private function backfillMembershipAccessRow($row, $accessTable)
    {
        global $wpdb;

        $accessType = 'subscription';
        if (($row->payment_mode ?? '') === 'manual') {
            $accessType = 'manual';
        } elseif (($row->interval_type ?? '') === 'one_time') {
            $accessType = 'one_time';
        }

        if ($accessType === 'subscription') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Duplicate guard for idempotent backfill.
            $existingId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$accessTable} WHERE subscription_id = %d LIMIT 1",
                (int) $row->id
            ));
        } elseif (!empty($row->transaction_id)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Duplicate guard for idempotent backfill.
            $existingId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$accessTable} WHERE transaction_id = %d LIMIT 1",
                (int) $row->transaction_id
            ));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Duplicate guard for idempotent backfill.
            $existingId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$accessTable} WHERE supporter_id = %d AND level_id = %d AND access_type = %s LIMIT 1",
                (int) $row->supporter_id,
                (int) $row->level_id,
                $accessType
            ));
        }

        if ($existingId) {
            return;
        }

        $expiresAt = null;
        if ($accessType === 'subscription' && !empty($row->current_period_end) && $row->current_period_end !== '0000-00-00 00:00:00') {
            $expiresAt = $row->current_period_end;
        }

        $wpdb->insert($accessTable, [
            'supporter_id'    => (int) $row->supporter_id,
            'wp_user_id'      => !empty($row->wp_user_id) ? (int) $row->wp_user_id : null,
            'level_id'        => (int) $row->level_id,
            'transaction_id'  => !empty($row->transaction_id) ? (int) $row->transaction_id : null,
            'subscription_id' => $accessType === 'subscription' ? (int) $row->id : null,
            'access_type'     => $accessType,
            'status'          => sanitize_text_field($row->status ?: 'incomplete'),
            'starts_at'       => !empty($row->created_at) ? $row->created_at : current_time('mysql'),
            'expires_at'      => $expiresAt,
            'created_at'      => !empty($row->created_at) ? $row->created_at : current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);
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
                'payment_type'  => 'subscription',
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
                '%s', // name
                '%s', // description
                '%d', // price
                '%s', // payment_type
                '%s', // interval_type
                '%s', // status
                '%s', // rewards (JSON)
                '%s', // access_rules (JSON)
                '%d', // sort_order
                '%s', // created_at
                '%s', // updated_at
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

    private function verifyMigrationState()
    {
        global $wpdb;

        $requiredTables = [
            $wpdb->prefix . 'buymecoffee_supporters',
            $wpdb->prefix . 'buymecoffee_transactions',
            $wpdb->prefix . 'buymecoffee_subscriptions',
            $wpdb->prefix . 'buymecoffee_activities',
            $wpdb->prefix . 'buymecoffee_membership_levels',
            $wpdb->prefix . 'buymecoffee_membership_access',
            $wpdb->prefix . 'buymecoffee_supporters_meta',
        ];

        foreach ($requiredTables as $tableName) {
            if (!$this->tableExists($tableName)) {
                return false;
            }
        }

        if (!$this->tableHasColumns($wpdb->prefix . 'buymecoffee_membership_levels', ['payment_type'])) {
            return false;
        }

        return $this->tableHasColumns($wpdb->prefix . 'buymecoffee_membership_access', [
            'supporter_id',
            'wp_user_id',
            'level_id',
            'transaction_id',
            'subscription_id',
            'access_type',
            'status',
            'starts_at',
            'expires_at',
        ]);
    }

    private function tableExists($tableName)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration verification table existence check.
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($tableName))) === $tableName;
    }

    private function tableHasColumns($tableName, array $columns)
    {
        global $wpdb;

        if (!$this->tableExists($tableName)) {
            return false;
        }

        foreach ($columns as $column) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned; column is prepared below.
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$tableName} LIKE %s", $column));
            if (!$exists) {
                return false;
            }
        }

        return true;
    }

    private function runSQL($sql, $tableName)
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        return $this->tableExists($tableName);
    }
}
