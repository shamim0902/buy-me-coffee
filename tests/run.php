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
use BuyMeCoffee\Services\SupporterDeletionService;

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

$suite->test('already cancelled and local-only subscriptions are deleted without calling Stripe', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeSupporter, $bmcCountRows, $bmcStripeStub) {
    $bmcStripeSettings();

    $graph = $bmcMakeSupporter([
        'terminal'  => ['stripe_id' => 'sub_high02_terminal', 'status' => 'cancelled', 'payment_mode' => 'live'],
        'localonly' => ['status' => 'active'],
    ]);

    $requests  = [];
    $responder = function () use ($bmcStripeBody) {
        return $bmcStripeBody(['id' => 'sub_high02_terminal', 'object' => 'subscription', 'status' => 'canceled']);
    };
    $stub = $bmcStripeStub($requests, $responder);

    add_filter('pre_http_request', $stub, 10, 3);
    add_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');

    try {
        $result = (new SupporterDeletionService())->delete($graph['supporter_id']);

        $test->assertFalse(is_wp_error($result), 'Rows that cannot bill must not block deletion');
        $test->assertSame([], $result['cancelled_subscription_ids']);
        $test->assertSame(0, count($requests), 'Terminal and local-only subscriptions must not reach the provider');

        foreach ($bmcCountRows($graph) as $table => $count) {
            $test->assertSame(0, $count, "Rows survived deletion in {$table}");
        }
    } finally {
        remove_filter('pre_http_request', $stub, 10);
        remove_filter('buymecoffee_supporter_delete_manages_transaction', '__return_false');
    }
});

$suite->test('one failing subscription blocks deletion and the confirmed one is not cancelled twice on retry', function ($test) use ($bmcStripeSettings, $bmcStripeBody, $bmcMakeSupporter, $bmcCountRows, $bmcStripeStub) {
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

        // The confirmed cancellation is persisted, so a retry reconciles instead
        // of asking Stripe to cancel the same agreement again.
        $test->assertSame('cancelled', (new Subscriptions())->find($graph['subscription_ids']['ok'])->status);
        $test->assertSame('past_due', (new Subscriptions())->find($graph['subscription_ids']['bad'])->status);

        // Retry once Stripe is reachable again.
        $requests  = [];
        $responder = function () use ($bmcStripeBody) {
            return $bmcStripeBody(['id' => 'sub_high02_bad', 'object' => 'subscription', 'status' => 'canceled']);
        };

        $result = (new SupporterDeletionService())->delete($graph['supporter_id']);

        $test->assertFalse(is_wp_error($result), 'The retry must succeed once every agreement is cancelled');
        $test->assertSame([$graph['subscription_ids']['bad']], $result['cancelled_subscription_ids']);
        $test->assertSame(1, count($requests), 'The already cancelled subscription must not be cancelled again');
        $test->assertContains('sub_high02_bad', $requests[0]['url']);

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
        $rival = $bmcMakeMigrationSource(['level_id' => $levelId, 'transactions' => 1]);

        $canonicalId = $insertAccess([
            'supporter_id'    => $rival['supporter_id'],
            'wp_user_id'      => null,
            'level_id'        => $levelId,
            'transaction_id'  => null,
            'subscription_id' => $rival['subscription_id'],
            'access_type'     => 'subscription',
            'status'          => 'active',
            'starts_at'       => null,
            'expires_at'      => null,
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);
        $duplicateId = $insertAccess([
            'supporter_id'    => $rival['supporter_id'],
            'wp_user_id'      => null,
            'level_id'        => $levelId,
            'transaction_id'  => $rival['transaction_ids'][0],
            'subscription_id' => null,
            'access_type'     => 'one_time',
            'status'          => 'incomplete',
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
        $test->assertSame($duplicateId, (int) $rivalRows[1]->id, 'The duplicate must be skipped, not deleted');
        $test->assertSame(null, $rivalRows[1]->subscription_id, 'The duplicate must not steal the unique subscription link');
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

exit($suite->run());
