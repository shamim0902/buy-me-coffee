# Plugin Audit Report — Buy Me a Coffee button & widgets - Fundraise with Stripe and PayPal
**Branch:** membership | **Date:** 2026-05-03 | **Auditor:** GPT-5 Codex (5-workstream + Pass 6 verification)

---

## 1) Executive Summary

- Overall risk level: High for the membership implementation introduced by `2f37c9629a9e8a1986cb6b5dc9d69ed8ccacb223`.
- Severity snapshot:

| Severity | Count |
|---|---|
| CRITICAL | 0 |
| HIGH | 1 |
| MEDIUM | 5 |
| SUGGESTION | 2 |

- Top 3 risks:
  - Paid membership level, amount, interval, and active status are not revalidated server-side before a Stripe subscription grants access.
  - Membership settings and access-rule UI expose controls that are not enforced in the payment or content-access paths.
  - Free invite and member-list flows do not yet match their admin UI promises.
- Audit scope notes:
  - Scope was the membership commit `2f37c9629a9e8a1986cb6b5dc9d69ed8ccacb223` and the current `membership` branch state.
  - Automated checks run: `npm run build`, PHP syntax checks for every PHP file changed by the commit, and `git diff --check`.
  - Five workstreams were emulated locally: Security, Performance/Optimization, Dead Code/Duplication, UI-to-handler Traceability, and Handler-to-database/response Traceability. Pass 6 re-traced every High finding.
- Accepted intentional behavior:
  - Subscription activation may create and authenticate a new WordPress user for the submitted supporter email. This is intentional product behavior and is excluded from future audit findings unless the implementation expands privileges beyond the subscriber/supporter account scope.

## 2) Table of Contents

- [HIGH-01: Client-controlled membership level price grants paid access](#high-01-client-controlled-membership-level-price-grants-paid-access)
- [MEDIUM-01: Pause membership setting is not enforced](#medium-01-pause-membership-setting-is-not-enforced)
- [MEDIUM-02: Free membership invite never grants free access](#medium-02-free-membership-invite-never-grants-free-access)
- [MEDIUM-03: Level access rules are not used for content authorization](#medium-03-level-access-rules-are-not-used-for-content-authorization)
- [MEDIUM-04: Zero preview words does not use the global default](#medium-04-zero-preview-words-does-not-use-the-global-default)
- [MEDIUM-05: Existing linked members may keep stale access after buying a new level](#medium-05-existing-linked-members-may-keep-stale-access-after-buying-a-new-level)
- [SUGGESTION-01: Post meta REST auth callback is broader than the target post](#suggestion-01-post-meta-rest-auth-callback-is-broader-than-the-target-post)
- [SUGGESTION-02: Members table includes non-active subscriptions](#suggestion-02-members-table-includes-non-active-subscriptions)

## 3) Findings by Severity

### Critical

No Critical findings confirmed.

### High

#### HIGH-01: Client-controlled membership level price grants paid access

- Area: Security
- Confidence: High
- File:line: includes/Controllers/SubmissionHandler.php:58
- Evidence: `SubmissionHandler::handleSubmission()` calculates `payment_total` from serialized public form fields, then passes `bmc_level_id` through to Stripe without loading the level server-side. `Stripe::makePayment()` copies the submitted `bmc_level_id` into `$paymentArgs`; `StripeSubscriptions::createSubscription()` stores that ID as `level_id`; `MonetizationController::userHasAccess()` grants access from active subscription `level_id`.
- Impact: A visitor can submit a cheap or modified amount while keeping `bmc_level_id` for an expensive level. After the smaller Stripe subscription succeeds, the local subscription is marked active for the target level and gated posts for that level become accessible.
- Recommended fix: When `bmc_level_id` is present, load the active membership level on the server, require Stripe, force recurring mode, overwrite `payment_total` from `MembershipLevel::price`, overwrite `recurring_interval` from `interval_type`, and reject inactive/deleted/missing levels. At confirmation/webhook time, verify the Stripe subscription amount and currency match the saved level price.
- Task statement: Implement server-side membership checkout binding so level ID, amount, currency, interval, status, and payment method are derived from the database instead of public form fields.
- Verifier note: Pass 6 confirmed the path from public AJAX submission to `buymecoffee_subscriptions.level_id` and then to content access; no later amount-to-level validation was found.

### Medium

#### MEDIUM-01: Pause membership setting is not enforced

- Area: Traceability
- Confidence: High
- File:line: includes/Classes/MembershipAjaxHandler.php:195
- Evidence: `membership_active` is saved in `buymecoffee_membership_settings`, but searches show no enforcement in `MonetizationController::renderPaywallCta()`, `prePopulateFormFromLevel()`, `SubmissionHandler::handleSubmission()`, or `StripeSubscriptions::createSubscription()`.
- Impact: The admin UI says pausing stops new members from joining, but paywall CTAs and direct `bmc_level_id` checkout flows continue accepting new membership subscriptions.
- Recommended fix: Check `membership_active` before rendering join CTAs, before pre-populating membership checkout, and again in public submission handling. Reject level checkouts while paused.
- Task statement: Enforce the pause-membership setting at paywall rendering and server-side checkout creation.

#### MEDIUM-02: Free membership invite never grants free access

- Area: Traceability
- Confidence: High
- File:line: includes/Classes/MembershipAjaxHandler.php:298
- Evidence: `sendMembershipInvite()` validates an email and sends a message saying the recipient has free access, but it does not create a user, supporter, subscription, level assignment, claim token, coupon, or access grant.
- Impact: The "Membership giveaway" admin flow is functionally misleading. Recipients receive a normal join URL and cannot claim free membership access.
- Recommended fix: Define a real invite model: create signed single-use invite tokens tied to a level and email, redeem them into a free subscription/access record, and expire or revoke them when used.
- Task statement: Implement redeemable membership invites instead of sending a plain checkout link.

#### MEDIUM-03: Level access rules are not used for content authorization

- Area: Traceability
- Confidence: High
- File:line: includes/Controllers/MonetizationController.php:47
- Evidence: `LevelEdit.vue` collects `access_rules.post_types`, `categories`, `preview_words`, and `access_level`; `filterLevelsForPost()` only uses rules to decide which level cards appear in the paywall. Actual authorization uses only post meta `_buymecoffee_level_ids` via `userHasAccess()`.
- Impact: Admins can configure level-level content access rules that do not grant access unless every post is also manually assigned matching level IDs. The `access_level = preview` setting is stored but has no runtime effect.
- Recommended fix: Pick one source of truth. Either remove the unused level access-rule controls or make `userHasAccess()` evaluate level rules against post type/category and respect `access_level`.
- Task statement: Align membership level access-rule UI with the actual content authorization logic.

#### MEDIUM-04: Zero preview words does not use the global default

- Area: Traceability
- Confidence: High
- File:line: includes/Controllers/MonetizationController.php:62
- Evidence: Both classic and Gutenberg UI say preview words `0` means global default, but `getPreviewWordCount()` treats numeric `0` as an override and returns `max(1, 0)`, producing a one-word teaser instead.
- Impact: Posts saved with the documented default value show only one teaser word, making paid content previews look broken.
- Recommended fix: Treat empty, false, non-numeric, and `<= 0` post meta as "use global default"; only apply a post override when the saved value is greater than zero.
- Task statement: Fix `getPreviewWordCount()` so `0` follows the documented global-default behavior.

#### MEDIUM-05: Existing linked members may keep stale access after buying a new level

- Area: Traceability
- Confidence: High
- File:line: includes/Classes/UserManager.php:46
- Evidence: `UserManager::handleSubscriptionActivated()` returns early when the supporter already has `wp_user_id`, before calling `syncSubscriptionAccessMeta()`. The active level IDs are cached in supporter meta by `buymecoffee_user_get_active_level_ids()`.
- Impact: A logged/linked member who buys another level can keep a stale `active_level_ids` cache and be denied newly purchased content until another event clears the cache.
- Recommended fix: Always call `syncSubscriptionAccessMeta()` for the linked user on subscription activation, even when no new WP user needs to be created.
- Task statement: Invalidate membership access cache for existing linked supporters when a subscription is activated.

### Suggestion

#### SUGGESTION-01: Post meta REST auth callback is broader than the target post

- Area: Security
- Confidence: Med
- File:line: includes/Classes/PostAccessMetaBox.php:28
- Evidence: The three registered meta fields use `auth_callback` closures that return `current_user_can('edit_posts')`, without checking the specific `$post_id`.
- Impact: WordPress post REST updates normally run their own post-level permission checks, but the meta callback itself is broader than the data being modified and can become unsafe if reused through another meta update path.
- Recommended fix: Accept the callback parameters and return `current_user_can('edit_post', $post_id)` for each registered meta key.
- Task statement: Narrow membership post-meta auth callbacks to the exact post being edited.

#### SUGGESTION-02: Members table includes non-active subscriptions

- Area: Traceability
- Confidence: High
- File:line: includes/Classes/MembershipAjaxHandler.php:260
- Evidence: The Members tab title is "Active Members", but `getMembershipMembers()` queries every subscription with `level_id IS NOT NULL`, including `incomplete`, `failed`, and `cancelled` rows.
- Impact: The admin membership list can overstate actual active membership and make failed/incomplete checkout attempts look like members.
- Recommended fix: Either rename the UI to "All membership subscriptions" or filter by access-granting statuses using the same active/cancelled-period logic used for content access.
- Task statement: Make the membership members query match the label and access semantics shown in the admin UI.

## 4) Prioritized Backlog (Quick Wins First)

1. [ ] Bind membership checkout to the server-side level record and reject mismatched amount, currency, interval, inactive status, and non-Stripe methods.
2. [ ] Enforce `membership_active` before rendering/joining membership levels.
3. [ ] Fix preview word `0` handling and existing-user cache invalidation.
4. [ ] Either implement or remove unused level access-rule, invite, and display settings.
5. [ ] Tighten post-meta auth callbacks and adjust Members tab status filtering.

## 5) Needs Manual Verification

- Finding key: Runtime checkout and webhook confirmation
  - Area: Traceability
  - File:line: includes/Builder/Methods/Stripe/StripeSubscriptions.php:22
  - Why uncertain: Static analysis confirms the server-side paths, but no live Stripe checkout/webhook was executed in this local audit.
  - Manual test to confirm: In a WordPress test site, create a paid level, tamper the hidden amount before checkout, complete Stripe test payment, and verify the resulting subscription `level_id`, amount, and gated-post access.
