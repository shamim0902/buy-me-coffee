# Plugin Audit Report — Buy Me a Coffee button & widgets - Fundraise with Stripe and PayPal
**Branch:** main | **Date:** 2026-05-02 | **Auditor:** GPT-5 (5-workstream + Pass 6 verification)

---

## 1) Executive Summary

- Overall risk level: High
- Severity snapshot:

| Severity | Count |
|---|---|
| CRITICAL | 0 |
| HIGH | 3 |
| MEDIUM | 5 |
| SUGGESTION | 3 |

- Top 3 risks:
  - Production assets are forced through the Vite development server on `http://localhost:3000`, which breaks live sites and can execute localhost-served JavaScript inside WordPress admin pages.
  - The custom read-only capability boundary exposes supporter PII, subscription records, activity logs, and payment metadata through admin AJAX routes.
  - Several dashboard/admin queries load unbounded data and will not scale to large supporter/subscription tables.
- Audit scope notes:
  - Workstream 1 Security: reviewed nonce/capability checks, public AJAX endpoints, payment confirmations, IPN/webhooks, account creation, output escaping, secrets, and remote calls.
  - Workstream 2 Performance and optimization: reviewed hot admin queries, model access patterns, dashboard stats, and public shortcode queries.
  - Workstream 3 Dead code and duplication: reviewed stale abstractions, release/development switches, route/method duplication, and unused model helpers.
  - Workstream 4 UI-to-handler traceability: traced Vue/admin actions, shortcodes, public forms, and payment widgets to PHP handlers.
  - Workstream 5 Handler-to-service/database traceability: traced handlers through models, gateway APIs, local tables, side effects, and JSON responses.
  - Pass 6 verification: re-traced every High candidate from entry point to effect and attempted to disprove exploitability with parent auth, nonce, gateway, and helper checks.

## 2) Table of Contents

- [HIGH-01: Production loads admin and public scripts from localhost Vite dev server](#high-01-production-loads-admin-and-public-scripts-from-localhost-vite-dev-server)
- [HIGH-02: View-only capability can read donor PII and payment records](#high-02-view-only-capability-can-read-donor-pii-and-payment-records)
- [HIGH-03: Subscription stats load every active subscription into PHP memory](#high-03-subscription-stats-load-every-active-subscription-into-php-memory)
- [MEDIUM-01: Public payment confirmation can create and auto-login an unverified email account](#medium-01-public-payment-confirmation-can-create-and-auto-login-an-unverified-email-account)
- [MEDIUM-02: Admin list pagination accepts attacker-sized page limits](#medium-02-admin-list-pagination-accepts-attacker-sized-page-limits)
- [MEDIUM-03: Settings route loads all published pages without a limit](#medium-03-settings-route-loads-all-published-pages-without-a-limit)
- [MEDIUM-04: Supporter detail loads unbounded donation history by email](#medium-04-supporter-detail-loads-unbounded-donation-history-by-email)
- [MEDIUM-05: Custom capabilities cannot access the WP admin menu they are authorized for](#medium-05-custom-capabilities-cannot-access-the-wp-admin-menu-they-are-authorized-for)
- [SUGGESTION-01: Missing supporter IDs can fatal instead of returning JSON errors](#suggestion-01-missing-supporter-ids-can-fatal-instead-of-returning-json-errors)
- [SUGGESTION-02: Base model exposes an unbounded all helper](#suggestion-02-base-model-exposes-an-unbounded-all-helper)
- [SUGGESTION-03: Public receipt page is a bearer URL with no additional viewer proof](#suggestion-03-public-receipt-page-is-a-bearer-url-with-no-additional-viewer-proof)

## 3) Findings by Severity

### Critical

No Critical findings were confirmed in this pass.

### High

#### HIGH-01: Production loads admin and public scripts from localhost Vite dev server

- Area: Security
- Confidence: High
- File:line: `buy-me-coffee.php:42`, `includes/Classes/Vite.php:152`
- Evidence: `BUYMECOFFEE_DEVELOPMENT` is hard-coded to `'yes'`, and `Vite::isDevMode()` then serves scripts/styles from `http://localhost:3000/src/...` via `getDevPath()`.
- Impact: Live frontend and admin screens fail unless every visitor/admin has a Vite server on their own machine. If an admin has an unrelated or malicious local service on port 3000, this plugin will execute that JavaScript inside the WordPress admin page context.
- Recommended fix: Default `BUYMECOFFEE_DEVELOPMENT` to false for distributable builds, derive dev mode from an explicit local constant/environment flag, and fail closed to manifest assets when `assets/manifest.json` exists.
- Task statement: Replace the hard-coded development constant with a production default and update `Vite::isDevMode()` so packaged plugins always load files from `assets/`.
- Verifier note: Confirmed from boot to enqueue: public shortcode/admin assets call `Vite::enqueueScript()`, `isDevMode()` returns true from the constant, and `getDevPath()` returns the localhost URL without any production guard.

#### HIGH-02: View-only capability can read donor PII and payment records

- Area: Security
- Confidence: High
- File:line: `includes/Classes/AccessControl.php:20`, `includes/Classes/AdminAjaxHandler.php:1114`
- Evidence: `hasTopLevelMenuPermission()` accepts `buy-me-coffee_can_view_menus`; `canAccessRoute()` lets that capability access read routes including `get_supporters`, `get_supporter`, `get_subscriptions`, `get_subscription`, `get_activities`, and `get_email_notifications`.
- Impact: Any role granted the menu-view capability can retrieve supporter names, emails, subscription rows, activity contexts, transaction history, and notification templates. This is materially stronger than viewing a menu and creates a delegated-role PII disclosure boundary drift.
- Recommended fix: Split capabilities by data class, for example `buy-me-coffee_view_reports`, `buy-me-coffee_view_supporters`, `buy-me-coffee_view_payments`, and `buy-me-coffee_manage_settings`; gate PII/payment routes with the stronger capabilities.
- Task statement: Replace the single read-route permission with per-route capability checks and document which custom capability grants access to supporter PII and financial records.
- Verifier note: Confirmed through `wp_ajax_buymecoffee_admin_ajax`: nonce and top-level permission are required, but no later route-specific PII/payment capability check is applied for the listed read routes.

#### HIGH-03: Subscription stats load every active subscription into PHP memory

- Area: Optimization
- Confidence: High
- File:line: `includes/Models/Subscriptions.php:68`
- Evidence: `getStats()` runs `->select('amount', 'interval_type')->where('status', 'active')->get()` and then calculates MRR in PHP across the full active subscription set.
- Impact: On a site with tens or hundreds of thousands of active subscriptions, opening dashboard/subscriptions stats can allocate a full active-subscription collection and degrade or exhaust PHP workers.
- Recommended fix: Calculate MRR in SQL using conditional aggregation, return only scalar totals, and add indexed predicates for `status` and `interval_type`.
- Task statement: Rewrite `Subscriptions::getStats()` to use SQL aggregate expressions for monthly/yearly MRR instead of loading all active rows.
- Verifier note: Confirmed through admin route `get_subscription_stats` to `AdminAjaxHandler::getSubscriptionStats()` to `Subscriptions::getStats()`; no `limit`, paging, cache, or aggregate-only query exists on the active-subscription MRR path.

### Medium

#### MEDIUM-01: Public payment confirmation can create and auto-login an unverified email account

- Area: Security
- Confidence: Med
- File:line: `includes/Classes/UserManager.php:101`, `includes/Classes/UserManager.php:125`
- Evidence: After `buymecoffee_subscription_activated`, `getOrCreateUser()` creates a subscriber for the submitted supporter email, and `maybeAutoLogin()` calls `wp_set_auth_cookie()` during public AJAX payment confirmation for newly created users.
- Impact: A payer can submit an email they do not control, complete a recurring payment, and get logged into a new WordPress subscriber account using that email. This does not grant admin access, but it weakens email ownership and identity assumptions for sites that rely on subscriber accounts.
- Recommended fix: Do not auto-login accounts until the email owner verifies through a signed magic link or password-set flow; alternatively bind auto-login to a one-time server-side token created for the payment session.
- Task statement: Remove automatic auth-cookie issuance from public payment confirmation and require email verification before first account login.

#### MEDIUM-02: Admin list pagination accepts attacker-sized page limits

- Area: Optimization
- Confidence: High
- File:line: `includes/Models/Supporters.php:20`, `includes/Models/Subscriptions.php:25`
- Evidence: `Supporters::index()` uses `$args['posts_per_page']` directly in `limit()`, while `Subscriptions::index()` sets a minimum but no maximum before applying `limit($posts_per_page)`.
- Impact: A low-trust admin user who can call read routes can request very large pages and force large database reads and JSON responses.
- Recommended fix: Clamp every `posts_per_page` to a small maximum such as 100 and reject invalid paging shapes before building queries.
- Task statement: Add upper bounds to supporter and subscription pagination parameters before they reach model queries.

#### MEDIUM-03: Settings route loads all published pages without a limit

- Area: Optimization
- Confidence: High
- File:line: `includes/Classes/AdminAjaxHandler.php:543`
- Evidence: `getSettings()` calls `get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC', 'post_status' => 'publish'])` without `number`, `fields`, or caching.
- Impact: Sites with large page trees load full `WP_Post` objects every time the settings screen is opened, causing avoidable memory and latency.
- Recommended fix: Use `get_posts()` or `WP_Query` with `post_type => 'page'`, `fields => 'ids'`, a bounded `posts_per_page`, and a search endpoint for large sites.
- Task statement: Replace the full-page load in `AdminAjaxHandler::getSettings()` with a capped page selector API.

#### MEDIUM-04: Supporter detail loads unbounded donation history by email

- Area: Optimization
- Confidence: High
- File:line: `includes/Models/Supporters.php:179`
- Evidence: `Supporters::find()` loads every donation sharing the same email, then loads every matching transaction via `whereIn('entry_id', $supporterIds)->get()`.
- Impact: A frequent donor or shared address can make a single supporter-detail view load thousands of rows and transactions into PHP memory.
- Recommended fix: Add pagination for related donations and calculate lifetime totals with SQL aggregates instead of loading all rows.
- Task statement: Refactor supporter detail to page related donations and compute lifetime totals with aggregate queries.

#### MEDIUM-05: Custom capabilities cannot access the WP admin menu they are authorized for

- Area: Traceability
- Confidence: High
- File:line: `includes/Classes/Menu.php:25`, `includes/Classes/AccessControl.php:20`
- Evidence: `AccessControl::hasTopLevelMenuPermission()` allows `buy-me-coffee_full_access` and `buy-me-coffee_can_view_menus`, but `add_menu_page()` is registered with the hard-coded capability `manage_options`.
- Impact: Users with plugin-specific capabilities pass AJAX/direct-page checks but may not see or access the normal WordPress menu entry, producing a broken role workflow and encouraging direct URL access.
- Recommended fix: Register the menu with a plugin-specific capability that matches the access-control helper, or register separate menu entries/pages for read-only and full-access roles.
- Task statement: Align `add_menu_page()` capability with the plugin access-control model and route-level permissions.

### Suggestion

#### SUGGESTION-01: Missing supporter IDs can fatal instead of returning JSON errors

- Area: Traceability
- Confidence: High
- File:line: `includes/Models/Supporters.php:140`, `includes/Classes/AdminAjaxHandler.php:294`
- Evidence: `Supporters::find()` throws `WpFluent\Exception` when no row exists; `deleteSupporter()` calls it directly and then checks `$supporter` after the exception point.
- Impact: Invalid or stale supporter IDs can produce PHP fatals/500 responses instead of predictable JSON errors.
- Recommended fix: Make model lookups return `null` for normal not-found cases or catch the exception at every AJAX boundary.
- Task statement: Update `deleteSupporter()` and other `Supporters::find()` callers to handle not-found results without uncaught exceptions.

#### SUGGESTION-02: Base model exposes an unbounded all helper

- Area: Optimization
- Confidence: Med
- File:line: `includes/Models/Model.php:24`
- Evidence: `Model::all()` returns `$this->getQuery()->get()` with no filters, limits, or ordering.
- Impact: The helper is not currently a confirmed hot path, but it creates an easy future footgun for full-table loads.
- Recommended fix: Remove the helper or require explicit limit/scope arguments.
- Task statement: Delete `Model::all()` or replace it with a bounded, documented query helper.

#### SUGGESTION-03: Public receipt page is a bearer URL with no additional viewer proof

- Area: Security
- Confidence: Med
- File:line: `includes/views/templates/FormTemplate.php:84`, `includes/Models/Supporters.php:242`
- Evidence: Any request with `buymecoffee_success` and a valid `hash` loads `Supporters::getByHash()` and renders receipt details including name, email, amount, status, and message.
- Impact: The 128-bit random hash is strong against guessing, but anyone who receives or leaks the URL can view donor PII without an expiry or secondary check.
- Recommended fix: Keep public thank-you receipts minimal, hide email by default, and require login or a short-lived signed token for full receipt details.
- Task statement: Reduce public receipt fields and add an expiring receipt token or account-bound receipt view for PII.

## 4) Prioritized Backlog (Quick Wins First)

1. [ ] Change production asset loading so packaged builds use `assets/manifest.json` and never default to `localhost:3000`.
2. [ ] Split admin AJAX route permissions by data sensitivity and require stronger capabilities for supporter PII, subscriptions, activity logs, settings, and payment actions.
3. [ ] Rewrite `Subscriptions::getStats()` to use database aggregation for MRR.
4. [ ] Clamp `posts_per_page` on supporter/subscription list endpoints.
5. [ ] Replace unbounded page loading in settings with a capped/searchable page selector.
6. [ ] Remove public payment-confirmation auto-login until email ownership is verified.
7. [ ] Page supporter detail history and move totals to aggregate SQL.
8. [ ] Align `add_menu_page()` capability with plugin-specific access controls.
9. [ ] Normalize model not-found behavior so AJAX routes return JSON errors.
10. [ ] Reduce public receipt PII and make full receipt access account-bound or token-expiring.

## 5) Needs Manual Verification

- None for the confirmed Critical/High findings. Gateway staging should still validate Stripe webhook replay handling, PayPal Standard refund IPNs, and role-permission QA after remediation.
