<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Models\MembershipLevel;
use BuyMeCoffee\Models\MembershipAccess;
use BuyMeCoffee\Controllers\MonetizationController;
use BuyMeCoffee\Helpers\PaymentHelper;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class MembershipAjaxHandler
{
    public function register()
    {
        add_action('buymecoffee_admin_ajax_handler_catch', [$this, 'handle']);
    }

    public function handle($route, $data = null)
    {
        if ($data === null) {
            $data = isset($_REQUEST['data']) ? $this->sanitizeData(wp_unslash($_REQUEST['data'])) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified in AdminAjaxHandler before this catch-all route.
        }

        if (!is_array($data)) {
            $data = [];
        }

        switch ($route) {
            case 'get_membership_levels':
                return $this->getMembershipLevels();
            case 'get_membership_level':
                return $this->getMembershipLevel($data);
            case 'save_membership_level':
                return $this->saveMembershipLevel($data);
            case 'delete_membership_level':
                return $this->deleteMembershipLevel($data);
            case 'reorder_membership_levels':
                return $this->reorderMembershipLevels($data);
            case 'get_membership_settings':
                return $this->getMembershipSettings();
            case 'save_membership_settings':
                return $this->saveMembershipSettings($data);
            case 'get_post_types_for_membership':
                return $this->getPostTypesForMembership();
            case 'get_categories_for_membership':
                return $this->getCategoriesForMembership($data);
            case 'get_membership_members':
                return $this->getMembershipMembers($data);
            case 'send_membership_invite':
                return $this->sendMembershipInvite($data);
        }
    }

    private function getMembershipLevels()
    {
        $levels = (new MembershipLevel())->getForAdmin();
        wp_send_json_success(['levels' => $levels]);
    }

    private function getMembershipLevel($data)
    {
        $id = isset($data['id']) ? absint($data['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid level ID.', 'buy-me-coffee')]);
            return;
        }

        $level = (new MembershipLevel())->findForAdmin($id);
        if (!$level) {
            wp_send_json_error(['message' => __('Level not found.', 'buy-me-coffee')], 404);
            return;
        }

        wp_send_json_success(['level' => $level]);
    }

    private function saveMembershipLevel($data)
    {
        global $wpdb;

        $name = isset($data['name']) ? sanitize_text_field($data['name']) : '';
        if (empty($name)) {
            wp_send_json_error(['message' => __('Level name is required.', 'buy-me-coffee')]);
            return;
        }

        $allowedIntervals = ['month', 'year'];
        $allowedPaymentTypes = ['one_time', 'subscription'];
        $allowedStatuses  = ['active', 'inactive'];
        $allowedAccess    = ['full', 'preview'];

        $price        = isset($data['price']) ? absint($data['price']) : 0;
        $paymentType  = isset($data['payment_type']) && in_array($data['payment_type'], $allowedPaymentTypes, true)
            ? $data['payment_type'] : 'subscription';
        $intervalType = isset($data['interval_type']) && in_array($data['interval_type'], $allowedIntervals, true)
            ? $data['interval_type'] : 'month';
        $status       = isset($data['status']) && in_array($data['status'], $allowedStatuses, true)
            ? $data['status'] : 'active';

        // Rewards — array of sanitized strings
        $rewards = [];
        if (!empty($data['rewards']) && is_array($data['rewards'])) {
            foreach ($data['rewards'] as $reward) {
                $clean = sanitize_text_field($reward);
                if ($clean !== '') {
                    $rewards[] = $clean;
                }
            }
        }

        // Access rules
        $accessRules = [];
        if (!empty($data['access_rules']) && is_array($data['access_rules'])) {
            $rawRules = $data['access_rules'];

            $postTypes = [];
            if (!empty($rawRules['post_types']) && is_array($rawRules['post_types'])) {
                $postTypes = array_map('sanitize_key', $rawRules['post_types']);
            }

            $categories = [];
            if (!empty($rawRules['categories']) && is_array($rawRules['categories'])) {
                $categories = array_map('absint', $rawRules['categories']);
            }

            $previewWords = isset($rawRules['preview_words']) ? absint($rawRules['preview_words']) : 50;

            $accessLevel = isset($rawRules['access_level']) && in_array($rawRules['access_level'], $allowedAccess, true)
                ? $rawRules['access_level'] : 'full';

            $accessRules = [
                'post_types'   => $postTypes,
                'categories'   => $categories,
                'preview_words' => $previewWords,
                'access_level' => $accessLevel,
            ];
        }

        $saveData = [
            'name'         => $name,
            'description'  => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'price'        => $price,
            'payment_type' => $paymentType,
            'interval_type' => $intervalType,
            'status'       => $status,
            'rewards'      => wp_json_encode($rewards),
            'access_rules' => wp_json_encode($accessRules),
            'updated_at'   => current_time('mysql'),
        ];

        $model = new MembershipLevel();
        $id    = isset($data['id']) ? absint($data['id']) : 0;

        if ($id) {
            $existing = $model->findForAdmin($id);
            if (!$existing) {
                wp_send_json_error(['message' => __('Level not found.', 'buy-me-coffee')]);
                return;
            }
            $updated = $model->updateData($id, $saveData);
            if ($updated === false) {
                wp_send_json_error([
                    'message' => $wpdb->last_error ?: __('Could not update membership level.', 'buy-me-coffee'),
                ], 500);
                return;
            }
        } else {
            $saveData['sort_order'] = buyMeCoffeeQuery()
                ->table('buymecoffee_membership_levels')
                ->where('status', '!=', 'deleted')
                ->count();
            $saveData['created_at'] = current_time('mysql');
            $id = $model->create($saveData);
            if (!$id) {
                wp_send_json_error([
                    'message' => $wpdb->last_error ?: __('Could not create membership level.', 'buy-me-coffee'),
                ], 500);
                return;
            }
        }

        $saved = $model->find($id);
        if (!$saved) {
            wp_send_json_error(['message' => __('Membership level was saved but could not be loaded.', 'buy-me-coffee')], 500);
            return;
        }

        wp_send_json_success([
            'level'   => $model->decodeJsonFields($saved),
            'message' => __('Membership level saved.', 'buy-me-coffee'),
        ]);
    }

    private function deleteMembershipLevel($data)
    {
        global $wpdb;

        $id = isset($data['id']) ? absint($data['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid level ID.', 'buy-me-coffee')]);
            return;
        }

        $accessTable = $wpdb->prefix . 'buymecoffee_membership_access';
        $now = current_time('mysql', true);

        // Block deletion if any access record still grants entitlement to this level.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table with prepared values.
        $activeCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$accessTable}
             WHERE level_id = %d
             AND (
                (status = 'active' AND (access_type IN ('one_time', 'manual') OR (access_type = 'subscription' AND expires_at IS NOT NULL AND expires_at > %s)))
                OR (status = 'cancelled' AND access_type = 'subscription' AND expires_at IS NOT NULL AND expires_at > %s)
             )",
            $id,
            $now,
            $now
        ));

        if ($activeCount > 0) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %d: number of active members */
                    __('Cannot delete: %d active member(s) on this level. Revoke their access first.', 'buy-me-coffee'),
                    (int) $activeCount
                ),
            ]);
            return;
        }

        (new MembershipLevel())->updateData($id, ['status' => 'deleted', 'updated_at' => current_time('mysql')]);
        wp_send_json_success(['message' => __('Level deleted.', 'buy-me-coffee')]);
    }

    private function reorderMembershipLevels($data)
    {
        $ids = isset($data['ids']) && is_array($data['ids']) ? array_map('absint', $data['ids']) : [];
        if (empty($ids)) {
            wp_send_json_error(['message' => __('No IDs provided.', 'buy-me-coffee')]);
            return;
        }

        (new MembershipLevel())->reorder($ids);
        wp_send_json_success(['message' => __('Order saved.', 'buy-me-coffee')]);
    }

    private function getMembershipSettings()
    {
        wp_send_json_success(['settings' => MonetizationController::getGlobalSettings()]);
    }

    private function saveMembershipSettings($data)
    {
        $current  = MonetizationController::getGlobalSettings();
        $boolKeys = ['accept_annual', 'display_member_count', 'display_earnings', 'membership_active', 'recovery_modal_enabled'];

        $updated = [
            'default_preview_words'  => isset($data['default_preview_words']) ? absint($data['default_preview_words']) : $current['default_preview_words'],
            'cta_heading'            => isset($data['cta_heading']) ? sanitize_text_field($data['cta_heading']) : $current['cta_heading'],
            'cta_subtext'            => isset($data['cta_subtext']) ? sanitize_text_field($data['cta_subtext']) : $current['cta_subtext'],
            'redirect_url'           => isset($data['redirect_url']) ? esc_url_raw($data['redirect_url']) : $current['redirect_url'],
            'recovery_modal_title'   => isset($data['recovery_modal_title']) ? sanitize_text_field($data['recovery_modal_title']) : $current['recovery_modal_title'],
            'recovery_modal_body'    => isset($data['recovery_modal_body']) ? sanitize_textarea_field($data['recovery_modal_body']) : $current['recovery_modal_body'],
        ];

        foreach ($boolKeys as $key) {
            // Values arrive form-encoded, so a JS `false` reaches PHP as the
            // string "false", which (bool) casts to true. Parse it properly so
            // toggles can actually be turned off.
            $updated[$key] = isset($data[$key])
                ? filter_var($data[$key], FILTER_VALIDATE_BOOLEAN)
                : $current[$key];
        }

        update_option('buymecoffee_membership_settings', $updated, false);
        wp_send_json_success([
            'settings' => $updated,
            'message'  => __('Settings saved.', 'buy-me-coffee'),
        ]);
    }

    private function getPostTypesForMembership()
    {
        $types  = get_post_types(['public' => true], 'objects');
        $result = [];
        foreach ($types as $type) {
            if ($type->name === 'attachment') {
                continue;
            }
            $result[] = [
                'name'  => $type->name,
                'label' => $type->label,
            ];
        }
        wp_send_json_success(['post_types' => $result]);
    }

    private function getCategoriesForMembership($data = [])
    {
        $page    = isset($data['page']) ? max(0, absint($data['page'])) : 0;
        $perPage = isset($data['per_page']) ? max(1, min(100, absint($data['per_page']))) : 50;
        $search  = isset($data['search']) ? sanitize_text_field($data['search']) : '';
        $include = [];

        if (!empty($data['include']) && is_array($data['include'])) {
            $include = array_values(array_filter(array_map('absint', $data['include'])));
        }

        $args = [
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'number'     => $perPage,
            'offset'     => $page * $perPage,
        ];

        if ($search !== '') {
            $args['search'] = $search;
        }

        $terms = get_terms($args);
        $result = [];
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $result[(int) $term->term_id] = $this->formatCategoryTerm($term);
            }
        }

        if (!empty($include)) {
            $includedTerms = get_terms([
                'taxonomy'   => 'category',
                'hide_empty' => false,
                'include'    => $include,
            ]);

            if (!is_wp_error($includedTerms)) {
                foreach ($includedTerms as $term) {
                    $result[(int) $term->term_id] = $this->formatCategoryTerm($term);
                }
            }
        }

        $countArgs = $args;
        unset($countArgs['number'], $countArgs['offset']);
        $countArgs['fields'] = 'count';
        $total = get_terms($countArgs);

        wp_send_json_success([
            'categories' => array_values($result),
            'total'      => is_wp_error($total) ? count($result) : (int) $total,
            'page'       => $page,
            'per_page'   => $perPage,
        ]);
    }

    private function formatCategoryTerm($term)
    {
        return [
            'id'   => (int) $term->term_id,
            'name' => $term->name,
        ];
    }

    private function getMembershipMembers($data)
    {
        if (!is_array($data)) {
            $data = [];
        }

        $page          = isset($data['page']) ? max(0, absint($data['page'])) : 0;
        $postsPerPage  = 20;
        $search        = isset($data['search']) ? sanitize_text_field($data['search']) : '';

        $offset = $page * $postsPerPage;
        $result = $this->queryMembershipMembers($search, $postsPerPage, $offset);

        wp_send_json_success([
            'members' => $result['rows'],
            'total'   => $result['total'],
        ]);
    }

    private function queryMembershipMembers($search, $limit, $offset)
    {
        global $wpdb;

        $accessTable = $wpdb->prefix . 'buymecoffee_membership_access';
        $subscriptionsTable = $wpdb->prefix . 'buymecoffee_subscriptions';

        $rows = $this->buildMembershipMembersQuery($search)
            ->select([
                'buymecoffee_membership_access.*',
                'buymecoffee_supporters.supporters_name',
                'buymecoffee_supporters.supporters_email',
                'buymecoffee_membership_access.id'         => 'access_id',
                'buymecoffee_membership_access.expires_at' => 'current_period_end',
                'buymecoffee_membership_levels.name'       => 'level_name',
                buyMeCoffeeQuery()->raw("CASE WHEN {$accessTable}.access_type = 'subscription' THEN {$subscriptionsTable}.interval_type ELSE {$accessTable}.access_type END as interval_type"),
            ])
            ->orderBy('buymecoffee_membership_access.created_at', 'DESC')
            ->limit(absint($limit))
            ->offset(absint($offset))
            ->get();

        $total = (int) $this->buildMembershipMembersQuery($search)->count();

        return [
            'rows'  => $rows ?: [],
            'total' => $total,
        ];
    }

    private function buildMembershipMembersQuery($search)
    {
        $query = buyMeCoffeeQuery()
            ->table('buymecoffee_membership_access')
            ->leftJoin('buymecoffee_supporters', 'buymecoffee_membership_access.supporter_id', '=', 'buymecoffee_supporters.id')
            ->leftJoin('buymecoffee_membership_levels', 'buymecoffee_membership_access.level_id', '=', 'buymecoffee_membership_levels.id')
            ->leftJoin('buymecoffee_subscriptions', 'buymecoffee_membership_access.subscription_id', '=', 'buymecoffee_subscriptions.id')
            ->whereNotNull('buymecoffee_membership_access.level_id')
            ->where('buymecoffee_membership_access.level_id', '>', 0);

        if ($search !== '') {
            $query->where(function ($whereQuery) use ($search) {
                $whereQuery->where('buymecoffee_supporters.supporters_name', 'LIKE', '%' . $search . '%')
                    ->orWhere('buymecoffee_supporters.supporters_email', 'LIKE', '%' . $search . '%');
            });
        }

        return $query;
    }

    private function sendMembershipInvite($data)
    {
        $email = isset($data['email']) ? sanitize_email($data['email']) : '';
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please provide a valid email address.', 'buy-me-coffee')]);
            return;
        }

        $levelId = isset($data['level_id']) ? absint($data['level_id']) : 0;
        $level = $levelId ? (new MembershipLevel())->find($levelId) : null;
        if (!$level || $level->status !== 'active') {
            wp_send_json_error(['message' => __('Please choose an active membership level.', 'buy-me-coffee')]);
            return;
        }

        $userId = $this->getOrCreateInviteUser($email);
        if (!$userId) {
            wp_send_json_error(['message' => __('Could not create a member account for this email.', 'buy-me-coffee')]);
            return;
        }

        $supporterId = $this->getOrCreateInviteSupporter($userId, $email);
        if (!$supporterId) {
            wp_send_json_error(['message' => __('Could not create a supporter record for this invite.', 'buy-me-coffee')]);
            return;
        }

        $accessId = $this->grantInviteSubscription($userId, $supporterId, $level);
        if (!$accessId) {
            wp_send_json_error(['message' => __('This member already has access to that level.', 'buy-me-coffee')]);
            return;
        }

        $settings = get_option('buymecoffee_payment_setting', []);
        $accountPageId = !empty($settings['account_page_id']) ? (int) $settings['account_page_id'] : 0;
        $accountUrl = $accountPageId ? get_permalink($accountPageId) : wp_login_url();

        $subject = sprintf(
            /* translators: %s: site name */
            __('You have been invited to join %s for free!', 'buy-me-coffee'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );

        $body = sprintf(
            /* translators: 1: site name, 2: level name, 3: account URL */
            __("You've been given free access to %1\$s membership level: %2\$s.\n\nAccess your account here: %3\$s", 'buy-me-coffee'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            sanitize_text_field($level->name),
            esc_url_raw($accountUrl)
        );

        $sent = wp_mail($email, $subject, $body);

        if ($sent) {
            wp_send_json_success(['message' => __('Invite sent successfully.', 'buy-me-coffee')]);
        } else {
            wp_send_json_error(['message' => __('Failed to send invite email.', 'buy-me-coffee')]);
        }
    }

    private function getOrCreateInviteUser($email)
    {
        $existing = get_user_by('email', $email);
        if ($existing) {
            return (int) $existing->ID;
        }

        $username = sanitize_user(strstr($email, '@', true), true);
        if (!$username) {
            $username = 'member_' . substr(md5($email), 0, 8);
        }

        if (username_exists($username)) {
            $username .= '_' . wp_generate_password(4, false, false);
        }

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'role'       => 'subscriber',
            'user_pass'  => wp_generate_password(24),
        ]);

        if (is_wp_error($userId)) {
            return 0;
        }

        wp_send_new_user_notifications($userId, 'user');
        return (int) $userId;
    }

    private function getOrCreateInviteSupporter($userId, $email)
    {
        $supporter = buyMeCoffeeQuery()
            ->table('buymecoffee_supporters')
            ->where('wp_user_id', (int) $userId)
            ->orderBy('created_at', 'DESC')
            ->first();

        if ($supporter) {
            return (int) $supporter->id;
        }

        return (int) buyMeCoffeeQuery()->table('buymecoffee_supporters')->insert([
            'supporters_name'    => '',
            'supporters_email'   => sanitize_email($email),
            'supporters_message' => '',
            'form_data_raw'      => maybe_serialize(['source' => 'membership_invite']),
            'currency'           => strtoupper(PaymentHelper::getCurrency()),
            'payment_status'     => 'paid',
            'entry_hash'         => 'membership_invite_' . wp_generate_password(20, false, false),
            'payment_total'      => 0,
            'coffee_count'       => 0,
            'payment_mode'       => 'manual',
            'payment_method'     => 'membership_invite',
            'status'             => 'new',
            'reference'          => 'membership_invite',
            'created_at'         => current_time('mysql'),
            'updated_at'         => current_time('mysql'),
            'wp_user_id'         => (int) $userId,
        ]);
    }

    private function grantInviteSubscription($userId, $supporterId, $level)
    {
        $supporterIds = buymecoffee_get_supporter_ids_for_user((int) $userId);
        if (empty($supporterIds)) {
            $supporterIds = [(int) $supporterId];
        }

        $existing = buyMeCoffeeQuery()
            ->table('buymecoffee_membership_access')
            ->whereIn('supporter_id', $supporterIds)
            ->where('level_id', (int) $level->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return 0;
        }

        $accessId = (new MembershipAccess())->grantManualAccess((int) $supporterId, (int) $userId, (int) $level->id);

        if ($accessId && function_exists('buymecoffee_delete_supporter_meta')) {
            buymecoffee_delete_supporter_meta((int) $supporterIds[0], 'active_level_ids');
        }

        return (int) $accessId;
    }

    private function sanitizeData($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeData'], $data);
        }
        return sanitize_text_field((string) $data);
    }
}
