<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Models\MembershipLevel;
use BuyMeCoffee\Controllers\MonetizationController;
use BuyMeCoffee\Helpers\PaymentHelper;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class MembershipAjaxHandler
{
    public function register()
    {
        add_action('buymecoffee_admin_ajax_handler_catch', [$this, 'handle']);
    }

    public function handle($route)
    {
        $data = isset($_REQUEST['data']) ? $this->sanitizeData(wp_unslash($_REQUEST['data'])) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified in AdminAjaxHandler before this catch-all route.

        switch ($route) {
            case 'get_membership_levels':
                return $this->getMembershipLevels();
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
                return $this->getCategoriesForMembership();
            case 'get_membership_members':
                return $this->getMembershipMembers($data);
            case 'send_membership_invite':
                return $this->sendMembershipInvite($data);
        }
    }

    private function getMembershipLevels()
    {
        $levels = (new MembershipLevel())->getActive();
        wp_send_json_success(['levels' => $levels]);
    }

    private function saveMembershipLevel($data)
    {
        $name = isset($data['name']) ? sanitize_text_field($data['name']) : '';
        if (empty($name)) {
            wp_send_json_error(['message' => __('Level name is required.', 'buy-me-coffee')]);
            return;
        }

        $allowedIntervals = ['month', 'year'];
        $allowedStatuses  = ['active', 'inactive'];
        $allowedAccess    = ['full', 'preview'];

        $price        = isset($data['price']) ? absint($data['price']) : 0;
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
            'interval_type' => $intervalType,
            'status'       => $status,
            'rewards'      => wp_json_encode($rewards),
            'access_rules' => wp_json_encode($accessRules),
            'updated_at'   => current_time('mysql'),
        ];

        $model = new MembershipLevel();
        $id    = isset($data['id']) ? absint($data['id']) : 0;

        if ($id) {
            $existing = $model->find($id);
            if (!$existing) {
                wp_send_json_error(['message' => __('Level not found.', 'buy-me-coffee')]);
                return;
            }
            $model->updateData($id, $saveData);
        } else {
            $saveData['sort_order'] = buyMeCoffeeQuery()
                ->table('buymecoffee_membership_levels')
                ->where('status', '!=', 'deleted')
                ->count();
            $saveData['created_at'] = current_time('mysql');
            $id = $model->create($saveData);
        }

        $saved = $model->find($id);
        wp_send_json_success([
            'level'   => $model->decodeJsonFields($saved),
            'message' => __('Membership level saved.', 'buy-me-coffee'),
        ]);
    }

    private function deleteMembershipLevel($data)
    {
        $id = isset($data['id']) ? absint($data['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid level ID.', 'buy-me-coffee')]);
            return;
        }

        // Block deletion if active subscriptions reference this level
        $activeCount = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->where('level_id', $id)
            ->where('status', 'active')
            ->count();

        if ($activeCount > 0) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %d: number of active subscribers */
                    __('Cannot delete: %d active subscriber(s) on this level. Cancel their subscriptions first.', 'buy-me-coffee'),
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
            $updated[$key] = isset($data[$key]) ? (bool) $data[$key] : $current[$key];
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

    private function getCategoriesForMembership()
    {
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        $result = [];
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $result[] = [
                    'id'   => (int) $term->term_id,
                    'name' => $term->name,
                ];
            }
        }
        wp_send_json_success(['categories' => $result]);
    }

    private function getMembershipMembers($data)
    {
        $page          = isset($data['page']) ? max(0, absint($data['page'])) : 0;
        $postsPerPage  = 20;
        $search        = isset($data['search']) ? sanitize_text_field($data['search']) : '';

        $offset = $page * $postsPerPage;
        $query = $this->buildMembershipMembersQuery($search);
        $total = (int) $this->buildMembershipMembersQuery($search)->count();
        $rows = $query
            ->select(
                'buymecoffee_subscriptions.id',
                'buymecoffee_subscriptions.id as subscription_id',
                'buymecoffee_subscriptions.created_at',
                'buymecoffee_subscriptions.status',
                'buymecoffee_subscriptions.current_period_end',
                'buymecoffee_subscriptions.interval_type',
                'buymecoffee_subscriptions.amount',
                'buymecoffee_subscriptions.currency',
                'buymecoffee_supporters.supporters_name',
                'buymecoffee_supporters.supporters_email',
                'buymecoffee_membership_levels.name as level_name'
            )
            ->orderBy('buymecoffee_subscriptions.created_at', 'DESC')
            ->limit($postsPerPage)
            ->offset($offset)
            ->get();

        wp_send_json_success([
            'members' => $rows ?: [],
            'total'   => $total,
        ]);
    }

    private function buildMembershipMembersQuery($search)
    {
        $query = buyMeCoffeeQuery()
            ->table('buymecoffee_subscriptions')
            ->leftJoin('buymecoffee_supporters', 'buymecoffee_subscriptions.supporter_id', '=', 'buymecoffee_supporters.id')
            ->leftJoin('buymecoffee_membership_levels', 'buymecoffee_subscriptions.level_id', '=', 'buymecoffee_membership_levels.id')
            ->whereNotNull('buymecoffee_subscriptions.level_id')
            ->where(function ($whereQuery) {
                $whereQuery->where('buymecoffee_subscriptions.status', 'active')
                    ->orWhere(function ($cancelledQuery) {
                        $cancelledQuery->where('buymecoffee_subscriptions.status', 'cancelled')
                            ->whereNotNull('buymecoffee_subscriptions.current_period_end')
                            ->where('buymecoffee_subscriptions.current_period_end', '>', current_time('mysql', true));
                    });
            });

        if ($search) {
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

        $subscriptionId = $this->grantInviteSubscription($userId, $supporterId, $level);
        if (!$subscriptionId) {
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
            ->table('buymecoffee_subscriptions')
            ->whereIn('supporter_id', $supporterIds)
            ->where('level_id', (int) $level->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return 0;
        }

        $subscriptionId = buyMeCoffeeQuery()->table('buymecoffee_subscriptions')->insert([
            'supporter_id'            => (int) $supporterId,
            'stripe_subscription_id'  => null,
            'stripe_customer_id'      => null,
            'interval_type'           => sanitize_text_field($level->interval_type ?: 'month'),
            'amount'                  => 0,
            'currency'                => strtolower(PaymentHelper::getCurrency()),
            'status'                  => 'active',
            'payment_mode'            => 'manual',
            'current_period_end'      => null,
            'cancelled_at'            => null,
            'level_id'                => (int) $level->id,
            'created_at'              => current_time('mysql'),
            'updated_at'              => current_time('mysql'),
        ]);

        if ($subscriptionId && function_exists('buymecoffee_delete_supporter_meta')) {
            buymecoffee_delete_supporter_meta((int) $supporterIds[0], 'active_level_ids');
        }

        if ($subscriptionId) {
            do_action('buymecoffee_subscription_activated', (int) $subscriptionId);
        }

        return (int) $subscriptionId;
    }

    private function sanitizeData($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeData'], $data);
        }
        return sanitize_text_field((string) $data);
    }
}
