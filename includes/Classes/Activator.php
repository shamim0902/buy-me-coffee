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
    const MIGRATION_HOOK = 'buymecoffee_run_migrations';
    const MIGRATION_STATE_OPTION = 'buymecoffee_migration_state';
    const MIGRATION_LOCK_OPTION = 'buymecoffee_migration_lock';

    const PHASE_SCHEMA = 'schema';
    const PHASE_NORMALIZE_SUBSCRIPTIONS = 'normalize_subscriptions';
    const PHASE_NORMALIZE_ACCESS = 'normalize_access';
    const PHASE_BACKFILL_ACCESS = 'backfill_access';
    const PHASE_PURGE_ACCESS_CACHE = 'purge_access_cache';
    const PHASE_VERIFY = 'verify';
    const PHASE_COMPLETE = 'complete';

    /**
     * Register the background migration worker before checking for pending work.
     *
     * @return void
     */
    public function registerMigrationHooks()
    {
        add_action(self::MIGRATION_HOOK, [$this, 'runMigrationBatch']);
    }

    /**
     * Drop the background worker event. Called on deactivation so a disabled
     * plugin never leaves an orphaned event behind in the site cron array.
     *
     * WordPress stores cron in a site-scoped option, and a network activation
     * schedules one event per site, so a network deactivation has to clear the
     * event on every site of the network rather than only the current one.
     *
     * @param bool $network_wide Whether the plugin is being deactivated for the whole network.
     * @return void
     */
    public function unscheduleMigration($network_wide = false)
    {
        if (!$network_wide) {
            wp_clear_scheduled_hook(self::MIGRATION_HOOK);

            return;
        }

        foreach ($this->networkSiteIds() as $site_id) {
            switch_to_blog($site_id);
            wp_clear_scheduled_hook(self::MIGRATION_HOOK);
            restore_current_blog();
        }
    }

    public function migrateDatabases($network_wide = false)
    {
        if ($network_wide) {
            // Install the plugin for every site of this network.
            foreach ($this->networkSiteIds() as $site_id) {
                switch_to_blog($site_id);
                $this->activateSite();
                restore_current_blog();
            }
        } else {
            $this->activateSite();
        }
    }

    /**
     * Every site ID of the current network.
     *
     * Activation and deactivation must walk exactly the same set of sites, so
     * both share this one WordPress-version-compatible lookup.
     *
     * @return array
     */
    private function networkSiteIds()
    {
        global $wpdb;

        // WordPress >= 4.6 provides easy to use functions for that.
        if (function_exists('get_sites') && function_exists('get_current_network_id')) {
            return get_sites(array('fields' => 'ids', 'network_id' => get_current_network_id()));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for multisite activation on older WordPress versions; the table name is a core property and the network ID is prepared.
        return $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d", $wpdb->siteid));
    }

    /**
     * Lightweight request-time migration check.
     *
     * Normal traffic only ensures that one cron event exists. Schema changes,
     * legacy normalization, and data backfills are never executed from
     * plugins_loaded, so a large install cannot exhaust an arbitrary visitor's
     * request or start the same migration in several PHP workers.
     *
     * @return void
     */
    public function maybeRunMigrations()
    {
        $this->ensureHotPathOptionsAutoloaded();

        if ($this->needsMigrationWork()) {
            $this->scheduleMigration();
        }
    }

    /**
     * Run one bounded migration phase or cursor batch.
     *
     * @return array|\WP_Error|false Updated state, an error, or false when a
     *                              live worker already owns the lock.
     */
    public function runMigrationBatch()
    {
        $lock = $this->acquireMigrationLock();
        if (!$lock) {
            return false;
        }

        $state = $this->getMigrationState();

        try {
            $needsWork = $this->needsMigrationWork();
            if (!$needsWork && $state['phase'] === self::PHASE_COMPLETE) {
                return $state;
            }

            // A completed document belongs to an earlier verification attempt
            // when a version marker or the seed flag says work is pending.
            if ($needsWork && $state['phase'] === self::PHASE_COMPLETE) {
                $state = $this->restartCompletedMigration($state);
            }

            $forcedError = apply_filters('buymecoffee_migration_force_error', false, $state['phase'], $state);
            if ($forcedError) {
                $error = is_wp_error($forcedError)
                    ? $forcedError
                    : new \WP_Error('buymecoffee_migration_forced_error', __('Migration query failure forced by a test seam.', 'buy-me-coffee'));

                return $this->recordMigrationFailure($state, $error);
            }

            $result = $this->runMigrationPhase($state);
            if (is_wp_error($result)) {
                return $this->recordMigrationFailure($state, $result);
            }

            $state = $result;
            $state['updated_at'] = current_time('mysql');
            $state['last_error'] = null;
            $state['retries'] = 0;

            if (!$this->persistMigrationState($state)) {
                return $this->recordMigrationFailure(
                    $state,
                    new \WP_Error('buymecoffee_migration_state_write_failed', __('Migration progress could not be saved.', 'buy-me-coffee'))
                );
            }

            if ($state['phase'] === self::PHASE_COMPLETE) {
                if (!$this->markMigrationStateCurrent()) {
                    $state['phase'] = self::PHASE_VERIFY;

                    return $this->recordMigrationFailure(
                        $state,
                        new \WP_Error('buymecoffee_migration_version_write_failed', __('Migration version markers could not be saved.', 'buy-me-coffee'))
                    );
                }
            } else {
                $this->scheduleMigration($this->nextRunDelay());
            }

            return $state;
        } finally {
            $this->releaseMigrationLock($lock);
        }
    }

    /**
     * Create or repair plugin tables. Data migration is deliberately excluded.
     *
     * @return bool Whether every required table/column exists afterwards.
     */
    private function prepareSchema()
    {
        $this->createSupportersTable();
        $this->createTransactionTable();
        $this->createSubscriptionsTable();
        $this->createActivitiesTable();
        $this->createMembershipLevelsTable();
        $this->createMembershipAccessTable();
        $this->createSupportersMetaTable();
        $this->createRequestGuardTable();
        $this->seedDefaultMembershipLevel();

        return $this->verifyMigrationState();
    }

    private function activateSite()
    {
        $isFreshInstall = $this->isFreshInstall();

        // Required empty schema is created synchronously so a newly activated
        // plugin is usable immediately. Existing data is never walked here.
        if (!$this->prepareSchema()) {
            $state = $this->getMigrationState(true);
            $this->recordMigrationFailure(
                $state,
                new \WP_Error('buymecoffee_schema_prepare_failed', __('The plugin database schema could not be prepared.', 'buy-me-coffee'))
            );
            return;
        }

        if ($isFreshInstall && $this->isEmptyInstall()) {
            $state = $this->getMigrationState(true);
            $state['phase'] = self::PHASE_COMPLETE;
            $state['completed_at'] = current_time('mysql');
            $state['updated_at'] = $state['completed_at'];
            if (!$this->markMigrationStateCurrent($state)) {
                $state['phase'] = self::PHASE_VERIFY;
                $this->recordMigrationFailure(
                    $state,
                    new \WP_Error('buymecoffee_migration_version_write_failed', __('Migration version markers could not be saved.', 'buy-me-coffee'))
                );
            }
        } else {
            $this->getMigrationState(true);
            $this->scheduleMigration();
        }

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

    /**
     * Confirm that a purported fresh install contains no legacy business rows.
     *
     * @return bool
     */
    private function isEmptyInstall()
    {
        global $wpdb;

        foreach (['buymecoffee_supporters', 'buymecoffee_transactions', 'buymecoffee_subscriptions', 'buymecoffee_membership_access'] as $table) {
            $tableName = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, one-row activation guard.
            if ($wpdb->get_var("SELECT id FROM {$tableName} LIMIT 1")) {
                return false;
            }
        }

        return true;
    }

    /**
     * Advance both version markers only after all bounded phases verify.
     *
     * The completed progress document is written first so that current version
     * markers always imply a completed document. needsMigrationWork() relies on
     * that ordering to stay free of per-request queries once a site is settled.
     *
     * @param array|null $state Completed migration state.
     * @return bool
     */
    private function markMigrationStateCurrent($state = null)
    {
        if (is_array($state) && !$this->persistMigrationState($state)) {
            return false;
        }

        // Both markers are read on every request, so they must stay autoloaded.
        update_option('buymecoffee_db_version', BUYMECOFFEE_DB_VERSION, true);
        update_option(self::SCHEMA_VERIFIED_DB_VERSION_OPTION, BUYMECOFFEE_DB_VERSION, true);

        // update_option() ignores its autoload argument whenever the stored
        // value is unchanged, which is exactly the case on a site that was
        // already at this database version. Settle the flag explicitly.
        $this->ensureHotPathOptionsAutoloaded();

        return get_option('buymecoffee_db_version') === BUYMECOFFEE_DB_VERSION
            && get_option(self::SCHEMA_VERIFIED_DB_VERSION_OPTION) === BUYMECOFFEE_DB_VERSION;
    }

    /**
     * Decide where a finished-but-stale migration has to resume.
     *
     * @param array $state Completed state that still reports pending work.
     * @return array
     */
    private function restartCompletedMigration(array $state)
    {
        $installedVersion = (string) get_option('buymecoffee_db_version', '1.0');
        $verifiedVersion = (string) get_option(self::SCHEMA_VERIFIED_DB_VERSION_OPTION, '');

        if (version_compare($installedVersion, BUYMECOFFEE_DB_VERSION, '<')
            || $verifiedVersion !== BUYMECOFFEE_DB_VERSION) {
            return $this->getMigrationState(true);
        }

        // Both version markers are already current, so the only outstanding
        // work is verification (for example an unseeded default level).
        // Replaying every bounded data phase here would make a large install
        // walk its whole dataset on every cron tick, forever.
        $state['phase'] = self::PHASE_VERIFY;
        $state['cursor'] = 0;
        $state['completed_at'] = null;

        return $state;
    }

    /**
     * Whether this site still needs schema, data, or seed work.
     *
     * Only autoloaded markers are consulted: this runs on every front-end
     * request, so it must not add a query to a settled site. The non-autoloaded
     * progress document is deliberately not read here — markMigrationStateCurrent()
     * persists a completed document before it advances either version marker.
     *
     * @return bool
     */
    private function needsMigrationWork()
    {
        $installedVersion = (string) get_option('buymecoffee_db_version', '1.0');
        $verifiedVersion = (string) get_option(self::SCHEMA_VERIFIED_DB_VERSION_OPTION, '');

        return version_compare($installedVersion, BUYMECOFFEE_DB_VERSION, '<')
            || $verifiedVersion !== BUYMECOFFEE_DB_VERSION
            || get_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION) !== 'yes';
    }

    /**
     * The markers needsMigrationWork() reads on every single request.
     *
     * @return array
     */
    private function hotPathOptions()
    {
        return [
            'buymecoffee_db_version',
            self::SCHEMA_VERIFIED_DB_VERSION_OPTION,
            self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION,
        ];
    }

    /**
     * Guarantee that the request-time markers are autoloaded.
     *
     * Releases before this one stored two of them with autoload disabled, and
     * update_option() returns early without touching autoload when the stored
     * value does not change. An upgraded site whose markers are already current
     * therefore never rewrites them, and would pay one uncached query per
     * marker on every front-end request forever.
     *
     * Detecting that is free: the autoloaded set is already in memory for the
     * request. The repair only runs while a marker is still outside it, so it
     * settles after one request and never becomes a per-request write.
     *
     * @return void
     */
    private function ensureHotPathOptionsAutoloaded()
    {
        $autoloaded = wp_load_alloptions();
        if (!is_array($autoloaded)) {
            return;
        }

        foreach ($this->hotPathOptions() as $option) {
            if (array_key_exists($option, $autoloaded)) {
                continue;
            }

            // A marker that does not exist yet costs nothing on a settled site:
            // WordPress remembers the miss in 'notoptions', and the migration
            // creates the option autoloaded when it finally writes it.
            if (get_option($option, null) === null) {
                continue;
            }

            $this->setOptionAutoload($option);
        }
    }

    /**
     * Move one existing option into the autoloaded set.
     *
     * @param string $option Option name.
     * @return void
     */
    private function setOptionAutoload($option)
    {
        if (function_exists('wp_set_option_autoload')) {
            wp_set_option_autoload($option, true);

            return;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table on WordPress versions without wp_set_option_autoload(); only the autoload flag changes.
        $wpdb->update($wpdb->options, ['autoload' => 'yes'], ['option_name' => $option], ['%s'], ['%s']);
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * Load or initialize progress for the current database version.
     *
     * @param bool $reset Force a new state document.
     * @return array
     */
    private function getMigrationState($reset = false)
    {
        $stored = get_option(self::MIGRATION_STATE_OPTION, []);
        $now = current_time('mysql');
        $defaults = [
            'target_version' => BUYMECOFFEE_DB_VERSION,
            'phase'          => self::PHASE_SCHEMA,
            'cursor'         => 0,
            'processed'      => 0,
            'started_at'     => $now,
            'updated_at'     => $now,
            'completed_at'   => null,
            'last_error'     => null,
            'retries'        => 0,
        ];

        if ($reset || !is_array($stored) || ($stored['target_version'] ?? '') !== BUYMECOFFEE_DB_VERSION) {
            $this->persistMigrationState($defaults);
            return $defaults;
        }

        $state = wp_parse_args($stored, $defaults);
        $allowedPhases = [
            self::PHASE_SCHEMA,
            self::PHASE_NORMALIZE_SUBSCRIPTIONS,
            self::PHASE_NORMALIZE_ACCESS,
            self::PHASE_BACKFILL_ACCESS,
            self::PHASE_PURGE_ACCESS_CACHE,
            self::PHASE_VERIFY,
            self::PHASE_COMPLETE,
        ];

        if (!in_array($state['phase'], $allowedPhases, true)) {
            $state['phase'] = self::PHASE_SCHEMA;
            $state['cursor'] = 0;
        }

        $state['cursor'] = absint($state['cursor']);
        $state['processed'] = absint($state['processed']);
        $state['retries'] = absint($state['retries']);

        return $state;
    }

    /**
     * @param array $state State document.
     * @return bool
     */
    private function persistMigrationState(array $state)
    {
        $updated = update_option(self::MIGRATION_STATE_OPTION, $state, false);

        return $updated || get_option(self::MIGRATION_STATE_OPTION) === $state;
    }

    /**
     * Execute exactly one state-machine phase.
     *
     * @param array $state Current state.
     * @return array|\WP_Error
     */
    private function runMigrationPhase(array $state)
    {
        switch ($state['phase']) {
            case self::PHASE_SCHEMA:
                if (!$this->prepareSchema()) {
                    return new \WP_Error('buymecoffee_migration_schema_failed', __('Required plugin tables or columns are missing.', 'buy-me-coffee'));
                }

                return $this->advanceMigrationPhase($state, self::PHASE_NORMALIZE_SUBSCRIPTIONS);

            case self::PHASE_NORMALIZE_SUBSCRIPTIONS:
                return $this->normalizeSubscriptionBatch($state);

            case self::PHASE_NORMALIZE_ACCESS:
                return $this->normalizeAccessBatch($state);

            case self::PHASE_BACKFILL_ACCESS:
                return $this->backfillAccessBatch($state);

            case self::PHASE_PURGE_ACCESS_CACHE:
                return $this->purgeAccessCacheBatch($state);

            case self::PHASE_VERIFY:
                if (!$this->verifyMigrationState()) {
                    return new \WP_Error('buymecoffee_migration_verification_failed', __('The completed migration did not pass schema verification.', 'buy-me-coffee'));
                }

                // needsMigrationWork() also gates on the seed flag, so a level
                // that never seeded has to fail here with a backed-off retry
                // instead of completing into an endless restart loop.
                if (get_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION) !== 'yes') {
                    $this->seedDefaultMembershipLevel();
                }

                if (get_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION) !== 'yes') {
                    return new \WP_Error('buymecoffee_migration_seed_failed', __('The default membership level could not be seeded.', 'buy-me-coffee'));
                }

                $state = $this->advanceMigrationPhase($state, self::PHASE_COMPLETE);
                $state['completed_at'] = current_time('mysql');

                return $state;

            case self::PHASE_COMPLETE:
                return $state;
        }

        return new \WP_Error('buymecoffee_migration_phase_invalid', __('The stored migration phase is invalid.', 'buy-me-coffee'));
    }

    /**
     * @param array  $state Current state.
     * @param string $phase Next phase.
     * @return array
     */
    private function advanceMigrationPhase(array $state, $phase)
    {
        $state['phase'] = $phase;
        $state['cursor'] = 0;

        return $state;
    }

    /**
     * Normalize a bounded number of legacy empty remote subscription IDs.
     *
     * @param array $state Current state.
     * @return array|\WP_Error
     */
    private function normalizeSubscriptionBatch(array $state)
    {
        global $wpdb;

        $batchSize = $this->migrationBatchSize();
        $table = $wpdb->prefix . 'buymecoffee_subscriptions';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned migration table; LIMIT bounds the write.
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET stripe_subscription_id = NULL WHERE stripe_subscription_id = '' ORDER BY id ASC LIMIT %d",
            $batchSize
        ));

        return $this->boundedWriteResult($state, $affected, $batchSize, self::PHASE_NORMALIZE_ACCESS, 'normalize_subscriptions');
    }

    /**
     * Remove invalid subscription links from a bounded legacy access batch.
     *
     * @param array $state Current state.
     * @return array|\WP_Error
     */
    private function normalizeAccessBatch(array $state)
    {
        global $wpdb;

        $batchSize = $this->migrationBatchSize();
        $table = $wpdb->prefix . 'buymecoffee_membership_access';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned migration table; LIMIT bounds the write.
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET subscription_id = NULL WHERE subscription_id IS NOT NULL AND access_type IN ('one_time', 'manual') ORDER BY id ASC LIMIT %d",
            $batchSize
        ));

        return $this->boundedWriteResult($state, $affected, $batchSize, self::PHASE_BACKFILL_ACCESS, 'normalize_access');
    }

    /**
     * Remove a bounded batch of access caches after the source-of-truth move.
     *
     * @param array $state Current state.
     * @return array|\WP_Error
     */
    private function purgeAccessCacheBatch(array $state)
    {
        global $wpdb;

        $batchSize = $this->migrationBatchSize();
        $table = $wpdb->prefix . 'buymecoffee_supporters_meta';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned migration table; LIMIT bounds the delete.
        $affected = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE meta_key = %s ORDER BY id ASC LIMIT %d",
            'active_level_ids',
            $batchSize
        ));

        return $this->boundedWriteResult($state, $affected, $batchSize, self::PHASE_VERIFY, 'purge_access_cache');
    }

    /**
     * Convert a bounded UPDATE/DELETE result into durable phase progress.
     *
     * @param array     $state Current state.
     * @param int|false $affected Query result.
     * @param int       $batchSize Batch ceiling.
     * @param string    $nextPhase Phase after this table is exhausted.
     * @param string    $operation Telemetry label.
     * @return array|\WP_Error
     */
    private function boundedWriteResult(array $state, $affected, $batchSize, $nextPhase, $operation)
    {
        global $wpdb;

        if ($affected === false) {
            return new \WP_Error(
                'buymecoffee_migration_query_failed',
                sprintf(
                    /* translators: 1: migration operation, 2: database error. */
                    __('Migration operation %1$s failed: %2$s', 'buy-me-coffee'),
                    $operation,
                    $wpdb->last_error ?: __('unknown database error', 'buy-me-coffee')
                )
            );
        }

        $affected = (int) $affected;
        $state['processed'] += $affected;
        $state['cursor'] += $affected;

        if ($affected < $batchSize) {
            return $this->advanceMigrationPhase($state, $nextPhase);
        }

        return $state;
    }

    /**
     * Copy one bounded subscription ID range with one set-based insert.
     *
     * A NOT EXISTS identity guard supplements the table's unique keys for
     * manual/one-time rows that have neither a transaction nor subscription ID.
     * Replaying a range after a crash is therefore safe for every access type.
     *
     * A row already owning a source's transaction is reconciled to that source
     * first, so accepting it as the source's row is a statement about its
     * contents and not only about its transaction ID.
     *
     * @param array $state Current state.
     * @return array|\WP_Error
     */
    private function backfillAccessBatch(array $state)
    {
        global $wpdb;

        $batchSize = $this->migrationBatchSize();
        $subscriptions = $wpdb->prefix . 'buymecoffee_subscriptions';
        $supporters = $wpdb->prefix . 'buymecoffee_supporters';
        $transactions = $wpdb->prefix . 'buymecoffee_transactions';
        $access = $wpdb->prefix . 'buymecoffee_membership_access';
        $cursor = absint($state['cursor']);

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; keyset cursor and LIMIT bound the read.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$subscriptions} WHERE id > %d AND level_id IS NOT NULL AND level_id > 0 ORDER BY id ASC LIMIT %d",
            $cursor,
            $batchSize
        ));

        if ($wpdb->last_error) {
            return new \WP_Error('buymecoffee_migration_query_failed', $wpdb->last_error);
        }

        if (empty($ids)) {
            return $this->advanceMigrationPhase($state, self::PHASE_PURGE_ACCESS_CACHE);
        }

        $lastId = max(array_map('absint', $ids));
        $now = current_time('mysql');
        $sourceSql = "
            SELECT
                s.id AS source_id,
                s.supporter_id,
                NULLIF(sup.wp_user_id, 0) AS wp_user_id,
                s.level_id,
                tx.transaction_id,
                CASE
                    WHEN s.payment_mode = 'manual' THEN 'manual'
                    WHEN s.interval_type = 'one_time' THEN 'one_time'
                    ELSE 'subscription'
                END AS access_type,
                COALESCE(NULLIF(s.status, ''), 'incomplete') AS access_status,
                s.current_period_end,
                s.created_at
            FROM {$subscriptions} s
            LEFT JOIN {$supporters} sup ON sup.id = s.supporter_id
            LEFT JOIN (
                SELECT subscription_id, MIN(id) AS transaction_id
                FROM {$transactions}
                WHERE subscription_id > %d AND subscription_id <= %d
                GROUP BY subscription_id
            ) tx ON tx.subscription_id = s.id
            WHERE s.id > %d AND s.id <= %d
              AND s.level_id IS NOT NULL AND s.level_id > 0";

        // Repair transaction-matched rows before the insert decides which
        // sources are still missing, so the identity guard below accepts only
        // rows that actually describe their source.
        $reconciled = $this->reconcileTransactionMatchedAccess($sourceSql, $cursor, $lastId, $now);
        if (is_wp_error($reconciled)) {
            return $reconciled;
        }

        // A row that already owns src.transaction_id counts as satisfied for
        // every access type: the table's unique key on transaction_id makes a
        // second row impossible, so treating that as "still missing" would fail
        // the batch on every retry forever. The reconciliation above is what
        // makes that acceptance legitimate rather than merely unavoidable.
        $identitySql = "
            (src.access_type = 'subscription' AND existing.subscription_id = src.source_id)
            OR (src.transaction_id IS NOT NULL AND existing.transaction_id = src.transaction_id)
            OR (
                src.access_type <> 'subscription'
                AND src.transaction_id IS NULL
                AND existing.subscription_id IS NULL
                AND existing.transaction_id IS NULL
                AND existing.supporter_id = src.supporter_id
                AND existing.level_id = src.level_id
                AND existing.access_type = src.access_type
            )";

        $insertSql = "
            INSERT IGNORE INTO {$access}
                (supporter_id, wp_user_id, level_id, transaction_id, subscription_id, access_type, status, starts_at, expires_at, created_at, updated_at)
            SELECT
                src.supporter_id,
                src.wp_user_id,
                src.level_id,
                src.transaction_id,
                CASE WHEN src.access_type = 'subscription' THEN src.source_id ELSE NULL END,
                src.access_type,
                src.access_status,
                COALESCE(NULLIF(src.created_at, '0000-00-00 00:00:00'), %s),
                CASE
                    WHEN src.access_type = 'subscription' AND src.current_period_end IS NOT NULL
                         AND src.current_period_end <> '0000-00-00 00:00:00'
                    THEN src.current_period_end
                    ELSE NULL
                END,
                COALESCE(NULLIF(src.created_at, '0000-00-00 00:00:00'), %s),
                %s
            FROM ({$sourceSql}) src
            WHERE NOT EXISTS (
                SELECT 1 FROM {$access} existing WHERE {$identitySql}
            )";

        // Each statement is prepared exactly once. Nesting an already prepared
        // fragment inside a second prepare() call would double-process any
        // placeholder escaping WordPress applies to literal data.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- All table names are plugin-owned; source ID range is prepared and bounded.
        $inserted = $wpdb->query($wpdb->prepare($insertSql, $now, $now, $now, $cursor, $lastId, $cursor, $lastId));
        if ($inserted === false) {
            return new \WP_Error('buymecoffee_migration_query_failed', $wpdb->last_error ?: __('Membership access rows could not be inserted.', 'buy-me-coffee'));
        }

        $missingSql = "SELECT COUNT(*) FROM ({$sourceSql}) src
            WHERE NOT EXISTS (SELECT 1 FROM {$access} existing WHERE {$identitySql})";
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The source is a bounded plugin-owned ID range prepared below.
        $missing = $wpdb->get_var($wpdb->prepare($missingSql, $cursor, $lastId, $cursor, $lastId));
        if ($wpdb->last_error || (int) $missing > 0) {
            return new \WP_Error(
                'buymecoffee_migration_backfill_incomplete',
                $wpdb->last_error ?: sprintf(
                    /* translators: %d: number of missing rows. */
                    __('%d membership access rows were not persisted.', 'buy-me-coffee'),
                    (int) $missing
                )
            );
        }

        $state['cursor'] = $lastId;
        $state['processed'] += count($ids);

        if (count($ids) < $batchSize) {
            return $this->advanceMigrationPhase($state, self::PHASE_PURGE_ACCESS_CACHE);
        }

        return $state;
    }

    /**
     * Rewrite transaction-matched rows of one bounded range to their source.
     *
     * The unique key on transaction_id means a source whose transaction is
     * already owned by some row can never be inserted, so the backfill has to
     * accept that row. Accepting it unconditionally is what leaves data wrong:
     * a legacy one_time row owning a recurring source's transaction keeps a
     * NULL subscription_id and the wrong access_type, so a later subscription
     * cancellation cannot find the access it is supposed to revoke.
     *
     * This is one extra set-based statement over the same bounded ID range, so
     * the per-batch query count stays fixed. A null-safe comparison excludes
     * rows that already describe their source, which keeps replaying a range
     * free of writes.
     *
     * @param string $sourceSql Source projection with four ID-range placeholders.
     * @param int    $cursor    Exclusive lower source ID bound.
     * @param int    $lastId    Inclusive upper source ID bound.
     * @param string $now       Timestamp for this batch.
     * @return int|\WP_Error Number of repaired rows.
     */
    private function reconcileTransactionMatchedAccess($sourceSql, $cursor, $lastId, $now)
    {
        global $wpdb;

        $access = $wpdb->prefix . 'buymecoffee_membership_access';

        // The canonical shape of a source, identical to the INSERT projection.
        $canonicalSubscription = "CASE WHEN src.access_type = 'subscription' THEN src.source_id ELSE NULL END";
        $canonicalExpires = "CASE
                    WHEN src.access_type = 'subscription' AND src.current_period_end IS NOT NULL
                         AND src.current_period_end <> '0000-00-00 00:00:00'
                    THEN src.current_period_end
                    ELSE NULL
                END";
        // A source without a usable date keeps the start already stored, so a
        // second pass cannot drift the value it wrote during the first one.
        $canonicalStarts = "COALESCE(NULLIF(src.created_at, '0000-00-00 00:00:00'), existing.starts_at, %s)";

        // IGNORE covers the single collision this cannot resolve: a different
        // row already owns the canonical subscription_id. That other row is the
        // one a cancellation finds, so the duplicate has to be skipped instead
        // of failing this range on every retry forever.
        $reconcileSql = "
            UPDATE IGNORE {$access} existing
            JOIN ({$sourceSql}) src
                ON src.transaction_id IS NOT NULL
                AND existing.transaction_id = src.transaction_id
            SET
                existing.supporter_id = src.supporter_id,
                existing.wp_user_id = src.wp_user_id,
                existing.level_id = src.level_id,
                existing.subscription_id = {$canonicalSubscription},
                existing.access_type = src.access_type,
                existing.status = src.access_status,
                existing.starts_at = {$canonicalStarts},
                existing.expires_at = {$canonicalExpires},
                existing.updated_at = %s
            WHERE NOT (
                existing.supporter_id <=> src.supporter_id
                AND existing.wp_user_id <=> src.wp_user_id
                AND existing.level_id <=> src.level_id
                AND existing.subscription_id <=> ({$canonicalSubscription})
                AND existing.access_type <=> src.access_type
                AND existing.status <=> src.access_status
                AND existing.starts_at <=> ({$canonicalStarts})
                AND existing.expires_at <=> ({$canonicalExpires})
            )";

        $wpdb->last_error = '';
        // The statement is prepared exactly once, for the same reason the
        // insert is: re-preparing an already prepared fragment would double
        // process the escaping WordPress applied to the literal data in it.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- All table names are plugin-owned; the source ID range is prepared and bounded.
        $repaired = $wpdb->query($wpdb->prepare($reconcileSql, $cursor, $lastId, $cursor, $lastId, $now, $now, $now));

        if ($repaired === false) {
            return new \WP_Error(
                'buymecoffee_migration_query_failed',
                $wpdb->last_error ?: __('Existing membership access rows could not be reconciled.', 'buy-me-coffee')
            );
        }

        return (int) $repaired;
    }

    /**
     * Persist failure telemetry and arrange a bounded retry.
     *
     * @param array     $state State at failure.
     * @param \WP_Error $error Failure.
     * @return \WP_Error
     */
    private function recordMigrationFailure(array $state, $error)
    {
        if (!is_wp_error($error)) {
            $error = new \WP_Error('buymecoffee_migration_failed', (string) $error);
        }

        $state['updated_at'] = current_time('mysql');
        $state['retries'] = absint($state['retries'] ?? 0) + 1;
        $state['last_error'] = [
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'at'      => $state['updated_at'],
        ];
        $this->persistMigrationState($state);
        $this->scheduleMigration($this->retryDelay($state['retries']));

        $error->add_data(['migration_state' => $state]);

        return $error;
    }

    /**
     * Schedule one worker event; WordPress's cron option is site-scoped.
     *
     * @param int|null $delay Seconds from now.
     * @return bool
     */
    private function scheduleMigration($delay = null)
    {
        if (wp_next_scheduled(self::MIGRATION_HOOK) !== false) {
            return true;
        }

        $delay = $delay === null ? $this->nextRunDelay() : max(1, absint($delay));

        return (bool) wp_schedule_single_event(time() + $delay, self::MIGRATION_HOOK);
    }

    /**
     * Claim the site migration with an atomic option insert/CAS takeover.
     *
     * add_option() cannot be used here: it performs a cached read, then an
     * INSERT ... ON DUPLICATE KEY UPDATE, so two concurrent workers can both
     * report success and the second silently overwrites a live lock.
     *
     * @return string|false Exact lock value, used for owner-checked release.
     */
    private function acquireMigrationLock()
    {
        global $wpdb;

        $value = wp_json_encode([
            'token'      => wp_generate_uuid4(),
            'expires_at' => time() + $this->lockTtl(),
        ]);

        // The unique key on option_name makes exactly one INSERT win.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; atomic lock acquisition cannot go through the option cache.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)",
            self::MIGRATION_LOCK_OPTION,
            $value,
            'off'
        ));
        $this->flushMigrationLockCache();

        if ($inserted === 1) {
            return $value;
        }

        $existing = $this->readMigrationLock();
        if ($existing === null) {
            // The row disappeared between the INSERT and the read; the next
            // scheduled run acquires it cleanly.
            return false;
        }

        $decoded = json_decode($existing, true);
        if (is_array($decoded) && absint($decoded['expires_at'] ?? 0) >= time()) {
            return false;
        }

        // Atomically replace only the stale value we observed. If another
        // worker won the race, zero rows change and this worker stays inert.
        $replaced = $wpdb->update(
            $wpdb->options,
            ['option_value' => $value],
            ['option_name' => self::MIGRATION_LOCK_OPTION, 'option_value' => $existing],
            ['%s'],
            ['%s', '%s']
        );
        $this->flushMigrationLockCache();

        return $replaced === 1 ? $value : false;
    }

    /**
     * Read the stored lock straight from the database.
     *
     * @return string|null Raw stored value, or null when no lock row exists.
     */
    private function readMigrationLock()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must never be decided from a possibly stale option cache.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
            self::MIGRATION_LOCK_OPTION
        ));

        return $existing === null ? null : (string) $existing;
    }

    /**
     * Keep the option cache consistent with direct lock row writes.
     *
     * @return void
     */
    private function flushMigrationLockCache()
    {
        wp_cache_delete(self::MIGRATION_LOCK_OPTION, 'options');

        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[self::MIGRATION_LOCK_OPTION])) {
            unset($notoptions[self::MIGRATION_LOCK_OPTION]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }

    /**
     * Release only the lock value acquired by this worker.
     *
     * @param string $lock Exact stored lock value.
     * @return void
     */
    private function releaseMigrationLock($lock)
    {
        global $wpdb;

        $wpdb->delete(
            $wpdb->options,
            ['option_name' => self::MIGRATION_LOCK_OPTION, 'option_value' => (string) $lock],
            ['%s', '%s']
        );
        $this->flushMigrationLockCache();
    }

    /** @return int */
    private function migrationBatchSize()
    {
        return min(500, max(1, absint(apply_filters('buymecoffee_migration_batch_size', 100))));
    }

    /** @return int */
    private function nextRunDelay()
    {
        return max(1, absint(apply_filters('buymecoffee_migration_next_run_delay', MINUTE_IN_SECONDS)));
    }

    /** @return int */
    private function lockTtl()
    {
        return max(30, absint(apply_filters('buymecoffee_migration_lock_ttl', 5 * MINUTE_IN_SECONDS)));
    }

    /**
     * @param int $retries Consecutive failures.
     * @return int
     */
    private function retryDelay($retries)
    {
        $default = min(HOUR_IN_SECONDS, 2 ** min(6, max(0, absint($retries) - 1)) * MINUTE_IN_SECONDS);

        return max(1, absint(apply_filters('buymecoffee_migration_retry_delay', $default, absint($retries))));
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
            // Read on every request by needsMigrationWork(); keep it autoloaded.
            update_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION, 'yes', true);
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
            // Read on every request by needsMigrationWork(); keep it autoloaded.
            update_option(self::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION, 'yes', true);
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

    /**
     * Create the public-request guard table.
     *
     * Every public write endpoint depends on this table for its single-execution
     * guarantee and refuses to run without it, so a site that cannot create it
     * is not "degraded" — it cannot take donations at all. Its absence is
     * therefore reported as a schema failure like any other missing table,
     * rather than quietly passing verification.
     *
     * @return bool
     */
    public function createRequestGuardTable()
    {
        require_once BUYMECOFFEE_DIR . 'includes/Services/PublicRequestGuard.php';

        return \BuyMeCoffee\Services\PublicRequestGuard::createTable();
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
            $wpdb->prefix . 'buymecoffee_request_guard',
        ];

        foreach ($requiredTables as $tableName) {
            if (!$this->tableExists($tableName)) {
                return false;
            }
        }

        // The lease column carries claim ownership; without it the guard cannot
        // make its single-execution promise, so the schema is not current.
        if (!$this->tableHasColumns($wpdb->prefix . 'buymecoffee_request_guard', ['owner_hash', 'state', 'expires_at'])) {
            return false;
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
