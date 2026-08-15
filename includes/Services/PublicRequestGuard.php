<?php

namespace BuyMeCoffee\Services;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Abuse controls and single-execution guarantees for every public
 * (unauthenticated) write endpoint.
 *
 * The public nonce is CSRF protection only: it is readable by anyone who can
 * load the donation form, so it cannot bound how often an endpoint runs, and it
 * cannot stop the same operation running twice at once. This service adds the
 * three controls the nonce cannot give:
 *
 *  - a route-specific request size ceiling, enforced against the real body,
 *  - rate limits consumed before any database insert or outbound provider call,
 *  - durable, atomic, lease-owned claims so one operation runs exactly once even
 *    when two workers arrive in the same millisecond.
 *
 * All three are backed by one plugin-owned table, because acquisition has to be
 * a single statement. `get_transient()` followed by `set_transient()` is a
 * read-modify-write across two round trips: it loses increments and hands the
 * same claim to two workers, which is exactly the case these controls exist to
 * survive. There is therefore no transient fallback — when the table cannot be
 * used the guard reports itself unavailable and its callers fail closed with a
 * retryable 503 rather than silently dropping to a non-atomic substitute.
 *
 * Nothing identifying is stored: addresses, idempotency keys, event ids, order
 * references and lease tokens are all reduced to a salted keyed digest before
 * they reach the database, and never appear in a message or a log line.
 */
class PublicRequestGuard
{
    const TABLE = 'buymecoffee_request_guard';

    const SCHEMA_OPTION  = 'buymecoffee_request_guard_schema';
    const SCHEMA_VERSION = '1.1';

    const TYPE_RATE  = 'rate';
    const TYPE_CLAIM = 'claim';

    const STATE_IN_PROGRESS = 'in_progress';
    const STATE_COMPLETED   = 'completed';

    /** Reported when the guard table cannot be used at all. */
    const STATE_UNAVAILABLE = 'unavailable';

    /**
     * Default lease length for an attempt that is still running.
     */
    const CLAIM_TTL = 900;

    /**
     * Longest wait a refused caller is ever told to observe.
     */
    const MAX_RETRY_AFTER = 60;

    /**
     * Largest replay payload stored against a completed claim.
     */
    const MAX_PAYLOAD_BYTES = 65536;

    /**
     * Rows removed per opportunistic cleanup pass.
     */
    const CLEANUP_LIMIT = 200;

    /**
     * Largest chunk pulled from the request stream in one read.
     */
    const READ_CHUNK_BYTES = 8192;

    /** @var bool|null Whether the guard table is usable in this request. */
    private static $tableReady = null;

    /**
     * Make the guard usable for this request.
     *
     * Loaded before the public handlers are registered so a routed request can
     * never reach an endpoint before the controls that bound it exist.
     *
     * @return void
     */
    public static function boot()
    {
        self::ensureSchema();
    }

    /**
     * @return string Site-scoped guard table name (per blog on multisite).
     */
    public static function tableName()
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create or upgrade the guard table.
     *
     * Called on activation through the Activator, and once per site from boot()
     * so an upgraded install is protected on its very next public request
     * instead of waiting for a background migration to land.
     *
     * @return bool Whether the table exists afterwards.
     */
    public static function createTable()
    {
        global $wpdb;

        $table           = self::tableName();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
                guard_key varchar(191) NOT NULL,
                guard_type varchar(20) NOT NULL DEFAULT '',
                counter int(10) UNSIGNED NOT NULL DEFAULT 0,
                state varchar(20) NOT NULL DEFAULT '',
                owner_hash varchar(64) NOT NULL DEFAULT '',
                payload longtext NULL,
                window_started_at int(10) UNSIGNED NOT NULL DEFAULT 0,
                expires_at int(10) UNSIGNED NOT NULL DEFAULT 0,
                created_at datetime NULL,
                updated_at datetime NULL,
                PRIMARY KEY  (guard_key),
                KEY bmc_rg_expires (expires_at),
                KEY bmc_rg_type (guard_type)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        self::$tableReady = self::schemaExists();

        return self::$tableReady;
    }

    /**
     * Whether the guard table can be used right now.
     *
     * @return bool
     */
    public static function isAvailable()
    {
        if (self::$tableReady === null) {
            self::ensureSchema();
        }

        return (bool) self::$tableReady;
    }

    /**
     * Reset the memoized availability probe. Test seam.
     *
     * @param bool|null $tableReady Force the probe result; null re-probes.
     * @return void
     */
    public static function resetRuntimeState($tableReady = null)
    {
        self::$tableReady = ($tableReady === null) ? null : (bool) $tableReady;
    }

    /**
     * Ensure the table exists and is current, at most one DDL attempt per
     * interval per site.
     *
     * The stored marker is never trusted on its own: a table that was dropped,
     * or a site restored from a database that predates it, would otherwise keep
     * reporting itself ready and every atomic guarantee below would silently
     * become a no-op. The marker only decides whether the DDL is worth running;
     * availability is always the result of an actual probe.
     *
     * @return bool
     */
    private static function ensureSchema()
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }

        $marker  = get_option(self::SCHEMA_OPTION, []);
        $version = (is_array($marker) && isset($marker['version'])) ? (string) $marker['version'] : '';

        if ($version === self::SCHEMA_VERSION && self::schemaExists()) {
            self::$tableReady = true;

            return true;
        }

        // A site whose database user cannot create the table must not retry the
        // DDL on every single request; it is simply reported unavailable until
        // the next attempt window, and its callers fail closed meanwhile. The
        // backoff applies only to a *failed* attempt (the marker is blanked
        // before the DDL runs): an upgrade, or a table that was dropped, has to
        // be repaired on the very next request rather than after a wait.
        $lastAttempt = (is_array($marker) && isset($marker['checked_at'])) ? (int) $marker['checked_at'] : 0;
        if ($version === '' && $lastAttempt && (time() - $lastAttempt) < 300) {
            self::$tableReady = self::schemaExists();

            return self::$tableReady;
        }

        update_option(self::SCHEMA_OPTION, [
            'version'    => '',
            'checked_at' => time(),
        ], true);

        if (!self::createTable()) {
            self::$tableReady = false;

            return false;
        }

        update_option(self::SCHEMA_OPTION, [
            'version'    => self::SCHEMA_VERSION,
            'checked_at' => time(),
        ], true);

        self::$tableReady = true;

        return true;
    }

    /**
     * Whether the table exists with the columns this build depends on.
     *
     * @return bool
     */
    private static function schemaExists()
    {
        global $wpdb;

        $table = self::tableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned schema probe.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
        return $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'owner_hash')) === 'owner_hash';
    }

    /* ------------------------------------------------------------------ */
    /* Client identity                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The address this request is attributed to.
     *
     * Forwarding headers are never read: any client can set them, so trusting
     * them turns a per-client limit into no limit at all. A host behind a proxy
     * opts in by resolving the real address itself and returning it from the
     * `buymecoffee_trusted_client_ip` filter.
     *
     * @return string Validated IP, or an empty string when there is none.
     */
    public static function clientIp()
    {
        $remoteAddr = '';
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP below.
            $remoteAddr = trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])));
        }

        if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            $remoteAddr = '';
        }

        $trusted = apply_filters('buymecoffee_trusted_client_ip', null, $remoteAddr);
        if (is_string($trusted) && filter_var(trim($trusted), FILTER_VALIDATE_IP)) {
            return trim($trusted);
        }

        return $remoteAddr;
    }

    /**
     * Stable, non-reversible identifier for the current client.
     *
     * @return string
     */
    public static function clientId()
    {
        $ip = self::clientIp();

        return self::fingerprint('client|' . ($ip !== '' ? $ip : 'unknown'));
    }

    /**
     * Keyed digest of a value. Raw values never reach storage or logs.
     *
     * @param string $value Value to fingerprint.
     * @return string 40 hex characters.
     */
    public static function fingerprint($value)
    {
        return substr(hash_hmac('sha256', (string) $value, wp_salt('auth')), 0, 40);
    }

    /* ------------------------------------------------------------------ */
    /* Route configuration                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Size ceiling and rate buckets for a route.
     *
     * Every threshold is filterable so a host can tune, tighten, or disable
     * (limit 0) any single control without patching the plugin.
     *
     * @param string $route Route key.
     * @return array
     */
    public static function routeConfig($route)
    {
        $routes = [
            'submission' => [
                'max_bytes' => 64 * 1024,
                'buckets'   => [
                    ['name' => 'burst', 'scope' => 'client', 'limit' => 8, 'window' => MINUTE_IN_SECONDS],
                    ['name' => 'sustained', 'scope' => 'client', 'limit' => 30, 'window' => HOUR_IN_SECONDS],
                    ['name' => 'site', 'scope' => 'site', 'limit' => 600, 'window' => 5 * MINUTE_IN_SECONDS],
                ],
            ],
            'payment_confirmation_stripe' => [
                'max_bytes' => 32 * 1024,
                'buckets'   => [
                    ['name' => 'burst', 'scope' => 'client', 'limit' => 12, 'window' => MINUTE_IN_SECONDS],
                    ['name' => 'sustained', 'scope' => 'client', 'limit' => 60, 'window' => HOUR_IN_SECONDS],
                    ['name' => 'operation', 'scope' => 'identifier', 'limit' => 6, 'window' => MINUTE_IN_SECONDS],
                    ['name' => 'site', 'scope' => 'site', 'limit' => 900, 'window' => 5 * MINUTE_IN_SECONDS],
                ],
            ],
            'payment_confirmation_paypal' => [
                'max_bytes' => 32 * 1024,
                'buckets'   => [
                    ['name' => 'burst', 'scope' => 'client', 'limit' => 12, 'window' => MINUTE_IN_SECONDS],
                    ['name' => 'sustained', 'scope' => 'client', 'limit' => 60, 'window' => HOUR_IN_SECONDS],
                    ['name' => 'operation', 'scope' => 'identifier', 'limit' => 6, 'window' => MINUTE_IN_SECONDS],
                    ['name' => 'site', 'scope' => 'site', 'limit' => 900, 'window' => 5 * MINUTE_IN_SECONDS],
                ],
            ],
            // Providers deliver from a small shared address pool, so there is
            // deliberately no per-address ceiling on delivery itself: a busy site
            // must never have genuine webhooks refused because somebody else's
            // traffic filled a bucket keyed on Stripe's own address. Only the
            // size ceiling applies here; amplification is bounded per event id
            // and, after the fact, per failed authentication.
            'webhook_stripe' => [
                'max_bytes' => 512 * 1024,
                'buckets'   => [],
            ],
            // Consumed just before the Stripe API re-fetch, so one repeated event
            // id cannot be used to amplify requests into the Stripe API.
            'webhook_stripe_event' => [
                'max_bytes' => 0,
                'buckets'   => [
                    ['name' => 'fetch', 'scope' => 'identifier', 'limit' => 6, 'window' => 5 * MINUTE_IN_SECONDS],
                ],
            ],
            // Charged only when an event failed to authenticate, so a genuine
            // delivery never spends any of it however high the volume.
            'webhook_stripe_invalid' => [
                'max_bytes' => 0,
                'buckets'   => [
                    ['name' => 'invalid', 'scope' => 'client', 'limit' => 60, 'window' => HOUR_IN_SECONDS],
                ],
            ],
            // The payload bucket keys on the exact IPN body, so a replayed body is
            // throttled while a genuinely different PayPal notification from the
            // same address is untouched.
            'ipn_paypal' => [
                'max_bytes' => 128 * 1024,
                'buckets'   => [
                    ['name' => 'payload', 'scope' => 'identifier', 'limit' => 5, 'window' => 5 * MINUTE_IN_SECONDS],
                ],
            ],
            // Charged only when PayPal answered anything other than VERIFIED.
            'ipn_paypal_invalid' => [
                'max_bytes' => 0,
                'buckets'   => [
                    ['name' => 'invalid', 'scope' => 'client', 'limit' => 60, 'window' => HOUR_IN_SECONDS],
                ],
            ],
        ];

        $config = isset($routes[$route]) ? $routes[$route] : ['max_bytes' => 0, 'buckets' => []];

        $config['max_bytes'] = max(0, (int) apply_filters(
            'buymecoffee_public_request_max_bytes',
            $config['max_bytes'],
            $route
        ));

        foreach ($config['buckets'] as $index => $bucket) {
            $config['buckets'][$index]['limit'] = max(0, (int) apply_filters(
                'buymecoffee_public_request_rate_limit',
                $bucket['limit'],
                $route,
                $bucket['name']
            ));

            $config['buckets'][$index]['window'] = max(1, (int) apply_filters(
                'buymecoffee_public_request_rate_window',
                $bucket['window'],
                $route,
                $bucket['name']
            ));
        }

        return $config;
    }

    /**
     * How long a claim in a given namespace lives.
     *
     * The two phases are answering different questions. An in-flight lease has
     * to outlive a worker that dies *after* creating a remote side effect but
     * before recording it, or the very next retry creates that side effect a
     * second time — so it is measured against a request that hangs, not against
     * one that runs normally. A completed claim is the real idempotency window
     * and is measured against how long a client or a provider may keep retrying.
     *
     * @param string $namespace Claim family.
     * @param string $phase     'in_progress' or 'completed'.
     * @return int Seconds.
     */
    public static function claimLifetime($namespace, $phase)
    {
        $lifetimes = [
            // A submission writes local rows and asks a gateway to create a
            // remote payment; a completed one may never be created again today.
            'submission' => ['in_progress' => 3600, 'completed' => 86400],
            // Browser confirmations verify and capture at the provider.
            'stripe_confirmation' => ['in_progress' => 900, 'completed' => 86400],
            'paypal_confirmation' => ['in_progress' => 900, 'completed' => 86400],
            // Providers redeliver for days, so a consumed delivery has to stay
            // recognisable for longer than the provider's own retry schedule.
            'stripe_event' => ['in_progress' => 900, 'completed' => 1209600],
            'paypal_ipn'   => ['in_progress' => 900, 'completed' => 1209600],
            // A subscription invoice is announced by more than one delivery and
            // more than one event id, so its identity has to stay recognisable
            // for as long as the provider may keep announcing it.
            'stripe_invoice' => ['in_progress' => 900, 'completed' => 1209600],
        ];

        $default  = ['in_progress' => self::CLAIM_TTL, 'completed' => 86400];
        $lifetime = isset($lifetimes[$namespace]) ? $lifetimes[$namespace] : $default;
        $seconds  = isset($lifetime[$phase]) ? $lifetime[$phase] : $default[$phase];

        return max(60, (int) apply_filters('buymecoffee_request_guard_claim_ttl', $seconds, $namespace, $phase));
    }

    /* ------------------------------------------------------------------ */
    /* Decisions                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Check only the size ceiling for a route.
     *
     * Separate from inspect() because it needs no storage: it is the one control
     * that can still run when the guard table is gone, and it is the first thing
     * a webhook does, before its body is read at all.
     *
     * @param string   $route    Route key.
     * @param int|null $measured Length of a body the caller already read.
     * @return array {allowed, code, http, message, retry_after}
     */
    public static function checkSize($route, $measured = null)
    {
        $config = self::routeConfig($route);
        $size   = self::requestSize($measured);

        if ($config['max_bytes'] > 0 && $size > $config['max_bytes']) {
            return self::decision(false, 'request_too_large', 413, __('The request is too large to be processed.', 'buy-me-coffee'));
        }

        return self::decision(true, 'allowed', 200, '');
    }

    /**
     * Decide whether a public request may run.
     *
     * Returning the decision instead of terminating keeps every outcome
     * assertable in tests; enforce() is the thin wrapper that answers.
     *
     * @param string $route   Route key.
     * @param array  $context 'identifier' scopes operation buckets, 'measured_bytes'
     *                        supplies a body length the caller already read, and
     *                        'buckets' restricts the pass to named buckets so a
     *                        handler that inspects twice cannot double-count one.
     * @return array {allowed, code, http, message, retry_after}
     */
    public static function inspect($route, array $context = [])
    {
        $size = self::checkSize($route, isset($context['measured_bytes']) ? $context['measured_bytes'] : null);
        if (!$size['allowed']) {
            return $size;
        }

        if (!self::isAvailable()) {
            return self::unavailable();
        }

        $config     = self::routeConfig($route);
        $identifier = isset($context['identifier']) ? (string) $context['identifier'] : '';
        $only       = (isset($context['buckets']) && is_array($context['buckets'])) ? $context['buckets'] : null;

        foreach ($config['buckets'] as $bucket) {
            if ($bucket['limit'] <= 0) {
                continue;
            }

            if ($only !== null && !in_array($bucket['name'], $only, true)) {
                continue;
            }

            if ($bucket['scope'] === 'identifier' && $identifier === '') {
                continue;
            }

            $result = self::consume(
                self::bucketKey($route, $bucket, $identifier),
                $bucket['limit'],
                $bucket['window']
            );

            if (!$result['allowed']) {
                if (!$result['available']) {
                    return self::unavailable();
                }

                return self::decision(
                    false,
                    'rate_limited',
                    429,
                    __('Too many requests. Please wait a moment and try again.', 'buy-me-coffee'),
                    $result['retry_after']
                );
            }
        }

        self::maybeCleanup();

        return self::decision(true, 'allowed', 200, '');
    }

    /**
     * Whether a route's buckets are already spent, without spending any.
     *
     * Used to gate an outbound provider call on a budget that is only ever
     * charged *after* the call failed to authenticate. Reading first and
     * charging later is safe here precisely because the charge is the atomic
     * part: the read only decides whether it is worth making the call at all.
     *
     * @param string $route   Route key.
     * @param array  $context See inspect().
     * @return array {allowed, code, http, message, retry_after}
     */
    public static function budgetRemaining($route, array $context = [])
    {
        if (!self::isAvailable()) {
            return self::unavailable();
        }

        $config     = self::routeConfig($route);
        $identifier = isset($context['identifier']) ? (string) $context['identifier'] : '';
        $now        = time();

        foreach ($config['buckets'] as $bucket) {
            if ($bucket['limit'] <= 0) {
                continue;
            }

            if ($bucket['scope'] === 'identifier' && $identifier === '') {
                continue;
            }

            $key = self::storageKey(self::bucketKey($route, $bucket, $identifier));
            $row = self::readRow($key);

            if (!$row || (int) $row->expires_at <= $now) {
                continue;
            }

            if ((int) $row->counter >= $bucket['limit']) {
                return self::decision(
                    false,
                    'rate_limited',
                    429,
                    __('Too many requests. Please wait a moment and try again.', 'buy-me-coffee'),
                    self::retryAfter((int) $row->expires_at - $now)
                );
            }
        }

        return self::decision(true, 'allowed', 200, '');
    }

    /**
     * Charge one hit against every bucket of a route.
     *
     * @param string $route   Route key.
     * @param array  $context See inspect().
     * @return void
     */
    public static function chargeBudget($route, array $context = [])
    {
        if (!self::isAvailable()) {
            return;
        }

        $config     = self::routeConfig($route);
        $identifier = isset($context['identifier']) ? (string) $context['identifier'] : '';

        foreach ($config['buckets'] as $bucket) {
            if ($bucket['limit'] <= 0) {
                continue;
            }

            if ($bucket['scope'] === 'identifier' && $identifier === '') {
                continue;
            }

            self::consume(self::bucketKey($route, $bucket, $identifier), $bucket['limit'], $bucket['window']);
        }
    }

    /**
     * Run inspect() and answer the request itself when it is refused.
     *
     * Only used by the JSON (admin-ajax) endpoints. Webhook/IPN handlers call
     * inspect() and fold the decision into their own outcome record.
     *
     * @param string $route   Route key.
     * @param array  $context See inspect().
     * @return void
     */
    public static function enforce($route, array $context = [])
    {
        $decision = self::inspect($route, $context);
        if ($decision['allowed']) {
            return;
        }

        self::sendRetryAfter($decision['retry_after']);

        wp_send_json_error([
            'message' => $decision['message'],
            'code'    => $decision['code'],
        ], $decision['http']);
    }

    /**
     * The decision every protected route returns when the guard cannot run.
     *
     * Failing closed is the whole point: without the table there is no atomic
     * claim, so allowing the request through would permit exactly the duplicate
     * side effect the claim exists to prevent. 503 with a wait is retryable, so
     * a provider redelivers and a browser can try again.
     *
     * @return array
     */
    public static function unavailable()
    {
        return self::decision(
            false,
            'guard_unavailable',
            503,
            __('This service is temporarily unavailable. Please try again shortly.', 'buy-me-coffee'),
            self::MAX_RETRY_AFTER
        );
    }

    /**
     * Emit a Retry-After header when a wait is known.
     *
     * @param int $seconds Seconds to wait.
     * @return void
     */
    public static function sendRetryAfter($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds <= 0 || headers_sent()) {
            return;
        }

        header('Retry-After: ' . $seconds);
    }

    /**
     * Size of the current request body in bytes.
     *
     * Content-Length is the only reading available before the body is touched.
     * A caller that already read the raw body passes its real length, and the
     * larger of the two wins, so neither an understated nor a missing header can
     * buy more room than the route allows.
     *
     * @param int|null $measured Length of a body the caller already read.
     * @return int
     */
    public static function requestSize($measured = null)
    {
        $size = 0;

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int.
            $size = (int) $_SERVER['CONTENT_LENGTH'];
        }

        if ($measured !== null) {
            $size = max($size, (int) $measured);
        }

        return max(0, $size);
    }

    /**
     * Read the request body without ever buffering more than a route allows.
     *
     * The stream is read in chunks and stopped one byte past the ceiling: that
     * single extra byte is enough to know the body is oversized, and it means an
     * attacker cannot make the process hold a payload larger than the route
     * accepts however the transfer is framed.
     *
     * @param int         $maxBytes Route ceiling; 0 means no ceiling.
     * @param string|null $rawBody  Body the caller already has; read when null.
     * @return array {body, bytes, exceeded}
     */
    public static function measureBody($maxBytes, $rawBody = null)
    {
        $maxBytes = max(0, (int) $maxBytes);

        if ($rawBody !== null) {
            $body = (string) $rawBody;

            return [
                'body'     => $body,
                'bytes'    => strlen($body),
                'exceeded' => $maxBytes > 0 && strlen($body) > $maxBytes,
            ];
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable input stream is an empty body, not a fatal.
        $handle = @fopen('php://input', 'rb');
        if (!$handle) {
            return ['body' => '', 'bytes' => 0, 'exceeded' => false];
        }

        $cap  = $maxBytes > 0 ? $maxBytes + 1 : 0;
        $body = '';

        while (!feof($handle)) {
            $chunk = fread($handle, self::READ_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;

            if ($cap > 0 && strlen($body) >= $cap) {
                $body = substr($body, 0, $cap);
                break;
            }
        }

        fclose($handle);

        return [
            'body'     => $body,
            'bytes'    => strlen($body),
            'exceeded' => $maxBytes > 0 && strlen($body) > $maxBytes,
        ];
    }

    /**
     * @param bool   $allowed    Whether the request may continue.
     * @param string $code       Machine-readable outcome.
     * @param int    $http       Status code to answer with.
     * @param string $message    Human-readable, never containing a key or an address.
     * @param int    $retryAfter Seconds until the caller may retry.
     * @return array
     */
    private static function decision($allowed, $code, $http, $message, $retryAfter = 0)
    {
        return [
            'allowed'     => (bool) $allowed,
            'code'        => $code,
            'http'        => (int) $http,
            'message'     => $message,
            'retry_after' => max(0, (int) $retryAfter),
        ];
    }

    /**
     * @param int $seconds Raw wait.
     * @return int Wait a caller is actually told to observe.
     */
    private static function retryAfter($seconds)
    {
        return max(1, min(self::MAX_RETRY_AFTER, (int) $seconds));
    }

    /**
     * @param string $route      Route key.
     * @param array  $bucket     Bucket definition.
     * @param string $identifier Operation identifier for identifier-scoped buckets.
     * @return string
     */
    private static function bucketKey($route, array $bucket, $identifier)
    {
        if ($bucket['scope'] === 'site') {
            return 'rate|' . $route . '|' . $bucket['name'] . '|site';
        }

        $key = 'rate|' . $route . '|' . $bucket['name'] . '|' . self::clientId();

        if ($bucket['scope'] === 'identifier') {
            // Identifier buckets are always client-scoped as well, so hammering
            // someone else's intent or order id can never exhaust the bucket the
            // real donor's browser needs.
            $key .= '|' . self::fingerprint('operation|' . $identifier);
        }

        return $key;
    }

    /* ------------------------------------------------------------------ */
    /* Atomic counters                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Count one hit against a bucket and report whether it is still allowed.
     *
     * The insert-or-increment is a single statement, so parallel PHP workers
     * cannot both read the same count and write the same value back. The count
     * is read back through LAST_INSERT_ID(), which is per-connection, so a
     * concurrent request's increment can never be mistaken for this one.
     *
     * @param string $bucketKey Bucket identity.
     * @param int    $limit     Hits allowed per window.
     * @param int    $window    Window length in seconds.
     * @return array {allowed, count, retry_after, available}
     */
    public static function consume($bucketKey, $limit, $window)
    {
        global $wpdb;

        $limit  = (int) $limit;
        $window = max(1, (int) $window);

        if ($limit <= 0) {
            return ['allowed' => true, 'count' => 0, 'retry_after' => 0, 'available' => true];
        }

        if (!self::isAvailable()) {
            return ['allowed' => false, 'count' => 0, 'retry_after' => self::MAX_RETRY_AFTER, 'available' => false];
        }

        $table   = self::tableName();
        $key     = self::storageKey($bucketKey);
        $now     = time();
        $expires = $now + $window;
        $stamp   = current_time('mysql');

        // The ON DUPLICATE assignments are evaluated left to right, so counter and
        // window_started_at still read the stored expires_at; it is written last.
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
            "INSERT INTO {$table}
                (guard_key, guard_type, counter, state, owner_hash, window_started_at, expires_at, created_at, updated_at)
             VALUES (%s, %s, LAST_INSERT_ID(1), '', '', %d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                counter = LAST_INSERT_ID(IF(expires_at <= %d, 1, counter + 1)),
                window_started_at = IF(expires_at <= %d, %d, window_started_at),
                expires_at = IF(expires_at <= %d, %d, expires_at),
                updated_at = %s",
            $key,
            self::TYPE_RATE,
            $now,
            $expires,
            $stamp,
            $stamp,
            $now,
            $now,
            $now,
            $now,
            $expires,
            $stamp
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; atomic counter.
        $written = $wpdb->query($sql);

        if ($written === false) {
            // The table went away underneath this request: fail closed rather
            // than pretend the hit was counted.
            self::$tableReady = false;

            return ['allowed' => false, 'count' => 0, 'retry_after' => self::MAX_RETRY_AFTER, 'available' => false];
        }

        $count = (int) $wpdb->insert_id;
        if ($count < 1) {
            // Any MySQL build that does not carry LAST_INSERT_ID(expr) into the
            // client falls back to a read; the write above stays atomic either way.
            $row   = self::readRow($key);
            $count = $row ? (int) $row->counter : 1;
        }

        if ($count <= $limit) {
            return ['allowed' => true, 'count' => $count, 'retry_after' => 0, 'available' => true];
        }

        $row = self::readRow($key);

        return [
            'allowed'     => false,
            'count'       => $count,
            'retry_after' => self::retryAfter($row ? ((int) $row->expires_at - $now) : $window),
            'available'   => true,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Idempotency / replay claims                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Claim a key exactly once, and hold the lease until it is settled.
     *
     * The claim is an INSERT against a unique key, so of two identical requests
     * arriving together exactly one is told it may proceed. A claim whose lease
     * has lapsed is taken over by a conditional UPDATE, which is equally atomic
     * and mints a fresh owner token — the worker that lost the lease can then no
     * longer complete or release the claim its replacement now owns.
     *
     * @param string $namespace Claim family, e.g. 'submission'.
     * @param string $rawKey    Caller-supplied key; hashed before storage.
     * @return array {acquired, key, owner, state, payload, retry_after, available}
     */
    public static function claim($namespace, $rawKey)
    {
        global $wpdb;

        $key = self::storageKey('claim|' . $namespace . '|' . $rawKey);
        $ttl = self::claimLifetime($namespace, 'in_progress');

        if (!self::isAvailable()) {
            return [
                'acquired'    => false,
                'key'         => $key,
                'owner'       => '',
                'state'       => self::STATE_UNAVAILABLE,
                'payload'     => null,
                'retry_after' => self::MAX_RETRY_AFTER,
                'available'   => false,
            ];
        }

        $table   = self::tableName();
        $now     = time();
        $expires = $now + $ttl;
        $stamp   = current_time('mysql');
        $owner   = self::mintOwnerToken();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared below; atomic claim.
        $inserted = $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
            "INSERT IGNORE INTO {$table}
                (guard_key, guard_type, counter, state, owner_hash, window_started_at, expires_at, created_at, updated_at)
             VALUES (%s, %s, 1, %s, %s, %d, %d, %s, %s)",
            $key,
            self::TYPE_CLAIM,
            self::STATE_IN_PROGRESS,
            self::ownerHash($owner),
            $now,
            $expires,
            $stamp,
            $stamp
        ));

        if ($inserted === false) {
            // The schema may have disappeared after this request's readiness
            // probe. Do not turn a failed atomic write into an ordinary
            // in-progress refusal: callers need the retryable 503 outcome.
            self::$tableReady = false;

            return self::unavailableClaim($key);
        }

        if ($inserted) {
            return self::acquired($key, $owner);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared below; atomic takeover of a lapsed claim.
        $takenOver = $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
            "UPDATE {$table}
                SET state = %s, payload = NULL, owner_hash = %s, window_started_at = %d, expires_at = %d, updated_at = %s
              WHERE guard_key = %s AND expires_at <= %d",
            self::STATE_IN_PROGRESS,
            self::ownerHash($owner),
            $now,
            $expires,
            $stamp,
            $key,
            $now
        ));

        if ($takenOver === false) {
            self::$tableReady = false;

            return self::unavailableClaim($key);
        }

        if ($takenOver) {
            return self::acquired($key, $owner);
        }

        $row = self::readRow($key);

        return [
            'acquired'    => false,
            'key'         => $key,
            'owner'       => '',
            'state'       => $row ? (string) $row->state : self::STATE_IN_PROGRESS,
            'payload'     => $row ? self::decodePayload($row->payload) : null,
            'retry_after' => self::retryAfter($row ? ((int) $row->expires_at - $now) : $ttl),
            'available'   => true,
        ];
    }

    /**
     * Claim-shaped fail-closed result for a guard storage failure.
     *
     * @param string $key Hashed storage key.
     * @return array
     */
    private static function unavailableClaim($key)
    {
        return [
            'acquired'    => false,
            'key'         => $key,
            'owner'       => '',
            'state'       => self::STATE_UNAVAILABLE,
            'payload'     => null,
            'retry_after' => self::MAX_RETRY_AFTER,
            'available'   => false,
        ];
    }

    /**
     * Return an acquired lease to its caller.
     *
     * @param string $key   Storage key.
     * @param string $owner Opaque lease token.
     * @return array
     */
    private static function acquired($key, $owner)
    {
        return [
            'acquired'    => true,
            'key'         => $key,
            'owner'       => $owner,
            'state'       => self::STATE_IN_PROGRESS,
            'payload'     => null,
            'retry_after' => 0,
            'available'   => true,
        ];
    }

    /**
     * Mark a claim finished and store the response a repeat may replay.
     *
     * Conditional on the lease token, so a stalled worker whose claim was taken
     * over cannot mark its replacement's work finished.
     *
     * @param string $key       Storage key returned by claim().
     * @param string $owner     Lease token returned by claim().
     * @param array  $payload   Replayable response data; stored only when small enough.
     * @param string $namespace Claim family, for the completed lifetime.
     * @return bool Whether this caller still owned the claim.
     */
    public static function completeClaim($key, $owner, array $payload = [], $namespace = '')
    {
        global $wpdb;

        if (!self::isAvailable() || $owner === '') {
            return false;
        }

        $ttl     = self::claimLifetime($namespace, 'completed');
        $encoded = $payload ? wp_json_encode($payload) : '';

        if (!is_string($encoded) || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            $encoded = '';
        }

        $table = self::tableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
        return (bool) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
            "UPDATE {$table}
                SET state = %s, payload = %s, expires_at = %d, updated_at = %s
              WHERE guard_key = %s AND owner_hash = %s AND state = %s",
            self::STATE_COMPLETED,
            $encoded,
            time() + $ttl,
            current_time('mysql'),
            $key,
            self::ownerHash($owner),
            self::STATE_IN_PROGRESS
        ));
    }

    /**
     * Give a claim back so the same key can be attempted again.
     *
     * Used when an attempt ended before it could produce anything durable: a key
     * must never be burned by a failure the caller can safely retry. Conditional
     * on the lease token and on the claim still being in flight, so neither a
     * stalled predecessor nor a late retry can delete a claim it does not own or
     * undo one that already completed.
     *
     * @param string $key   Storage key returned by claim().
     * @param string $owner Lease token returned by claim().
     * @return bool Whether this caller still owned the claim.
     */
    public static function releaseClaim($key, $owner)
    {
        global $wpdb;

        if (!self::isAvailable() || $owner === '') {
            return false;
        }

        $table = self::tableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
        return (bool) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
            "DELETE FROM {$table} WHERE guard_key = %s AND owner_hash = %s AND state = %s",
            $key,
            self::ownerHash($owner),
            self::STATE_IN_PROGRESS
        ));
    }

    /**
     * Read a claim without altering it.
     *
     * @param string $namespace Claim family.
     * @param string $rawKey    Caller-supplied key.
     * @return array|null {state, payload}
     */
    public static function readClaim($namespace, $rawKey)
    {
        if (!self::isAvailable()) {
            return null;
        }

        $row = self::readRow(self::storageKey('claim|' . $namespace . '|' . $rawKey));

        if (!$row || (int) $row->expires_at <= time()) {
            return null;
        }

        return [
            'state'   => (string) $row->state,
            'payload' => self::decodePayload($row->payload),
        ];
    }

    /**
     * @param string $key Storage key.
     * @return object|null
     */
    private static function readRow($key)
    {
        global $wpdb;

        if (!self::isAvailable()) {
            return null;
        }

        $table = self::tableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE guard_key = %s", $key));
    }

    /**
     * @param string|null $payload Stored JSON payload.
     * @return array|null
     */
    private static function decodePayload($payload)
    {
        if (!$payload) {
            return null;
        }

        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A fresh, unguessable lease token. Only its digest is ever stored.
     *
     * @return string
     */
    private static function mintOwnerToken()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $error) {
            return bin2hex(wp_generate_password(16, false, false));
        }
    }

    /**
     * @param string $owner Lease token.
     * @return string Digest stored in place of the token.
     */
    private static function ownerHash($owner)
    {
        return hash_hmac('sha256', 'owner|' . (string) $owner, wp_salt('auth'));
    }

    /* ------------------------------------------------------------------ */
    /* Housekeeping                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Delete a bounded slice of lapsed rows.
     *
     * Runs rarely and with a hard row limit, so the guard table stays small
     * without ever turning a donation request into a long delete.
     *
     * @return int Rows removed.
     */
    public static function cleanup()
    {
        global $wpdb;

        if (!self::isAvailable()) {
            return 0;
        }

        $table = self::tableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
        return (int) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
            "DELETE FROM {$table} WHERE expires_at > 0 AND expires_at < %d LIMIT %d",
            time(),
            self::CLEANUP_LIMIT
        ));
    }

    /**
     * @return void
     */
    private static function maybeCleanup()
    {
        $odds = (int) apply_filters('buymecoffee_request_guard_cleanup_odds', 50);
        if ($odds <= 0) {
            return;
        }

        if (wp_rand(1, $odds) !== 1) {
            return;
        }

        self::cleanup();
    }

    /**
     * Hash a guard key so no address, order reference, or idempotency key is
     * ever stored in readable form.
     *
     * @param string $rawKey Raw key.
     * @return string
     */
    private static function storageKey($rawKey)
    {
        return self::fingerprint('guard|' . $rawKey);
    }
}
