<?php

/**
 * Bootstrap WordPress core and Buy Me Coffee without loading unrelated plugins.
 *
 * Set BMC_WP_ROOT when the plugin is not installed under wp-content/plugins.
 */

error_reporting(E_ALL);

$wpRoot = getenv('BMC_WP_ROOT');
if (!$wpRoot) {
    $wpRoot = dirname(__DIR__, 4);
}

$wpLoad = rtrim($wpRoot, '/\\') . '/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "Unable to find wp-load.php. Set BMC_WP_ROOT to a local WordPress installation.\n");
    exit(2);
}

if (!defined('WP_INSTALLING')) {
    // Prevent WordPress from loading every active plugin in the development site.
    define('WP_INSTALLING', true);
}

if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}

require_once $wpLoad;

$homeHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);
$isLocalHost = in_array($homeHost, ['localhost', '127.0.0.1', '::1'], true)
    || substr($homeHost, -5) === '.test'
    || substr($homeHost, -6) === '.local';

if (!$isLocalHost && getenv('BMC_ALLOW_NONLOCAL_TEST_DB') !== '1') {
    fwrite(
        STDERR,
        "Refusing to run database-backed feature tests against '{$homeHost}'. "
        . "Use a local .test/.local site or explicitly set BMC_ALLOW_NONLOCAL_TEST_DB=1.\n"
    );
    exit(2);
}

require_once dirname(__DIR__) . '/buy-me-coffee.php';
do_action('plugins_loaded');
