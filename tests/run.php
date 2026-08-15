<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/FeatureTestRunner.php';

use BuyMeCoffee\Builder\Methods\PayPal\IPN;
use BuyMeCoffee\Builder\Methods\PayPal\PayPal;
use BuyMeCoffee\Builder\Methods\PayPal\PayPalSettings;
use BuyMeCoffee\Builder\Methods\Stripe\Stripe;
use BuyMeCoffee\Classes\AccessControl;
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

exit($suite->run());
