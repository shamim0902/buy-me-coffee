<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/FeatureTestRunner.php';

use BuyMeCoffee\Builder\Methods\PayPal\IPN;
use BuyMeCoffee\Builder\Methods\PayPal\PayPal;
use BuyMeCoffee\Builder\Methods\PayPal\PayPalSettings;
use BuyMeCoffee\Builder\Methods\Stripe\Stripe;
use BuyMeCoffee\Classes\AccessControl;
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

exit($suite->run());
