<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/FeatureTestRunner.php';

use BuyMeCoffee\Builder\Methods\PayPal\IPN;
use BuyMeCoffee\Builder\Methods\PayPal\PayPal;
use BuyMeCoffee\Builder\Methods\PayPal\PayPalSettings;
use BuyMeCoffee\Builder\Methods\Stripe\Stripe;
use BuyMeCoffee\Classes\AccessControl;
use BuyMeCoffee\Classes\Activator;
use BuyMeCoffee\Classes\ActivityLogger;
use BuyMeCoffee\Classes\AdminAjaxHandler;
use BuyMeCoffee\Classes\EmailNotifications;
use BuyMeCoffee\Classes\PostAccessMetaBox;
use BuyMeCoffee\Controllers\MonetizationController;
use BuyMeCoffee\Controllers\PaymentHandler;
use BuyMeCoffee\Controllers\SubmissionHandler;
use BuyMeCoffee\Helpers\ArrayHelper;
use BuyMeCoffee\Helpers\PaymentHelper;
use BuyMeCoffee\Helpers\SanitizeHelper;
use BuyMeCoffee\Models\MembershipAccess;
use BuyMeCoffee\Models\MembershipLevel;
use BuyMeCoffee\Models\Subscriptions;
use BuyMeCoffee\Models\Supporters;
use BuyMeCoffee\Models\Transactions;
use BuyMeCoffee\Services\OneTimePaymentStatusService;
use BuyMeCoffee\Services\PublicRequestGuard;
use BuyMeCoffee\Services\SupporterDeletionService;

/**
 * Stands in for the die() that ends a real wp_send_json_*() response, so an
 * endpoint can be run to completion without ending the test process.
 *
 * Deliberately an Error rather than an Exception: gateway code legitimately
 * wraps its own calls in catch (\Exception), and a halted response must not be
 * mistaken for a payment failure and answered a second time.
 */
class BmcHaltedPublicRequest extends Error
{
}

$suite = new BmcFeatureTestRunner();

$suite->test('plugin boot registers public features and payment endpoints', function ($test) {
    foreach (['buymecoffee_button', 'buymecoffee_form', 'buymecoffee_basic', 'buymecoffee_account', 'buymecoffee_supporters'] as $shortcode) {
        $test->assertTrue(shortcode_exists($shortcode), "Missing shortcode: {$shortcode}");
    }

    $test->assertNotEmpty(has_action('wp_ajax_buymecoffee_submit'), 'Authenticated submission endpoint is missing');
    $test->assertNotEmpty(has_action('wp_ajax_nopriv_buymecoffee_submit'), 'Guest submission endpoint is missing');
    $test->assertNotEmpty(has_action('wp_ajax_buymecoffee_payment_confirmation_stripe'), 'Stripe confirmation endpoint is missing');
    $test->assertNotEmpty(has_action('wp_ajax_nopriv_buymecoffee_payment_confirmation_stripe'), 'Guest Stripe confirmation endpoint is missing');
    $test->assertNotEmpty(has_action('wp_ajax_buymecoffee_payment_confirmation_paypal'), 'PayPal confirmation endpoint is missing');
    $test->assertNotEmpty(has_action('wp_ajax_nopriv_buymecoffee_payment_confirmation_paypal'), 'Guest PayPal confirmation endpoint is missing');
    $test->assertNotEmpty(has_action('buymecoffee_ipn_endpoint_stripe'), 'Stripe webhook hook is missing');
    $test->assertNotEmpty(has_action('buymecoffee_ipn_endpoint_paypal'), 'PayPal webhook hook is missing');
    $test->assertNotEmpty(has_filter('the_content'), 'Paywall content filter is missing');
    $test->assertNotEmpty(has_action('wp_ajax_buymecoffee_cancel_subscription'), 'Account cancellation endpoint is missing');
    $test->assertFalse(has_action('wp_ajax_nopriv_buymecoffee_cancel_subscription') !== false, 'Cancellation must not be public');
});

$suite->test('database schema has every feature table and critical index', function ($test) {
    global $wpdb;

    $tables = [
        'buymecoffee_supporters',
        'buymecoffee_transactions',
        'buymecoffee_subscriptions',
        'buymecoffee_activities',
        'buymecoffee_membership_levels',
        'buymecoffee_membership_access',
        'buymecoffee_supporters_meta',
        'buymecoffee_request_guard',
    ];

    foreach ($tables as $table) {
        $fullName = $wpdb->prefix . $table;
        $actual = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($fullName)));
        $test->assertSame($fullName, $actual, "Missing table: {$fullName}");
    }

    $requiredIndexes = [
        'buymecoffee_supporters' => ['PRIMARY', 'bmc_sup_email', 'bmc_sup_wp_user', 'bmc_sup_hash'],
        'buymecoffee_transactions' => ['PRIMARY', 'bmc_tx_entry', 'bmc_tx_charge', 'bmc_tx_sub'],
        'buymecoffee_subscriptions' => ['PRIMARY', 'bmc_sub_stripe_sub', 'bmc_sub_supporter', 'bmc_sub_lvl'],
        'buymecoffee_membership_access' => ['PRIMARY', 'bmc_ma_tx', 'bmc_ma_sub', 'bmc_ma_supporter', 'bmc_ma_level'],
    ];

    foreach ($requiredIndexes as $table => $expectedIndexes) {
        $rows = $wpdb->get_results('SHOW INDEX FROM ' . $wpdb->prefix . $table);
        $actualIndexes = array_unique(wp_list_pluck($rows, 'Key_name'));
        foreach ($expectedIndexes as $index) {
            $test->assertTrue(in_array($index, $actualIndexes, true), "Missing index {$table}.{$index}");
        }
    }
});

$suite->test('payment methods normalize enabled states and registered gateways', function ($test) {
    $methods = PaymentHandler::getAllMethods();
    $test->assertTrue(isset($methods['stripe']), 'Stripe is not registered');
    $test->assertTrue(isset($methods['paypal']), 'PayPal is not registered');
    $test->assertTrue(in_array('subscription', $methods['stripe']['supports'], true), 'Stripe subscription support is missing');
    $test->assertFalse(in_array('subscription', $methods['paypal']['supports'], true), 'PayPal must not advertise subscription support');

    foreach ([true, 'yes', 1, '1'] as $enabled) {
        $test->assertTrue(PaymentHandler::isMethodEnabled(['status' => $enabled]));
    }
    foreach ([false, 'no', 0, '0', null] as $disabled) {
        $test->assertFalse(PaymentHandler::isMethodEnabled(['status' => $disabled]));
    }
    $test->assertFalse(PaymentHandler::isMethodEnabled([]));
});

$suite->test('Stripe currency conversions preserve the plugin storage scale', function ($test) {
    $test->assertTrue(PaymentHelper::isStripeZeroDecimalCurrency('jpy'));
    $test->assertTrue(PaymentHelper::isStripeZeroDecimalCurrency('KRW'));
    $test->assertFalse(PaymentHelper::isStripeZeroDecimalCurrency('USD'));
    $test->assertSame(1299, PaymentHelper::toStripeAmount(1299, 'USD'));
    $test->assertSame(13, PaymentHelper::toStripeAmount(1299, 'JPY'));
    $test->assertSame(1299, PaymentHelper::fromStripeAmount(1299, 'USD'));
    $test->assertSame(1300, PaymentHelper::fromStripeAmount(13, 'JPY'));
});

$suite->test('array, color, and email helpers sanitize frontend-facing values', function ($test) {
    $source = ['gateway' => ['stripe' => ['enabled' => 'yes']], 'zero' => 0];
    $test->assertTrue(ArrayHelper::has($source, 'gateway.stripe.enabled'));
    $test->assertSame('yes', ArrayHelper::get($source, 'gateway.stripe.enabled'));
    $test->assertSame('fallback', ArrayHelper::get($source, 'missing', 'fallback'));
    $test->assertSame('fallback', ArrayHelper::get($source, 'zero', 'fallback'));

    $test->assertSame('#0f766e', SanitizeHelper::cssColor('#0f766e', 'red'));
    $test->assertSame('rgba(13, 148, 136, 25%)', SanitizeHelper::cssColor('rgba(13, 148, 136, 25%)', 'red'));
    $test->assertSame('red', SanitizeHelper::cssColor('url(javascript:alert(1))', 'red'));
    $test->assertSame('rgba(1, 2, 3, 10%)', SanitizeHelper::rgbToRgba('rgb(1, 2, 3)', '10%'));

    $vars = ['donor_name' => 'Ada', 'amount' => '$5.00'];
    $test->assertSame('Thanks Ada for $5.00', EmailNotifications::parse('Thanks {{donor_name}} for {{amount}}', $vars));
});

$suite->test('submission input is sanitized and donation totals are bounded', function ($test) {
    $handler = new SubmissionHandler();
    $raw = [
        ['name' => 'wpm-supporter-name', 'value' => '<script>alert(1)</script>Ada'],
        ['name' => 'wpm-supporter-email', 'value' => ' ADa+test@Example.COM '],
        ['name' => 'wpm-supporter-message', 'value' => '<img src=x onerror=alert(1)>Hello'],
        ['value' => 'ignored'],
    ];

    $sanitized = $test->invokePrivate($handler, 'sanitizeFormData', [$raw]);
    $test->assertNotContains('<script', $sanitized['wpm-supporter-name']);
    $test->assertSame('ADa+test@Example.COM', $sanitized['wpm-supporter-email']);
    $test->assertNotContains('<img', $sanitized['wpm-supporter-message']);
    $test->assertSame(3, count($sanitized));

    $calculated = $test->invokePrivate($handler, 'calculatePaymentData', [
        ['buymecoffee_amount' => '12.34', 'radio-group' => '3'],
        ['defaultAmount' => '5'],
    ]);
    $test->assertSame(3702, $calculated['payment_total']);
    $test->assertSame(3, $calculated['coffee_count']);

    $bounded = $test->invokePrivate($handler, 'calculatePaymentData', [
        ['buymecoffee_amount' => '-10', 'buymecoffee_quantity' => '999999'],
        ['defaultAmount' => '5'],
    ]);
    $test->assertSame(500000, $bounded['payment_total']);
    $test->assertSame(1000, $bounded['coffee_count']);

    $test->assertFalse($test->invokePrivate($handler, 'canAllowLegacyPublicRequest', ['submission']));
});

$suite->test('membership checkout ignores tampered client price and interval', function ($test) {
    update_option('buymecoffee_membership_settings', ['membership_active' => true], false);

    $levelId = (new MembershipLevel())->create([
        'name' => 'Annual Security Test',
        'description' => '',
        'price' => 1234,
        'payment_type' => 'subscription',
        'interval_type' => 'year',
        'status' => 'active',
        'rewards' => '[]',
        'access_rules' => '[]',
        'sort_order' => 999,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);

    $formData = [
        'bmc_level_id' => $levelId,
        'wpm-supporter-email' => 'member@example.com',
        'buymecoffee_amount' => '0.01',
        'buymecoffee_quantity' => '999',
    ];
    $paymentMethod = 'stripe';
    $paymentTotal = 1;
    $quantity = 999;
    $isRecurring = 'no';
    $recurringInterval = 'month';
    $allMethods = ['stripe' => ['status' => true]];

    $arguments = [
        &$formData,
        &$paymentMethod,
        &$paymentTotal,
        &$quantity,
        &$isRecurring,
        &$recurringInterval,
        'USD',
        $allMethods,
    ];

    $level = $test->invokePrivate(new SubmissionHandler(), 'bindMembershipCheckout', $arguments);
    $test->assertSame((int) $levelId, (int) $level->id);
    $test->assertSame(1234, $paymentTotal);
    $test->assertSame(1, $quantity);
    $test->assertSame('yes', $isRecurring);
    $test->assertSame('year', $recurringInterval);
    $test->assertSame('12.34', $formData['buymecoffee_amount']);
    $test->assertSame(1234, $formData['payment_total']);
});

$suite->test('subscription access validity handles active, expired, and cancelled periods', function ($test) {
    $future = gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS);
    $past = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);

    $test->assertTrue(Subscriptions::hasAccessValidity((object) ['status' => 'active', 'access_type' => 'manual']));
    $test->assertTrue(Subscriptions::hasAccessValidity((object) ['status' => 'active', 'interval_type' => 'one_time']));
    $test->assertTrue(Subscriptions::hasAccessValidity((object) ['status' => 'active', 'access_type' => 'subscription', 'expires_at' => $future]));
    $test->assertFalse(Subscriptions::hasAccessValidity((object) ['status' => 'active', 'access_type' => 'subscription', 'expires_at' => $past]));
    $test->assertTrue(Subscriptions::hasAccessValidity((object) ['status' => 'cancelled', 'current_period_end' => $future]));
    $test->assertFalse(Subscriptions::hasAccessValidity((object) ['status' => 'cancelled', 'current_period_end' => $past]));
    $test->assertFalse(Subscriptions::hasAccessValidity((object) ['status' => 'failed']));
});

$suite->test('one-time membership activation and refund update user access', function ($test) {
    global $wpdb;

    $userId = wp_insert_user([
        'user_login' => 'bmc_feature_' . wp_generate_password(8, false, false),
        'user_email' => 'bmc-feature-' . wp_generate_password(8, false, false) . '@example.com',
        'user_pass' => wp_generate_password(24),
        'role' => 'subscriber',
    ]);
    $test->assertFalse(is_wp_error($userId), 'Could not create test user');

    $levelId = (new MembershipLevel())->create([
        'name' => 'One-time Feature Test',
        'price' => 2500,
        'payment_type' => 'one_time',
        'interval_type' => 'month',
        'status' => 'active',
        'rewards' => '[]',
        'access_rules' => '[]',
        'sort_order' => 999,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);

    $supporterId = buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
        'supporters_name' => 'Feature Member',
        'supporters_email' => 'bmc-feature-member@example.com',
        'payment_status' => 'pending',
        'entry_hash' => 'bmc_feature_' . wp_generate_password(20, false, false),
        'payment_total' => 2500,
        'coffee_count' => 1,
        'payment_mode' => 'test',
        'payment_method' => 'stripe',
        'status' => 'new',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'wp_user_id' => $userId,
    ]);

    $transactionId = buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
        'entry_id' => $supporterId,
        'entry_hash' => 'bmc_tx_' . wp_generate_password(20, false, false),
        'transaction_type' => 'one_time',
        'payment_method' => 'stripe',
        'payment_total' => 2500,
        'status' => 'pending',
        'currency' => 'USD',
        'payment_mode' => 'test',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);

    $access = new MembershipAccess();
    $accessId = $access->createPendingForTransaction($transactionId, $supporterId, $levelId);
    $test->assertNotEmpty($accessId);
    $test->assertSame([], buymecoffee_user_get_active_level_ids($userId, true));

    $test->assertSame((int) $accessId, $access->activateByTransaction($transactionId));
    $test->assertSame([(int) $levelId], buymecoffee_user_get_active_level_ids($userId, true));

    do_action('buymecoffee_payment_status_updated', $transactionId, 'refunded');
    $test->assertSame([], buymecoffee_user_get_active_level_ids($userId, true));

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}buymecoffee_membership_access WHERE id = %d",
        $accessId
    ));
    $test->assertSame('refunded', $row->status);
});

$suite->test('paywall hides paid content from guests and reveals it to entitled members', function ($test) {
    global $post, $wp_query;

    update_option('buymecoffee_membership_settings', [
        'membership_active' => true,
        'default_preview_words' => 3,
    ], false);

    $levelId = (new MembershipLevel())->create([
        'name' => 'Paywall Feature Test',
        'description' => 'Feature test plan',
        'price' => 500,
        'payment_type' => 'one_time',
        'interval_type' => 'month',
        'status' => 'active',
        'rewards' => '["Premium article"]',
        'access_rules' => '{"post_types":["post"],"categories":[],"access_level":"full"}',
        'sort_order' => 999,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);

    $userId = wp_insert_user([
        'user_login' => 'bmc_paywall_' . wp_generate_password(8, false, false),
        'user_email' => 'bmc-paywall-' . wp_generate_password(8, false, false) . '@example.com',
        'user_pass' => wp_generate_password(24),
        'role' => 'subscriber',
    ]);
    $supporterId = buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
        'supporters_name' => 'Paywall Member',
        'supporters_email' => 'bmc-paywall-member@example.com',
        'payment_status' => 'paid',
        'entry_hash' => 'bmc_paywall_' . wp_generate_password(20, false, false),
        'payment_total' => 500,
        'coffee_count' => 1,
        'payment_mode' => 'manual',
        'payment_method' => 'membership_invite',
        'status' => 'new',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'wp_user_id' => $userId,
    ]);
    (new MembershipAccess())->grantManualAccess($supporterId, $userId, $levelId);

    $postId = wp_insert_post([
        'post_title' => 'BMC Paywall Feature Test',
        'post_content' => 'one two three four five six',
        'post_status' => 'publish',
        'post_type' => 'post',
    ]);
    update_post_meta($postId, '_buymecoffee_access', 'paid');
    update_post_meta($postId, '_buymecoffee_level_ids', [(int) $levelId]);
    update_post_meta($postId, '_buymecoffee_preview_words', 0);

    $post = get_post($postId);
    $wp_query->is_singular = true;
    $wp_query->queried_object = $post;
    $wp_query->queried_object_id = $postId;

    $controller = new MonetizationController();
    wp_set_current_user(0);
    $guestContent = $controller->filterContent($post->post_content);
    $test->assertContains('one two three', $guestContent);
    $test->assertNotContains('four five six', $guestContent);
    $test->assertContains('bmc-paywall', $guestContent);
    $test->assertContains('Paywall Feature Test', $guestContent);

    wp_set_current_user($userId);
    $memberContent = $controller->filterContent($post->post_content);
    $test->assertSame($post->post_content, $memberContent);
});

$suite->test('shortcodes render escaped public and account markup', function ($test) {
    update_option('buymecoffee_payment_setting', [
        'buttonText' => '<script>alert(1)</script>Support safely',
        'enable_account' => 'yes',
        'currency' => 'USD',
    ], false);

    wp_set_current_user(0);

    $button = do_shortcode('[buymecoffee_button]');
    $test->assertContains('wpm-buymecoffee-button', $button);
    $test->assertContains('Support safely', $button);
    $test->assertNotContains('<script>', $button);

    $form = do_shortcode('[buymecoffee_form]');
    $test->assertContains('buymecoffee_form', $form);
    $test->assertContains('wpm_submit_button', $form);

    $account = do_shortcode('[buymecoffee_account]');
    $test->assertContains('bmc-account-login', $account);
    $test->assertContains('loginform', $account);

    $wall = do_shortcode('[buymecoffee_supporters limit="3" show_amount="yes"]');
    $test->assertContains('buymecoffee-supporters-wall', $wall);
});

$suite->test('delegated capabilities cannot cross admin route boundaries', function ($test) {
    $handler = new AdminAjaxHandler();

    $userId = wp_insert_user([
        'user_login' => 'bmc_acl_' . wp_generate_password(8, false, false),
        'user_email' => 'bmc-acl-' . wp_generate_password(8, false, false) . '@example.com',
        'user_pass' => wp_generate_password(24),
        'role' => 'subscriber',
    ]);
    $user = new WP_User($userId);
    $user->add_cap('buy-me-coffee_can_view_menus');
    $user->add_cap('buy-me-coffee_view_supporters');
    wp_set_current_user($userId);

    $test->assertTrue(AccessControl::hasTopLevelMenuPermission());
    $test->assertTrue($test->invokePrivate($handler, 'canAccessRoute', ['get_supporters']));
    $test->assertTrue($test->invokePrivate($handler, 'canAccessRoute', ['get_review_prompt']));
    $test->assertFalse($test->invokePrivate($handler, 'canAccessRoute', ['get_settings']));
    $test->assertFalse($test->invokePrivate($handler, 'canAccessRoute', ['refund_transaction']));
    $test->assertFalse($test->invokePrivate($handler, 'canAccessRoute', ['delete_supporter']));

    $user->add_cap('buy-me-coffee_manage_settings');
    wp_set_current_user(0);
    wp_set_current_user($userId);
    $test->assertTrue($test->invokePrivate($handler, 'canAccessRoute', ['get_settings']));
    $test->assertFalse($test->invokePrivate($handler, 'canAccessRoute', ['refund_transaction']));
});

$suite->test('post access metadata checks permission on the exact post', function ($test) {
    $authorId = wp_insert_user([
        'user_login' => 'bmc_author_' . wp_generate_password(8, false, false),
        'user_email' => 'bmc-author-' . wp_generate_password(8, false, false) . '@example.com',
        'user_pass' => wp_generate_password(24),
        'role' => 'author',
    ]);
    $otherId = wp_insert_user([
        'user_login' => 'bmc_other_' . wp_generate_password(8, false, false),
        'user_email' => 'bmc-other-' . wp_generate_password(8, false, false) . '@example.com',
        'user_pass' => wp_generate_password(24),
        'role' => 'author',
    ]);
    $ownPostId = wp_insert_post([
        'post_title' => 'Own protected post',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_author' => $authorId,
    ]);
    $otherPostId = wp_insert_post([
        'post_title' => 'Other protected post',
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_author' => $otherId,
    ]);

    (new PostAccessMetaBox())->registerPostMeta();
    $meta = get_registered_meta_keys('post', 'post');
    $callback = $meta['_buymecoffee_access']['auth_callback'];

    wp_set_current_user($authorId);
    $test->assertTrue((bool) call_user_func($callback, false, '_buymecoffee_access', $ownPostId));
    $test->assertFalse((bool) call_user_func($callback, false, '_buymecoffee_access', $otherPostId));
});

$suite->test('PayPal IPN verification cannot be disabled by any stored setting', function ($test) {
    // A site upgraded from a release that stored the removed toggle.
    update_option('buymecoffee_payment_settings_paypal', [
        'enable' => 'yes',
        'payment_mode' => 'test',
        'payment_type' => 'standard',
        'paypal_email' => 'merchant@example.com',
        'disable_ipn_verification' => 'yes',
    ], false);

    $test->assertFalse(
        method_exists(IPN::class, 'isVerificationDisabled'),
        'The IPN verification bypass method must not exist'
    );

    $ipnSource = file_get_contents(dirname(__DIR__) . '/includes/Builder/Methods/PayPal/IPN.php');
    $test->assertNotContains(
        'disable_ipn_verification',
        $ipnSource,
        'The IPN listener must not read the legacy bypass setting'
    );

    $stored = PayPalSettings::get();
    $test->assertFalse(
        array_key_exists('disable_ipn_verification', $stored),
        'A legacy stored bypass value must be ignored by the settings reader'
    );

    $sanitized = (new PayPal())->sanitize([
        'enable' => 'yes',
        'payment_mode' => 'test',
        'payment_type' => 'standard',
        'paypal_email' => 'merchant@example.com',
        'disable_ipn_verification' => 'yes',
    ]);
    $test->assertFalse(
        array_key_exists('disable_ipn_verification', $sanitized),
        'The bypass setting must never be stored again'
    );

    // Stub PayPal's _notify-validate round trip; no network call is made.
    $requests = [];
    $paypalResponse = 'INVALID';
    $stub = function ($pre, $args, $url) use (&$requests, &$paypalResponse) {
        $requests[] = ['url' => $url, 'body' => isset($args['body']) ? $args['body'] : ''];
        return [
            'headers' => [],
            'body' => $paypalResponse,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $ipn = new IPN();
        $encoded = 'cmd=_notify-validate&txn_type=web_accept&payment_status=Completed';

        $rejected = $test->invokePrivate($ipn, 'verifyWithPayPal', [$encoded]);
        $test->assertTrue(
            is_wp_error($rejected),
            'A non-VERIFIED PayPal response must be rejected even in test mode with the legacy toggle stored'
        );

        $paypalResponse = 'VERIFIED';
        $accepted = $test->invokePrivate($ipn, 'verifyWithPayPal', [$encoded]);
        $test->assertSame(true, $accepted, 'A VERIFIED PayPal response must be accepted');

        $test->assertSame(2, count($requests), 'Both sandbox IPNs must round-trip to PayPal');
        $test->assertContains('ipnpb.sandbox.paypal.com', $requests[0]['url'], 'Sandbox IPNs must be verified against PayPal sandbox');
        $test->assertContains('cmd=_notify-validate', $requests[0]['body']);
    } finally {
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('PayPal IPN status updates require a present, exactly matching receiver', function ($test) {
    global $wpdb;

    update_option('buymecoffee_payment_settings_paypal', [
        'enable' => 'yes',
        'payment_mode' => 'test',
        'payment_type' => 'standard',
        'paypal_email' => 'merchant@example.com',
    ], false);

    $makeTransaction = function ($mode = 'test') {
        $supporterId = buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
            'supporters_name' => 'IPN Receiver Test',
            'supporters_email' => 'bmc-ipn-receiver@example.com',
            'payment_status' => 'pending',
            'entry_hash' => 'bmc_ipn_' . wp_generate_password(20, false, false),
            'payment_total' => 2500,
            'coffee_count' => 1,
            'payment_mode' => $mode,
            'payment_method' => 'paypal',
            'status' => 'new',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        $transactionId = buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
            'entry_id' => $supporterId,
            'entry_hash' => 'bmc_ipn_tx_' . wp_generate_password(20, false, false),
            'transaction_type' => 'one_time',
            'payment_method' => 'paypal',
            'payment_total' => 2500,
            'status' => 'pending',
            'currency' => 'USD',
            'payment_mode' => $mode,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return [$supporterId, $transactionId];
    };

    $statusOf = function ($transactionId) use ($wpdb) {
        return $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}buymecoffee_transactions WHERE id = %d",
            $transactionId
        ));
    };

    $supporterStatusOf = function ($supporterId) use ($wpdb) {
        return $wpdb->get_var($wpdb->prepare(
            "SELECT payment_status FROM {$wpdb->prefix}buymecoffee_supporters WHERE id = %d",
            $supporterId
        ));
    };

    $paypal = new PayPal();
    $completedIpn = [
        'txn_type' => 'web_accept',
        'payment_status' => 'Completed',
        'mc_currency' => 'USD',
        'mc_gross' => '25.00',
        'txn_id' => 'BMC-TEST-TXN',
    ];

    // Receiver missing entirely.
    list($supporterId, $transactionId) = $makeTransaction();
    $paypal->updateStatus($completedIpn, $transactionId);
    $test->assertSame('failed', $statusOf($transactionId), 'A missing receiver must not be accepted');
    $test->assertSame('failed', $supporterStatusOf($supporterId), 'A missing receiver must not mark the supporter paid');

    // Receiver present but not the configured account.
    list($supporterId, $transactionId) = $makeTransaction();
    $paypal->updateStatus(array_merge($completedIpn, ['receiver_email' => 'attacker@example.com']), $transactionId);
    $test->assertSame('failed', $statusOf($transactionId), 'A mismatched receiver must not be accepted');

    // Business field carrying a different account.
    list($supporterId, $transactionId) = $makeTransaction();
    $paypal->updateStatus(array_merge($completedIpn, ['business' => 'attacker@example.com']), $transactionId);
    $test->assertSame('failed', $statusOf($transactionId), 'A mismatched business field must not be accepted');

    // Matching receiver on a genuine completed payment.
    list($supporterId, $transactionId) = $makeTransaction();
    $paypal->updateStatus(array_merge($completedIpn, ['receiver_email' => 'Merchant@Example.com ']), $transactionId);
    $test->assertSame('paid', $statusOf($transactionId), 'A matching receiver on a completed payment must be accepted');
    $test->assertSame('paid', $supporterStatusOf($supporterId));

    // Matching business fallback on a genuine completed payment.
    list($supporterId, $transactionId) = $makeTransaction();
    $paypal->updateStatus(array_merge($completedIpn, ['business' => 'merchant@example.com']), $transactionId);
    $test->assertSame('paid', $statusOf($transactionId), 'A matching business field on a completed payment must be accepted');

    // No configured PayPal email means nothing can match.
    update_option('buymecoffee_payment_settings_paypal', [
        'enable' => 'yes',
        'payment_mode' => 'test',
        'payment_type' => 'standard',
        'paypal_email' => '',
    ], false);
    list($supporterId, $transactionId) = $makeTransaction();
    $paypal->updateStatus(array_merge($completedIpn, ['receiver_email' => 'merchant@example.com']), $transactionId);
    $test->assertSame('failed', $statusOf($transactionId), 'An unconfigured payee must never match a receiver');
});

$suite->test('only a completed PayPal payment can transition a transaction to paid', function ($test) {
    global $wpdb;

    update_option('buymecoffee_payment_settings_paypal', [
        'enable' => 'yes',
        'payment_mode' => 'test',
        'payment_type' => 'standard',
        'paypal_email' => 'merchant@example.com',
    ], false);

    $makeTransaction = function ($mode) {
        $supporterId = buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
            'supporters_name' => 'IPN Status Test',
            'supporters_email' => 'bmc-ipn-status@example.com',
            'payment_status' => 'pending',
            'entry_hash' => 'bmc_ipn_s_' . wp_generate_password(20, false, false),
            'payment_total' => 2500,
            'coffee_count' => 1,
            'payment_mode' => $mode,
            'payment_method' => 'paypal',
            'status' => 'new',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        $transactionId = buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
            'entry_id' => $supporterId,
            'entry_hash' => 'bmc_ipn_s_tx_' . wp_generate_password(20, false, false),
            'transaction_type' => 'one_time',
            'payment_method' => 'paypal',
            'payment_total' => 2500,
            'status' => 'pending',
            'currency' => 'USD',
            'payment_mode' => $mode,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return $transactionId;
    };

    $statusOf = function ($transactionId) use ($wpdb) {
        return $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}buymecoffee_transactions WHERE id = %d",
            $transactionId
        ));
    };

    $paypal = new PayPal();
    $baseIpn = [
        'txn_type' => 'web_accept',
        'receiver_email' => 'merchant@example.com',
        'mc_currency' => 'USD',
        'mc_gross' => '25.00',
        'txn_id' => 'BMC-TEST-TXN',
    ];

    // Test-mode statuses that are not a completed payment.
    $transactionId = $makeTransaction('test');
    $paypal->updateStatus(array_merge($baseIpn, ['payment_status' => 'Pending', 'pending_reason' => 'echeck']), $transactionId);
    $test->assertSame('processing', $statusOf($transactionId), 'A pending test IPN must stay unpaid');

    $transactionId = $makeTransaction('test');
    $paypal->updateStatus(array_merge($baseIpn, ['payment_status' => 'Denied']), $transactionId);
    $test->assertSame('pending', $statusOf($transactionId), 'A denied test IPN must not become paid');

    $transactionId = $makeTransaction('test');
    $paypal->updateStatus(array_merge($baseIpn, ['payment_status' => 'Failed']), $transactionId);
    $test->assertSame('pending', $statusOf($transactionId), 'A failed test IPN must not become paid');

    // Refunds report a negative gross and must never be read as a payment.
    $transactionId = $makeTransaction('test');
    $paypal->updateStatus(array_merge($baseIpn, [
        'payment_status' => 'Refunded',
        'mc_gross' => '-25.00',
        'parent_txn_id' => 'BMC-TEST-TXN',
    ]), $transactionId);
    $test->assertSame('refunded', $statusOf($transactionId), 'A refund IPN must not become paid');

    // Completed payments still settle, in both modes.
    $transactionId = $makeTransaction('test');
    $paypal->updateStatus(array_merge($baseIpn, ['payment_status' => 'Completed']), $transactionId);
    $test->assertSame('paid', $statusOf($transactionId), 'A completed test IPN must settle');

    $transactionId = $makeTransaction('live');
    $paypal->updateStatus(array_merge($baseIpn, ['payment_status' => 'Completed']), $transactionId);
    $test->assertSame('paid', $statusOf($transactionId), 'A completed live IPN must settle');

    // Amount tampering is still rejected.
    $transactionId = $makeTransaction('test');
    $paypal->updateStatus(array_merge($baseIpn, ['payment_status' => 'Completed', 'mc_gross' => '0.01']), $transactionId);
    $test->assertSame('failed', $statusOf($transactionId), 'A tampered amount must not settle');
});

// ── HIGH-02: supporter deletion must never leave a live Stripe subscription ──

/**
 * Store Stripe credentials for both modes. The UI mode defaults to 'test' so the
 * tests can prove that stored per-subscription modes pick the credentials.
 */
$bmcStripeSettings = function (array $overrides = []) {
    update_option('buymecoffee_payment_settings_stripe', array_merge([
        'enable'          => 'yes',
        'payment_mode'    => 'test',
        'live_pub_key'    => 'pk_live_bmc',
        'live_secret_key' => 'sk_live_bmc',
        'test_pub_key'    => 'pk_test_bmc',
        'test_secret_key' => 'sk_test_bmc',
    ], $overrides), false);
};

/** Build a wp_remote_request() response array for a Stripe JSON body. */
$bmcStripeBody = function (array $body, $code = 200) {
    return [
        'headers'  => [],
        'body'     => wp_json_encode($body),
        'response' => ['code' => $code, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
};

/** Create a supporter with transactions, access, meta, activities and subscriptions. */
$bmcMakeSupporter = function (array $subscriptionSpecs = [], $wpUserId = null) {
    $suffix = wp_generate_password(12, false, false);

    $supporterId = (int) buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
        'supporters_name'  => 'HIGH02 Donor',
        'supporters_email' => 'bmc-high02-' . $suffix . '@example.com',
        'payment_status'   => 'paid',
        'entry_hash'       => 'bmc_high02_' . $suffix,
        'payment_total'    => 2500,
        'coffee_count'     => 1,
        'payment_mode'     => 'live',
        'payment_method'   => 'stripe',
        'status'           => 'new',
        'created_at'       => current_time('mysql'),
        'updated_at'       => current_time('mysql'),
        'wp_user_id'       => $wpUserId,
    ]);

    $transactionId = (int) buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
        'entry_id'         => $supporterId,
        'entry_hash'       => 'bmc_high02_tx_' . $suffix,
        'transaction_type' => 'recurring',
        'payment_method'   => 'stripe',
        'payment_total'    => 2500,
        'status'           => 'paid',
        'currency'         => 'USD',
        'payment_mode'     => 'live',
        'created_at'       => current_time('mysql'),
        'updated_at'       => current_time('mysql'),
    ]);

    buyMeCoffeeQuery()->table('buymecoffee_membership_access')->insert([
        'supporter_id' => $supporterId,
        'wp_user_id'   => $wpUserId,
        'level_id'     => 4242,
        'access_type'  => 'manual',
        'status'       => 'active',
        'created_at'   => current_time('mysql'),
        'updated_at'   => current_time('mysql'),
    ]);

    buymecoffee_update_supporter_meta($supporterId, 'active_level_ids', [
        'level_ids'  => [4242],
        'expires_at' => null,
    ]);

    ActivityLogger::logSubmission($supporterId, 'submission_created', 'Donation received');
    ActivityLogger::logPayment($transactionId, 'payment_paid', 'Payment captured');

    $subscriptionIds = [];

    foreach ($subscriptionSpecs as $key => $spec) {
        $subscriptionId = (int) buyMeCoffeeQuery()->table('buymecoffee_subscriptions')->insert([
            'supporter_id'           => $supporterId,
            'stripe_subscription_id' => isset($spec['stripe_id']) ? $spec['stripe_id'] . '_' . $suffix : null,
            'stripe_customer_id'     => 'cus_' . $suffix,
            'interval_type'          => 'month',
            'amount'                 => 2500,
            'currency'               => 'usd',
            'status'                 => $spec['status'],
            'payment_mode'           => isset($spec['payment_mode']) ? $spec['payment_mode'] : 'live',
            'created_at'             => current_time('mysql'),
            'updated_at'             => current_time('mysql'),
        ]);

        ActivityLogger::logSubscription($subscriptionId, 'subscription_created', 'Subscription created');
        $subscriptionIds[$key] = $subscriptionId;
    }

    return [
        'supporter_id'     => $supporterId,
        'transaction_id'   => $transactionId,
        'subscription_ids' => $subscriptionIds,
    ];
};

/** Count every row that a supporter deletion is expected to remove. */
$bmcCountRows = function (array $graph) {
    global $wpdb;

    $supporterId = (int) $graph['supporter_id'];
    $prefix      = $wpdb->prefix;

    $counts = [
        'supporters' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}buymecoffee_supporters WHERE id = %d",
            $supporterId
        )),
        'subscriptions' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}buymecoffee_subscriptions WHERE supporter_id = %d",
            $supporterId
        )),
        'transactions' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}buymecoffee_transactions WHERE entry_id = %d",
            $supporterId
        )),
        'membership_access' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}buymecoffee_membership_access WHERE supporter_id = %d",
            $supporterId
        )),
        'supporters_meta' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}buymecoffee_supporters_meta WHERE supporter_id = %d",
            $supporterId
        )),
    ];

    $activities = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}buymecoffee_activities
         WHERE (object_type IN ('submission', 'email') AND object_id = %d)
            OR (object_type = 'payment' AND object_id = %d)",
        $supporterId,
        (int) $graph['transaction_id']
    ));

    $subscriptionIds = array_values($graph['subscription_ids']);
    if ($subscriptionIds) {
        $placeholders = implode(', ', array_fill(0, count($subscriptionIds), '%d'));
        $activities  += (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}buymecoffee_activities
             WHERE object_type = 'subscription' AND object_id IN ({$placeholders})",
            $subscriptionIds
        ));
    }

    $counts['activities'] = $activities;

    return $counts;
};

/**
 * Record every outbound HTTP call and answer it from $responder — no request
 * ever reaches Stripe.
 */
$bmcStripeStub = function (&$requests, &$responder) {
    return function ($pre, $args, $url) use (&$requests, &$responder) {
        $requests[] = [
            'url'    => $url,
            'method' => isset($args['method']) ? $args['method'] : 'GET',
            'auth'   => isset($args['headers']['Authorization']) ? $args['headers']['Authorization'] : '',
        ];

        return $responder($url, $args);
    };
};

$suite->test('supporter deletion cancels the live Stripe subscription with the stored mode key and invalidates caches once', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeSupporter, $bmcCountRows, $bmcStripeStub) {
    $bmcStripeSettings();

    // The subscription was created in live mode while the settings UI is on test.
    $graph = $bmcMakeSupporter([
        'live' => ['stripe_id' => 'sub_high02_active', 'status' => 'active', 'payment_mode' => 'live'],
    ], 906001);

    // A second supporter row for the same WP user: it survives, but its cached
    // level list is computed from rows that are about to disappear.
    $sibling = $bmcMakeSupporter([], 906001);

    $publicVersionBefore = absint(get_option(Supporters::PUBLIC_SUPPORTERS_CACHE_VERSION_OPTION, 1));
    $reportVersionBefore = absint(get_option(Supporters::ADMIN_REPORT_CACHE_VERSION_OPTION, 1));

    $requests  = [];
    $responder = function () use ($bmcStripeBody) {
        return $bmcStripeBody(['id' => 'sub_high02_active', 'object' => 'subscription', 'status' => 'canceled']);
    };
    $stub = $bmcStripeStub($requests, $responder);

    add_filter('pre_http_request', $stub, 10, 3);
    add_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');

    try {
        $result = (new SupporterDeletionService())->delete($graph['supporter_id']);

        $test->assertFalse(is_wp_error($result), 'Deletion must proceed once Stripe confirms the cancellation');
        $test->assertSame([$graph['subscription_ids']['live']], $result['cancelled_subscription_ids']);

        $test->assertSame(1, count($requests), 'Exactly one Stripe call is needed to cancel one subscription');
        $test->assertSame('DELETE', $requests[0]['method'], 'The agreement must be cancelled, not just read');
        $test->assertContains('subscriptions/sub_high02_active', $requests[0]['url']);
        $test->assertSame(
            'Bearer sk_live_bmc',
            $requests[0]['auth'],
            'The stored subscription payment_mode must select the key, not the mode selected in the UI'
        );

        foreach ($bmcCountRows($graph) as $table => $count) {
            $test->assertSame(0, $count, "Rows survived deletion in {$table}");
        }

        $test->assertSame(
            $publicVersionBefore + 1,
            absint(get_option(Supporters::PUBLIC_SUPPORTERS_CACHE_VERSION_OPTION, 1)),
            'The public supporters cache must be invalidated exactly once'
        );
        $test->assertSame(
            $reportVersionBefore + 1,
            absint(get_option(Supporters::ADMIN_REPORT_CACHE_VERSION_OPTION, 1)),
            'The admin report cache must be invalidated exactly once'
        );
        $test->assertSame(
            null,
            buymecoffee_get_supporter_meta($sibling['supporter_id'], 'active_level_ids'),
            'The surviving sibling row must lose its cached membership levels'
        );
        $test->assertSame(1, $bmcCountRows($sibling)['supporters'], 'Unrelated supporter rows must not be deleted');
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        remove_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');
    }
});

$suite->test('an unconfirmed Stripe cancellation aborts deletion and keeps every local row', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeSupporter, $bmcCountRows, $bmcStripeStub) {
    $scenarios = [
        'a transport failure' => [
            'settings'  => [],
            'responder' => function () {
                return new WP_Error('http_request_failed', 'Connection timed out');
            },
            'failure_code' => 'bmc_stripe_cancel_failed',
            // The DELETE fails, then one read confirms the agreement is still live.
            'requests' => 2,
        ],
        'a non-canceled status' => [
            'settings'  => [],
            'responder' => function () use ($bmcStripeBody) {
                return $bmcStripeBody(['id' => 'sub_high02_x', 'object' => 'subscription', 'status' => 'active']);
            },
            'failure_code' => 'bmc_stripe_cancel_unconfirmed',
            'requests'     => 1,
        ],
        'a missing key for the stored mode' => [
            'settings'  => ['live_secret_key' => ''],
            'responder' => function () use ($bmcStripeBody) {
                return $bmcStripeBody(['id' => 'sub_high02_x', 'object' => 'subscription', 'status' => 'canceled']);
            },
            'failure_code' => 'bmc_stripe_key_missing',
            'requests'     => 0,
        ],
    ];

    foreach ($scenarios as $label => $scenario) {
        $bmcStripeSettings($scenario['settings']);

        $graph = $bmcMakeSupporter([
            'live' => ['stripe_id' => 'sub_high02_blocked', 'status' => 'active', 'payment_mode' => 'live'],
        ]);
        $countsBefore = $bmcCountRows($graph);

        $requests  = [];
        $responder = $scenario['responder'];
        $stub      = $bmcStripeStub($requests, $responder);

        add_filter('pre_http_request', $stub, 10, 3);
        add_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');

        try {
            $result = (new SupporterDeletionService())->delete($graph['supporter_id']);

            $test->assertTrue(is_wp_error($result), "Deletion must abort on {$label}");
            $test->assertSame('bmc_supporter_delete_blocked', $result->get_error_code());

            $data = $result->get_error_data();
            $test->assertSame([], $data['cancelled_subscription_ids'], "Nothing may be reported cancelled on {$label}");
            $test->assertSame(1, count($data['failed_subscriptions']));
            $test->assertSame($scenario['failure_code'], $data['failed_subscriptions'][0]['code'], "Wrong failure code for {$label}");
            $test->assertSame($scenario['requests'], count($requests), "Unexpected Stripe call count for {$label}");

            $test->assertSame($countsBefore, $bmcCountRows($graph), "Local rows were lost on {$label}");
            $test->assertSame(1, $countsBefore['supporters'], 'The fixture must exist before deletion is attempted');

            $subscription = (new Subscriptions())->find($graph['subscription_ids']['live']);
            $test->assertSame('active', $subscription->status, "The subscription must not be marked cancelled on {$label}");
        } finally {
            remove_filter('pre_http_request', $stub, 10);
            remove_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');
        }
    }
});

$suite->test('only provider-confirmed expiry and local-only subscriptions are deleted without calling Stripe', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeSupporter, $bmcCountRows, $bmcStripeStub) {
    $bmcStripeSettings();

    // 'expired' comes from Stripe's own lifecycle, so it is proof the agreement
    // can no longer bill. A local-only row never had an agreement at all.
    $graph = $bmcMakeSupporter([
        'expired'   => ['stripe_id' => 'sub_high02_expired', 'status' => 'expired', 'payment_mode' => 'live'],
        'localonly' => ['status' => 'active'],
    ]);

    $requests  = [];
    $responder = function () use ($bmcStripeBody) {
        return $bmcStripeBody(['id' => 'sub_high02_expired', 'object' => 'subscription', 'status' => 'canceled']);
    };
    $stub = $bmcStripeStub($requests, $responder);

    add_filter('pre_http_request', $stub, 10, 3);
    add_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');

    try {
        $result = (new SupporterDeletionService())->delete($graph['supporter_id']);

        $test->assertFalse(is_wp_error($result), 'Rows that cannot bill must not block deletion');
        $test->assertSame([], $result['cancelled_subscription_ids']);
        $test->assertSame(0, count($requests), 'Provider-expired and local-only subscriptions must not reach the provider');

        foreach ($bmcCountRows($graph) as $table => $count) {
            $test->assertSame(0, $count, "Rows survived deletion in {$table}");
        }
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        remove_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');
    }
});

$suite->test('a locally cancelled subscription is still confirmed at Stripe before it is deleted', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeSupporter, $bmcCountRows, $bmcStripeStub) {
    $bmcStripeSettings();

    // A local 'cancelled' is not proof of anything: the legacy donor flow wrote
    // it without Stripe ever confirming. Deleting on the strength of it loses
    // the reconciliation id while the agreement carries on billing, so the
    // agreement has to be confirmed gone before the row may go.
    $graph = $bmcMakeSupporter([
        'locallyCancelled' => ['stripe_id' => 'sub_high02_local_cancel', 'status' => 'cancelled', 'payment_mode' => 'live'],
    ]);

    $requests  = [];
    $responder = function () use ($bmcStripeBody) {
        return $bmcStripeBody(['id' => 'sub_high02_local_cancel', 'object' => 'subscription', 'status' => 'canceled']);
    };
    $stub = $bmcStripeStub($requests, $responder);

    add_filter('pre_http_request', $stub, 10, 3);
    add_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');

    try {
        $result = (new SupporterDeletionService())->delete($graph['supporter_id']);

        $test->assertFalse(is_wp_error($result), 'An agreement Stripe confirms is gone must not block deletion');
        $test->assertSame(1, count($requests), 'A locally cancelled agreement must be verified at Stripe');
        $test->assertSame(
            [$graph['subscription_ids']['locallyCancelled']],
            $result['cancelled_subscription_ids'],
            'The verified cancellation is reported'
        );

        foreach ($bmcCountRows($graph) as $table => $count) {
            $test->assertSame(0, $count, "Rows survived deletion in {$table}");
        }
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        remove_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');
    }
});

$suite->test('one failing subscription blocks deletion and every agreement is re-confirmed on retry', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeSupporter, $bmcCountRows, $bmcStripeStub) {
    $bmcStripeSettings();

    $graph = $bmcMakeSupporter([
        'ok'  => ['stripe_id' => 'sub_high02_ok', 'status' => 'active', 'payment_mode' => 'live'],
        'bad' => ['stripe_id' => 'sub_high02_bad', 'status' => 'past_due', 'payment_mode' => 'live'],
    ]);
    $countsBefore = $bmcCountRows($graph);

    $requests  = [];
    $responder = function ($url) use ($bmcStripeBody) {
        if (strpos($url, 'sub_high02_bad') !== false) {
            return $bmcStripeBody(['error' => ['message' => 'Stripe is unavailable']], 500);
        }

        return $bmcStripeBody(['id' => 'sub_high02_ok', 'object' => 'subscription', 'status' => 'canceled']);
    };
    $stub = $bmcStripeStub($requests, $responder);

    add_filter('pre_http_request', $stub, 10, 3);
    add_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');

    try {
        $blocked = (new SupporterDeletionService())->delete($graph['supporter_id']);

        $test->assertTrue(is_wp_error($blocked), 'A single unconfirmed subscription must block the whole deletion');
        $data = $blocked->get_error_data();
        $test->assertSame([$graph['subscription_ids']['ok']], $data['cancelled_subscription_ids'], 'Partial success must be reported');
        $test->assertSame(1, count($data['failed_subscriptions']));
        $test->assertSame($graph['subscription_ids']['bad'], $data['failed_subscriptions'][0]['subscription_id']);

        // Nothing is removed; the confirmed cancellation only adds an audit entry.
        $countsAfter = $bmcCountRows($graph);
        $test->assertSame($countsBefore['activities'] + 1, $countsAfter['activities'], 'The confirmed cancellation must be logged');
        unset($countsBefore['activities'], $countsAfter['activities']);
        $test->assertSame($countsBefore, $countsAfter, 'Every local row must remain so the delete can be retried');

        // The confirmed cancellation is persisted.
        $test->assertSame('cancelled', (new Subscriptions())->find($graph['subscription_ids']['ok'])->status);
        $test->assertSame('past_due', (new Subscriptions())->find($graph['subscription_ids']['bad'])->status);

        // Retry once Stripe is reachable again.
        $requests  = [];
        $responder = function () use ($bmcStripeBody) {
            return $bmcStripeBody(['id' => 'sub_high02_bad', 'object' => 'subscription', 'status' => 'canceled']);
        };

        $result = (new SupporterDeletionService())->delete($graph['supporter_id']);

        $test->assertFalse(is_wp_error($result), 'The retry must succeed once every agreement is cancelled');

        // A local 'cancelled' is not treated as proof, so the retry re-confirms
        // the agreement this service cancelled a moment ago as well as the one
        // that failed. Cancelling an already cancelled agreement is a success at
        // Stripe, so the retry costs one extra call and nothing else — the
        // alternative, trusting the local status, is exactly what let an
        // unconfirmed cancellation delete a still-billing agreement.
        $cancelled = $result['cancelled_subscription_ids'];
        sort($cancelled);
        $expected = [$graph['subscription_ids']['ok'], $graph['subscription_ids']['bad']];
        sort($expected);
        $test->assertSame($expected, $cancelled, 'Every agreement is confirmed gone before the rows go');
        $test->assertSame(2, count($requests), 'Both agreements are confirmed at Stripe on the retry');

        $urls = implode(' ', wp_list_pluck($requests, 'url'));
        $test->assertContains('sub_high02_bad', $urls);
        $test->assertContains('sub_high02_ok', $urls);

        foreach ($bmcCountRows($graph) as $table => $count) {
            $test->assertSame(0, $count, "Rows survived deletion in {$table}");
        }
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        remove_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');
    }
});

// ── HIGH-03: a Stripe refund event must never restore paid state or access ──

/**
 * A one-time membership purchase: supporter (with WP user), transaction and the
 * pending access row the checkout creates. Pass 'paid' to get the state a
 * settled purchase leaves behind, access included.
 */
$bmcMakeOneTimePurchase = function ($status = 'pending') {
    $suffix = wp_generate_password(12, false, false);

    // Checkout stamps the same reference on the supporter and its transaction:
    // the webhook looks the transaction up by it, the browser confirmation the
    // supporter.
    $orderHash = 'bmc_high03_tx_' . $suffix;

    $userId = wp_insert_user([
        'user_login' => 'bmc_high03_' . $suffix,
        'user_email' => 'bmc-high03-' . $suffix . '@example.com',
        'user_pass'  => wp_generate_password(24),
        'role'       => 'subscriber',
    ]);

    $levelId = (int) (new MembershipLevel())->create([
        'name'          => 'HIGH03 One-time Level',
        'description'   => '',
        'price'         => 2500,
        'payment_type'  => 'one_time',
        'interval_type' => 'month',
        'status'        => 'active',
        'rewards'       => '[]',
        'access_rules'  => '[]',
        'sort_order'    => 999,
        'created_at'    => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
    ]);

    $supporterId = (int) buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
        'supporters_name'  => 'HIGH03 Donor',
        'supporters_email' => 'bmc-high03-' . $suffix . '@example.com',
        'payment_status'   => $status,
        'entry_hash'       => $orderHash,
        'payment_total'    => 2500,
        'coffee_count'     => 1,
        'payment_mode'     => 'test',
        'payment_method'   => 'stripe',
        'status'           => 'new',
        'created_at'       => current_time('mysql'),
        'updated_at'       => current_time('mysql'),
        'wp_user_id'       => $userId,
    ]);

    $transactionId = (int) buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
        'entry_id'         => $supporterId,
        'entry_hash'       => $orderHash,
        'transaction_type' => 'one_time',
        'payment_method'   => 'stripe',
        'payment_total'    => 2500,
        'status'           => $status,
        'currency'         => 'USD',
        'payment_mode'     => 'test',
        'charge_id'        => 'pi_' . $suffix,
        'created_at'       => current_time('mysql'),
        'updated_at'       => current_time('mysql'),
    ]);

    $access   = new MembershipAccess();
    $accessId = (int) $access->createPendingForTransaction($transactionId, $supporterId, $levelId);

    if ($status === 'paid') {
        $access->activateByTransaction($transactionId);
    }

    return [
        'suffix'         => $suffix,
        'user_id'        => (int) $userId,
        'level_id'       => $levelId,
        'supporter_id'   => $supporterId,
        'transaction_id' => $transactionId,
        'access_id'      => $accessId,
        'order_hash'     => $orderHash,
    ];
};

/**
 * Let the payment status service work inside the runner's transaction.
 *
 * Every test already runs in one, and MySQL has no nested transactions: the
 * service opening its own would implicitly commit the runner's and leave the
 * fixtures behind. The row lock and the ordering it protects are unaffected —
 * only ownership of BEGIN/COMMIT moves to the runner.
 *
 * @return callable Restores the default (service-owned) behaviour.
 */
$bmcServiceSharesTestTransaction = function () {
    add_filter('buymecoffee_payment_status_manages_transaction', '__return_false');

    return function () {
        remove_filter('buymecoffee_payment_status_manages_transaction', '__return_false');
    };
};

/** Wrap a Stripe object in the event envelope the API returns. */
$bmcStripeEvent = function ($eventId, $type, array $object) {
    return [
        'id'               => $eventId,
        'object'           => 'event',
        'api_version'      => '2020-08-27',
        'created'          => 1700000000,
        'livemode'         => false,
        'pending_webhooks' => 0,
        'type'             => $type,
        'data'             => ['object' => $object],
    ];
};

/** A realistic Stripe Charge, including the fields a refund actually changes. */
$bmcStripeCharge = function ($orderHash, array $overrides = []) {
    return array_merge([
        'id'              => 'ch_' . wp_generate_password(14, false, false),
        'object'          => 'charge',
        'amount'          => 2500,
        'amount_captured' => 2500,
        'amount_refunded' => 0,
        'captured'        => true,
        'currency'        => 'usd',
        'paid'            => true,
        'refunded'        => false,
        // A refunded charge keeps reporting "succeeded" here — that is the whole
        // reason the status field cannot be trusted as a payment outcome.
        'status'          => 'succeeded',
        'metadata'        => ['ref_id' => $orderHash],
    ], $overrides);
};

/** Read the current row states a payment transition is expected to touch. */
$bmcPaymentState = function (array $purchase) {
    global $wpdb;

    return [
        'transaction' => $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}buymecoffee_transactions WHERE id = %d",
            $purchase['transaction_id']
        )),
        'supporter' => $wpdb->get_var($wpdb->prepare(
            "SELECT payment_status FROM {$wpdb->prefix}buymecoffee_supporters WHERE id = %d",
            $purchase['supporter_id']
        )),
        'access' => $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}buymecoffee_membership_access WHERE id = %d",
            $purchase['access_id']
        )),
        'levels' => buymecoffee_user_get_active_level_ids($purchase['user_id'], true),
    ];
};

/**
 * Record every payment hook, entitlement change and outgoing email, so a replay
 * can be shown to fire nothing a second time. No mail leaves the process.
 */
$bmcWatchPaymentSideEffects = function () {
    $log = new ArrayObject([
        'status'    => [],
        'activated' => [],
        'revoked'   => [],
        'mail'      => [],
    ]);

    $watchers = [
        ['buymecoffee_payment_status_updated', function ($transactionId, $status) use ($log) {
            $log['status'] = array_merge($log['status'], [[(int) $transactionId, (string) $status]]);
        }, 99, 2],
        ['buymecoffee_membership_access_activated', function ($accessId) use ($log) {
            $log['activated'] = array_merge($log['activated'], [(int) $accessId]);
        }, 99, 1],
        ['buymecoffee_membership_access_revoked', function ($accessId, $status) use ($log) {
            $log['revoked'] = array_merge($log['revoked'], [[(int) $accessId, (string) $status]]);
        }, 99, 2],
    ];

    foreach ($watchers as $watcher) {
        add_action($watcher[0], $watcher[1], $watcher[2], $watcher[3]);
    }

    $mailStub = function ($shortCircuit, $atts) use ($log) {
        $log['mail'] = array_merge($log['mail'], [isset($atts['to']) ? $atts['to'] : '']);
        return true;
    };
    add_filter('pre_wp_mail', $mailStub, 10, 2);

    $stop = function () use ($watchers, $mailStub) {
        foreach ($watchers as $watcher) {
            remove_action($watcher[0], $watcher[1], $watcher[2]);
        }
        remove_filter('pre_wp_mail', $mailStub, 10);
    };

    return [$log, $stop];
};

/** Count the activity-log entries written for a transaction. */
$bmcPaymentActivityCount = function ($transactionId, $event) {
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}buymecoffee_activities
         WHERE object_type = 'payment' AND object_id = %d AND event = %s",
        $transactionId,
        $event
    ));
};

$suite->test('a Stripe refund event refunds the payment and revokes one-time access, once', function ($test) use ($bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcWatchPaymentSideEffects, $bmcPaymentActivityCount, $bmcServiceSharesTestTransaction) {
    $restoreTransactions = $bmcServiceSharesTestTransaction();
    $purchase = $bmcMakeOneTimePurchase('paid');
    $test->assertSame([$purchase['level_id']], $bmcPaymentState($purchase)['levels'], 'The fixture must start with live access');

    $eventId = 'evt_high03_refund_' . $purchase['suffix'];
    $event   = $bmcStripeEvent($eventId, 'charge.refunded', $bmcStripeCharge($purchase['order_hash'], [
        'refunded'        => true,
        'amount_refunded' => 2500,
    ]));

    list($log, $stopWatching) = $bmcWatchPaymentSideEffects();

    try {
        $outcome = (new Stripe())->processAuthenticatedEvent($event);

        $test->assertSame('payment_status_updated', $outcome['code']);
        $test->assertSame(200, $outcome['http']);
        $test->assertSame('refunded', $outcome['status'], 'A refunded charge must map to refunded, never to paid');
        $test->assertSame('charge.refunded', $outcome['event_type']);
        $test->assertSame($purchase['transaction_id'], $outcome['transaction_id']);

        $state = $bmcPaymentState($purchase);
        $test->assertSame('refunded', $state['transaction']);
        $test->assertSame('refunded', $state['supporter']);
        $test->assertSame('refunded', $state['access'], 'The access row must leave every access-granting status');
        $test->assertSame([], $state['levels'], 'A refunded supporter must lose entitlement to the level');

        $test->assertSame([[$purchase['transaction_id'], 'refunded']], $log['status']);
        $test->assertSame([[$purchase['access_id'], 'refunded']], $log['revoked'], 'Access must be revoked exactly once');
        $test->assertSame([], $log['activated']);
        $test->assertSame([], $log['mail'], 'A refund must not send a payment confirmation');
        $test->assertSame(1, $bmcPaymentActivityCount($purchase['transaction_id'], 'refund_completed'));

        // Stripe redelivers events; the second copy must be inert.
        $replay = (new Stripe())->processAuthenticatedEvent($event);

        $test->assertSame('duplicate_event', $replay['code']);
        $test->assertSame(200, $replay['http']);
        $test->assertSame($bmcPaymentState($purchase), $state, 'A replayed event must not change any row');
        $test->assertSame(1, count($log['status']), 'A replayed event must not re-fire the payment hook');
        $test->assertSame(1, count($log['revoked']), 'A replayed event must not revoke access again');
        $test->assertSame(1, $bmcPaymentActivityCount($purchase['transaction_id'], 'refund_completed'));
    } finally {
        $stopWatching();
        $restoreTransactions();
    }
});

$suite->test('no later Stripe success event can revive a refunded payment', function ($test) use ($bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcWatchPaymentSideEffects, $bmcServiceSharesTestTransaction) {
    $restoreTransactions = $bmcServiceSharesTestTransaction();
    list($log, $stopWatching) = $bmcWatchPaymentSideEffects();

    try {
        $purchase = $bmcMakeOneTimePurchase('paid');
        $stripe   = new Stripe();

        $stripe->processAuthenticatedEvent($bmcStripeEvent(
            'evt_high03_r_' . $purchase['suffix'],
            'charge.refunded',
            $bmcStripeCharge($purchase['order_hash'], ['refunded' => true, 'amount_refunded' => 2500])
        ));

        $refundedState = $bmcPaymentState($purchase);
        $test->assertSame('refunded', $refundedState['transaction'], 'The refund must land before the ordering is tested');
        $test->assertSame([], $refundedState['levels']);

        // Only what the late events do is under test; the refund's own side
        // effects are asserted by the test above.
        $log['status']    = [];
        $log['activated'] = [];
        $log['revoked']   = [];
        $log['mail']      = [];

        // Out-of-order delivery: the original charge succeeded before the refund, so
        // its event can still arrive — or be redelivered — afterwards.
        $late = [
            'charge.succeeded' => $bmcStripeEvent(
                'evt_high03_late_charge_' . $purchase['suffix'],
                'charge.succeeded',
                $bmcStripeCharge($purchase['order_hash'])
            ),
            'checkout.session.completed' => $bmcStripeEvent(
                'evt_high03_late_session_' . $purchase['suffix'],
                'checkout.session.completed',
                [
                    'id'             => 'cs_' . $purchase['suffix'],
                    'object'         => 'checkout.session',
                    'payment_status' => 'paid',
                    'status'         => 'complete',
                    'amount_total'   => 2500,
                    'currency'       => 'usd',
                    'metadata'       => ['ref_id' => $purchase['order_hash']],
                ]
            ),
        ];

        foreach ($late as $type => $event) {
            $outcome = $stripe->processAuthenticatedEvent($event);

            $test->assertSame('transition_refused', $outcome['code'], "{$type} must be refused after a refund");
            $test->assertSame(200, $outcome['http'], "{$type} must not ask Stripe to retry");
            $test->assertSame('', $outcome['status'], "{$type} must apply no status");
            $test->assertSame($refundedState, $bmcPaymentState($purchase), "{$type} restored state after a refund");
        }

        $test->assertSame([], $log['status'], 'A refused transition must not fire the payment hook');
        $test->assertSame([], $log['activated'], 'A refused transition must not re-grant access');
        $test->assertSame([], $log['mail'], 'A refused transition must not email the donor');
    } finally {
        $stopWatching();
        $restoreTransactions();
    }
});

$suite->test('the event fetched from Stripe decides the outcome, not the type the caller claims', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcServiceSharesTestTransaction) {
    $bmcStripeSettings();

    $stripe   = new Stripe();
    $requests = [];
    $fetched  = null;

    $stub = function ($pre, $args, $url) use (&$requests, &$fetched, $bmcStripeBody) {
        $requests[] = $url;
        return $bmcStripeBody($fetched);
    };
    add_filter('pre_http_request', $stub, 10, 3);
    $restoreTransactions = $bmcServiceSharesTestTransaction();

    try {
        // A refund at Stripe, announced to the site as a successful charge.
        $refunded = $bmcMakeOneTimePurchase('paid');
        $eventId  = 'evt_high03_spoof_' . $refunded['suffix'];
        $fetched  = $bmcStripeEvent($eventId, 'charge.refunded', $bmcStripeCharge($refunded['order_hash'], [
            'refunded'        => true,
            'amount_refunded' => 2500,
        ]));

        $spoofed = json_decode(wp_json_encode($bmcStripeEvent(
            $eventId,
            'charge.succeeded',
            $bmcStripeCharge($refunded['order_hash'])
        )));

        $outcome = $stripe->processIncomingEvent($spoofed);

        $test->assertSame(1, count($requests), 'The event must be re-fetched from Stripe');
        $test->assertContains('events/' . $eventId, $requests[0]);
        $test->assertSame('charge.refunded', $outcome['event_type'], 'The fetched type must win');
        $test->assertSame('refunded', $outcome['status']);

        $state = $bmcPaymentState($refunded);
        $test->assertSame('refunded', $state['transaction'], 'A spoofed type must not keep a refunded payment paid');
        $test->assertSame([], $state['levels']);

        // And the same seam the other way round: a genuine success announced as
        // a refund still settles, because the fetched copy says so.
        $pending = $bmcMakeOneTimePurchase();
        $eventId = 'evt_high03_spoof2_' . $pending['suffix'];
        $fetched = $bmcStripeEvent($eventId, 'charge.succeeded', $bmcStripeCharge($pending['order_hash']));

        $spoofed = json_decode(wp_json_encode($bmcStripeEvent(
            $eventId,
            'charge.refunded',
            $bmcStripeCharge($pending['order_hash'], ['refunded' => true, 'amount_refunded' => 2500])
        )));

        $outcome = $stripe->processIncomingEvent($spoofed);

        $test->assertSame('charge.succeeded', $outcome['event_type']);
        $test->assertSame('paid', $outcome['status']);
        $test->assertSame('paid', $bmcPaymentState($pending)['transaction']);

        // A claimed type this plugin never handles is dropped before any fetch.
        $requests = [];
        $ignored  = $stripe->processIncomingEvent((object) ['id' => 'evt_high03_ignored', 'type' => 'charge.captured']);

        $test->assertSame('unsupported_event', $ignored['code']);
        $test->assertSame(200, $ignored['http']);
        $test->assertSame(0, count($requests), 'An unhandled claimed type must not cost a Stripe round trip');
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        $restoreTransactions();
    }
});

$suite->test('a pending purchase settles once on charge.succeeded and never twice', function ($test) use ($bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcWatchPaymentSideEffects, $bmcPaymentActivityCount, $bmcServiceSharesTestTransaction) {
    $restoreTransactions = $bmcServiceSharesTestTransaction();
    $purchase = $bmcMakeOneTimePurchase();
    $stripe   = new Stripe();

    $test->assertSame([], $bmcPaymentState($purchase)['levels'], 'A pending purchase grants nothing');

    $event = $bmcStripeEvent(
        'evt_high03_paid_' . $purchase['suffix'],
        'charge.succeeded',
        $bmcStripeCharge($purchase['order_hash'])
    );

    list($log, $stopWatching) = $bmcWatchPaymentSideEffects();

    try {
        $outcome = $stripe->processAuthenticatedEvent($event);

        $test->assertSame('payment_status_updated', $outcome['code']);
        $test->assertSame('paid', $outcome['status']);
        $test->assertTrue($outcome['changed']);

        $state = $bmcPaymentState($purchase);
        $test->assertSame('paid', $state['transaction']);
        $test->assertSame('paid', $state['supporter']);
        $test->assertSame('active', $state['access']);
        $test->assertSame([$purchase['level_id']], $state['levels']);

        $test->assertSame([[$purchase['transaction_id'], 'paid']], $log['status']);
        $test->assertSame([$purchase['access_id']], $log['activated'], 'Access must be activated exactly once');
        $test->assertSame(2, count($log['mail']), 'The donor and admin are each notified once');
        $test->assertSame(1, $bmcPaymentActivityCount($purchase['transaction_id'], 'payment_completed'));

        // The same event again, and then a different event carrying the same
        // status: neither is a new transition.
        $replay = $stripe->processAuthenticatedEvent($event);
        $test->assertSame('duplicate_event', $replay['code']);

        $resent = $stripe->processAuthenticatedEvent($bmcStripeEvent(
            'evt_high03_paid_again_' . $purchase['suffix'],
            'charge.succeeded',
            $bmcStripeCharge($purchase['order_hash'])
        ));
        $test->assertSame('status_unchanged', $resent['code']);
        $test->assertSame(200, $resent['http']);
        $test->assertFalse($resent['changed']);

        $test->assertSame($state, $bmcPaymentState($purchase));
        $test->assertSame(1, count($log['status']), 'A repeated status must not re-fire the payment hook');
        $test->assertSame(1, count($log['activated']), 'A repeated status must not re-activate access');
        $test->assertSame(2, count($log['mail']), 'A repeated status must not send the emails again');
        $test->assertSame(1, $bmcPaymentActivityCount($purchase['transaction_id'], 'payment_completed'));
    } finally {
        $stopWatching();
        $restoreTransactions();
    }
});

$suite->test('unreadable, ambiguous and unreferenced Stripe events change nothing', function ($test) use ($bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcWatchPaymentSideEffects, $bmcServiceSharesTestTransaction) {
    $restoreTransactions = $bmcServiceSharesTestTransaction();
    $purchase = $bmcMakeOneTimePurchase('paid');
    $stripe   = new Stripe();
    $before   = $bmcPaymentState($purchase);
    $hash     = $purchase['order_hash'];

    $rejected = [
        // A refund event that reports no refund at all.
        'unconfirmed refund' => [
            'event' => $bmcStripeEvent('evt_high03_bad1_' . $purchase['suffix'], 'charge.refunded', $bmcStripeCharge($hash)),
            'code'  => 'unmapped_event',
            'http'  => 400,
        ],
        // A success event for a charge that carries a refund: ambiguous.
        'already refunded charge' => [
            'event' => $bmcStripeEvent('evt_high03_bad2_' . $purchase['suffix'], 'charge.succeeded', $bmcStripeCharge($hash, [
                'refunded'        => true,
                'amount_refunded' => 2500,
            ])),
            'code' => 'unmapped_event',
            'http' => 400,
        ],
        // The wrong kind of object for the event type.
        'object mismatch' => [
            'event' => $bmcStripeEvent('evt_high03_bad3_' . $purchase['suffix'], 'charge.succeeded', [
                'id'             => 'cs_' . $purchase['suffix'],
                'object'         => 'checkout.session',
                'status'         => 'succeeded',
                'payment_status' => 'paid',
                'metadata'       => ['ref_id' => $hash],
            ]),
            'code' => 'unmapped_event',
            'http' => 400,
        ],
        // A completed session that was never paid.
        'unpaid session' => [
            'event' => $bmcStripeEvent('evt_high03_bad4_' . $purchase['suffix'], 'checkout.session.completed', [
                'id'             => 'cs_' . $purchase['suffix'],
                'object'         => 'checkout.session',
                'status'         => 'complete',
                'payment_status' => 'unpaid',
                'metadata'       => ['ref_id' => $hash],
            ]),
            'code' => 'unmapped_event',
            'http' => 400,
        ],
        // No data object at all.
        'malformed object' => [
            'event' => ['id' => 'evt_high03_bad5_' . $purchase['suffix'], 'object' => 'event', 'type' => 'charge.succeeded'],
            'code'  => 'unmapped_event',
            'http'  => 400,
        ],
        // Not an event.
        'no id or type' => [
            'event' => ['object' => 'event', 'data' => ['object' => $bmcStripeCharge($hash)]],
            'code'  => 'malformed_event',
            'http'  => 400,
        ],
        // A type this plugin does not handle.
        'unknown type' => [
            'event' => $bmcStripeEvent('evt_high03_bad7_' . $purchase['suffix'], 'charge.captured', $bmcStripeCharge($hash)),
            'code'  => 'unsupported_event',
            'http'  => 200,
        ],
        // Nothing ties the charge to a local order.
        'missing metadata' => [
            'event' => $bmcStripeEvent('evt_high03_bad8_' . $purchase['suffix'], 'charge.succeeded', $bmcStripeCharge($hash, ['metadata' => []])),
            'code'  => 'missing_reference',
            'http'  => 200,
        ],
        // A reference that matches no local transaction.
        'unknown order' => [
            'event' => $bmcStripeEvent('evt_high03_bad9_' . $purchase['suffix'], 'charge.succeeded', $bmcStripeCharge('bmc_high03_nothing')),
            'code'  => 'transaction_not_found',
            'http'  => 200,
        ],
    ];

    list($log, $stopWatching) = $bmcWatchPaymentSideEffects();

    try {
        foreach ($rejected as $label => $case) {
            $outcome = $stripe->processAuthenticatedEvent($case['event']);

            $test->assertSame($case['code'], $outcome['code'], "Wrong outcome for {$label}");
            $test->assertSame($case['http'], $outcome['http'], "Wrong status code for {$label}");
            $test->assertSame('', $outcome['status'], "{$label} must resolve no status");
            $test->assertSame($before, $bmcPaymentState($purchase), "{$label} mutated local state");
        }

        $test->assertSame([], $log['status'], 'A rejected event must not fire the payment hook');
        $test->assertSame([], $log['activated']);
        $test->assertSame([], $log['revoked']);

        // A rejected event is never consumed: the same id still works once the
        // event can actually be read.
        $reused = $stripe->processAuthenticatedEvent($bmcStripeEvent(
            'evt_high03_bad1_' . $purchase['suffix'],
            'charge.refunded',
            $bmcStripeCharge($hash, ['refunded' => true, 'amount_refunded' => 2500])
        ));

        $test->assertSame('payment_status_updated', $reused['code'], 'An unreadable event must not consume its id');
        $test->assertSame('refunded', $bmcPaymentState($purchase)['transaction']);
    } finally {
        $stopWatching();
        $restoreTransactions();
    }
});

$suite->test('a browser payment confirmation cannot restore a refunded payment or its access', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcWatchPaymentSideEffects, $bmcPaymentActivityCount, $bmcServiceSharesTestTransaction) {
    $bmcStripeSettings();

    $restoreTransactions = $bmcServiceSharesTestTransaction();
    $purchase = $bmcMakeOneTimePurchase('paid');
    $intentId = 'pi_high03_confirm_' . $purchase['suffix'];

    // The refund happens first — an admin refund or a Stripe webhook, it makes
    // no difference to what the browser may do afterwards.
    (new Stripe())->processAuthenticatedEvent($bmcStripeEvent(
        'evt_high03_confirm_refund_' . $purchase['suffix'],
        'charge.refunded',
        $bmcStripeCharge($purchase['order_hash'], ['refunded' => true, 'amount_refunded' => 2500])
    ));

    $refundedState = $bmcPaymentState($purchase);
    $test->assertSame('refunded', $refundedState['transaction'], 'The refund must land before the confirmation is replayed');
    $test->assertSame([], $refundedState['levels']);

    // A refunded PaymentIntent keeps reporting "succeeded" for good, so a
    // reloaded confirmation page replays a perfectly authentic success.
    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests, $bmcStripeBody, $intentId, $purchase) {
        $requests[] = $url;

        return $bmcStripeBody([
            'id'              => $intentId,
            'object'          => 'payment_intent',
            'status'          => 'succeeded',
            'amount'          => 2500,
            'amount_received' => 2500,
            'currency'        => 'usd',
            'livemode'        => false,
            'metadata'        => ['ref_id' => $purchase['order_hash']],
            'charges'         => ['data' => [[
                'payment_method_details' => ['card' => ['last4' => '4242', 'brand' => 'visa']],
            ]]],
        ]);
    };
    add_filter('pre_http_request', $stub, 10, 3);

    list($log, $stopWatching) = $bmcWatchPaymentSideEffects();

    try {
        $result = (new PaymentHelper())->updatePaymentData($intentId);

        $test->assertFalse(is_wp_error($result), 'The confirmation itself is valid; only its status claim is refused');
        $test->assertSame(1, count($requests), 'The intent must be fetched from Stripe');
        $test->assertSame('succeeded', $result['stripe_status'], 'What Stripe reports is passed through unchanged');
        $test->assertSame('refunded', $result['payment_status'], 'The stored status is what is reported back, not the claim');
        $test->assertSame($purchase['transaction_id'], $result['transaction_id']);
        $test->assertFalse($result['access_active'], 'A refunded payment must not be reported as granting access');
        $test->assertSame('refunded', $result['membership_access_status']);

        $test->assertSame($refundedState, $bmcPaymentState($purchase), 'A replayed confirmation must not change any row');
        $test->assertSame([], $log['status'], 'A refused confirmation must not fire the payment hook');
        $test->assertSame([], $log['activated'], 'A refused confirmation must not re-activate access');
        $test->assertSame([], $log['mail'], 'A refused confirmation must not email the donor');
        $test->assertSame(0, $bmcPaymentActivityCount($purchase['transaction_id'], 'payment_completed'));

        // The descriptive Stripe fields are still recorded: they say which card
        // paid, not whether the payment stands.
        $transaction = buyMeCoffeeQuery()
            ->table('buymecoffee_transactions')
            ->where('id', $purchase['transaction_id'])
            ->first();

        $test->assertSame($intentId, $transaction->charge_id);
        $test->assertSame('4242', $transaction->card_last_4);
        $test->assertSame('visa', $transaction->card_brand);
        $test->assertSame('refunded', $transaction->status, 'Storing card details must not touch the payment status');
    } finally {
        $stopWatching();
        remove_filter('pre_http_request', $stub, 10);
        $restoreTransactions();
    }
});

// ── HIGH-04: activation must never migrate data inside a visitor request ──

/**
 * Snapshot and restore every option, cron event and filter the migration tests
 * touch. The runner already rolls the fixture transaction back, so this only
 * has to undo state that outlives a rollback (object cache, cron array).
 */
$bmcMigrationRestore = function () {
    $options = [
        'buymecoffee_db_version'                          => get_option('buymecoffee_db_version'),
        Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION      => get_option(Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION),
        Activator::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION => get_option(Activator::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION),
        Activator::MIGRATION_STATE_OPTION                 => get_option(Activator::MIGRATION_STATE_OPTION),
        Activator::MIGRATION_LOCK_OPTION                  => get_option(Activator::MIGRATION_LOCK_OPTION),
    ];

    return function () use ($options) {
        // Never leave a background migration event behind for the dev site.
        wp_clear_scheduled_hook(Activator::MIGRATION_HOOK);

        foreach ($options as $name => $value) {
            if ($value === false) {
                delete_option($name);
                continue;
            }

            update_option($name, $value, $name === Activator::MIGRATION_STATE_OPTION ? false : true);
        }
    };
};

/**
 * Put the site into "migration pending" shape and start the durable state at a
 * data phase. PHASE_SCHEMA is deliberately never used: dbDelta() issues DDL,
 * which would implicitly commit the runner's fixture transaction.
 */
$bmcSeedMigrationState = function ($phase, $cursor = 0) {
    $now = current_time('mysql');

    update_option('buymecoffee_db_version', '1.0', true);
    update_option(Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION, '', true);
    // The schema already exists in the test database, so the seed marker stands
    // in for the schema phase that these tests intentionally skip.
    update_option(Activator::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION, 'yes', true);

    $state = [
        'target_version' => BUYMECOFFEE_DB_VERSION,
        'phase'          => $phase,
        'cursor'         => (int) $cursor,
        'processed'      => 0,
        'started_at'     => $now,
        'updated_at'     => $now,
        'completed_at'   => null,
        'last_error'     => null,
        'retries'        => 0,
    ];

    update_option(Activator::MIGRATION_STATE_OPTION, $state, false);
    delete_option(Activator::MIGRATION_LOCK_OPTION);
    wp_clear_scheduled_hook(Activator::MIGRATION_HOOK);

    return $state;
};

/** Read the lock row straight from the options table, never from the cache. */
$bmcReadLockRow = function () {
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        Activator::MIGRATION_LOCK_OPTION
    ));
};

/** Build a supporter + subscription (+ optional transactions) migration source. */
$bmcMakeMigrationSource = function (array $spec) {
    $suffix = wp_generate_password(12, false, false);
    $now    = current_time('mysql');

    $supporterId = (int) buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
        'supporters_name'  => 'HIGH04 Source',
        'supporters_email' => 'bmc-high04-' . $suffix . '@example.com',
        'payment_status'   => 'paid',
        'entry_hash'       => 'bmc_high04_' . $suffix,
        'payment_total'    => 2500,
        'coffee_count'     => 1,
        'payment_mode'     => 'test',
        'payment_method'   => 'stripe',
        'status'           => 'new',
        'created_at'       => $now,
        'updated_at'       => $now,
        'wp_user_id'       => isset($spec['wp_user_id']) ? $spec['wp_user_id'] : null,
    ]);

    $subscriptionId = (int) buyMeCoffeeQuery()->table('buymecoffee_subscriptions')->insert([
        'supporter_id'           => $supporterId,
        'stripe_subscription_id' => array_key_exists('stripe_id', $spec) ? $spec['stripe_id'] : 'sub_high04_' . $suffix,
        'stripe_customer_id'     => 'cus_high04_' . $suffix,
        'interval_type'          => isset($spec['interval_type']) ? $spec['interval_type'] : 'month',
        'amount'                 => 2500,
        'currency'               => 'usd',
        'status'                 => isset($spec['status']) ? $spec['status'] : 'active',
        'payment_mode'           => isset($spec['payment_mode']) ? $spec['payment_mode'] : 'test',
        'current_period_end'     => isset($spec['current_period_end']) ? $spec['current_period_end'] : null,
        'created_at'             => isset($spec['created_at']) ? $spec['created_at'] : $now,
        'updated_at'             => $now,
        'level_id'               => $spec['level_id'],
    ]);

    $transactionIds = [];
    for ($i = 0; $i < (isset($spec['transactions']) ? (int) $spec['transactions'] : 0); $i++) {
        $transactionIds[] = (int) buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
            'entry_id'         => $supporterId,
            'entry_hash'       => 'bmc_high04_tx_' . $suffix . '_' . $i,
            'subscription_id'  => $subscriptionId,
            'transaction_type' => 'recurring',
            'payment_method'   => 'stripe',
            'payment_total'    => 2500,
            'status'           => 'paid',
            'currency'         => 'USD',
            'payment_mode'     => 'test',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    return [
        'supporter_id'    => $supporterId,
        'subscription_id' => $subscriptionId,
        'transaction_ids' => $transactionIds,
    ];
};

/** Every access row a migration source produced, oldest first. */
$bmcAccessRowsFor = function ($supporterId) {
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}buymecoffee_membership_access WHERE supporter_id = %d ORDER BY id ASC",
        (int) $supporterId
    ));
};

/**
 * Exclude everything already in the development database from the phases under
 * test, so each phase's batch count is decided only by this test's fixtures.
 * All of it is rolled back with the fixture transaction.
 */
$bmcIsolateMigrationSources = function () {
    global $wpdb;

    $wpdb->query("UPDATE {$wpdb->prefix}buymecoffee_subscriptions SET stripe_subscription_id = NULL WHERE stripe_subscription_id = ''");
    $wpdb->query("UPDATE {$wpdb->prefix}buymecoffee_subscriptions SET level_id = NULL WHERE level_id IS NOT NULL AND level_id > 0");
    $wpdb->query("UPDATE {$wpdb->prefix}buymecoffee_membership_access SET subscription_id = NULL WHERE subscription_id IS NOT NULL AND access_type IN ('one_time', 'manual')");
    $wpdb->query("DELETE FROM {$wpdb->prefix}buymecoffee_supporters_meta WHERE meta_key = 'active_level_ids'");
};

$suite->test('maybeRunMigrations only schedules the worker and never backfills in-request', function ($test) use ($bmcMigrationRestore, $bmcSeedMigrationState, $bmcMakeMigrationSource, $bmcAccessRowsFor) {
    global $wpdb;

    $restore = $bmcMigrationRestore();

    try {
        $cursor = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}buymecoffee_subscriptions");
        $seeded = $bmcSeedMigrationState(Activator::PHASE_BACKFILL_ACCESS, $cursor);

        $source = $bmcMakeMigrationSource(['level_id' => 987001, 'transactions' => 1]);

        $test->assertFalse(wp_next_scheduled(Activator::MIGRATION_HOOK), 'The test must start with no worker event');

        $activator = new Activator();
        $activator->maybeRunMigrations();

        $scheduled = wp_next_scheduled(Activator::MIGRATION_HOOK);
        $test->assertTrue(is_int($scheduled) && $scheduled > 0, 'Pending work must schedule the background worker');

        // Nothing may be migrated from the request that merely noticed the work.
        $test->assertSame([], $bmcAccessRowsFor($source['supporter_id']), 'maybeRunMigrations must not backfill synchronously');
        $test->assertSame($seeded, get_option(Activator::MIGRATION_STATE_OPTION), 'maybeRunMigrations must not advance durable progress');
        $test->assertSame('1.0', get_option('buymecoffee_db_version'), 'The DB version must not move before the worker finishes');
        $test->assertSame('', get_option(Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION), 'The verified marker must not move either');

        // A second visitor request must not pile up a second event.
        $activator->maybeRunMigrations();
        $events = 0;
        foreach (_get_cron_array() as $hooks) {
            $events += isset($hooks[Activator::MIGRATION_HOOK]) ? count($hooks[Activator::MIGRATION_HOOK]) : 0;
        }
        $test->assertSame(1, $events, 'Repeated requests must reuse the single pending worker event');
        $test->assertSame($scheduled, wp_next_scheduled(Activator::MIGRATION_HOOK), 'The pending event must not be rescheduled');
    } finally {
        $restore();
    }
});

$suite->test('one filtered batch migrates a single cursor range and leaves both version markers old', function ($test) use ($bmcMigrationRestore, $bmcSeedMigrationState, $bmcMakeMigrationSource, $bmcAccessRowsFor) {
    global $wpdb;

    $restore  = $bmcMigrationRestore();
    $batchOne = function () {
        return 1;
    };

    add_filter('buymecoffee_migration_batch_size', $batchOne);

    try {
        $cursor = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}buymecoffee_subscriptions");
        $bmcSeedMigrationState(Activator::PHASE_BACKFILL_ACCESS, $cursor);

        $first  = $bmcMakeMigrationSource(['level_id' => 987002, 'transactions' => 1]);
        $second = $bmcMakeMigrationSource(['level_id' => 987002, 'transactions' => 1]);

        $state = (new Activator())->runMigrationBatch();

        $test->assertFalse(is_wp_error($state), 'A bounded batch must not fail');
        $test->assertSame(Activator::PHASE_BACKFILL_ACCESS, $state['phase'], 'One batch must not finish the backfill phase');
        $test->assertSame((int) $first['subscription_id'], (int) $state['cursor'], 'The cursor must stop at the first range');
        $test->assertSame(1, (int) $state['processed'], 'Exactly one source may be processed');

        $test->assertSame(1, count($bmcAccessRowsFor($first['supporter_id'])), 'The first source must be migrated');
        $test->assertSame([], $bmcAccessRowsFor($second['supporter_id']), 'The second source must wait for the next batch');

        $test->assertSame('1.0', get_option('buymecoffee_db_version'), 'A partial migration must leave the DB version old');
        $test->assertSame('', get_option(Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION), 'A partial migration must leave the schema marker old');
        $test->assertSame($state, get_option(Activator::MIGRATION_STATE_OPTION), 'Progress must be durable between batches');
    } finally {
        remove_filter('buymecoffee_migration_batch_size', $batchOne);
        $restore();
    }
});

$suite->test('repeated batches resume through every bounded phase and only then advance both versions', function ($test) use ($bmcMigrationRestore, $bmcSeedMigrationState, $bmcMakeMigrationSource, $bmcAccessRowsFor, $bmcIsolateMigrationSources) {
    global $wpdb;

    $restore  = $bmcMigrationRestore();
    $batchTwo = function () {
        return 2;
    };

    add_filter('buymecoffee_migration_batch_size', $batchTwo);

    try {
        $bmcIsolateMigrationSources();
        $bmcSeedMigrationState(Activator::PHASE_NORMALIZE_SUBSCRIPTIONS);

        $levelId = 987003;
        $expires = gmdate('Y-m-d H:i:s', time() + (30 * DAY_IN_SECONDS));
        $created = gmdate('Y-m-d H:i:s', time() - (10 * DAY_IN_SECONDS));

        // A recurring source with a legacy empty remote ID, a zero wp_user_id
        // and two transactions: the earliest one has to win.
        $recurring = $bmcMakeMigrationSource([
            'level_id'           => $levelId,
            'stripe_id'          => '',
            'wp_user_id'         => 0,
            'interval_type'      => 'month',
            'current_period_end' => $expires,
            'created_at'         => $created,
            'transactions'       => 2,
        ]);
        $oneTime = $bmcMakeMigrationSource(['level_id' => $levelId, 'interval_type' => 'one_time', 'transactions' => 1]);
        $manual  = $bmcMakeMigrationSource(['level_id' => $levelId, 'payment_mode' => 'manual', 'transactions' => 0]);
        // level_id 0 is not a membership source and must never produce a row.
        $unlinked = $bmcMakeMigrationSource(['level_id' => 0, 'transactions' => 0]);

        // A legacy access row that still links a one-time grant to a subscription.
        $legacyAccessId = (int) buyMeCoffeeQuery()->table('buymecoffee_membership_access')->insert([
            'supporter_id'    => $unlinked['supporter_id'],
            'level_id'        => $levelId,
            'subscription_id' => $unlinked['subscription_id'],
            'access_type'     => 'one_time',
            'status'          => 'active',
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);

        // Two stale access caches, so the purge phase needs two bounded batches.
        buymecoffee_update_supporter_meta($recurring['supporter_id'], 'active_level_ids', ['level_ids' => [$levelId]]);
        buymecoffee_update_supporter_meta($oneTime['supporter_id'], 'active_level_ids', ['level_ids' => [$levelId]]);

        $activator = new Activator();
        $phases    = [];

        for ($i = 0; $i < 12; $i++) {
            $state = $activator->runMigrationBatch();
            $test->assertFalse(is_wp_error($state), 'Batch ' . ($i + 1) . ' failed: ' . (is_wp_error($state) ? $state->get_error_message() : ''));
            $phases[] = $state['phase'];

            if ($state['phase'] === Activator::PHASE_COMPLETE) {
                break;
            }

            $test->assertSame('1.0', get_option('buymecoffee_db_version'), 'The DB version moved before the pipeline finished');
        }

        $test->assertSame([
            Activator::PHASE_NORMALIZE_ACCESS,
            Activator::PHASE_BACKFILL_ACCESS,
            Activator::PHASE_BACKFILL_ACCESS,
            Activator::PHASE_PURGE_ACCESS_CACHE,
            Activator::PHASE_PURGE_ACCESS_CACHE,
            Activator::PHASE_VERIFY,
            Activator::PHASE_COMPLETE,
        ], $phases, 'Each invocation must advance exactly one bounded phase or cursor range');

        // Legacy normalization.
        $test->assertSame(null, $wpdb->get_var($wpdb->prepare(
            "SELECT stripe_subscription_id FROM {$wpdb->prefix}buymecoffee_subscriptions WHERE id = %d",
            $recurring['subscription_id']
        )), 'An empty remote subscription ID must become NULL');
        $test->assertSame(null, $wpdb->get_var($wpdb->prepare(
            "SELECT subscription_id FROM {$wpdb->prefix}buymecoffee_membership_access WHERE id = %d",
            $legacyAccessId
        )), 'A one-time access row must not keep a subscription link');
        $test->assertSame('0', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}buymecoffee_supporters_meta WHERE meta_key = 'active_level_ids'"), 'Every stale access cache must be purged');

        // Exactly one correct access row per migrated source.
        $recurringRows = $bmcAccessRowsFor($recurring['supporter_id']);
        $test->assertSame(1, count($recurringRows), 'The recurring source must produce exactly one access row');
        $test->assertSame('subscription', $recurringRows[0]->access_type);
        $test->assertSame((int) $recurring['subscription_id'], (int) $recurringRows[0]->subscription_id);
        $test->assertSame((int) $recurring['transaction_ids'][0], (int) $recurringRows[0]->transaction_id, 'The earliest transaction must win');
        $test->assertSame('active', $recurringRows[0]->status);
        $test->assertSame($expires, $recurringRows[0]->expires_at);
        $test->assertSame($created, $recurringRows[0]->starts_at);
        $test->assertSame(null, $recurringRows[0]->wp_user_id, 'A zero wp_user_id must be stored as NULL, as production does');

        $oneTimeRows = $bmcAccessRowsFor($oneTime['supporter_id']);
        $test->assertSame(1, count($oneTimeRows), 'The one-time source must produce exactly one access row');
        $test->assertSame('one_time', $oneTimeRows[0]->access_type);
        $test->assertSame(null, $oneTimeRows[0]->subscription_id, 'A one-time grant must not be linked to a subscription');
        $test->assertSame((int) $oneTime['transaction_ids'][0], (int) $oneTimeRows[0]->transaction_id);
        $test->assertSame(null, $oneTimeRows[0]->expires_at, 'Only subscriptions carry a period end');

        $manualRows = $bmcAccessRowsFor($manual['supporter_id']);
        $test->assertSame(1, count($manualRows), 'The manual source must produce exactly one access row');
        $test->assertSame('manual', $manualRows[0]->access_type);
        $test->assertSame(null, $manualRows[0]->subscription_id);
        $test->assertSame(null, $manualRows[0]->transaction_id, 'A manual grant has no transaction');

        $test->assertSame(1, count($bmcAccessRowsFor($unlinked['supporter_id'])), 'A subscription without a level must not be migrated');

        // Both markers advance only after the whole pipeline verified.
        $test->assertSame(BUYMECOFFEE_DB_VERSION, get_option('buymecoffee_db_version'));
        $test->assertSame(BUYMECOFFEE_DB_VERSION, get_option(Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION));
        $test->assertSame(Activator::PHASE_COMPLETE, get_option(Activator::MIGRATION_STATE_OPTION)['phase']);

        // A settled site does no further work.
        $settled = $activator->runMigrationBatch();
        $test->assertSame(Activator::PHASE_COMPLETE, $settled['phase'], 'A settled migration must stay complete');
        $test->assertSame(1, count($bmcAccessRowsFor($recurring['supporter_id'])), 'A settled run must not duplicate access rows');
    } finally {
        remove_filter('buymecoffee_migration_batch_size', $batchTwo);
        $restore();
    }
});

$suite->test('a live migration lock keeps other workers inert and an expired lock is recovered atomically', function ($test) use ($bmcMigrationRestore, $bmcSeedMigrationState, $bmcMakeMigrationSource, $bmcAccessRowsFor, $bmcReadLockRow) {
    global $wpdb;

    $restore = $bmcMigrationRestore();
    $batch   = function () {
        return 5;
    };

    add_filter('buymecoffee_migration_batch_size', $batch);

    try {
        $cursor = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}buymecoffee_subscriptions");
        $seeded = $bmcSeedMigrationState(Activator::PHASE_BACKFILL_ACCESS, $cursor);
        $source = $bmcMakeMigrationSource(['level_id' => 987004, 'transactions' => 1]);

        $liveLock = wp_json_encode(['token' => 'bmc-live-owner', 'expires_at' => time() + 300]);
        update_option(Activator::MIGRATION_LOCK_OPTION, $liveLock, false);

        $activator = new Activator();

        $test->assertFalse($activator->runMigrationBatch(), 'A worker must stay inert while another owns the lock');
        $test->assertSame([], $bmcAccessRowsFor($source['supporter_id']), 'An inert worker must not touch data');
        $test->assertSame($seeded, get_option(Activator::MIGRATION_STATE_OPTION), 'An inert worker must not touch progress');
        $test->assertSame($liveLock, $bmcReadLockRow(), 'A live lock must still be owned by its holder');

        // The same holds for the raw primitive: a live lock is never handed out.
        $test->assertFalse($test->invokePrivate($activator, 'acquireMigrationLock'), 'A live lock must never be re-acquired');
        $test->assertSame($liveLock, $bmcReadLockRow(), 'A refused acquisition must not rewrite the lock');

        // An abandoned worker's lock expires and is taken over exactly once.
        $staleLock = wp_json_encode(['token' => 'bmc-crashed-owner', 'expires_at' => time() - 1]);
        update_option(Activator::MIGRATION_LOCK_OPTION, $staleLock, false);

        $recovered = $test->invokePrivate($activator, 'acquireMigrationLock');
        $test->assertTrue(is_string($recovered) && $recovered !== $staleLock, 'An expired lock must be taken over');
        $test->assertSame($recovered, $bmcReadLockRow(), 'The taking-over worker must own the stored value');

        // A second worker racing on the same stale value loses the CAS.
        $test->assertFalse($test->invokePrivate($activator, 'acquireMigrationLock'), 'Only one worker may take over an expired lock');
        $test->assertSame($recovered, $bmcReadLockRow(), 'The loser must not overwrite the winner');

        // Only the exact owning value may release the lock.
        $test->invokePrivate($activator, 'releaseMigrationLock', [$staleLock]);
        $test->assertSame($recovered, $bmcReadLockRow(), 'A stale owner must not release the current lock');

        $test->invokePrivate($activator, 'releaseMigrationLock', [$recovered]);
        $test->assertSame(null, $bmcReadLockRow(), 'The owner must release its own lock');

        // With the stale lock gone the worker runs normally again.
        update_option(Activator::MIGRATION_LOCK_OPTION, $staleLock, false);
        $state = $activator->runMigrationBatch();
        $test->assertFalse(is_wp_error($state), 'A recovered worker must be able to migrate');
        $test->assertSame(1, count($bmcAccessRowsFor($source['supporter_id'])), 'The recovered worker must migrate the pending source');
        $test->assertSame(null, $bmcReadLockRow(), 'A finished batch must release its lock');
    } finally {
        remove_filter('buymecoffee_migration_batch_size', $batch);
        $restore();
    }
});

$suite->test('replaying a backfill range after a crash never duplicates access rows', function ($test) use ($bmcMigrationRestore, $bmcSeedMigrationState, $bmcMakeMigrationSource, $bmcAccessRowsFor) {
    global $wpdb;

    $restore = $bmcMigrationRestore();
    $batch   = function () {
        return 10;
    };

    add_filter('buymecoffee_migration_batch_size', $batch);

    try {
        $cursor = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}buymecoffee_subscriptions");
        $bmcSeedMigrationState(Activator::PHASE_BACKFILL_ACCESS, $cursor);

        $levelId = 987005;
        $sources = [
            'subscription' => $bmcMakeMigrationSource(['level_id' => $levelId, 'transactions' => 1]),
            // The interesting replay cases: no transaction, so the unique keys
            // on transaction_id/subscription_id cannot protect the row.
            'manual'       => $bmcMakeMigrationSource(['level_id' => $levelId, 'payment_mode' => 'manual', 'transactions' => 0]),
            'one_time'     => $bmcMakeMigrationSource(['level_id' => $levelId, 'interval_type' => 'one_time', 'transactions' => 0]),
        ];

        $activator = new Activator();
        $state     = $activator->runMigrationBatch();
        $test->assertFalse(is_wp_error($state), 'The first pass must succeed');

        foreach ($sources as $label => $source) {
            $test->assertSame(1, count($bmcAccessRowsFor($source['supporter_id'])), "The {$label} source must migrate once");
        }

        $firstIds = [];
        foreach ($sources as $label => $source) {
            $firstIds[$label] = (int) $bmcAccessRowsFor($source['supporter_id'])[0]->id;
        }

        // Simulate a worker that inserted the range and then died before it
        // could persist the advanced cursor: the same range is replayed twice.
        for ($replay = 0; $replay < 2; $replay++) {
            $rewound            = get_option(Activator::MIGRATION_STATE_OPTION);
            $rewound['phase']   = Activator::PHASE_BACKFILL_ACCESS;
            $rewound['cursor']  = $cursor;
            update_option(Activator::MIGRATION_STATE_OPTION, $rewound, false);

            $replayed = $activator->runMigrationBatch();
            $test->assertFalse(
                is_wp_error($replayed),
                'Replay ' . ($replay + 1) . ' failed: ' . (is_wp_error($replayed) ? $replayed->get_error_message() : '')
            );

            foreach ($sources as $label => $source) {
                $rows = $bmcAccessRowsFor($source['supporter_id']);
                $test->assertSame(1, count($rows), "Replay duplicated the {$label} access row");
                $test->assertSame($firstIds[$label], (int) $rows[0]->id, "Replay replaced the {$label} access row");
            }
        }

        $test->assertSame(null, $bmcAccessRowsFor($sources['manual']['supporter_id'])[0]->transaction_id, 'The manual row must stay transaction-free');
        $test->assertSame(null, $bmcAccessRowsFor($sources['one_time']['supporter_id'])[0]->transaction_id, 'The one-time row must stay transaction-free');
    } finally {
        remove_filter('buymecoffee_migration_batch_size', $batch);
        $restore();
    }
});

$suite->test('an access row that already owns a source transaction is reconciled to that source', function ($test) use ($bmcMigrationRestore, $bmcSeedMigrationState, $bmcMakeMigrationSource, $bmcAccessRowsFor) {
    global $wpdb;

    $restore = $bmcMigrationRestore();
    $batch   = function () {
        return 10;
    };
    $accessTable = $wpdb->prefix . 'buymecoffee_membership_access';

    add_filter('buymecoffee_migration_batch_size', $batch);

    try {
        $cursor = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}buymecoffee_subscriptions");
        $bmcSeedMigrationState(Activator::PHASE_BACKFILL_ACCESS, $cursor);

        $levelId = 987007;
        $expires = gmdate('Y-m-d H:i:s', time() + (30 * DAY_IN_SECONDS));
        $created = gmdate('Y-m-d H:i:s', time() - (10 * DAY_IN_SECONDS));

        $source = $bmcMakeMigrationSource([
            'level_id'           => $levelId,
            'wp_user_id'         => 424242,
            'interval_type'      => 'month',
            'current_period_end' => $expires,
            'created_at'         => $created,
            'transactions'       => 1,
        ]);
        $transactionId = (int) $source['transaction_ids'][0];

        // wpdb::insert() is used for the access fixtures instead of the query
        // builder: the builder binds every null as %s, so an int column would
        // silently store 0 and these rows have to hold real NULLs.
        $insertAccess = function (array $row) use ($wpdb, $accessTable) {
            $wpdb->insert($accessTable, $row);

            return (int) $wpdb->insert_id;
        };

        // A legacy row that already owns this source's transaction, but with the
        // wrong type and no subscription link. The unique key on transaction_id
        // makes inserting the source impossible, so a cancellation of the
        // subscription could never find the access row it has to revoke.
        $collisionId = $insertAccess([
            'supporter_id'    => $source['supporter_id'],
            'wp_user_id'      => null,
            'level_id'        => 987999,
            'transaction_id'  => $transactionId,
            'subscription_id' => null,
            'access_type'     => 'one_time',
            'status'          => 'incomplete',
            'starts_at'       => null,
            'expires_at'      => null,
            'created_at'      => current_time('mysql'),
            'updated_at'      => '2001-01-01 00:00:00',
        ]);

        $activator = new Activator();
        $state     = $activator->runMigrationBatch();
        $test->assertFalse(is_wp_error($state), 'The reconciling batch must succeed: ' . (is_wp_error($state) ? $state->get_error_message() : ''));

        $rows = $bmcAccessRowsFor($source['supporter_id']);
        $test->assertSame(1, count($rows), 'Reconciliation must repair the row instead of adding a second one');
        $test->assertSame($collisionId, (int) $rows[0]->id, 'The colliding row must be repaired in place');
        $test->assertSame($transactionId, (int) $rows[0]->transaction_id, 'The matched transaction must be kept');
        $test->assertSame('subscription', $rows[0]->access_type, 'The row must take the source access type');
        $test->assertSame((int) $source['subscription_id'], (int) $rows[0]->subscription_id, 'A recurring source must own its subscription link');
        $test->assertSame($levelId, (int) $rows[0]->level_id, 'The row must take the source level');
        $test->assertSame(424242, (int) $rows[0]->wp_user_id, 'The row must take the source user link');
        $test->assertSame('active', $rows[0]->status, 'The row must take the source status');
        $test->assertSame($created, $rows[0]->starts_at, 'The row must take the source start date');
        $test->assertSame($expires, $rows[0]->expires_at, 'The row must take the source period end');
        $test->assertFalse('2001-01-01 00:00:00' === $rows[0]->updated_at, 'A repaired row must be stamped as updated');

        // Replaying the same range must not rewrite a row that already matches.
        $wpdb->update($accessTable, ['updated_at' => '2002-02-02 00:00:00'], ['id' => $collisionId]);

        $rewound           = get_option(Activator::MIGRATION_STATE_OPTION);
        $rewound['phase']  = Activator::PHASE_BACKFILL_ACCESS;
        $rewound['cursor'] = $cursor;
        update_option(Activator::MIGRATION_STATE_OPTION, $rewound, false);

        $replayed = $activator->runMigrationBatch();
        $test->assertFalse(is_wp_error($replayed), 'The replay must succeed: ' . (is_wp_error($replayed) ? $replayed->get_error_message() : ''));

        $rows = $bmcAccessRowsFor($source['supporter_id']);
        $test->assertSame(1, count($rows), 'A replay must not duplicate the reconciled row');
        $test->assertSame($collisionId, (int) $rows[0]->id, 'A replay must not replace the reconciled row');
        $test->assertSame('2002-02-02 00:00:00', $rows[0]->updated_at, 'A row that already describes its source must not be rewritten');

        // A rival row already owning the canonical subscription link is the one
        // a cancellation finds, so the batch must skip the duplicate instead of
        // failing this range on every retry forever.
        $rivalUserId = 424343;
        $rival = $bmcMakeMigrationSource([
            'level_id'           => $levelId,
            'wp_user_id'         => $rivalUserId,
            'current_period_end' => $expires,
            'transactions'       => 1,
        ]);

        // The row that wins the collision is stale: legacy data split this
        // subscription across two rows, and the one holding the subscription_id
        // is not the one that describes the entitlement correctly. Retiring the
        // other and trusting this one blindly would leave the wrong level, the
        // wrong user and no expiry in effect, with nothing left to correct it.
        $canonicalId = $insertAccess([
            'supporter_id'    => $rival['supporter_id'],
            'wp_user_id'      => null,
            'level_id'        => 987999,
            'transaction_id'  => null,
            'subscription_id' => $rival['subscription_id'],
            'access_type'     => 'one_time',
            'status'          => 'incomplete',
            'starts_at'       => null,
            'expires_at'      => null,
            'created_at'      => current_time('mysql'),
            'updated_at'      => '2004-04-04 00:00:00',
        ]);
        // The dangerous shape of a duplicate: 'active' and untyped as recurring,
        // so every grant path reads it as a one-time purchase that never
        // expires. Skipping it is not enough — a cancellation updates only the
        // canonical row above, and this one would go on granting the level for
        // good.
        $duplicateId = $insertAccess([
            'supporter_id'    => $rival['supporter_id'],
            'wp_user_id'      => $rivalUserId,
            'level_id'        => $levelId,
            'transaction_id'  => $rival['transaction_ids'][0],
            'subscription_id' => null,
            'access_type'     => 'one_time',
            'status'          => 'active',
            'starts_at'       => null,
            'expires_at'      => null,
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);

        $rewound           = get_option(Activator::MIGRATION_STATE_OPTION);
        $rewound['phase']  = Activator::PHASE_BACKFILL_ACCESS;
        $rewound['cursor'] = $cursor;
        update_option(Activator::MIGRATION_STATE_OPTION, $rewound, false);

        $contested = $activator->runMigrationBatch();
        $test->assertFalse(is_wp_error($contested), 'A collision on the subscription key must not fail the batch: ' . (is_wp_error($contested) ? $contested->get_error_message() : ''));

        $rivalRows = $bmcAccessRowsFor($rival['supporter_id']);
        $test->assertSame(2, count($rivalRows), 'A skipped duplicate must not become a third row');
        $test->assertSame($canonicalId, (int) $rivalRows[0]->id, 'The canonical row must survive');
        $test->assertSame((int) $rival['subscription_id'], (int) $rivalRows[0]->subscription_id, 'The canonical row must keep the subscription link');

        // Surviving is not enough: the row that is about to become the only one
        // answering for this subscription is brought to its source first.
        $test->assertSame($levelId, (int) $rivalRows[0]->level_id, 'The surviving row must take the source level');
        $test->assertSame($rivalUserId, (int) $rivalRows[0]->wp_user_id, 'And the source user link');
        $test->assertSame('subscription', $rivalRows[0]->access_type, 'And the source access type');
        $test->assertSame('active', $rivalRows[0]->status, 'And the source status');
        $test->assertSame($expires, $rivalRows[0]->expires_at, 'And the period the source actually paid for');
        $test->assertFalse('2004-04-04 00:00:00' === $rivalRows[0]->updated_at, 'A repaired owner must be stamped as updated');
        $test->assertSame($duplicateId, (int) $rivalRows[1]->id, 'The duplicate must be skipped, not deleted');
        $test->assertSame(null, $rivalRows[1]->subscription_id, 'The duplicate must not steal the unique subscription link');

        // Skipped, but no longer granting: the canonical row is left as the one
        // row that answers for this subscription, so a later cancellation of it
        // actually ends the entitlement.
        $test->assertSame('superseded', $rivalRows[1]->status, 'A duplicate the collision left behind must stop granting');
        $test->assertSame($rival['transaction_ids'][0], (int) $rivalRows[1]->transaction_id, 'A retired duplicate stays on record');

        $test->assertSame(
            [$levelId],
            buymecoffee_user_get_active_level_ids($rivalUserId, true),
            'The canonical row alone grants the level'
        );

        // Cancelling the subscription and letting its paid period lapse has to
        // take the access with it. Before the duplicate was retired, the
        // cancellation reached only the canonical row and the level stayed
        // granted forever through the one nothing else ever looks at.
        $wpdb->update($accessTable, [
            'status'     => 'cancelled',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
        ], ['id' => $canonicalId]);
        $test->assertSame(
            [],
            buymecoffee_user_get_active_level_ids($rivalUserId, true),
            'A cancelled subscription must not keep granting through the duplicate'
        );

        // Replaying the range must not rewrite an already retired duplicate.
        $wpdb->update($accessTable, ['updated_at' => '2003-03-03 00:00:00'], ['id' => $duplicateId]);

        $rewound           = get_option(Activator::MIGRATION_STATE_OPTION);
        $rewound['phase']  = Activator::PHASE_BACKFILL_ACCESS;
        $rewound['cursor'] = $cursor;
        update_option(Activator::MIGRATION_STATE_OPTION, $rewound, false);

        $settled = $activator->runMigrationBatch();
        $test->assertFalse(is_wp_error($settled), 'A replay over a retired duplicate must succeed: ' . (is_wp_error($settled) ? $settled->get_error_message() : ''));

        $settledRows = $bmcAccessRowsFor($rival['supporter_id']);
        $test->assertSame(2, count($settledRows), 'A replay must not add a row');
        $test->assertSame('2003-03-03 00:00:00', $settledRows[1]->updated_at, 'An already retired duplicate must not be rewritten');
    } finally {
        remove_filter('buymecoffee_migration_batch_size', $batch);
        $restore();
    }
});

$suite->test('the request-time migration markers are repaired to autoload without running migration work', function ($test) use ($bmcMigrationRestore) {
    global $wpdb;

    $restore = $bmcMigrationRestore();
    $markers = [
        'buymecoffee_db_version',
        Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION,
        Activator::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION,
    ];

    try {
        // A settled site upgraded from a release that stored these markers with
        // autoload disabled. update_option() will not repair that on its own:
        // it returns early whenever the stored value is unchanged.
        update_option('buymecoffee_db_version', BUYMECOFFEE_DB_VERSION, true);
        update_option(Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION, BUYMECOFFEE_DB_VERSION, true);
        update_option(Activator::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION, 'yes', true);

        foreach ($markers as $marker) {
            $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $marker], ['%s'], ['%s']);
        }

        wp_cache_flush();
        $before = wp_load_alloptions();
        foreach ($markers as $marker) {
            $test->assertFalse(array_key_exists($marker, $before), "The fixture must start with {$marker} outside the autoloaded set");
        }

        (new Activator())->maybeRunMigrations();

        wp_cache_delete('alloptions', 'options');
        $after = wp_load_alloptions();
        foreach ($markers as $marker) {
            $test->assertTrue(array_key_exists($marker, $after), "{$marker} is read on every request and must be autoloaded");
        }

        // The repair must not turn a settled site into a migrating one.
        $test->assertFalse((bool) wp_next_scheduled(Activator::MIGRATION_HOOK), 'A settled site must not schedule migration work');
        $test->assertSame(BUYMECOFFEE_DB_VERSION, get_option('buymecoffee_db_version'), 'The repair must not touch marker values');
        $test->assertSame('yes', get_option(Activator::DEFAULT_MEMBERSHIP_LEVEL_SEEDED_OPTION), 'The repair must not touch marker values');
    } finally {
        $restore();
    }
});

$suite->test('a failed batch persists its error, keeps the old versions, releases the lock and still retries', function ($test) use ($bmcMigrationRestore, $bmcSeedMigrationState, $bmcMakeMigrationSource, $bmcAccessRowsFor, $bmcReadLockRow) {
    global $wpdb;

    $restore = $bmcMigrationRestore();
    $batch   = function () {
        return 5;
    };
    $fail = function () {
        return new WP_Error('bmc_forced_migration_failure', 'Forced migration failure for HIGH-04.');
    };

    add_filter('buymecoffee_migration_batch_size', $batch);
    add_filter('buymecoffee_migration_force_error', $fail, 10, 3);

    try {
        $cursor = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}buymecoffee_subscriptions");
        $bmcSeedMigrationState(Activator::PHASE_BACKFILL_ACCESS, $cursor);
        $source = $bmcMakeMigrationSource(['level_id' => 987006, 'transactions' => 1]);

        $activator = new Activator();
        $error     = $activator->runMigrationBatch();

        $test->assertTrue(is_wp_error($error), 'A forced failure must surface as an error');
        $test->assertSame('bmc_forced_migration_failure', $error->get_error_code());

        $state = get_option(Activator::MIGRATION_STATE_OPTION);
        $test->assertSame(Activator::PHASE_BACKFILL_ACCESS, $state['phase'], 'A failure must not advance the phase');
        $test->assertSame($cursor, (int) $state['cursor'], 'A failure must not advance the cursor');
        $test->assertSame(1, (int) $state['retries'], 'The first failure must record one retry');
        $test->assertSame('bmc_forced_migration_failure', $state['last_error']['code'], 'The failure code must be durable');
        $test->assertSame('Forced migration failure for HIGH-04.', $state['last_error']['message'], 'The failure message must be durable');
        $test->assertNotEmpty($state['last_error']['at'], 'The failure must be timestamped');

        $test->assertSame([], $bmcAccessRowsFor($source['supporter_id']), 'A failed batch must not migrate anything');
        $test->assertSame('1.0', get_option('buymecoffee_db_version'), 'A failure must leave the DB version old');
        $test->assertSame('', get_option(Activator::SCHEMA_VERIFIED_DB_VERSION_OPTION), 'A failure must leave the schema marker old');
        $test->assertSame(null, $bmcReadLockRow(), 'A failed batch must still release its lock');
        $test->assertTrue((bool) wp_next_scheduled(Activator::MIGRATION_HOOK), 'A failure must schedule a retry');

        // Consecutive failures accumulate instead of resetting.
        wp_clear_scheduled_hook(Activator::MIGRATION_HOOK);
        $activator->runMigrationBatch();
        $test->assertSame(2, (int) get_option(Activator::MIGRATION_STATE_OPTION)['retries'], 'Consecutive failures must accumulate');
        $test->assertSame(null, $bmcReadLockRow(), 'The second failed batch must release its lock too');

        // The retry succeeds once the fault clears.
        remove_filter('buymecoffee_migration_force_error', $fail, 10);
        $retried = $activator->runMigrationBatch();

        $test->assertFalse(is_wp_error($retried), 'The retry must succeed once the failure clears');
        $test->assertSame(0, (int) $retried['retries'], 'A successful batch must reset the retry counter');
        $test->assertSame(null, $retried['last_error'], 'A successful batch must clear the stored error');
        $test->assertSame(1, count($bmcAccessRowsFor($source['supporter_id'])), 'The retry must migrate the pending source');
        $test->assertSame(null, $bmcReadLockRow(), 'The successful retry must release its lock');
    } finally {
        remove_filter('buymecoffee_migration_batch_size', $batch);
        remove_filter('buymecoffee_migration_force_error', $fail, 10);
        $restore();
    }
});

$suite->test('the payment status service owns and commits its production transaction boundary', function ($test) {
    global $wpdb;

    $suffix = wp_generate_password(12, false, false);
    $now    = current_time('mysql');

    $supporterId = (int) buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
        'supporters_name'  => 'HIGH03 Transaction Boundary',
        'supporters_email' => 'bmc-high03-boundary-' . $suffix . '@example.com',
        'payment_status'   => 'pending',
        'entry_hash'       => 'bmc_high03_boundary_' . $suffix,
        'payment_total'    => 2500,
        'coffee_count'     => 1,
        'payment_mode'     => 'test',
        'payment_method'   => 'stripe',
        'status'           => 'new',
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);

    $transactionId = (int) buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
        'entry_id'         => $supporterId,
        'entry_hash'       => 'bmc_high03_boundary_tx_' . $suffix,
        'transaction_type' => 'one_time',
        'payment_method'   => 'stripe',
        'payment_total'    => 2500,
        'status'           => 'pending',
        'currency'         => 'USD',
        'payment_mode'     => 'test',
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);

    $statuses = [];
    $watcher  = function ($updatedTransactionId, $status) use (&$statuses) {
        $statuses[] = [(int) $updatedTransactionId, (string) $status];
    };
    $mailStub = '__return_true';

    add_action('buymecoffee_payment_status_updated', $watcher, 99, 2);
    add_filter('pre_wp_mail', $mailStub);

    try {
        $test->assertTrue(
            (bool) apply_filters('buymecoffee_payment_status_manages_transaction', true, $transactionId),
            'This test must exercise the production-owned transaction path'
        );

        // START TRANSACTION here intentionally commits the runner's fixture
        // transaction. The finally block therefore removes every persisted row.
        $result = (new OneTimePaymentStatusService())->apply((object) ['id' => $transactionId], 'paid');

        $test->assertFalse(is_wp_error($result), 'The production transaction boundary failed');
        $test->assertTrue($result['changed']);
        $test->assertSame('pending', $result['from']);
        $test->assertSame('paid', $result['to']);
        $test->assertSame('paid', (new Transactions())->find($transactionId)->status);
        $test->assertSame('paid', (new Supporters())->find($supporterId)->payment_status);
        $test->assertSame([[$transactionId, 'paid']], $statuses, 'The hook must fire once, after COMMIT');
    } finally {
        remove_action('buymecoffee_payment_status_updated', $watcher, 99);
        remove_filter('pre_wp_mail', $mailStub);

        $wpdb->delete(
            $wpdb->prefix . 'buymecoffee_activities',
            ['object_type' => 'payment', 'object_id' => $transactionId]
        );
        $wpdb->delete($wpdb->prefix . 'buymecoffee_transactions', ['id' => $transactionId]);
        $wpdb->delete($wpdb->prefix . 'buymecoffee_supporters', ['id' => $supporterId]);
    }
});

/**
 * HIGH-05: supporter aggregates must not multiply revenue through join fan-out,
 * and a page of identities must not read paid history outside that page.
 */
$bmcSeedIdentity = function (array $spec) {
    $suffix   = wp_generate_password(16, false, false);
    $currency = isset($spec['currency']) ? $spec['currency'] : 'USD';
    $moment   = isset($spec['created_at']) ? $spec['created_at'] : current_time('mysql');

    $supporterId = (int) buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
        'supporters_name'  => isset($spec['name']) ? $spec['name'] : 'HIGH05 Donor',
        'supporters_email' => isset($spec['email']) ? $spec['email'] : '',
        'currency'         => $currency,
        'payment_status'   => 'paid',
        'entry_hash'       => 'bmc_high05_' . $suffix,
        'payment_total'    => 0,
        'coffee_count'     => 1,
        'payment_mode'     => 'test',
        'payment_method'   => 'stripe',
        'status'           => 'new',
        'created_at'       => $moment,
        'updated_at'       => $moment,
    ]);

    foreach (['paid', 'pending'] as $transactionStatus) {
        $amounts = isset($spec[$transactionStatus]) ? $spec[$transactionStatus] : [];
        foreach ($amounts as $amount) {
            buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
                'entry_id'         => $supporterId,
                'entry_hash'       => 'bmc_high05_tx_' . wp_generate_password(16, false, false),
                'transaction_type' => 'one_time',
                'payment_method'   => 'stripe',
                'payment_total'    => (int) $amount,
                'status'           => $transactionStatus,
                'currency'         => $currency,
                'payment_mode'     => 'test',
                'created_at'       => $moment,
                'updated_at'       => $moment,
            ]);
        }
    }

    $subscriptions = isset($spec['subscriptions']) ? $spec['subscriptions'] : [];
    foreach ($subscriptions as $subscriptionStatus) {
        buyMeCoffeeQuery()->table('buymecoffee_subscriptions')->insert([
            'supporter_id'           => $supporterId,
            'stripe_subscription_id' => 'sub_high05_' . wp_generate_password(20, false, false),
            'stripe_customer_id'     => 'cus_high05_' . $suffix,
            'interval_type'          => 'month',
            'amount'                 => 500,
            'currency'               => $currency,
            'status'                 => $subscriptionStatus,
            'payment_mode'           => 'test',
            'created_at'             => $moment,
            'updated_at'             => $moment,
        ]);
    }

    return $supporterId;
};

$bmcIdentityEmails = function (array $payload) {
    $emails = [];
    foreach ($payload['supporters'] as $row) {
        $emails[] = strtolower((string) $row->supporters_email);
    }

    return $emails;
};

$bmcIdentityByEmail = function (array $payload, $email) {
    foreach ($payload['supporters'] as $row) {
        if (strtolower((string) $row->supporters_email) === strtolower($email)) {
            return $row;
        }
    }

    return null;
};

$suite->test('two paid transactions and two subscription rows produce one exact lifetime total', function ($test) use ($bmcSeedIdentity, $bmcIdentityByEmail) {
    $email = 'bmc-high05-fanout-' . wp_generate_password(10, false, false) . '@example.com';

    $bmcSeedIdentity([
        'name'          => 'HIGH05 Fan Out',
        'email'         => $email,
        'paid'          => [1000, 2500],
        'pending'       => [900],
        'subscriptions' => ['active', 'active'],
    ]);

    $payload = (new Supporters())->getUniqueSupportersData([
        'search'         => $email,
        'page'           => 0,
        'posts_per_page' => 12,
    ]);

    $test->assertSame(1, $payload['total']);
    $test->assertSame(1, count($payload['supporters']));

    $row = $bmcIdentityByEmail($payload, $email);
    $test->assertNotEmpty($row, 'The seeded identity is missing from the page');

    $test->assertSame(3500, $row->total_paid, 'Subscription rows must not multiply the lifetime total');
    $test->assertSame(2, $row->donation_count, 'Only paid transactions count, and only once each');
    $test->assertTrue($row->has_subscription, 'An active subscription must still be reported');
    $test->assertSame(1, (int) $row->entry_count);
    $test->assertSame(PaymentHelper::getFormattedAmount(3500, 'USD'), $row->total_formatted);
    $test->assertTrue(is_bool($row->has_subscription), 'has_subscription must stay a boolean');
});

$suite->test('top supporter ranking and totals survive the same fan-out fixture', function ($test) use ($bmcSeedIdentity) {
    global $wpdb;

    $txTable = $wpdb->prefix . 'buymecoffee_transactions';
    $ceiling = (int) $wpdb->get_var("SELECT COALESCE(SUM(payment_total), 0) FROM {$txTable} WHERE status = 'paid'");
    $base    = $ceiling + 1000;

    $test->assertTrue($base + 2000 < 2147483647, 'The fixture amounts must fit the payment_total column');

    $suffix   = wp_generate_password(10, false, false);
    $leader   = 'bmc-high05-lead-' . $suffix . '@example.com';
    $runnerUp = 'bmc-high05-second-' . $suffix . '@example.com';

    // The leader owns three subscription rows: the old query multiplied its two
    // paid transactions by them and ranked on a total three times too large.
    $bmcSeedIdentity([
        'name'          => 'HIGH05 Leader',
        'email'         => $leader,
        'paid'          => [$base, 2000],
        'pending'       => [50000],
        'subscriptions' => ['active', 'active', 'cancelled'],
    ]);

    $bmcSeedIdentity([
        'name'  => 'HIGH05 Runner Up',
        'email' => $runnerUp,
        'paid'  => [$base + 500],
    ]);

    Supporters::flushAdminReportCache();
    $top = (new Supporters())->getTopSupportersList(10);

    $test->assertTrue(count($top) >= 2, 'Both seeded leaders must be ranked');
    $test->assertSame(strtolower($leader), strtolower((string) $top[0]->supporters_email));
    $test->assertSame($base + 2000, $top[0]->total_paid, 'Lifetime ranking total must not be multiplied');
    $test->assertSame(2, $top[0]->donation_count);
    $test->assertTrue($top[0]->has_subscription);
    $test->assertSame(PaymentHelper::getFormattedAmount($base + 2000, 'USD'), $top[0]->total_formatted);

    $test->assertSame(strtolower($runnerUp), strtolower((string) $top[1]->supporters_email));
    $test->assertSame($base + 500, $top[1]->total_paid);
    $test->assertSame(1, $top[1]->donation_count);
    $test->assertFalse($top[1]->has_subscription);

    foreach (['latest_entry_id', 'supporters_name', 'supporters_email', 'currency', 'total_paid', 'donation_count', 'has_subscription', 'last_donation_date', 'total_formatted', 'avatar'] as $key) {
        $test->assertTrue(property_exists($top[0], $key), "Leaderboard row lost the {$key} field");
    }

    Supporters::flushAdminReportCache();
});

$suite->test('entries sharing an email aggregate once and anonymous donors stay separate', function ($test) use ($bmcSeedIdentity, $bmcIdentityEmails, $bmcIdentityByEmail) {
    $token  = 'bmchigh05grp' . wp_generate_password(8, false, false);
    $shared = $token . '@example.com';

    $bmcSeedIdentity([
        'name'          => 'HIGH05 Shared First',
        'email'         => $shared,
        'created_at'    => '2026-03-01 09:00:00',
        'paid'          => [1000],
        'subscriptions' => ['active'],
    ]);
    $sharedLatest = $bmcSeedIdentity([
        'name'          => 'HIGH05 Shared Second',
        'email'         => $shared,
        'created_at'    => '2026-03-01 10:00:00',
        'paid'          => [2000, 3000],
        'subscriptions' => ['cancelled'],
    ]);

    $anonOne = $bmcSeedIdentity([
        'name'       => 'HIGH05 Anon One ' . $token,
        'email'      => '',
        'created_at' => '2026-03-01 08:00:00',
        'paid'       => [700],
    ]);
    $anonTwo = $bmcSeedIdentity([
        'name'          => 'HIGH05 Anon Two ' . $token,
        'email'         => '',
        'created_at'    => '2026-03-01 07:00:00',
        'paid'          => [800],
        'subscriptions' => ['active'],
    ]);

    $payload = (new Supporters())->getUniqueSupportersData([
        'search'         => $token,
        'page'           => 0,
        'posts_per_page' => 12,
    ]);

    $test->assertSame(3, $payload['total'], 'One email identity plus two anonymous entries');
    $test->assertSame(3, count($payload['supporters']));

    $sharedRow = $bmcIdentityByEmail($payload, $shared);
    $test->assertNotEmpty($sharedRow);
    $test->assertSame(6000, $sharedRow->total_paid, 'Both entries of one email aggregate exactly once');
    $test->assertSame(3, $sharedRow->donation_count);
    $test->assertSame(2, (int) $sharedRow->entry_count);
    $test->assertSame($sharedLatest, (int) $sharedRow->latest_entry_id);
    $test->assertTrue($sharedRow->has_subscription);

    $anonRows = [];
    foreach ($payload['supporters'] as $row) {
        if ((string) $row->supporters_email === '') {
            $anonRows[(int) $row->latest_entry_id] = $row;
        }
    }

    $test->assertSame(2, count($anonRows), 'Anonymous donors must never be merged');
    $test->assertSame(700, $anonRows[$anonOne]->total_paid);
    $test->assertSame(1, $anonRows[$anonOne]->donation_count);
    $test->assertFalse($anonRows[$anonOne]->has_subscription);
    $test->assertSame('', $anonRows[$anonOne]->avatar, 'Anonymous rows carry no avatar');
    $test->assertSame(800, $anonRows[$anonTwo]->total_paid);
    $test->assertTrue($anonRows[$anonTwo]->has_subscription);

    $test->assertSame(
        [strtolower($shared), '', ''],
        $bmcIdentityEmails($payload),
        'Identities must stay ordered by their latest entry date'
    );
});

$suite->test('search and the subscribers and one-time filters keep their identity semantics', function ($test) use ($bmcSeedIdentity, $bmcIdentityByEmail) {
    $token = 'sbmchigh05flt' . wp_generate_password(8, false, false);

    $subscriber = 'sub-' . $token . '@example.com';
    $oneTime    = 'one-' . $token . '@example.com';
    $pastMember = 'past-' . $token . '@example.com';
    $mixed      = 'mix-' . $token . '@example.com';

    $bmcSeedIdentity(['name' => 'HIGH05 Subscriber', 'email' => $subscriber, 'created_at' => '2026-04-04 10:00:00', 'paid' => [1500], 'subscriptions' => ['active', 'active']]);
    $bmcSeedIdentity(['name' => 'HIGH05 One Time', 'email' => $oneTime, 'created_at' => '2026-04-03 10:00:00', 'paid' => [2500]]);
    $bmcSeedIdentity(['name' => 'HIGH05 Past Member', 'email' => $pastMember, 'created_at' => '2026-04-02 10:00:00', 'paid' => [3500], 'subscriptions' => ['cancelled']]);
    $bmcSeedIdentity(['name' => 'HIGH05 Mixed Sub', 'email' => $mixed, 'created_at' => '2026-04-01 11:00:00', 'paid' => [100], 'subscriptions' => ['active']]);
    $bmcSeedIdentity(['name' => 'HIGH05 Mixed Plain', 'email' => $mixed, 'created_at' => '2026-04-01 10:00:00', 'paid' => [900]]);

    $supporters = new Supporters();

    // The search term starts with "s": it must survive as a LIKE value and not
    // be read as a "%s" placeholder.
    $all = $supporters->getUniqueSupportersData(['search' => $token, 'filter' => 'all', 'page' => 0, 'posts_per_page' => 12]);
    $test->assertSame(4, $all['total']);
    $test->assertSame(1500, $bmcIdentityByEmail($all, $subscriber)->total_paid);
    $test->assertSame(2500, $bmcIdentityByEmail($all, $oneTime)->total_paid);
    $test->assertSame(3500, $bmcIdentityByEmail($all, $pastMember)->total_paid);
    $test->assertSame(1000, $bmcIdentityByEmail($all, $mixed)->total_paid, 'Both entries of the mixed identity count under "all"');
    $test->assertSame(2, (int) $bmcIdentityByEmail($all, $mixed)->entry_count);

    $subscribers = $supporters->getUniqueSupportersData(['search' => $token, 'filter' => 'subscribers', 'page' => 0, 'posts_per_page' => 12]);
    $test->assertSame(2, $subscribers['total'], 'Only entries with an active subscription qualify');
    $test->assertSame(1500, $bmcIdentityByEmail($subscribers, $subscriber)->total_paid);
    $test->assertTrue($bmcIdentityByEmail($subscribers, $subscriber)->has_subscription);
    $test->assertSame(100, $bmcIdentityByEmail($subscribers, $mixed)->total_paid, 'Only the subscribed entry of the identity is aggregated');
    $test->assertSame(1, (int) $bmcIdentityByEmail($subscribers, $mixed)->entry_count);
    $test->assertTrue($bmcIdentityByEmail($subscribers, $pastMember) === null, 'A cancelled subscription is not a subscriber');

    $oneTimeOnly = $supporters->getUniqueSupportersData(['search' => $token, 'filter' => 'one-time', 'page' => 0, 'posts_per_page' => 12]);
    $test->assertSame(2, $oneTimeOnly['total'], 'Any subscription row disqualifies an entry from one-time');
    $test->assertSame(2500, $bmcIdentityByEmail($oneTimeOnly, $oneTime)->total_paid);
    $test->assertSame(900, $bmcIdentityByEmail($oneTimeOnly, $mixed)->total_paid);
    $test->assertFalse($bmcIdentityByEmail($oneTimeOnly, $mixed)->has_subscription);
    $test->assertTrue($bmcIdentityByEmail($oneTimeOnly, $pastMember) === null);
    $test->assertTrue($bmcIdentityByEmail($oneTimeOnly, $subscriber) === null);

    $exact = $supporters->getUniqueSupportersData(['search' => $subscriber, 'filter' => 'all', 'page' => 0, 'posts_per_page' => 12]);
    $test->assertSame(1, $exact['total']);
    $test->assertSame(strtolower($subscriber), strtolower((string) $exact['supporters'][0]->supporters_email));

    $byName = $supporters->getUniqueSupportersData(['search' => 'HIGH05 Past Member', 'filter' => 'all', 'page' => 0, 'posts_per_page' => 12]);
    $test->assertNotEmpty($bmcIdentityByEmail($byName, $pastMember), 'Search must still match supporter names');
});

$suite->test('three pages of identities stay stable, non-overlapping and exactly totalled', function ($test) use ($bmcSeedIdentity, $bmcIdentityEmails) {
    $token = 'bmchigh05pg' . wp_generate_password(8, false, false);

    $email = function ($slot) use ($token) {
        return 'p' . $slot . '-' . $token . '@example.com';
    };

    // p1 and p2 share a timestamp on purpose: the deterministic tiebreaker must
    // place the newer entry id first on every request.
    $bmcSeedIdentity(['name' => 'HIGH05 Page 1', 'email' => $email(1), 'created_at' => '2026-05-02 10:00:00', 'paid' => [100]]);
    $bmcSeedIdentity(['name' => 'HIGH05 Page 2', 'email' => $email(2), 'created_at' => '2026-05-02 10:00:00', 'paid' => [200]]);
    $bmcSeedIdentity(['name' => 'HIGH05 Page 3', 'email' => $email(3), 'created_at' => '2026-05-03 10:00:00', 'paid' => [300], 'pending' => [30000]]);
    $bmcSeedIdentity(['name' => 'HIGH05 Page 4', 'email' => $email(4), 'created_at' => '2026-05-04 10:00:00', 'paid' => [400]]);
    $bmcSeedIdentity(['name' => 'HIGH05 Page 5', 'email' => $email(5), 'created_at' => '2026-05-05 10:00:00', 'paid' => [500], 'subscriptions' => ['cancelled']]);
    $bmcSeedIdentity(['name' => 'HIGH05 Page 6', 'email' => $email(6), 'created_at' => '2026-05-06 10:00:00', 'paid' => [600, 60], 'subscriptions' => ['active', 'active']]);
    $bmcSeedIdentity(['name' => 'HIGH05 Page 7', 'email' => $email(7), 'created_at' => '2026-05-07 10:00:00', 'paid' => [700]]);

    $expected = [
        [$email(7), 700, 1, false],
        [$email(6), 660, 2, true],
        [$email(5), 500, 1, false],
        [$email(4), 400, 1, false],
        [$email(3), 300, 1, false],
        [$email(2), 200, 1, false],
        [$email(1), 100, 1, false],
    ];

    $supporters = new Supporters();
    $seenIds    = [];
    $position   = 0;

    for ($page = 0; $page < 3; $page++) {
        $payload = $supporters->getUniqueSupportersData([
            'search'         => $token,
            'page'           => $page,
            'posts_per_page' => 3,
        ]);

        $test->assertSame(7, $payload['total'], "Page {$page} reported the wrong identity total");

        foreach ($payload['supporters'] as $row) {
            list($expectedEmail, $expectedTotal, $expectedCount, $expectedSubscription) = $expected[$position];

            $test->assertSame(strtolower($expectedEmail), strtolower((string) $row->supporters_email), "Wrong identity at position {$position}");
            $test->assertSame($expectedTotal, $row->total_paid, "Wrong lifetime total at position {$position}");
            $test->assertSame($expectedCount, $row->donation_count);
            $test->assertSame($expectedSubscription, $row->has_subscription);

            $identityId = (int) $row->latest_entry_id;
            $test->assertFalse(isset($seenIds[$identityId]), 'Pages must never repeat an identity');
            $seenIds[$identityId] = true;
            $position++;
        }
    }

    $test->assertSame(7, $position, 'Three pages of three must return every identity exactly once');

    $repeat = $supporters->getUniqueSupportersData(['search' => $token, 'page' => 2, 'posts_per_page' => 3]);
    $test->assertSame([strtolower($email(1))], $bmcIdentityEmails($repeat), 'Tied timestamps must page deterministically');

    $emptyPage = $supporters->getUniqueSupportersData(['search' => $token, 'page' => 9, 'posts_per_page' => 3]);
    $test->assertSame(7, $emptyPage['total']);
    $test->assertSame(0, count($emptyPage['supporters']));
});

$suite->test('a supporter page reads paid history only for the identities on that page', function ($test) use ($bmcSeedIdentity) {
    global $wpdb;

    $token   = 'bmchigh05sql' . wp_generate_password(8, false, false);
    $onPage  = 'a-' . $token . '@example.com';
    $offPage = 'b-' . $token . '@example.com';

    $bmcSeedIdentity([
        'name'          => 'HIGH05 On Page',
        'email'         => $onPage,
        'created_at'    => '2026-06-02 10:00:00',
        'paid'          => [1200],
        'subscriptions' => ['active', 'active'],
    ]);
    $bmcSeedIdentity([
        'name'          => 'HIGH05 Off Page',
        'email'         => $offPage,
        'created_at'    => '2026-06-01 10:00:00',
        'paid'          => [9999999],
        'subscriptions' => ['active'],
    ]);

    $captured = [];
    $capture  = function ($query) use (&$captured) {
        $captured[] = $query;
        return $query;
    };

    add_filter('query', $capture);
    try {
        $payload = (new Supporters())->getUniqueSupportersData([
            'search'         => $token,
            'page'           => 0,
            'posts_per_page' => 1,
        ]);
    } finally {
        remove_filter('query', $capture);
    }

    $test->assertSame(2, $payload['total']);
    $test->assertSame(1, count($payload['supporters']));
    $test->assertSame(1200, $payload['supporters'][0]->total_paid, 'An off-page transaction must not reach this row');
    $test->assertTrue($payload['supporters'][0]->has_subscription);

    $supTable = $wpdb->prefix . 'buymecoffee_supporters';
    $txTable  = $wpdb->prefix . 'buymecoffee_transactions';
    $subTable = $wpdb->prefix . 'buymecoffee_subscriptions';

    foreach ($captured as $query) {
        $test->assertFalse(
            strpos($query, $txTable) !== false && strpos($query, $subTable) !== false,
            'No supporter page query may reference transactions and subscriptions together'
        );
        $test->assertNotContains('JOIN ' . $subTable, $query, 'The subscriptions table must never be joined');
    }

    $txQueries = array_values(array_filter($captured, function ($query) use ($txTable) {
        return strpos($query, $txTable) !== false;
    }));

    $test->assertSame(1, count($txQueries), 'Exactly one query may read raw transactions');
    $test->assertContains('s.supporters_email IN (', $txQueries[0], 'Paid history must be restricted to the page identities');
    $test->assertContains($onPage, $txQueries[0]);
    $test->assertNotContains($offPage, $txQueries[0], 'An identity outside the page must not reach the paid-history query');

    $subQueries = array_values(array_filter($captured, function ($query) use ($subTable) {
        return strpos($query, $subTable) !== false;
    }));

    $test->assertSame(1, count($subQueries), 'Exactly one query may read subscriptions');
    $test->assertContains('EXISTS (', $subQueries[0], 'Subscription presence must be an EXISTS, not a join');
    $test->assertNotContains($offPage, $subQueries[0]);

    $plan = [];
    foreach ($wpdb->get_results('EXPLAIN ' . $txQueries[0], ARRAY_A) as $planRow) {
        $plan[(string) $planRow['table']] = $planRow;
    }

    $test->assertSame(2, count($plan), 'The paid-history plan must read supporters and transactions only');
    $test->assertTrue(isset($plan['s']) && isset($plan['t']), 'The paid-history plan lost an expected table');
    $test->assertContains(
        'bmc_sup_email',
        (string) $plan['s']['possible_keys'] . ' ' . (string) $plan['s']['key'],
        'The identity restriction must be able to use bmc_sup_email'
    );
    $test->assertContains(
        'bmc_tx_entry',
        (string) $plan['t']['possible_keys'] . ' ' . (string) $plan['t']['key'],
        'The transaction lookup must be able to use bmc_tx_entry'
    );
});


// ── MEDIUM-01: public write endpoints need anti-automation and idempotency ──

/**
 * Run a public endpoint that answers with wp_send_json_*() and capture what it
 * sent, instead of letting the response end the process.
 *
 * Deliberately re-entrant: a stub for an outbound provider call may run a second
 * endpoint inside the first, which is how one process reproduces two requests
 * that overlap — the second one runs while the first is still holding whatever
 * it took out before calling the provider.
 *
 * @param callable $run Endpoint invocation.
 * @return array {status, body, raw, ended}
 */
$bmcCapturePublicResponse = function (callable $run) {
    $captured = ['status' => 200, 'body' => null, 'raw' => '', 'ended' => false];

    $statusFilter = function ($header, $code) use (&$captured) {
        $captured['status'] = (int) $code;

        return $header;
    };

    $dieHandler = function () {
        return function ($message = '', $title = '', $args = []) {
            throw new BmcHaltedPublicRequest();
        };
    };

    // A distinct closure per capture, never a shared named callback: a nested
    // capture removing a callback the outer one still needs would leave the
    // outer response to end the process instead of being caught.
    $ajaxFilter = function () {
        return true;
    };

    add_filter('wp_doing_ajax', $ajaxFilter);
    add_filter('status_header', $statusFilter, 10, 2);
    add_filter('wp_die_handler', $dieHandler);
    add_filter('wp_die_ajax_handler', $dieHandler);
    add_filter('wp_die_json_handler', $dieHandler);

    $baseLevel = ob_get_level();
    ob_start();

    try {
        $run();
    } catch (BmcHaltedPublicRequest $halted) {
        $captured['ended'] = true;
    } finally {
        while (ob_get_level() > $baseLevel) {
            $captured['raw'] = ob_get_clean() . $captured['raw'];
        }

        remove_filter('wp_doing_ajax', $ajaxFilter);
        remove_filter('status_header', $statusFilter, 10);
        remove_filter('wp_die_handler', $dieHandler);
        remove_filter('wp_die_ajax_handler', $dieHandler);
        remove_filter('wp_die_json_handler', $dieHandler);
    }

    $captured['body'] = json_decode($captured['raw'], true);

    return $captured;
};

/** Every guard row currently stored. */
$bmcGuardRows = function ($type = null) {
    global $wpdb;

    if ($type === null) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}buymecoffee_request_guard");
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}buymecoffee_request_guard WHERE guard_type = %s",
        $type
    ));
};

/** Read one guard row by the storage key a claim reported. */
$bmcGuardRow = function ($key) {
    global $wpdb;

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}buymecoffee_request_guard WHERE guard_key = %s",
        $key
    ));
};

/** Move a guard row's lease so a lapse or a long wait can be observed. */
$bmcExpireGuardRow = function ($key, $offset) {
    global $wpdb;

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}buymecoffee_request_guard SET expires_at = %d WHERE guard_key = %s",
        time() + $offset,
        $key
    ));
};

/** Start a test from an empty guard table and an unmemoized probe. */
$bmcClearGuard = function () {
    global $wpdb;

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DELETE FROM {$wpdb->prefix}buymecoffee_request_guard");

    PublicRequestGuard::resetRuntimeState();
};

/** A syntactically valid, unique idempotency key. */
$bmcIdemKey = function ($label) {
    return 'bmcidem-' . substr(hash('sha256', (string) $label), 0, 24);
};

/** Put a complete public donation request into the superglobals. */
$bmcSubmissionRequest = function (array $overrides = []) use ($bmcIdemKey) {
    $request = array_merge([
        'action'            => 'buymecoffee_submit',
        'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
        'idempotency_key'   => $bmcIdemKey('attempt-' . wp_generate_password(12, false, false)),
        'payment_method'    => 'paypal',
        'is_recurring'      => 'no',
        'form_data'         => [
            ['name' => 'wpm-supporter-name', 'value' => 'Guard Donor'],
            ['name' => 'wpm-supporter-email', 'value' => 'guard-donor@example.com'],
            ['name' => 'buymecoffee_amount', 'value' => '5'],
            ['name' => 'buymecoffee_quantity', 'value' => '1'],
        ],
    ], $overrides);

    // A null override means "omit this field entirely".
    foreach ($request as $field => $value) {
        if ($value === null) {
            unset($request[$field]);
        }
    }

    $_REQUEST = $request;
    $_POST    = $request;

    return $request;
};

/** Count the supporter rows a submission test created. */
$bmcCountSubmissions = function ($email = 'guard-donor@example.com') {
    global $wpdb;

    return [
        'supporters' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}buymecoffee_supporters WHERE supporters_email = %s",
            $email
        )),
        'transactions' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}buymecoffee_transactions t
             INNER JOIN {$wpdb->prefix}buymecoffee_supporters s ON s.id = t.entry_id
             WHERE s.supporters_email = %s",
            $email
        )),
    ];
};

/** Enable PayPal Standard, which needs no outbound call to start a donation. */
$bmcPayPalStandardSettings = function () {
    update_option('buymecoffee_payment_settings_paypal', [
        'enable'       => 'yes',
        'payment_mode' => 'test',
        'payment_type' => 'standard',
        'paypal_email' => 'merchant@example.com',
    ], false);
};

/** Enable PayPal Pro, which verifies and captures orders through the REST API. */
$bmcPayPalProSettings = function () {
    update_option('buymecoffee_payment_settings_paypal', [
        'enable'          => 'yes',
        'payment_mode'    => 'test',
        'payment_type'    => 'pro',
        'paypal_email'    => 'merchant@example.com',
        'test_public_key' => 'client-id',
        'test_secret_key' => 'client-secret',
    ], false);
};

/** A PayPal donation waiting to be confirmed or notified about. */
$bmcMakePayPalDonation = function ($status = 'pending', $chargeId = '') {
    $hash = 'bmc_paypal_' . wp_generate_password(12, false, false);

    $supporterId = (int) buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
        'supporters_name'  => 'PayPal Donor',
        'supporters_email' => 'paypal-donor@example.com',
        'payment_status'   => $status,
        'entry_hash'       => $hash,
        'payment_total'    => 2500,
        'coffee_count'     => 1,
        'payment_method'   => 'paypal',
        'payment_mode'     => 'test',
        'status'           => 'new',
        'created_at'       => current_time('mysql'),
        'updated_at'       => current_time('mysql'),
    ]);

    $transactionId = (int) buyMeCoffeeQuery()->table('buymecoffee_transactions')->insert([
        'entry_id'         => $supporterId,
        'entry_hash'       => $hash,
        'transaction_type' => 'one_time',
        'payment_method'   => 'paypal',
        'payment_total'    => 2500,
        'status'           => $status,
        'currency'         => 'USD',
        'payment_mode'     => 'test',
        'charge_id'        => $chargeId,
        'created_at'       => current_time('mysql'),
        'updated_at'       => current_time('mysql'),
    ]);

    return [
        'hash'           => $hash,
        'supporter_id'   => $supporterId,
        'transaction_id' => $transactionId,
    ];
};

/** Build a wp_remote_request() response for a PayPal JSON body. */
$bmcPayPalBody = function ($body, $code = 200) {
    return [
        'headers'  => [],
        'body'     => is_string($body) ? $body : wp_json_encode($body),
        'response' => ['code' => $code, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
};

/** A captured PayPal order as the REST API returns it. */
$bmcPayPalOrder = function ($orderId, $hash, $status = 'COMPLETED', $captureId = '') {
    return [
        'id'             => $orderId,
        'status'         => $status,
        'purchase_units' => [[
            'reference_id' => $hash,
            'amount'       => ['value' => '25.00', 'currency_code' => 'USD'],
            'payments'     => ['captures' => [['id' => $captureId ?: ('CAPTURE-' . $orderId)]]],
        ]],
    ];
};

/** The current status of a transaction row. */
$bmcTransactionStatus = function ($transactionId) {
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}buymecoffee_transactions WHERE id = %d",
        $transactionId
    ));
};

$suite->test('a request past its route ceiling is refused with 413, measured on the body that actually arrived', function ($test) use ($bmcCapturePublicResponse, $bmcSubmissionRequest, $bmcCountSubmissions, $bmcPayPalStandardSettings, $bmcClearGuard) {
    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $_SERVER['REMOTE_ADDR']    = '203.0.113.10';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // A declared oversize is refused before the body is touched at all.
    $_SERVER['CONTENT_LENGTH'] = (string) (2 * 1024 * 1024);

    $decision = PublicRequestGuard::checkSize('submission');
    $test->assertFalse($decision['allowed'], 'A body past the route ceiling must not be allowed');
    $test->assertSame('request_too_large', $decision['code']);
    $test->assertSame(413, $decision['http']);

    $before = $bmcCountSubmissions();
    $bmcSubmissionRequest();
    $response = $bmcCapturePublicResponse(function () {
        (new SubmissionHandler())->handleSubmission();
    });

    $test->assertSame(413, $response['status'], 'The endpoint itself must fail closed');
    $test->assertFalse($response['body']['success']);
    $test->assertSame('request_too_large', $response['body']['data']['code'], 'The error payload must stay stable');
    $test->assertSame($before, $bmcCountSubmissions(), 'A refused request must write nothing');

    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests) {
        $requests[] = $url;

        return $pre;
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        // Content-Length is a claim. With none at all, and with an understated
        // one, the Stripe webhook still measures what it really read.
        $oversizedEvent = str_repeat('a', 512 * 1024 + 64);

        foreach ([null, '32'] as $declared) {
            unset($_SERVER['CONTENT_LENGTH']);
            if ($declared !== null) {
                $_SERVER['CONTENT_LENGTH'] = $declared;
            }

            $outcome = (new Stripe())->processIncomingEvent(null, $oversizedEvent);

            $test->assertSame('request_too_large', $outcome['code'], 'The real body length must decide');
            $test->assertSame(413, $outcome['http']);
            $test->assertSame(0, count($requests), 'An oversized webhook body must not be re-fetched from Stripe');
        }

        // PayPal enforces the body it was handed in exactly the same way.
        $oversizedIpn = 'txn_id=BMC-BIG&payload=' . str_repeat('b', 128 * 1024);

        foreach ([null, '32'] as $declared) {
            unset($_SERVER['CONTENT_LENGTH']);
            if ($declared !== null) {
                $_SERVER['CONTENT_LENGTH'] = $declared;
            }

            $outcome = (new IPN())->processIncomingIpn($oversizedIpn);

            $test->assertSame('request_too_large', $outcome['code']);
            $test->assertSame(413, $outcome['http']);
            $test->assertSame(0, count($requests), 'An oversized IPN must never be echoed back to PayPal');
        }

        // A body of a normal size passes the very same checks and is processed.
        unset($_SERVER['CONTENT_LENGTH']);
        $test->assertTrue(PublicRequestGuard::checkSize('submission')['allowed'], 'A normal donation body must pass');

        $normal = (new Stripe())->processIncomingEvent(null, wp_json_encode([
            'id'   => 'evt_guard_normal_size',
            'type' => 'charge.succeeded',
        ]));

        $test->assertFalse($normal['code'] === 'request_too_large', 'A normal webhook body must get past the ceiling');
        $test->assertSame(1, count($requests), 'A normal webhook body is authenticated against Stripe');
    } finally {
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('rate limits are exact at their boundary and no increment is ever lost or duplicated', function ($test) use ($bmcClearGuard, $bmcGuardRows) {
    $bmcClearGuard();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.11';

    $bucket = 'rate|guard-test|boundary|' . PublicRequestGuard::clientId();

    for ($hit = 1; $hit <= 5; $hit++) {
        $result = PublicRequestGuard::consume($bucket, 5, MINUTE_IN_SECONDS);
        $test->assertTrue($result['allowed'], "Hit {$hit} is inside the limit and must be allowed");
        $test->assertSame($hit, $result['count'], 'Every hit must be counted exactly once');

        // A worker that shares nothing but the database still continues the same
        // window: the count lives in the row, never in a cache or in this process.
        wp_cache_flush();
    }

    $denied = PublicRequestGuard::consume($bucket, 5, MINUTE_IN_SECONDS);
    $test->assertFalse($denied['allowed'], 'The hit past the limit must be refused');
    $test->assertSame(6, $denied['count'], 'The refused hit is still counted, so it cannot be lost');
    $test->assertTrue($denied['retry_after'] > 0, 'A refusal must say how long to wait');
    $test->assertTrue($denied['retry_after'] <= MINUTE_IN_SECONDS, 'The wait must not exceed the window');

    $rows = $bmcGuardRows();
    $test->assertSame(1, count($rows), 'One window is one row, so parallel workers contend on one key');
    $test->assertSame(6, (int) $rows[0]->counter, 'No increment may be lost or applied twice');
    $test->assertSame('rate', $rows[0]->guard_type);

    // Ten consumers of one bucket are handed ten distinct positions. That is the
    // property a read-then-write limiter loses: two of them would read the same
    // count and write the same value back, and one hit would vanish.
    $fresh  = 'rate|guard-test|distinct|' . PublicRequestGuard::clientId();
    $counts = [];
    for ($hit = 1; $hit <= 10; $hit++) {
        wp_cache_flush();
        $counts[] = PublicRequestGuard::consume($fresh, 100, MINUTE_IN_SECONDS)['count'];
    }

    $test->assertSame(range(1, 10), $counts, 'Every consumer must be handed its own position in the window');
    $test->assertSame(10, count(array_unique($counts)), 'No two consumers may be given the same count');

    // A lapsed window starts again from one, and does so in the same statement.
    global $wpdb;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}buymecoffee_request_guard SET expires_at = %d WHERE guard_key = %s",
        time() - 5,
        $rows[0]->guard_key
    ));

    $rolled = PublicRequestGuard::consume($bucket, 5, MINUTE_IN_SECONDS);
    $test->assertTrue($rolled['allowed'], 'A new window must be allowed again');
    $test->assertSame(1, $rolled['count'], 'A lapsed window restarts at one');
    $test->assertSame(2, count($bmcGuardRows()), 'A rolled window must reuse its row, not add one');

    // A limit of zero is the documented "off" setting.
    $test->assertTrue(PublicRequestGuard::consume($bucket, 0, MINUTE_IN_SECONDS)['allowed']);
});

$suite->test('a forwarded client IP is ignored unless a trusted resolver supplies one', function ($test) use ($bmcClearGuard, $bmcGuardRows) {
    $bmcClearGuard();

    $_SERVER['REMOTE_ADDR']          = '198.51.100.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';
    $_SERVER['HTTP_CLIENT_IP']       = '7.7.7.7';
    $_SERVER['HTTP_X_REAL_IP']       = '6.6.6.6';

    $test->assertSame('198.51.100.5', PublicRequestGuard::clientIp(), 'Only REMOTE_ADDR is trusted by default');
    $first = PublicRequestGuard::clientId();

    // Rotating every forwarding header buys an attacker nothing at all.
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 1.1.1.1';
    $_SERVER['HTTP_CLIENT_IP']       = '5.5.5.5';
    $test->assertSame($first, PublicRequestGuard::clientId(), 'A rotated forwarding header must not create a new bucket');

    for ($hit = 1; $hit <= 3; $hit++) {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.' . $hit;
        PublicRequestGuard::consume('rate|guard-test|forwarded|' . PublicRequestGuard::clientId(), 3, MINUTE_IN_SECONDS);
    }

    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.250';
    $test->assertFalse(
        PublicRequestGuard::consume('rate|guard-test|forwarded|' . PublicRequestGuard::clientId(), 3, MINUTE_IN_SECONDS)['allowed'],
        'The limit must still trip while the forwarding header changes on every request'
    );
    $test->assertSame(1, count($bmcGuardRows()), 'Every request must land in the same bucket');

    // A host behind a proxy opts in explicitly, and only then is it honoured.
    $resolver = function () {
        return '203.0.113.99';
    };
    add_filter('buymecoffee_trusted_client_ip', $resolver);

    try {
        $test->assertSame('203.0.113.99', PublicRequestGuard::clientIp(), 'An explicitly resolved address must win');
        $test->assertFalse($first === PublicRequestGuard::clientId(), 'A different client must get a different bucket');
    } finally {
        remove_filter('buymecoffee_trusted_client_ip', $resolver);
    }

    // A rubbish resolved value falls back rather than poisoning the bucket key.
    $broken = function () {
        return 'not-an-ip';
    };
    add_filter('buymecoffee_trusted_client_ip', $broken);

    try {
        $test->assertSame('198.51.100.5', PublicRequestGuard::clientIp());
    } finally {
        remove_filter('buymecoffee_trusted_client_ip', $broken);
    }

    // Nothing readable is ever persisted.
    foreach ($bmcGuardRows() as $row) {
        $test->assertNotContains('198.51.100.5', $row->guard_key, 'A raw address must never be stored');
        $test->assertTrue((bool) preg_match('/\A[a-f0-9]{40}\z/', $row->guard_key), 'Guard keys must be keyed digests');
    }
});

$suite->test('a submission without a usable idempotency key is refused and writes nothing', function ($test) use ($bmcCapturePublicResponse, $bmcSubmissionRequest, $bmcCountSubmissions, $bmcPayPalStandardSettings, $bmcClearGuard, $bmcGuardRows, $bmcIdemKey) {
    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.30';

    // No key at all. This is what an attacker sends, and what a double-clicking
    // browser used to send: without it there is nothing to make the request
    // repeatable, so it is refused rather than served unprotected.
    $bmcSubmissionRequest(['idempotency_key' => null]);
    $missing = $bmcCapturePublicResponse(function () {
        (new SubmissionHandler())->handleSubmission();
    });

    $test->assertSame(400, $missing['status']);
    $test->assertSame('idempotency_key_required', $missing['body']['data']['code']);
    $test->assertSame(['supporters' => 0, 'transactions' => 0], $bmcCountSubmissions(), 'A keyless request must write nothing');
    $test->assertSame(0, count($bmcGuardRows('claim')), 'A keyless request must not take out a claim');

    foreach (['', 'short', str_repeat('x', 129), 'has spaces in it here'] as $bad) {
        $bmcSubmissionRequest(['idempotency_key' => $bad]);
        $refused = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(400, $refused['status'], 'A malformed key must be refused');
        $test->assertTrue(
            in_array($refused['body']['data']['code'], ['idempotency_key_required', 'invalid_idempotency_key'], true),
            'A malformed key must be named as such'
        );
    }

    $test->assertSame(['supporters' => 0, 'transactions' => 0], $bmcCountSubmissions());

    // A host that has to serve a client which cannot produce a key opts out
    // deliberately, and only then does the old unprotected behaviour return.
    $optOut = '__return_true';
    add_filter('buymecoffee_allow_unkeyed_submission', $optOut);

    try {
        $bmcSubmissionRequest(['idempotency_key' => null]);
        $legacy = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(200, $legacy['status'], 'The explicit opt-out must restore the old behaviour');
        $test->assertSame(1, $bmcCountSubmissions()['supporters']);
    } finally {
        remove_filter('buymecoffee_allow_unkeyed_submission', $optOut);
    }

    // And with a well-formed key the donation goes through as normal.
    $bmcSubmissionRequest(['idempotency_key' => $bmcIdemKey('well-formed')]);
    $accepted = $bmcCapturePublicResponse(function () {
        (new SubmissionHandler())->handleSubmission();
    });

    $test->assertSame(200, $accepted['status']);
    $test->assertNotEmpty($accepted['body']['data']['redirectTo'], 'PayPal Standard must still hand back its redirect');
    $test->assertSame(2, $bmcCountSubmissions()['supporters']);
});

$suite->test('a retried or concurrent submission creates no second donation and no second provider payment', function ($test) use ($bmcCapturePublicResponse, $bmcSubmissionRequest, $bmcCountSubmissions, $bmcStripeSettings, $bmcStripeBody, $bmcClearGuard, $bmcIdemKey) {
    global $wpdb;

    $bmcClearGuard();
    $bmcStripeSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.20';

    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests, $bmcStripeBody) {
        $requests[] = $url;

        if (strpos($url, '/customers') !== false) {
            return $bmcStripeBody(['id' => 'cus_guard_dup']);
        }

        return $bmcStripeBody([
            'id'            => 'pi_guard_dup_' . count($requests),
            'object'        => 'payment_intent',
            'amount'        => 500,
            'currency'      => 'usd',
            'status'        => 'requires_payment_method',
            'client_secret' => 'pi_guard_dup_secret',
        ]);
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $key = $bmcIdemKey('retry-same-attempt');

        $bmcSubmissionRequest(['payment_method' => 'stripe', 'idempotency_key' => $key]);
        $first = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(200, $first['status']);
        $test->assertTrue($first['body']['success'], 'The first attempt must be accepted');
        $test->assertSame(2, count($requests), 'The first attempt creates the customer and the intent');
        $test->assertSame(['supporters' => 1, 'transactions' => 1], $bmcCountSubmissions());

        // The intent is bound to the order before the browser ever sees it, so
        // the confirmation that comes back can be matched without asking Stripe.
        $boundIntent = $wpdb->get_var($wpdb->prepare(
            "SELECT t.charge_id FROM {$wpdb->prefix}buymecoffee_transactions t
             INNER JOIN {$wpdb->prefix}buymecoffee_supporters s ON s.id = t.entry_id
             WHERE s.supporters_email = %s",
            'guard-donor@example.com'
        ));
        $test->assertSame('pi_guard_dup_2', $boundIntent, 'Checkout must record the intent it created');

        // The browser never saw the answer and retries the very same attempt.
        $bmcSubmissionRequest(['payment_method' => 'stripe', 'idempotency_key' => $key]);
        $second = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(409, $second['status'], 'A completed attempt must not be run again');
        $test->assertSame('submission_already_completed', $second['body']['data']['code']);
        $test->assertSame(2, count($requests), 'A retry must not create a second remote payment');
        $test->assertSame(['supporters' => 1, 'transactions' => 1], $bmcCountSubmissions(), 'A retry must not create a second row');

        // A second request that arrives while the first is still running — a
        // double click, or two tabs — is refused before it writes or pays.
        $concurrentKey = $bmcIdemKey('still-running');
        $inFlight = PublicRequestGuard::claim('submission', $concurrentKey);
        $test->assertTrue($inFlight['acquired'], 'The first worker takes the lease');

        $bmcSubmissionRequest(['payment_method' => 'stripe', 'idempotency_key' => $concurrentKey]);
        $concurrent = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(409, $concurrent['status'], 'A concurrent attempt must be refused');
        $test->assertSame('submission_in_progress', $concurrent['body']['data']['code']);
        $test->assertSame(2, count($requests), 'A concurrent attempt must not reach Stripe');
        $test->assertSame(['supporters' => 1, 'transactions' => 1], $bmcCountSubmissions(), 'A concurrent attempt must write nothing');

        // A different attempt is a different key, and is a real donation again.
        $bmcSubmissionRequest(['payment_method' => 'stripe', 'idempotency_key' => $bmcIdemKey('new-attempt')]);
        $third = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(200, $third['status']);
        $test->assertSame(4, count($requests), 'A new attempt may create a new payment');
        $test->assertSame(['supporters' => 2, 'transactions' => 2], $bmcCountSubmissions());

        // The same browser may move between networks before retrying. The attempt
        // key, not its current address, remains the idempotency boundary.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.21';
        $bmcSubmissionRequest(['payment_method' => 'stripe', 'idempotency_key' => $key]);
        $other = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(409, $other['status'], 'An address change must not reopen the same attempt');
        $test->assertSame('submission_already_completed', $other['body']['data']['code']);
        $test->assertSame(2, $bmcCountSubmissions()['supporters']);
    } finally {
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('a submission refused before any side effect leaves its key reusable', function ($test) use ($bmcCapturePublicResponse, $bmcSubmissionRequest, $bmcCountSubmissions, $bmcPayPalStandardSettings, $bmcClearGuard, $bmcIdemKey) {
    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.31';

    $key = $bmcIdemKey('correctable-attempt');

    $bmcSubmissionRequest(['payment_method' => 'not-a-gateway', 'idempotency_key' => $key]);
    $refused = $bmcCapturePublicResponse(function () {
        (new SubmissionHandler())->handleSubmission();
    });

    $test->assertSame(400, $refused['status'], 'An unknown gateway is still refused');
    $test->assertSame(['supporters' => 0, 'transactions' => 0], $bmcCountSubmissions(), 'Validation must not write anything');
    $test->assertSame(
        null,
        PublicRequestGuard::readClaim('submission', $key),
        'A validation failure must never consume the key'
    );

    // The donor corrects the request and retries the same attempt.
    $bmcSubmissionRequest(['idempotency_key' => $key]);
    $accepted = $bmcCapturePublicResponse(function () {
        (new SubmissionHandler())->handleSubmission();
    });

    $test->assertSame(200, $accepted['status'], 'The corrected retry must be accepted');
    $test->assertTrue($accepted['body']['success']);
    $test->assertSame(['supporters' => 1, 'transactions' => 1], $bmcCountSubmissions());

    $consumed = PublicRequestGuard::readClaim('submission', $key);
    $test->assertSame(PublicRequestGuard::STATE_COMPLETED, $consumed['state'], 'A donation that was created consumes its key');
});

$suite->test('submissions are rate limited before any row is written', function ($test) use ($bmcCapturePublicResponse, $bmcSubmissionRequest, $bmcCountSubmissions, $bmcPayPalStandardSettings, $bmcClearGuard) {
    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.40';

    $tighten = function ($limit, $route, $bucket) {
        return ($route === 'submission' && $bucket === 'burst') ? 2 : $limit;
    };
    add_filter('buymecoffee_public_request_rate_limit', $tighten, 10, 3);

    try {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $bmcSubmissionRequest();
            $allowed = $bmcCapturePublicResponse(function () {
                (new SubmissionHandler())->handleSubmission();
            });
            $test->assertSame(200, $allowed['status'], "Donation {$attempt} is inside the limit");
        }

        $test->assertSame(2, $bmcCountSubmissions()['supporters']);

        $bmcSubmissionRequest();
        $limited = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(429, $limited['status'], 'A flood must be answered with 429');
        $test->assertSame('rate_limited', $limited['body']['data']['code']);
        $test->assertSame(2, $bmcCountSubmissions()['supporters'], 'A limited request must not reach the database');

        // Another visitor is unaffected by the first one hitting the limit.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.41';
        $bmcSubmissionRequest();
        $other = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(200, $other['status'], 'One flooding client must not block everybody else');
    } finally {
        remove_filter('buymecoffee_public_request_rate_limit', $tighten, 10);
    }
});

$suite->test('a Stripe confirmation for an intent this site never created reaches neither Stripe nor a lease', function ($test) use ($bmcCapturePublicResponse, $bmcStripeSettings, $bmcMakeOneTimePurchase, $bmcClearGuard, $bmcGuardRows) {
    $bmcClearGuard();
    $bmcStripeSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.52';

    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests) {
        $requests[] = $url;

        return $pre;
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'intentId'          => 'pi_never_created_here',
        ];

        $unknown = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        $test->assertSame(404, $unknown['status'], 'An unrecognised intent must be refused');
        $test->assertSame('payment_intent_not_recognized', $unknown['body']['data']['code']);
        $test->assertSame(0, count($requests), 'An unrecognised intent must not be looked up at Stripe');
        $test->assertSame(0, count($bmcGuardRows('claim')), 'An unrecognised intent must not be able to hold a lease');

        // A real intent, but presented with a subscription it was never bound
        // to: refused on local state, again without a provider call.
        $purchase = $bmcMakeOneTimePurchase();
        $_REQUEST['intentId']       = 'pi_' . $purchase['suffix'];
        $_REQUEST['subscriptionId'] = 4242;

        $mismatched = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        $test->assertSame(403, $mismatched['status']);
        $test->assertSame('subscription_mismatch', $mismatched['body']['data']['code']);
        $test->assertSame(0, count($requests), 'A mismatched subscription claim must not be checked at Stripe');
        $test->assertSame(0, count($bmcGuardRows('claim')), 'A mismatched claim must not hold a lease either');
    } finally {
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('two Stripe confirmations of one intent cannot both reach Stripe, and a settled one replays locally', function ($test) use ($bmcCapturePublicResponse, $bmcStripeSettings, $bmcStripeBody, $bmcMakeOneTimePurchase, $bmcServiceSharesTestTransaction, $bmcClearGuard) {
    global $wpdb;

    $bmcClearGuard();
    $bmcStripeSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.50';

    $restoreTransactions = $bmcServiceSharesTestTransaction();

    $purchase = $bmcMakeOneTimePurchase();
    $intentId = 'pi_' . $purchase['suffix'];

    $requests   = [];
    $concurrent = null;

    $stub = function ($pre, $args, $url) use (&$requests, &$concurrent, $bmcStripeBody, $bmcCapturePublicResponse, $purchase, $intentId) {
        $requests[] = $url;

        // While this confirmation is at Stripe, a second one for the same intent
        // arrives — deliberately from a different address, because a lease
        // scoped to whoever is asking would let exactly that pair race.
        if ($concurrent === null) {
            $elsewhere = $_SERVER['REMOTE_ADDR'];
            $_SERVER['REMOTE_ADDR'] = '198.51.100.44';

            $concurrent = $bmcCapturePublicResponse(function () {
                (new Stripe())->paymentConfirmation();
            });

            $_SERVER['REMOTE_ADDR'] = $elsewhere;
        }

        return $bmcStripeBody([
            'id'              => $intentId,
            'object'          => 'payment_intent',
            'status'          => 'succeeded',
            'amount'          => 2500,
            'amount_received' => 2500,
            'currency'        => 'usd',
            'livemode'        => false,
            'metadata'        => ['ref_id' => $purchase['order_hash']],
            'charges'         => ['data' => [[
                'payment_method_details' => ['card' => ['last4' => '4242', 'brand' => 'visa']],
            ]]],
        ]);
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'intentId'          => $intentId,
        ];

        $confirmed = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        $test->assertSame(200, $confirmed['status'], 'The confirmation that holds the lease completes');
        $test->assertSame('paid', $confirmed['body']['data']['payment_status']);
        $test->assertSame(1, count($requests), 'Only one of the two confirmations may reach Stripe');

        $test->assertNotEmpty($concurrent, 'The concurrent confirmation must have run');
        $test->assertSame(409, $concurrent['status'], 'A confirmation of an operation already running is a conflict');
        $test->assertSame('confirmation_in_progress', $concurrent['body']['data']['code']);
        $test->assertFalse($concurrent['body']['success'], 'A conflicted confirmation must not report success');

        // Now that it is settled, further confirmations are answered from the
        // rows alone: no customer, intent, invoice or subscription lookup.
        $wpdb->update(
            $wpdb->prefix . 'buymecoffee_transactions',
            ['payment_note' => wp_json_encode(['id' => $intentId, 'status' => 'succeeded'])],
            ['id' => $purchase['transaction_id']]
        );

        $replayed = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        $test->assertSame(200, $replayed['status']);
        $test->assertSame(1, count($requests), 'A settled confirmation must not call Stripe again');
        $test->assertSame('paid', $replayed['body']['data']['payment_status']);
        $test->assertSame('succeeded', $replayed['body']['data']['stripe_status'], 'The stored intent status is replayed');
        $test->assertSame($purchase['transaction_id'], $replayed['body']['data']['transaction_id']);
        $test->assertTrue($replayed['body']['data']['access_active'], 'A paid membership must still report its access');
        $test->assertTrue($replayed['body']['data']['replayed']);
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        $restoreTransactions();
    }
});

$suite->test('a Stripe confirmation that failed at the provider gives its lease straight back', function ($test) use ($bmcCapturePublicResponse, $bmcStripeSettings, $bmcStripeBody, $bmcMakeOneTimePurchase, $bmcServiceSharesTestTransaction, $bmcClearGuard) {
    $bmcClearGuard();
    $bmcStripeSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.53';

    $restoreTransactions = $bmcServiceSharesTestTransaction();

    $purchase = $bmcMakeOneTimePurchase();
    $intentId = 'pi_' . $purchase['suffix'];

    $requests = [];
    $failing  = true;

    $stub = function ($pre, $args, $url) use (&$requests, &$failing, $bmcStripeBody, $purchase, $intentId) {
        $requests[] = $url;

        if ($failing) {
            return new WP_Error('http_request_failed', 'Stripe is unreachable');
        }

        return $bmcStripeBody([
            'id'              => $intentId,
            'object'          => 'payment_intent',
            'status'          => 'succeeded',
            'amount'          => 2500,
            'amount_received' => 2500,
            'currency'        => 'usd',
            'livemode'        => false,
            'metadata'        => ['ref_id' => $purchase['order_hash']],
        ]);
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'intentId'          => $intentId,
        ];

        $failed = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        $test->assertSame(400, $failed['status'], 'A provider failure is reported as a failure');
        $test->assertSame(1, count($requests));
        $test->assertSame(
            null,
            PublicRequestGuard::readClaim('stripe_confirmation', 'intent|' . $intentId),
            'A failed confirmation must not leave the intent locked'
        );

        // The donor tries again, and this time it works: the lease was never
        // burned by a failure that a retry could fix.
        $failing = false;

        $retried = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        $test->assertSame(200, $retried['status'], 'The retry must be able to reach Stripe again');
        $test->assertSame('paid', $retried['body']['data']['payment_status']);
        $test->assertSame(2, count($requests), 'The retry really did reach Stripe');

        $settled = PublicRequestGuard::readClaim('stripe_confirmation', 'intent|' . $intentId);
        $test->assertSame(PublicRequestGuard::STATE_COMPLETED, $settled['state'], 'A settled confirmation is recorded as done');
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        $restoreTransactions();
    }
});

$suite->test('replaying one Stripe intent is throttled per caller without locking out its donor', function ($test) use ($bmcCapturePublicResponse, $bmcStripeSettings, $bmcMakeOneTimePurchase, $bmcClearGuard) {
    global $wpdb;

    $bmcClearGuard();
    $bmcStripeSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.54';

    $purchase = $bmcMakeOneTimePurchase('paid');
    $intentId = 'pi_' . $purchase['suffix'];

    $wpdb->update(
        $wpdb->prefix . 'buymecoffee_transactions',
        ['payment_note' => wp_json_encode(['id' => $intentId, 'status' => 'succeeded'])],
        ['id' => $purchase['transaction_id']]
    );

    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests) {
        $requests[] = $url;

        return $pre;
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'intentId'          => $intentId,
        ];

        $statuses = [];
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $statuses[] = $bmcCapturePublicResponse(function () {
                (new Stripe())->paymentConfirmation();
            })['status'];
        }

        $test->assertSame(
            [200, 200, 200, 200, 200, 200, 429, 429],
            $statuses,
            'One caller may replay one intent a bounded number of times per minute'
        );
        $test->assertSame(0, count($requests), 'Not one replay may reach Stripe');

        // The bucket belongs to the caller, so a flood cannot spend the budget
        // the real donor's own browser needs for the same intent.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.55';
        $donor = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        $test->assertSame(200, $donor['status'], 'One caller flooding an intent must not lock out its donor');
    } finally {
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('a duplicate Stripe delivery costs no second fetch, and one still running stays retryable', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcWatchPaymentSideEffects, $bmcServiceSharesTestTransaction, $bmcClearGuard) {
    $bmcClearGuard();
    $bmcStripeSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.60';

    $restoreTransactions = $bmcServiceSharesTestTransaction();
    $purchase = $bmcMakeOneTimePurchase();
    $eventId  = 'evt_guard_dup_' . $purchase['suffix'];
    $event    = $bmcStripeEvent($eventId, 'charge.succeeded', $bmcStripeCharge($purchase['order_hash']));

    $second   = $bmcMakeOneTimePurchase();
    $secondId = 'evt_guard_busy_' . $second['suffix'];

    $events = [
        $eventId  => $event,
        $secondId => $bmcStripeEvent($secondId, 'charge.succeeded', $bmcStripeCharge($second['order_hash'])),
    ];

    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests, $bmcStripeBody, $events) {
        $requests[] = $url;

        foreach ($events as $id => $payload) {
            if (strpos($url, 'events/' . $id) !== false) {
                return $bmcStripeBody($payload);
            }
        }

        return $bmcStripeBody(['error' => ['message' => 'No such event']], 404);
    };
    add_filter('pre_http_request', $stub, 10, 3);
    list($log, $stopWatching) = $bmcWatchPaymentSideEffects();

    try {
        $claimed = (object) ['id' => $eventId, 'type' => 'charge.succeeded'];

        $first = (new Stripe())->processIncomingEvent($claimed);
        $test->assertSame('payment_status_updated', $first['code']);
        $test->assertSame('paid', $bmcPaymentState($purchase)['transaction']);
        $test->assertSame(1, count($requests), 'The event must be authenticated against Stripe once');
        $test->assertSame(1, count($log['status']), 'The payment hook fires once');

        $duplicate = (new Stripe())->processIncomingEvent($claimed);
        $test->assertSame('duplicate_event', $duplicate['code'], 'A redelivery must be recognised');
        $test->assertSame(200, $duplicate['http'], 'Stripe must not be asked to retry a finished event');
        $test->assertSame(1, count($requests), 'A redelivery of a consumed event must not re-fetch it');
        $test->assertSame(1, count($log['status']), 'A redelivery must not fire the payment hook again');
        $test->assertSame('paid', $bmcPaymentState($purchase)['transaction']);

        // A second delivery of an event another worker is still applying must
        // NOT be answered 200: that would let Stripe drop it while the worker
        // holding it may still fail, and the event would be lost for good.
        $inFlight = PublicRequestGuard::claim('stripe_event', $secondId);
        $test->assertTrue($inFlight['acquired'], 'The first worker takes the lease');

        $busy = (new Stripe())->processIncomingEvent((object) ['id' => $secondId, 'type' => 'charge.succeeded']);

        $test->assertSame('event_in_progress', $busy['code'], 'An event being applied elsewhere is a conflict');
        $test->assertSame(409, $busy['http'], 'A conflicted delivery must stay retryable');
        $test->assertTrue($busy['retry_after'] > 0, 'A conflicted delivery must be told when to return');
        $test->assertSame('pending', $bmcPaymentState($second)['transaction'], 'The conflicted delivery must change nothing');
        $test->assertSame(1, count($log['status']), 'The conflicted delivery must not fire the payment hook');

        // Once that worker gives the lease back, the redelivery applies normally.
        PublicRequestGuard::releaseClaim($inFlight['key'], $inFlight['owner']);

        $redelivered = (new Stripe())->processIncomingEvent((object) ['id' => $secondId, 'type' => 'charge.succeeded']);
        $test->assertSame('payment_status_updated', $redelivered['code'], 'The released event must still be applied');
        $test->assertSame('paid', $bmcPaymentState($second)['transaction']);
    } finally {
        $stopWatching();
        remove_filter('pre_http_request', $stub, 10);
        $restoreTransactions();
    }
});

$suite->test('unauthenticated Stripe deliveries are bounded per address without blocking genuine ones', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcServiceSharesTestTransaction, $bmcClearGuard) {
    $bmcClearGuard();
    $bmcStripeSettings();

    $restoreTransactions = $bmcServiceSharesTestTransaction();

    $genuine  = $bmcMakeOneTimePurchase();
    $genuineId = 'evt_guard_genuine_' . $genuine['suffix'];
    $followUp  = $bmcMakeOneTimePurchase();
    $followUpId = 'evt_guard_genuine2_' . $followUp['suffix'];

    $events = [
        $genuineId  => $bmcStripeEvent($genuineId, 'charge.succeeded', $bmcStripeCharge($genuine['order_hash'])),
        $followUpId => $bmcStripeEvent($followUpId, 'charge.succeeded', $bmcStripeCharge($followUp['order_hash'])),
    ];

    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests, $bmcStripeBody, $events) {
        $requests[] = $url;

        foreach ($events as $id => $payload) {
            if (strpos($url, 'events/' . $id) !== false) {
                return $bmcStripeBody($payload);
            }
        }

        return $bmcStripeBody(['error' => ['message' => 'No such event']], 404);
    };
    add_filter('pre_http_request', $stub, 10, 3);

    $tighten = function ($limit, $route, $bucket) {
        return $route === 'webhook_stripe_invalid' ? 2 : $limit;
    };
    add_filter('buymecoffee_public_request_rate_limit', $tighten, 10, 3);

    try {
        // An attacker inventing event ids from their own address.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.66';
        $codes = [];

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $codes[] = (new Stripe())->processIncomingEvent((object) [
                'id'   => 'evt_guard_invented_' . $attempt,
                'type' => 'charge.succeeded',
            ])['code'];
        }

        $test->assertSame(
            ['event_fetch_failed', 'event_fetch_failed', 'rate_limited', 'rate_limited'],
            $codes,
            'Deliveries that fail to authenticate are bounded per address'
        );
        $test->assertSame(2, count($requests), 'A bounded caller must stop reaching the Stripe API');

        // Stripe's own delivery address is untouched by that: the budget above
        // is only ever charged by a delivery that failed to authenticate, and a
        // genuine one never charges it however many arrive.
        $_SERVER['REMOTE_ADDR'] = '54.187.174.169';
        $requests = [];

        $delivered = (new Stripe())->processIncomingEvent((object) ['id' => $genuineId, 'type' => 'charge.succeeded']);
        $test->assertSame('payment_status_updated', $delivered['code'], 'A genuine delivery must still be processed');
        $test->assertSame('paid', $bmcPaymentState($genuine)['transaction']);

        $again = (new Stripe())->processIncomingEvent((object) ['id' => $followUpId, 'type' => 'charge.succeeded']);
        $test->assertSame('payment_status_updated', $again['code'], 'Genuine volume must never fill a shared-address budget');
        $test->assertSame('paid', $bmcPaymentState($followUp)['transaction']);
        $test->assertSame(2, count($requests), 'Both genuine deliveries reached Stripe');
    } finally {
        remove_filter('buymecoffee_public_request_rate_limit', $tighten, 10);
        remove_filter('pre_http_request', $stub, 10);
        $restoreTransactions();
    }
});

$suite->test('a duplicate VERIFIED PayPal notification is applied once, and a distinct one is never mistaken for it', function ($test) use ($bmcClearGuard, $bmcPayPalStandardSettings, $bmcMakePayPalDonation, $bmcTransactionStatus) {
    global $wpdb;

    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $_SERVER['REMOTE_ADDR']    = '173.0.93.10';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $donation      = $bmcMakePayPalDonation();
    $transactionId = $donation['transaction_id'];

    $calls = [];
    $stub = function ($pre, $args, $url) use (&$calls) {
        $calls[] = $url;

        return [
            'headers'  => [],
            'body'     => 'VERIFIED',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $completed = [
            'txn_type'       => 'web_accept',
            'payment_status' => 'Completed',
            'txn_id'         => 'BMC-IPN-REPLAY-1',
            'receiver_email' => 'merchant@example.com',
            'mc_currency'    => 'USD',
            'mc_gross'       => '25.00',
            'custom'         => $transactionId,
            'ipn_track_id'   => 'trackA',
        ];

        $first = (new IPN())->processIncomingIpn(http_build_query($completed));
        $test->assertSame('ipn_processed', $first['code']);
        $test->assertSame(200, $first['http']);
        $test->assertSame(1, count($calls), 'Every notification is echoed back to PayPal first');
        $test->assertSame('paid', $bmcTransactionStatus($transactionId), 'A verified completed payment settles');

        // A refund lands after it; the redelivery must not resurrect the payment.
        $wpdb->update($wpdb->prefix . 'buymecoffee_transactions', ['status' => 'refunded'], ['id' => $transactionId]);

        $duplicate = (new IPN())->processIncomingIpn(http_build_query($completed));
        $test->assertSame('duplicate_ipn', $duplicate['code'], 'A duplicate delivery must be recognised');
        $test->assertSame(200, $duplicate['http'], 'PayPal must not be asked to redeliver it forever');
        $test->assertSame('refunded', $bmcTransactionStatus($transactionId), 'A duplicate delivery must mutate nothing');
        $test->assertSame(2, count($calls), 'Authenticity is still established before anything is decided');

        // PayPal reordered the fields, which is the same message. Keying on a
        // canonical form of the whole payload still recognises it.
        $reordered = array_reverse($completed, true);
        $shuffled  = (new IPN())->processIncomingIpn(http_build_query($reordered));
        $test->assertSame('duplicate_ipn', $shuffled['code'], 'Field order must not create a new notification');

        // Two notifications that share every field the old partial key looked at
        // — type, status, amount and the local reference — but are genuinely
        // different messages. Neither may be discarded as a replay of the other.
        $wpdb->update($wpdb->prefix . 'buymecoffee_transactions', ['status' => 'pending'], ['id' => $transactionId]);

        $pendingBase = [
            'txn_type'       => 'web_accept',
            'payment_status' => 'Pending',
            'receiver_email' => 'merchant@example.com',
            'mc_currency'    => 'USD',
            'mc_gross'       => '25.00',
            'custom'         => $transactionId,
        ];

        $stageOne = (new IPN())->processIncomingIpn(http_build_query(array_merge($pendingBase, [
            'pending_reason' => 'echeck',
            'payment_date'   => '10:00:00 Jan 01, 2026 PST',
            'ipn_track_id'   => 'trackB',
        ])));

        $stageTwo = (new IPN())->processIncomingIpn(http_build_query(array_merge($pendingBase, [
            'pending_reason' => 'multi_currency',
            'payment_date'   => '11:00:00 Jan 01, 2026 PST',
            'ipn_track_id'   => 'trackC',
        ])));

        $test->assertSame('ipn_processed', $stageOne['code']);
        $test->assertSame('ipn_processed', $stageTwo['code'], 'A distinct notification must never be dropped as a duplicate');
        $test->assertSame('processing', $bmcTransactionStatus($transactionId));

        // Without a track id the identity falls back to the whole payload, and
        // is just as exact.
        $noTrack = array_merge($pendingBase, ['pending_reason' => 'address', 'payment_date' => '12:00:00 Jan 01, 2026 PST']);

        $test->assertSame('ipn_processed', (new IPN())->processIncomingIpn(http_build_query($noTrack))['code']);
        $test->assertSame('duplicate_ipn', (new IPN())->processIncomingIpn(http_build_query($noTrack))['code']);
    } finally {
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('a PayPal notification that could not be applied, or is being applied elsewhere, stays redeliverable', function ($test) use ($bmcClearGuard, $bmcPayPalStandardSettings, $bmcMakePayPalDonation, $bmcTransactionStatus) {
    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $_SERVER['REMOTE_ADDR']    = '173.0.93.11';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $donation      = $bmcMakePayPalDonation();
    $transactionId = $donation['transaction_id'];

    $calls = [];
    $stub = function ($pre, $args, $url) use (&$calls) {
        $calls[] = $url;

        return [
            'headers'  => [],
            'body'     => 'VERIFIED',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    };
    add_filter('pre_http_request', $stub, 10, 3);

    $failing = function () {
        throw new RuntimeException('storage unavailable');
    };

    try {
        $body = http_build_query([
            'txn_type'       => 'web_accept',
            'payment_status' => 'Completed',
            'txn_id'         => 'BMC-IPN-FAIL-1',
            'receiver_email' => 'merchant@example.com',
            'mc_currency'    => 'USD',
            'mc_gross'       => '25.00',
            'custom'         => $transactionId,
            'ipn_track_id'   => 'trackFail',
        ]);

        add_action('buymecoffee_paypal_action_web_accept', $failing, 1);
        $failed = (new IPN())->processIncomingIpn($body);
        remove_action('buymecoffee_paypal_action_web_accept', $failing, 1);

        $test->assertSame('processing_failed', $failed['code']);
        $test->assertSame(500, $failed['http'], 'A failed application must ask PayPal to redeliver');
        $test->assertSame('pending', $bmcTransactionStatus($transactionId), 'A failed application must change nothing');

        $redelivered = (new IPN())->processIncomingIpn($body);
        $test->assertSame('ipn_processed', $redelivered['code'], 'The redelivery must still be applied');
        $test->assertSame('paid', $bmcTransactionStatus($transactionId));

        // A notification another worker is still applying is a conflict, not a
        // duplicate: answering 200 would let PayPal drop it.
        $busyDonation = $bmcMakePayPalDonation();
        $busyBody = http_build_query([
            'txn_type'       => 'web_accept',
            'payment_status' => 'Completed',
            'txn_id'         => 'BMC-IPN-BUSY-1',
            'receiver_email' => 'merchant@example.com',
            'mc_currency'    => 'USD',
            'mc_gross'       => '25.00',
            'custom'         => $busyDonation['transaction_id'],
            'ipn_track_id'   => 'trackBusy',
        ]);

        $inFlight = PublicRequestGuard::claim('paypal_ipn', 'track|trackBusy');
        $test->assertTrue($inFlight['acquired'], 'The first worker takes the lease');

        $busy = (new IPN())->processIncomingIpn($busyBody);
        $test->assertSame('ipn_in_progress', $busy['code']);
        $test->assertSame(409, $busy['http'], 'A conflicted notification must stay retryable');
        $test->assertSame('pending', $bmcTransactionStatus($busyDonation['transaction_id']), 'It must change nothing');

        PublicRequestGuard::releaseClaim($inFlight['key'], $inFlight['owner']);

        $afterRelease = (new IPN())->processIncomingIpn($busyBody);
        $test->assertSame('ipn_processed', $afterRelease['code'], 'Once released, the redelivery applies');
        $test->assertSame('paid', $bmcTransactionStatus($busyDonation['transaction_id']));
    } finally {
        remove_action('buymecoffee_paypal_action_web_accept', $failing, 1);
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('a replayed PayPal body is throttled, and verified notifications are never charged to a shared address', function ($test) use ($bmcClearGuard, $bmcPayPalStandardSettings, $bmcMakePayPalDonation, $bmcTransactionStatus) {
    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $answer = 'INVALID';
    $calls  = [];

    $stub = function ($pre, $args, $url) use (&$calls, &$answer) {
        $calls[] = $url;

        return [
            'headers'  => [],
            'body'     => $answer,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    };
    add_filter('pre_http_request', $stub, 10, 3);

    $tighten = function ($limit, $route, $bucket) {
        return $route === 'ipn_paypal_invalid' ? 3 : $limit;
    };
    add_filter('buymecoffee_public_request_rate_limit', $tighten, 10, 3);

    try {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.77';

        $repeated = http_build_query([
            'txn_type'       => 'web_accept',
            'payment_status' => 'Completed',
            'txn_id'         => 'BMC-IPN-FLOOD',
            'receiver_email' => 'merchant@example.com',
            'mc_currency'    => 'USD',
            'mc_gross'       => '25.00',
            'custom'         => 0,
        ]);

        // One repeated body may only be echoed back to PayPal a bounded number
        // of times, whatever PayPal answers.
        $codes = [];
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $codes[] = (new IPN())->processIncomingIpn($repeated)['code'];
        }

        $test->assertSame(
            ['verification_failed', 'verification_failed', 'verification_failed', 'rate_limited', 'rate_limited', 'rate_limited'],
            $codes,
            'A repeated body is bounded, and so is unverified traffic from one address'
        );
        $test->assertSame(3, count($calls), 'The throttled attempts must not reach PayPal');

        $throttled = (new IPN())->processIncomingIpn($repeated);
        $test->assertSame(429, $throttled['http'], 'A throttled body is asked to come back later');
        $test->assertTrue($throttled['retry_after'] > 0);

        // PayPal's own delivery address is untouched: the budget above is spent
        // only by notifications PayPal refused to confirm, so a busy site behind
        // the same shared addresses is never collateral damage.
        $_SERVER['REMOTE_ADDR'] = '173.0.93.10';
        $answer = 'VERIFIED';
        $calls  = [];

        for ($notification = 1; $notification <= 6; $notification++) {
            $donation = $bmcMakePayPalDonation();

            $outcome = (new IPN())->processIncomingIpn(http_build_query([
                'txn_type'       => 'web_accept',
                'payment_status' => 'Completed',
                'txn_id'         => 'BMC-IPN-REAL-' . $notification,
                'receiver_email' => 'merchant@example.com',
                'mc_currency'    => 'USD',
                'mc_gross'       => '25.00',
                'custom'         => $donation['transaction_id'],
                'ipn_track_id'   => 'trackReal' . $notification,
            ]));

            $test->assertSame('ipn_processed', $outcome['code'], "Genuine notification {$notification} must be applied");
            $test->assertSame('paid', $bmcTransactionStatus($donation['transaction_id']));
        }

        $test->assertSame(6, count($calls), 'Every genuine notification is verified with PayPal');
    } finally {
        remove_filter('buymecoffee_public_request_rate_limit', $tighten, 10);
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('two PayPal orders for one donation cannot both capture, and the paying order replays', function ($test) use ($bmcCapturePublicResponse, $bmcClearGuard, $bmcPayPalProSettings, $bmcMakePayPalDonation, $bmcPayPalBody, $bmcPayPalOrder, $bmcTransactionStatus) {
    $bmcClearGuard();
    $bmcPayPalProSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.80';

    $donation = $bmcMakePayPalDonation();
    $hash     = $donation['hash'];

    $apiCalls   = [];
    $concurrent = null;

    $stub = function ($pre, $args, $url) use (&$apiCalls, &$concurrent, $bmcPayPalBody, $bmcPayPalOrder, $bmcCapturePublicResponse, $hash) {
        if (strpos($url, 'oauth2/token') !== false) {
            return $bmcPayPalBody(['access_token' => 'test-token']);
        }

        $apiCalls[] = $url;

        // While order A is being verified, the same donation is confirmed again
        // from a second tab holding a completely different PayPal order. Without
        // a lease on the donation, that second order would go on to capture too.
        if ($concurrent === null) {
            $concurrent = $bmcCapturePublicResponse(function () {
                $_REQUEST['charge_id'] = 'PAYPAL-ORDER-B';
                (new PayPal())->paymentConfirmation();
                });
            $_REQUEST['charge_id'] = 'PAYPAL-ORDER-A';
        }

        if (strpos($url, '/capture') !== false) {
            return $bmcPayPalBody($bmcPayPalOrder('PAYPAL-ORDER-A', $hash, 'COMPLETED'));
        }

        return $bmcPayPalBody($bmcPayPalOrder('PAYPAL-ORDER-A', $hash, 'APPROVED'));
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'charge_id'         => 'PAYPAL-ORDER-A',
            'hash'              => $hash,
        ];

        $captured = $bmcCapturePublicResponse(function () {
            (new PayPal())->paymentConfirmation();
        });

        $test->assertSame(200, $captured['status'], 'The confirmation holding the lease captures');
        $test->assertSame($donation['transaction_id'], (int) $captured['body']['data']['data']);
        $test->assertSame('paid', $bmcTransactionStatus($donation['transaction_id']));
        $test->assertSame(2, count($apiCalls), 'Exactly one verify and one capture reached PayPal');
        $test->assertSame(1, count(array_filter($apiCalls, function ($url) {
            return strpos($url, '/capture') !== false;
        })), 'Only one capture may ever be made for one donation');

        $test->assertNotEmpty($concurrent, 'The concurrent confirmation must have run');
        $test->assertSame(409, $concurrent['status'], 'A second order arriving mid-capture is a conflict');
        $test->assertSame('confirmation_in_progress', $concurrent['body']['data']['code']);

        // And once it has settled, that second order is refused outright rather
        // than sent to PayPal, where it would charge the donor a second time.
        $_REQUEST['charge_id'] = 'PAYPAL-ORDER-B';
        $conflicting = $bmcCapturePublicResponse(function () {
            (new PayPal())->paymentConfirmation();
        });

        $test->assertSame(409, $conflicting['status']);
        $test->assertSame('payment_already_completed', $conflicting['body']['data']['code']);
        $test->assertSame(2, count($apiCalls), 'A conflicting order must never be captured');

        // The order that did pay replays successfully, so a donor reloading
        // their confirmation still sees their donation succeed.
        $_REQUEST['charge_id'] = 'PAYPAL-ORDER-A';
        $replayed = $bmcCapturePublicResponse(function () {
            (new PayPal())->paymentConfirmation();
        });

        $test->assertSame(200, $replayed['status'], 'The paying order must still confirm successfully');
        $test->assertTrue($replayed['body']['data']['replayed']);
        $test->assertSame(2, count($apiCalls), 'A settled capture must not be re-verified at PayPal');
    } finally {
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('a PayPal confirmation that failed at the provider gives its lease straight back', function ($test) use ($bmcCapturePublicResponse, $bmcClearGuard, $bmcPayPalProSettings, $bmcMakePayPalDonation, $bmcPayPalBody, $bmcPayPalOrder, $bmcTransactionStatus) {
    $bmcClearGuard();
    $bmcPayPalProSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.81';

    $donation = $bmcMakePayPalDonation();
    $hash     = $donation['hash'];
    $approved = false;

    $stub = function ($pre, $args, $url) use (&$approved, $bmcPayPalBody, $bmcPayPalOrder, $hash) {
        if (strpos($url, 'oauth2/token') !== false) {
            return $bmcPayPalBody(['access_token' => 'test-token']);
        }

        if (!$approved) {
            return $bmcPayPalBody($bmcPayPalOrder('PAYPAL-ORDER-C', $hash, 'PAYER_ACTION_REQUIRED'));
        }

        if (strpos($url, '/capture') !== false) {
            return $bmcPayPalBody($bmcPayPalOrder('PAYPAL-ORDER-C', $hash, 'COMPLETED'));
        }

        return $bmcPayPalBody($bmcPayPalOrder('PAYPAL-ORDER-C', $hash, 'APPROVED'));
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'charge_id'         => 'PAYPAL-ORDER-C',
            'hash'              => $hash,
        ];

        $notReady = $bmcCapturePublicResponse(function () {
            (new PayPal())->paymentConfirmation();
        });

        $test->assertSame(400, $notReady['status'], 'An order the buyer has not approved is refused');
        $test->assertSame('pending', $bmcTransactionStatus($donation['transaction_id']));
        $test->assertSame(
            null,
            PublicRequestGuard::readClaim('paypal_confirmation', 'transaction|' . $donation['transaction_id']),
            'A confirmation that captured nothing must not leave the donation locked'
        );

        // The buyer approves and the browser confirms again.
        $approved = true;

        $captured = $bmcCapturePublicResponse(function () {
            (new PayPal())->paymentConfirmation();
        });

        $test->assertSame(200, $captured['status'], 'The retry must be able to reach PayPal again');
        $test->assertSame('paid', $bmcTransactionStatus($donation['transaction_id']));

        $settled = PublicRequestGuard::readClaim('paypal_confirmation', 'transaction|' . $donation['transaction_id']);
        $test->assertSame(PublicRequestGuard::STATE_COMPLETED, $settled['state'], 'A captured donation is recorded as done');
    } finally {
        remove_filter('pre_http_request', $stub, 10);
    }
});

$suite->test('a stale claim owner can neither complete nor release the claim that replaced it', function ($test) use ($bmcClearGuard, $bmcGuardRow, $bmcExpireGuardRow) {
    $bmcClearGuard();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.90';

    $first = PublicRequestGuard::claim('submission', 'lease-handover');
    $test->assertTrue($first['acquired'], 'The first worker takes the lease');
    $test->assertNotEmpty($first['owner'], 'A lease has to be owned by somebody');

    // That worker stalls long enough for its lease to lapse.
    $bmcExpireGuardRow($first['key'], -1);

    $second = PublicRequestGuard::claim('submission', 'lease-handover');
    $test->assertTrue($second['acquired'], 'A lapsed lease may be taken over');
    $test->assertFalse($first['owner'] === $second['owner'], 'A takeover must mint a new owner');

    // The stalled worker wakes up and tries to finish work it no longer owns.
    $test->assertFalse(
        PublicRequestGuard::completeClaim($first['key'], $first['owner'], ['stale' => true], 'submission'),
        'A stale owner must not be able to complete its replacement claim'
    );

    $row = $bmcGuardRow($first['key']);
    $test->assertSame(PublicRequestGuard::STATE_IN_PROGRESS, $row->state, 'The replacement claim must still be in flight');
    $test->assertTrue(empty($row->payload), 'A stale owner must not be able to store a replay payload');

    $test->assertFalse(
        PublicRequestGuard::releaseClaim($first['key'], $first['owner']),
        'A stale owner must not be able to release its replacement claim'
    );
    $test->assertNotEmpty($bmcGuardRow($first['key']), 'The replacement claim must survive a stale release');

    // An empty or invented token is not an owner either.
    $test->assertFalse(PublicRequestGuard::releaseClaim($first['key'], ''));
    $test->assertFalse(PublicRequestGuard::completeClaim($first['key'], str_repeat('f', 32), [], 'submission'));
    $test->assertSame(PublicRequestGuard::STATE_IN_PROGRESS, $bmcGuardRow($first['key'])->state);

    // The real owner may, and once it has, nobody may undo it.
    $test->assertTrue(PublicRequestGuard::completeClaim($second['key'], $second['owner'], [], 'submission'));
    $test->assertSame(PublicRequestGuard::STATE_COMPLETED, $bmcGuardRow($second['key'])->state);
    $test->assertFalse(
        PublicRequestGuard::releaseClaim($second['key'], $second['owner']),
        'A completed claim must not be releasable, even by its owner'
    );

    // The token itself is never written down.
    $row = $bmcGuardRow($second['key']);
    $test->assertNotContains($first['owner'], wp_json_encode($row), 'A lease token must never be stored');
    $test->assertNotContains($second['owner'], wp_json_encode($row), 'A lease token must never be stored');
    $test->assertTrue((bool) preg_match('/\A[a-f0-9]{64}\z/', $row->owner_hash), 'Only a digest of the token is kept');
});

$suite->test('claim lifetimes are long enough that a crashed worker cannot open a duplicate window', function ($test) use ($bmcCapturePublicResponse, $bmcSubmissionRequest, $bmcCountSubmissions, $bmcPayPalStandardSettings, $bmcClearGuard, $bmcGuardRow, $bmcExpireGuardRow, $bmcIdemKey) {
    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.91';

    $now = time();

    // In flight: long enough that a worker which died after asking a gateway to
    // create a remote payment cannot have its key reused before anyone notices.
    $inFlight = PublicRequestGuard::claim('submission', 'lifetime-in-flight');
    $test->assertTrue(
        (int) $bmcGuardRow($inFlight['key'])->expires_at - $now >= HOUR_IN_SECONDS,
        'An in-flight submission lease must outlive a crashed request'
    );

    // Completed: the real idempotency window, and a full day rather than the
    // fifteen minutes a retried donation can easily outlast.
    PublicRequestGuard::completeClaim($inFlight['key'], $inFlight['owner'], [], 'submission');
    $test->assertTrue(
        (int) $bmcGuardRow($inFlight['key'])->expires_at - $now >= DAY_IN_SECONDS,
        'A completed submission must stay recognised for at least a day'
    );

    // Provider deliveries are redelivered for days, so a consumed one has to
    // stay recognisable for longer than the provider keeps trying.
    $event = PublicRequestGuard::claim('stripe_event', 'lifetime-event');
    PublicRequestGuard::completeClaim($event['key'], $event['owner'], [], 'stripe_event');
    $test->assertTrue(
        (int) $bmcGuardRow($event['key'])->expires_at - $now >= 14 * DAY_IN_SECONDS,
        'A consumed provider event must outlast the provider retry schedule'
    );

    // And the window is real, not just a stored number: a donation retried
    // almost a full day later is still refused rather than created twice.
    $key = $bmcIdemKey('long-window');
    $bmcSubmissionRequest(['idempotency_key' => $key]);
    $created = $bmcCapturePublicResponse(function () {
        (new SubmissionHandler())->handleSubmission();
    });

    $test->assertSame(200, $created['status']);
    $test->assertSame(1, $bmcCountSubmissions()['supporters']);

    $claimKey = PublicRequestGuard::claim('submission', 'unused-probe')['key'];
    $test->assertNotEmpty($claimKey, 'Claim keys are opaque digests');

    $stored = PublicRequestGuard::readClaim('submission', $key);
    $test->assertSame(PublicRequestGuard::STATE_COMPLETED, $stored['state']);

    $bmcSubmissionRequest(['idempotency_key' => $key]);
    $muchLater = $bmcCapturePublicResponse(function () {
        (new SubmissionHandler())->handleSubmission();
    });

    $test->assertSame(409, $muchLater['status'], 'A key completed today must still be refused today');
    $test->assertSame('submission_already_completed', $muchLater['body']['data']['code']);
    $test->assertSame(1, $bmcCountSubmissions()['supporters'], 'No second donation may be created');
});

$suite->test('every protected public route fails closed when the guard table cannot be used', function ($test) use ($bmcCapturePublicResponse, $bmcSubmissionRequest, $bmcCountSubmissions, $bmcPayPalStandardSettings, $bmcStripeSettings, $bmcMakeOneTimePurchase, $bmcMakePayPalDonation, $bmcClearGuard, $bmcTransactionStatus) {
    $bmcClearGuard();
    $bmcPayPalStandardSettings();
    $bmcStripeSettings();
    $_SERVER['REMOTE_ADDR']    = '203.0.113.99';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $purchase = $bmcMakeOneTimePurchase();
    $donation = $bmcMakePayPalDonation();

    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests) {
        $requests[] = $url;

        return $pre;
    };
    add_filter('pre_http_request', $stub, 10, 3);

    // Without the table there is no atomic claim, so there is no way to promise
    // an operation runs once. Every protected route says so and stays retryable
    // rather than quietly running twice.
    PublicRequestGuard::resetRuntimeState(false);

    try {
        $test->assertFalse(PublicRequestGuard::isAvailable(), 'The guard must report itself unusable');

        $bmcSubmissionRequest();
        $submission = $bmcCapturePublicResponse(function () {
            (new SubmissionHandler())->handleSubmission();
        });

        $test->assertSame(503, $submission['status'], 'A donation must not be taken without the guard');
        $test->assertSame('guard_unavailable', $submission['body']['data']['code']);
        $test->assertSame(['supporters' => 0, 'transactions' => 0], $bmcCountSubmissions(), 'Nothing may be written');

        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'intentId'          => 'pi_' . $purchase['suffix'],
        ];
        $stripeConfirmation = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        $test->assertSame(503, $stripeConfirmation['status']);
        $test->assertSame('guard_unavailable', $stripeConfirmation['body']['data']['code']);

        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'charge_id'         => 'PAYPAL-ORDER-X',
            'hash'              => $donation['hash'],
        ];
        $paypalConfirmation = $bmcCapturePublicResponse(function () {
            (new PayPal())->paymentConfirmation();
        });

        $test->assertSame(503, $paypalConfirmation['status']);
        $test->assertSame('guard_unavailable', $paypalConfirmation['body']['data']['code']);

        $webhook = (new Stripe())->processIncomingEvent((object) [
            'id'   => 'evt_guard_missing_table',
            'type' => 'charge.succeeded',
        ]);

        $test->assertSame('guard_unavailable', $webhook['code']);
        $test->assertSame(503, $webhook['http'], 'A provider must be told to redeliver, not that it succeeded');
        $test->assertTrue($webhook['retry_after'] > 0);

        $ipn = (new IPN())->processIncomingIpn(http_build_query([
            'txn_type'       => 'web_accept',
            'payment_status' => 'Completed',
            'txn_id'         => 'BMC-IPN-NO-GUARD',
            'custom'         => $donation['transaction_id'],
        ]));

        $test->assertSame('guard_unavailable', $ipn['code']);
        $test->assertSame(503, $ipn['http']);
        $test->assertSame('pending', $bmcTransactionStatus($donation['transaction_id']), 'Nothing may be applied');

        $test->assertSame(0, count($requests), 'No provider may be contacted while the guard is unusable');
    } finally {
        PublicRequestGuard::resetRuntimeState();
        remove_filter('pre_http_request', $stub, 10);
    }

    // And once the table is usable again the very same routes work normally.
    $test->assertTrue(PublicRequestGuard::isAvailable(), 'The probe must recover on its own');

    $bmcSubmissionRequest();
    $recovered = $bmcCapturePublicResponse(function () {
        (new SubmissionHandler())->handleSubmission();
    });

    $test->assertSame(200, $recovered['status'], 'A recovered guard must not keep refusing donations');
    $test->assertSame(1, $bmcCountSubmissions()['supporters']);
});

$suite->test('lapsed guard rows are cleaned up in bounded slices and live ones are left alone', function ($test) use ($bmcClearGuard, $bmcGuardRows) {
    global $wpdb;

    $bmcClearGuard();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.92';

    for ($row = 1; $row <= 5; $row++) {
        PublicRequestGuard::consume('rate|guard-test|cleanup|' . $row, 10, MINUTE_IN_SECONDS);
    }

    $live = PublicRequestGuard::claim('submission', 'cleanup-live');
    $test->assertSame(6, count($bmcGuardRows()));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}buymecoffee_request_guard SET expires_at = %d WHERE guard_type = %s",
        time() - 10,
        'rate'
    ));

    $removed = PublicRequestGuard::cleanup();

    $test->assertSame(5, $removed, 'Every lapsed row is removed');
    $remaining = $bmcGuardRows();
    $test->assertSame(1, count($remaining), 'A live claim must survive housekeeping');
    $test->assertSame($live['key'], $remaining[0]->guard_key);
    $test->assertTrue(PublicRequestGuard::CLEANUP_LIMIT > 0, 'Cleanup must always be bounded');
});

$suite->test('a payment left unbound by the upgrade is refused in the browser and still settled by the webhook', function ($test) use ($bmcCapturePublicResponse, $bmcStripeSettings, $bmcStripeBody, $bmcMakeOneTimePurchase, $bmcStripeEvent, $bmcStripeCharge, $bmcPaymentState, $bmcClearGuard, $bmcGuardRows, $bmcServiceSharesTestTransaction) {
    global $wpdb;

    $bmcClearGuard();
    $bmcStripeSettings();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.78';

    $restoreTransactions = $bmcServiceSharesTestTransaction();

    // A payment already in flight when the site upgraded: Stripe holds an
    // intent for it, the row predates checkout-time binding and knows nothing
    // about that intent.
    $stranded = $bmcMakeOneTimePurchase();
    $wpdb->update($wpdb->prefix . 'buymecoffee_transactions', ['charge_id' => ''], ['id' => $stranded['transaction_id']]);

    $requests = [];
    $stub = function ($pre, $args, $url) use (&$requests) {
        $requests[] = $url;

        return $pre;
    };
    add_filter('pre_http_request', $stub, 10, 3);

    try {
        $_REQUEST = [
            'buymecoffee_nonce' => wp_create_nonce('buymecoffee_nonce'),
            'intentId'          => 'pi_stranded_' . $stranded['suffix'],
        ];

        $refused = $bmcCapturePublicResponse(function () {
            (new Stripe())->paymentConfirmation();
        });

        // Refusing costs nothing and grants nothing. Rebuilding the binding here
        // would mean asking Stripe about an id the caller chose, which is the
        // amplification the binding exists to prevent.
        $test->assertSame(404, $refused['status'], 'A confirmation with no binding is refused');
        $test->assertSame('payment_intent_not_recognized', $refused['body']['data']['code']);
        $test->assertSame(0, count($requests), 'The refusal must not reach Stripe');
        $test->assertSame(0, count($bmcGuardRows('claim')), 'The refusal must not take a lease');
        $test->assertSame('pending', $bmcPaymentState($stranded)['transaction'], 'Nothing is settled by the refusal');

        // The payment is not lost by that refusal. The webhook resolves the
        // transaction from the order hash in the event's own metadata, so it
        // settles the donation the browser could not confirm.
        (new Stripe())->processAuthenticatedEvent($bmcStripeEvent(
            'evt_unbound_' . $stranded['suffix'],
            'charge.succeeded',
            $bmcStripeCharge($stranded['order_hash'])
        ));

        $settled = $bmcPaymentState($stranded);
        $test->assertSame('paid', $settled['transaction'], 'The webhook settles a payment that has no binding');
        $test->assertSame('active', $settled['access'], 'And grants the entitlement it paid for');
        $test->assertSame([(int) $stranded['level_id']], $settled['levels']);
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        $restoreTransactions();
    }
});

$suite->test('an intent is only handed to the browser once its binding is confirmed stored', function ($test) use ($bmcMakeOneTimePurchase) {
    global $wpdb;

    $helper  = new PaymentHelper();
    $txTable = $wpdb->prefix . 'buymecoffee_transactions';

    $purchase = $bmcMakeOneTimePurchase();
    $wpdb->update($txTable, ['charge_id' => ''], ['id' => $purchase['transaction_id']]);

    $intentId = 'pi_bind_' . $purchase['suffix'];

    // The ordinary case: the binding is written and read back.
    $test->assertTrue($helper->bindStripeIntent($purchase['transaction_id'], $intentId), 'A stored binding reports success');
    $test->assertSame(
        $intentId,
        $wpdb->get_var($wpdb->prepare("SELECT charge_id FROM {$txTable} WHERE id = %d", $purchase['transaction_id'])),
        'The transaction carries the intent'
    );

    // Repeating it is still true: the row already carries this intent, even
    // though the update itself changes no row and reports zero.
    $test->assertTrue($helper->bindStripeIntent($purchase['transaction_id'], $intentId), 'An unchanged binding is not a failure');

    // A write that silently does nothing must not report success. Swallowing
    // the UPDATE reproduces every way that can happen — a row that has gone, a
    // column that refused the value — without having to stage each one.
    $swallow = function ($query) use ($txTable) {
        if (stripos($query, 'UPDATE') === 0 && strpos($query, $txTable) !== false) {
            return 'SELECT 1';
        }

        return $query;
    };
    add_filter('query', $swallow);

    try {
        $test->assertFalse(
            $helper->bindStripeIntent($purchase['transaction_id'], 'pi_never_stored_' . $purchase['suffix']),
            'A binding that did not take must not report success'
        );
    } finally {
        remove_filter('query', $swallow);
    }

    // The row is untouched, so the confirmation still matches the intent the
    // browser was actually given.
    $test->assertSame(
        $intentId,
        $wpdb->get_var($wpdb->prepare("SELECT charge_id FROM {$txTable} WHERE id = %d", $purchase['transaction_id'])),
        'A refused binding leaves the previous one in place'
    );

    $test->assertFalse($helper->bindStripeIntent(0, $intentId), 'No transaction is not a binding');
    $test->assertFalse($helper->bindStripeIntent($purchase['transaction_id'], ''), 'No intent is not a binding');

    // Columns written alongside the binding land with it.
    $second = $bmcMakeOneTimePurchase();
    $test->assertTrue($helper->bindStripeIntent(
        $second['transaction_id'],
        'pi_recurring_' . $second['suffix'],
        ['transaction_type' => 'recurring']
    ));
    $test->assertSame(
        'recurring',
        $wpdb->get_var($wpdb->prepare("SELECT transaction_type FROM {$txTable} WHERE id = %d", $second['transaction_id'])),
        'The columns bound with the intent are stored too'
    );
});

exit($suite->run());
