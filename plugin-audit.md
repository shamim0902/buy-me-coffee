# Plugin Audit Report — Buy Me Coffee
**Branch:** main | **Date:** 2026-08-15 | **Auditor:** GPT-5 Codex (5-workstream + Pass 6 verification)

---

## 1) Executive Summary

- Overall risk level: **High**. The plugin has sound nonce and capability checks on the principal admin routes, server-side membership price binding, and authenticates Stripe events by re-fetching them. However, the confirmed payment-state, subscription-deletion, migration, and reporting defects make the current build unsuitable for an unqualified production release.
- Severity snapshot:

| Severity | Count |
|---|---:|
| CRITICAL | 0 |
| HIGH | 5 |
| MEDIUM | 29 |
| SUGGESTION | 8 |

- Top 3 risks:
  - PayPal Standard test mode can accept an unauthenticated forged IPN and mark a known pending transaction paid when the exposed verification-disable setting is enabled.
  - Deleting a supporter removes the local subscription record without cancelling the live Stripe subscription, so billing can continue without a local management record.
  - A genuine Stripe `charge.refunded` event can be mapped from the Charge object's generic `succeeded` status back to local `paid`, restoring one-time membership access after a refund.
- Audit scope notes:
  - Five independent workstreams covered security, optimization, dead code, UI-to-handler traceability, and handler-to-database/service traceability; every High finding received an independent Pass 6 re-check.
  - Automated verification now includes 13 WordPress integration tests with 123 assertions, 11 read-only HTTP smoke checks, PHP syntax compatibility checks, and a complete Vite production build. See `tests/README.md` and `tests/FEATURE-MATRIX.md`.
  - Local integration and HTTP tests passed. Real Stripe/PayPal, email delivery, browser interaction, large-data performance, accessibility, and upgrade tests remain explicit staging gates.
  - No SQL injection, stored/reflected XSS, arbitrary file operation, SSRF, privilege-bypass, secret-commit, or cross-user subscription-cancellation exploit was confirmed in the reviewed paths.

## 2) Table of Contents

- [HIGH-01: PayPal test-mode IPN verification bypass permits forged paid transactions](#high-01-paypal-test-mode-ipn-verification-bypass-permits-forged-paid-transactions)
- [HIGH-02: Deleting a supporter can leave a live Stripe subscription billing](#high-02-deleting-a-supporter-can-leave-a-live-stripe-subscription-billing)
- [HIGH-03: Stripe refund webhooks can restore paid state and membership access](#high-03-stripe-refund-webhooks-can-restore-paid-state-and-membership-access)
- [HIGH-04: Synchronous membership migration can exhaust request resources and repeat indefinitely](#high-04-synchronous-membership-migration-can-exhaust-request-resources-and-repeat-indefinitely)
- [HIGH-05: Supporter aggregates scan full datasets and multiply revenue through join fan-out](#high-05-supporter-aggregates-scan-full-datasets-and-multiply-revenue-through-join-fan-out)
- [MEDIUM-01: Public write and gateway endpoints lack anti-automation and idempotency controls](#medium-01-public-write-and-gateway-endpoints-lack-anti-automation-and-idempotency-controls)
- [MEDIUM-02: Delegated supporter viewers can retrieve raw gateway payloads](#medium-02-delegated-supporter-viewers-can-retrieve-raw-gateway-payloads)
- [MEDIUM-03: Successful webhook paths bypass the canonical payment transition side effects](#medium-03-successful-webhook-paths-bypass-the-canonical-payment-transition-side-effects)
- [MEDIUM-04: Checkout persistence failures can leave orphaned local or remote payment state](#medium-04-checkout-persistence-failures-can-leave-orphaned-local-or-remote-payment-state)
- [MEDIUM-05: Monetary reports combine unrelated currencies into a single value](#medium-05-monetary-reports-combine-unrelated-currencies-into-a-single-value)
- [MEDIUM-06: Dashboard range selection produces inconsistent and incomplete metrics](#medium-06-dashboard-range-selection-produces-inconsistent-and-incomplete-metrics)
- [MEDIUM-07: Supporter CSV export silently truncates at 100 rows](#medium-07-supporter-csv-export-silently-truncates-at-100-rows)
- [MEDIUM-08: Membership invite failure can conceal an already-granted entitlement](#medium-08-membership-invite-failure-can-conceal-an-already-granted-entitlement)
- [MEDIUM-09: Destructive local deletes leave report and public caches stale](#medium-09-destructive-local-deletes-leave-report-and-public-caches-stale)
- [MEDIUM-10: Concurrent refund requests can issue duplicate provider refunds](#medium-10-concurrent-refund-requests-can-issue-duplicate-provider-refunds)
- [MEDIUM-11: Delegated users see routes and controls they are not authorized to call](#medium-11-delegated-users-see-routes-and-controls-they-are-not-authorized-to-call)
- [MEDIUM-12: The Cancelled payment status offered by the UI is always rejected](#medium-12-the-cancelled-payment-status-offered-by-the-ui-is-always-rejected)
- [MEDIUM-13: Three supporter-wall and privacy controls are inert](#medium-13-three-supporter-wall-and-privacy-controls-are-inert)
- [MEDIUM-14: Three membership display and billing controls are inert](#medium-14-three-membership-display-and-billing-controls-are-inert)
- [MEDIUM-15: Membership access rules and preview length are applied inconsistently](#medium-15-membership-access-rules-and-preview-length-are-applied-inconsistently)
- [MEDIUM-16: Membership recovery messaging targets administrators instead of cancelling members](#medium-16-membership-recovery-messaging-targets-administrators-instead-of-cancelling-members)
- [MEDIUM-17: Manual membership access cannot be revoked through the plugin UI or API](#medium-17-manual-membership-access-cannot-be-revoked-through-the-plugin-ui-or-api)
- [MEDIUM-18: Multiple modal button shortcodes share the same DOM IDs](#medium-18-multiple-modal-button-shortcodes-share-the-same-dom-ids)
- [MEDIUM-19: The basic shortcode terminates normal WordPress page rendering](#medium-19-the-basic-shortcode-terminates-normal-wordpress-page-rendering)
- [MEDIUM-20: Subscription status counts are hard-coded or overwritten by filters](#medium-20-subscription-status-counts-are-hard-coded-or-overwritten-by-filters)
- [MEDIUM-21: Vite reads and decodes the manifest on ordinary WordPress requests](#medium-21-vite-reads-and-decodes-the-manifest-on-ordinary-wordpress-requests)
- [MEDIUM-22: Status polling and admin-bar hooks cause repeated full-table work](#medium-22-status-polling-and-admin-bar-hooks-cause-repeated-full-table-work)
- [MEDIUM-23: Stripe confirmation performs duplicate remote and detail-heavy local reads](#medium-23-stripe-confirmation-performs-duplicate-remote-and-detail-heavy-local-reads)
- [MEDIUM-24: Membership cache misses begin with an unbounded supporter-ID query](#medium-24-membership-cache-misses-begin-with-an-unbounded-supporter-id-query)
- [MEDIUM-25: Test-data cleanup is an unbounded AJAX job without supporting mode indexes](#medium-25-test-data-cleanup-is-an-unbounded-ajax-job-without-supporting-mode-indexes)
- [MEDIUM-26: Supporter activity lookup materializes an unbounded transaction-ID list](#medium-26-supporter-activity-lookup-materializes-an-unbounded-transaction-id-list)
- [MEDIUM-27: The configured default avatar asset is missing](#medium-27-the-configured-default-avatar-asset-is-missing)
- [MEDIUM-28: Vite's missing-manifest-entry guard is unreachable in production](#medium-28-vites-missing-manifest-entry-guard-is-unreachable-in-production)
- [MEDIUM-29: Frontend build dependencies contain unresolved high-severity advisories](#medium-29-frontend-build-dependencies-contain-unresolved-high-severity-advisories)
- [SUGGESTION-01: Payment updates increment cache versions redundantly](#suggestion-01-payment-updates-increment-cache-versions-redundantly)
- [SUGGESTION-02: Admin media and font assets are loaded more broadly than needed](#suggestion-02-admin-media-and-font-assets-are-loaded-more-broadly-than-needed)
- [SUGGESTION-03: Stripe webhook secrets are collected but never used](#suggestion-03-stripe-webhook-secrets-are-collected-but-never-used)
- [SUGGESTION-04: The admin framework is instantiated and mixed in twice](#suggestion-04-the-admin-framework-is-instantiated-and-mixed-in-twice)
- [SUGGESTION-05: Orphaned components and copied images increase maintenance and package size](#suggestion-05-orphaned-components-and-copied-images-increase-maintenance-and-package-size)
- [SUGGESTION-06: The email activity producer has no caller](#suggestion-06-the-email-activity-producer-has-no-caller)
- [SUGGESTION-07: Unused access predicates duplicate the live entitlement rules](#suggestion-07-unused-access-predicates-duplicate-the-live-entitlement-rules)
- [SUGGESTION-08: Package metadata is stale and still uses a boilerplate identity](#suggestion-08-package-metadata-is-stale-and-still-uses-a-boilerplate-identity)

## 3) Findings by Severity

### Critical

No Critical finding was confirmed.

### High

#### HIGH-01: PayPal test-mode IPN verification bypass permits forged paid transactions

- Area: Security
- Confidence: High
- File:line: `buy-me-coffee.php:182-205`; `includes/Builder/Methods/PayPal/IPN.php:42-50,81-87,116-139`; `includes/Builder/Methods/PayPal/PayPal.php:276-310,399-485`; `src/js/Components/PayPal.vue:81-104`
- Evidence: The public IPN listener skips PayPal's `_notify-validate` round trip when payment mode is test and `disable_ipn_verification=yes`. It then accepts attacker-controlled `custom` as the local transaction ID. Standard checkout discloses that ID, amount, and currency in the PayPal redirect. Payee validation only rejects when both configured and received values are non-empty, and `updateStatus()` marks any test-mode transaction paid after amount/currency matching even if the posted payment status is not completed.
- Impact: An unauthenticated attacker can create a pending donation, learn its identifiers, and forge a paid test-mode record without paying. This corrupts revenue, supporter-wall, notification, and entitlement consumers that trust `paid`.
- Recommended fix: Remove the public verification bypass. Sandbox IPNs must receive a PayPal `VERIFIED` response. Require a present, exact `receiver_email`/`business` match, enforce terminal state transitions and replay protection, and place any fake-event facility behind an administrator-only test harness that cannot mutate ordinary transactions.
- Task statement: Delete the public test IPN bypass and implement an authenticated, isolated PayPal simulation harness with forged-IPN regression coverage.
- Verifier note: Pass 6 confirmed that test mode and the explicit setting are the only mitigations; no token, owner binding, source allowlist, or alternate authentication executes when verification is disabled.

#### HIGH-02: Deleting a supporter can leave a live Stripe subscription billing

- Area: Security
- Confidence: High
- File:line: `includes/Classes/AdminAjaxHandler.php:344-421,795-847`; `includes/Builder/Methods/Stripe/StripeSubscriptions.php:68-80,115-131`
- Evidence: `deleteSupporter()` deletes local subscription, membership-access, transaction, supporter, and activity rows, but never calls Stripe. The separate cancellation route does call Stripe and verify the returned state, proving deletion does not reuse that lifecycle. The remote subscription ID is stored locally when created and is lost during deletion.
- Impact: A donor can continue to be charged after the plugin reports that the supporter and all related data were deleted. Later webhooks cannot reconcile the charge to the erased local subscription.
- Recommended fix: Block deletion while a remote subscription is active, or cancel and verify every remote agreement before starting a transactional local delete. Abort and retain local records on remote failure, and report partial outcomes explicitly.
- Task statement: Make supporter deletion cancel and verify all remote recurring agreements before atomically deleting local records.
- Verifier note: Pass 6 re-traced every call in `deleteSupporter()` and found no provider cancellation or compensating operation; cancellation exists only on separate routes.

#### HIGH-03: Stripe refund webhooks can restore paid state and membership access

- Area: Security
- Confidence: High
- File:line: `includes/Builder/Methods/Stripe/Stripe.php:420-428,485-507,512-565`; `includes/Classes/AdminAjaxHandler.php:974-1005`; `includes/Models/MembershipAccess.php:134-163`
- Evidence: `charge.refunded` is accepted and authenticated by re-fetching the Stripe event, but one-time event handling ignores the event type and maps `data.object.status === succeeded` to local `paid`. A refunded Stripe Charge retains a generic succeeded charge status. `updateStatus()` then writes supporter and transaction state to paid, activates one-time membership access, and fires the canonical paid hook after the admin refund path had marked it refunded and revoked access.
- Impact: A genuinely refunded payment can reappear as revenue and restore premium access after the customer received their money back.
- Recommended fix: Implement an explicit event-type-to-local-state map (`charge.refunded` to `refunded`) using refund fields such as `refunded` and `amount_refunded`. Enforce terminal transition rules and event idempotency so generic success events cannot revive refunded transactions.
- Task statement: Replace generic Stripe object-status mapping with a typed payment state machine and regression-test refund event ordering and replay.
- Verifier note: Pass 6 confirmed the authentic-event check does not correct the semantic mapping. A final sandbox replay with an actual Stripe refund payload remains in the manual verification section.

#### HIGH-04: Synchronous membership migration can exhaust request resources and repeat indefinitely

- Area: Optimization
- Confidence: High
- File:line: `buy-me-coffee.php:55-57`; `includes/Classes/Activator.php:39-76,307-360,384-438`
- Evidence: `plugins_loaded` runs `maybeRunMigrations()` in the web request. The access backfill reads batches of 500 but performs a duplicate SELECT and INSERT decision for every subscription and one-time row, followed by broad normalization work. There is no process lock, durable cursor, background scheduling, or request time budget. The DB version is advanced only after the whole migration succeeds.
- Impact: Large sites can issue roughly two queries per migrated row, time out or exhaust memory during normal traffic, run concurrently, and restart the same work on every request when the version never advances.
- Recommended fix: Move the migration to a lock-protected resumable background job with a durable cursor, set-based `INSERT ... SELECT`/upsert operations, bounded batches, progress/error telemetry, and version advancement only after verified completion.
- Task statement: Convert membership-access backfill into a resumable, idempotent, lock-protected migration that stays within a fixed per-run query and time budget.
- Verifier note: Pass 6 confirmed the apparent 500-row batching does not bound the overall request and no lock, scheduler, cursor option, or maximum-run timer mitigates repeated execution.

#### HIGH-05: Supporter aggregates scan full datasets and multiply revenue through join fan-out

- Area: Optimization
- Confidence: High
- File:line: `includes/Models/Supporters.php:406-477,537-582`; `src/js/Components/SupportersList.vue:350-401`
- Evidence: Unique-supporter pagination first groups and counts the full supporter identity set, then joins supporter rows directly to both transactions and subscriptions before grouping and sorting. With N transactions and M subscription rows, SUM sees N×M joined rows while only the count uses DISTINCT. LIMIT/OFFSET is applied after the expensive aggregation; the related paginated request is uncached.
- Impact: List and leaderboard requests degrade sharply with data volume, and lifetime revenue/ranking is overstated whenever one supporter has multiple transactions and subscription rows. Financial output can be wrong while the request also becomes a database hot spot.
- Recommended fix: Aggregate paid transactions and active-subscription flags independently by supporter/email, then join those compact result sets. Add indexes for the selected grouping/filter/order paths and replace deep offset pagination with a cursor where practical.
- Task statement: Redesign supporter aggregates to eliminate join fan-out and bound work per page while preserving exact totals and ranking.
- Verifier note: Pass 6 confirmed LIMIT is downstream of count/group/sort and reproduced the cardinality logic: two paid transactions joined to two subscription rows yield four SUM inputs.

### Medium

#### MEDIUM-01: Public write and gateway endpoints lack anti-automation and idempotency controls

- Area: Security
- Confidence: High
- File:line: `buy-me-coffee.php:126,151-154,182-205`; `includes/Controllers/SubmissionHandler.php:15-155`; `includes/Builder/Methods/BaseMethods.php:35-37`
- Evidence: Public submission, gateway confirmation, and listener routes rely on public nonces/provider checks but have no per-IP/session/order throttling, request-body limit, or shared idempotency contract before database writes and provider calls. A public nonce is necessarily obtainable by loading the form.
- Impact: Automated clients can amplify database and Stripe/PayPal API work, create junk pending records, and race/replay operations even though nonce checks reject blind cross-site submissions.
- Recommended fix: Add bounded request sizes, rate limits keyed by IP plus order/session, durable idempotency keys, replay-safe state transitions, and monitoring without treating public nonces as abuse controls.
- Task statement: Add layered anti-automation and idempotency controls to every unauthenticated mutation endpoint.

#### MEDIUM-02: Delegated supporter viewers can retrieve raw gateway payloads

- Area: Security
- Confidence: Medium
- File:line: `includes/Helpers/PaymentHelper.php:67-70`; `includes/Classes/AdminAjaxHandler.php:1190-1268`; `includes/Models/Supporters.php:129-280`
- Evidence: Complete gateway response data is JSON-encoded into `payment_note`. The supporter detail route is available to the delegated supporter-view capability and returns full transaction objects without projecting or redacting that column.
- Impact: Non-financial support staff can receive provider identifiers, customer/payment metadata, or future sensitive fields that were intended only for payment administrators. Exact contents vary by gateway response and need a live-payload check.
- Recommended fix: Store a minimal allowlisted audit projection, encrypt any operational secret that must remain, and return a field-projected transaction DTO based on the caller's payment capability.
- Task statement: Redact raw gateway payloads from supporter-only responses and minimize persisted provider data.

#### MEDIUM-03: Successful webhook paths bypass the canonical payment transition side effects

- Area: Traceability
- Confidence: High
- File:line: `includes/Builder/Methods/Stripe/StripeSubscriptions.php:219-359`; `includes/Builder/Methods/PayPal/PayPal.php:462-485`; `buy-me-coffee.php:156-169`; `includes/Classes/EmailNotifications.php:153-195`; `includes/Classes/ActivityLogHooks.php:9-59`
- Evidence: A Stripe subscription-create invoice activates the subscription without making the original supporter/transaction paid when browser confirmation never arrives. Renewal inserts a paid transaction but does not fire `buymecoffee_payment_status_updated`. PayPal Standard IPN `changeStatus()` also writes state without that canonical hook. Email, payment activity, entitlement sync, and cache invalidation listen to the omitted hook.
- Impact: Webhook-only success can leave initial payments pending, renewals absent from notification/activity consumers, and cached revenue or public supporter output stale.
- Recommended fix: Centralize idempotent payment transitions and invoke the service exactly once for browser confirmations, initial invoices, renewals, and PayPal IPNs.
- Task statement: Route every successful provider event through one atomic payment transition service with exactly-once side effects.

#### MEDIUM-04: Checkout persistence failures can leave orphaned local or remote payment state

- Area: Traceability
- Confidence: High
- File:line: `includes/Controllers/SubmissionHandler.php:117-155`; `includes/Builder/Methods/Stripe/StripeSubscriptions.php:79-170`; `includes/Builder/Methods/Stripe/Stripe.php:81-89,334-365`
- Evidence: Supporter and transaction insert results are not consistently checked before dereference. Stripe creates the remote subscription before confirming the local subscription insert. A one-time membership-access insert failure can silently continue into an ordinary payment.
- Impact: The request can report success or proceed with charging while leaving missing transaction, subscription, or entitlement records; a recurring customer can have an untracked remote subscription.
- Recommended fix: Validate every write, wrap related local writes in a database transaction, fail closed on entitlement creation, and cancel the remote object as compensation if local persistence fails.
- Task statement: Add failure-aware checkout orchestration with local rollback and verified remote compensation.

#### MEDIUM-05: Monetary reports combine unrelated currencies into a single value

- Area: Traceability
- Confidence: High
- File:line: `includes/Classes/AdminAjaxHandler.php:132-143`; `includes/Models/Supporters.php:507-526,553-579`; `includes/Models/Subscriptions.php:108-119`; `src/js/Components/Gateway.vue:31,140-142`; `src/js/Components/Dashboard.vue:391-399`
- Evidence: Gateway total, supporter lifetime/average, top-supporter totals, and MRR sum rows without grouping by currency, then label the scalar with a default or arbitrary `MAX(currency)` value.
- Impact: A USD, EUR, and JPY site displays numerically false revenue, averages, ranking, and recurring revenue.
- Recommended fix: Return and render explicit `{currency,total}` buckets, or convert through a documented exchange-rate source and timestamp; never attach one currency label to a mixed sum.
- Task statement: Make every monetary aggregate currency-aware from SQL through the API and UI.

#### MEDIUM-06: Dashboard range selection produces inconsistent and incomplete metrics

- Area: Traceability
- Confidence: High
- File:line: `includes/Models/Supporters.php:44-72,308-370`; `src/js/Components/Dashboard.vue:12-32,64-90,149-170,299-305,373-442,492-577`
- Evidence: The paid/paid-initially predicate is not grouped before date filters, so SQL precedence lets all `paid` rows bypass `date_from`. Range changes refresh only selected data while revenue, coffees, pending totals, and subscription stats remain lifetime/initial values. `filter_top=yes` orders by amount although the UI says Recent Transactions; gateway breakdowns are placeholders; weekly revenue caps date×currency groups at 50 despite 90/365-day ranges.
- Impact: One dashboard view mixes filtered, lifetime, top-value, placeholder, and truncated data. Counts, average donation, chart, and latest rows can contradict each other.
- Recommended fix: Define one validated dashboard-range contract applied to every metric, group status predicates, add a genuine `created_at DESC` recent query, return gateway aggregates, and remove or range-aware the 50-group cap.
- Task statement: Make every dashboard card, table, chart, and breakdown honor the same selected range and semantic ordering.

#### MEDIUM-07: Supporter CSV export silently truncates at 100 rows

- Area: Traceability
- Confidence: High
- File:line: `src/js/Components/Dashboard.vue:443-481`; `includes/Classes/AdminAjaxHandler.php:66,257-260`; `includes/Models/Supporters.php:29-42`
- Evidence: Export requests `posts_per_page:9999`, but `Supporters::index()` clamps it to 100. The browser immediately builds one successful CSV and ignores the returned total/page count.
- Impact: Sites with 101 or more matching records receive a plausible but incomplete export without warning.
- Recommended fix: Add a permission-protected streaming export endpoint or iterate all pages while preserving filters, escaping, failure handling, and memory bounds.
- Task statement: Implement a complete, bounded supporter export that includes every matching row and never silently truncates.

#### MEDIUM-08: Membership invite failure can conceal an already-granted entitlement

- Area: Traceability
- Confidence: High
- File:line: `includes/Classes/MembershipAjaxHandler.php:435-493,495-582`; `src/js/Components/Memberships/Memberships.vue:312-323,522-532`
- Evidence: The handler creates/reuses a user, creates a supporter, grants manual access, and only then sends mail. If `wp_mail()` fails it returns an error although access is active; retry reports that the user already has access and cannot repair delivery through the same path.
- Impact: The UI says the action failed while authorization changed, and administrators cannot reliably resend the invite.
- Recommended fix: Make grant idempotent, separate `access_granted` and `email_sent` outcomes, persist notification delivery state, add resend, and transactionally group plugin database writes.
- Task statement: Return truthful, idempotent membership-grant and invitation-delivery outcomes with a resend path.

#### MEDIUM-09: Destructive local deletes leave report and public caches stale

- Area: Traceability
- Confidence: High
- File:line: `includes/Classes/AdminAjaxHandler.php:344-421,478-525`; `includes/Models/Supporters.php:121-126,647-656`
- Evidence: Supporter and test-data deletion use direct table deletes and do not invoke the model update path or either cache-version flush. No listener consumes the test-data-deleted event to invalidate report/public output.
- Impact: Deleted supporters and revenue can remain visible in admin reports and the public wall for the cache TTL.
- Recommended fix: Centralize mutation finalization and increment each affected cache version exactly once after a successful destructive transaction.
- Task statement: Invalidate report and public caches after every destructive supporter/test-data batch.

#### MEDIUM-10: Concurrent refund requests can issue duplicate provider refunds

- Area: Security
- Confidence: High
- File:line: `includes/Classes/AdminAjaxHandler.php:880-1005`
- Evidence: The refund route accepts both `paid` and `refunding`, calls the gateway, and changes local state without an atomic paid-to-refunding compare-and-set or a provider idempotency key. Two authorized requests can pass the same precondition concurrently.
- Impact: Duplicate refund attempts can produce inconsistent local/provider state or duplicate refunds where the provider accepts multiple operations.
- Recommended fix: Atomically claim the transaction from paid to refunding, attach a deterministic gateway idempotency key, persist attempt/outcome data, and make repeats return the existing result.
- Task statement: Make refunds a compare-and-set, provider-idempotent state machine with concurrency tests.

#### MEDIUM-11: Delegated users see routes and controls they are not authorized to call

- Area: Traceability
- Confidence: High
- File:line: `includes/Classes/AccessControl.php:20-77`; `includes/Classes/AdminAjaxHandler.php:1190-1267`; `includes/Classes/AdminAppAssets.php:97-112`; `src/js/Components/UI/navigation.js:15-38`; `src/js/routes.js:19-177`
- Evidence: Any delegated plugin capability grants the app shell, but localized app data contains no capability map and all navigation/routes/actions render. Endpoint permissions differ: report-only users cannot load all Dashboard calls, supporter-only users cannot load supporter stats, and membership pages mix settings-only and supporter-only operations.
- Impact: Authorized staff encounter partial pages, 403s, and unusable/destructive controls. Server authorization remains intact, so this is a UI contract failure rather than a privilege bypass.
- Recommended fix: Localize capability booleans, filter navigation and actions, add route guards, and split mixed-capability screens into coherent permission boundaries.
- Task statement: Make visible admin navigation, calls, and actions exactly match the current user's route permissions.

#### MEDIUM-12: The Cancelled payment status offered by the UI is always rejected

- Area: Traceability
- Confidence: High
- File:line: `src/js/Components/Supporter.vue:587-593,632-662`; `includes/Classes/AdminAjaxHandler.php:200-218`
- Evidence: The status dialog sends `cancelled`, but the backend allowlist excludes that value. The request is not returned/awaited and has no useful failure path.
- Impact: A normal rendered option always fails and gives the administrator no actionable feedback.
- Recommended fix: Remove the option or define its backend semantics, then await the request and display the server response.
- Task statement: Align every rendered payment-status option with the handler allowlist and surface update failures.

#### MEDIUM-13: Three supporter-wall and privacy controls are inert

- Area: Traceability
- Confidence: High
- File:line: `src/js/Components/SupportersList.vue:197-244,292-315,372-381`; `includes/Classes/AdminAjaxHandler.php:279-307`; `includes/Classes/DemoPage.php:215-245`; `includes/Models/Supporters.php:591-645,735-747`; `includes/views/templates/SupportersWall.php:5-59`
- Evidence: The UI saves `show_message`, `hide_email`, and `allow_anonymous`, but public query/render/submission paths implement only name, avatar, amount, and limit. Message is not selected/rendered, email is always absent regardless of the toggle, and anonymous submission behavior never reads the setting.
- Impact: Administrators are told that display/privacy/anonymous behavior changed when public behavior is unchanged.
- Recommended fix: Define and implement each setting end to end or remove it. Keep email private unless a deliberate privacy-reviewed design requires disclosure.
- Task statement: Wire supporter-wall and anonymous-donation settings to public rendering and server validation.

#### MEDIUM-14: Three membership display and billing controls are inert

- Area: Traceability
- Confidence: High
- File:line: `src/js/Components/Memberships/Memberships.vue:326-349,415-427,511-519`; `includes/Classes/MembershipAjaxHandler.php:255-282`; `includes/Controllers/MonetizationController.php:164-181`; `src/js/Components/Memberships/LevelEdit.vue:59-65`; `includes/views/templates/PaywallCta.php:48-66`
- Evidence: `accept_annual`, `display_member_count`, and `display_earnings` are saved and loaded but have no checkout/paywall/public consumer. Checkout remains fixed to the level interval and no member/earnings display reads the toggles.
- Impact: Three successful settings changes produce no observable feature change.
- Recommended fix: Implement the promised behavior with privacy-safe aggregates and server-enforced billing choices, or remove/relabel the controls.
- Task statement: Ensure every membership settings toggle has an observable and tested public effect.

#### MEDIUM-15: Membership access rules and preview length are applied inconsistently

- Area: Traceability
- Confidence: High
- File:line: `src/js/Components/Memberships/LevelEdit.vue:92-151,279-300`; `includes/Classes/MembershipAjaxHandler.php:112-148`; `includes/Controllers/MonetizationController.php:47-79,105-134`
- Evidence: Automatic matching excludes preview-only levels from full access, but explicitly selected `_buymecoffee_level_ids` grant full access by ID intersection without checking `access_level`. Saved per-level `preview_words` is ignored; teaser length reads only post override/global default.
- Impact: Explicit assignment of a preview-only level can unlock the entire post, while its configured teaser length never applies.
- Recommended fix: Encode access semantics in one policy used for explicit and automatic matching and derive teaser length deterministically from applicable levels.
- Task statement: Enforce access type and preview length consistently for every membership post-assignment path.

#### MEDIUM-16: Membership recovery messaging targets administrators instead of cancelling members

- Area: Traceability
- Confidence: High
- File:line: `src/js/Components/Memberships/Memberships.vue:158-193,276-285,429-435`; `src/js/Components/Subscriptions/SubscriptionDetail.vue:291-338`; `includes/views/templates/SubscriberAccount.php:217-257`
- Evidence: The settings guide promises a recovery modal when a member cancels, but the subscriber account uses a fixed native confirmation. Saved recovery content is loaded only for administrator cancellation in Subscription Detail.
- Impact: The advertised retention feature does not reach members and instead interrupts administrators.
- Recommended fix: Render sanitized recovery content in the member-facing cancellation flow and define a separate admin confirmation policy.
- Task statement: Move membership recovery behavior to subscriber cancellation and test both keep/cancel outcomes.

#### MEDIUM-17: Manual membership access cannot be revoked through the plugin UI or API

- Area: Traceability
- Confidence: High
- File:line: `src/js/Components/Memberships/Memberships.vue:70-84,554-577`; `includes/Classes/MembershipAjaxHandler.php:29-52,195-235,558-582`; `includes/Models/MembershipAccess.php:107-130,189-215`
- Evidence: Invite creates active manual access without a subscription ID. Member actions render only for rows with `subscription_id`; no access-ID revoke route exists. Level deletion instructs the admin to revoke access first, creating an impossible workflow for these rows.
- Impact: Free/manual members can retain access indefinitely and block membership-level deletion through normal plugin controls.
- Recommended fix: Add a capability-protected `revoke_membership_access(access_id)` action, invalidate entitlement caches, log the decision, and render it for manual/one-time access.
- Task statement: Add an end-to-end revoke workflow for non-subscription membership access.

#### MEDIUM-18: Multiple modal button shortcodes share the same DOM IDs

- Area: Traceability
- Confidence: High
- File:line: `includes/Builder/Render.php:57-68`; `src/js/BmcPublic.js:44-59`
- Evidence: Every modal shortcode emits `bmc_open_modal_btn` and `bmc_modal_wrapper`; global ID selectors bind one target and global close handlers manipulate the same instance.
- Impact: Pages with multiple buttons can open the wrong modal or leave later instances nonfunctional.
- Recommended fix: Generate unique target IDs or use delegated, container-scoped event handling without duplicate IDs.
- Task statement: Scope open, close, and outside-click behavior to each rendered modal shortcode instance.

#### MEDIUM-19: The basic shortcode terminates normal WordPress page rendering

- Area: Traceability
- Confidence: High
- File:line: `buy-me-coffee.php:115-119`; `includes/Classes/DemoPage.php:36-49,62-100`; `includes/views/templates/BasicTemplate.php:2-15`; `src/js/Editor/gutenBlock.jsx:30-35,57-59`
- Evidence: The documented `[buymecoffee_basic]` shortcode calls a renderer that echoes a complete HTML document, invokes head/footer, and calls `exit()` even inside ordinary post rendering.
- Impact: Embedding the shortcode can truncate the WordPress response and inject a second document instead of returning shortcode content.
- Recommended fix: Separate the standalone full-document route from a shortcode fragment renderer; shortcode callbacks must return content and never terminate the request.
- Task statement: Refactor the basic shortcode into a non-terminating embeddable renderer for classic and block themes.

#### MEDIUM-20: Subscription status counts are hard-coded or overwritten by filters

- Area: Traceability
- Confidence: High
- File:line: `src/js/Components/Subscriptions/Subscriptions.vue:191-229`; `includes/Models/Subscriptions.php:63-85,108-157`
- Evidence: Cancelled and Past Due counts are hard-coded to zero. The All badge uses `total`, which becomes the filtered result count after a status selection; the stats endpoint returns only active count and MRR.
- Impact: Status badges misstate inventory and the unfiltered total changes while filtering.
- Recommended fix: Return independent, currency-aware counts per status and an unfiltered total from the stats contract.
- Task statement: Populate stable subscription status counts from backend aggregates and preserve them across filters/search.

#### MEDIUM-21: Vite reads and decodes the manifest on ordinary WordPress requests

- Area: Optimization
- Confidence: High
- File:line: `buy-me-coffee.php:138-149`; `includes/Builder/Methods/PayPal/PayPal.php:16-23`; `includes/Builder/Methods/Stripe/Stripe.php:19-26`; `includes/Classes/Vite.php:21-29,98-119`
- Evidence: Gateway objects are constructed during normal plugin boot and call `Vite::staticPath()`, which reads and JSON-decodes the roughly 32 KB manifest. This occurs before a route proves that frontend assets are needed.
- Impact: Every relevant WordPress request pays unnecessary filesystem and JSON work, magnified under uncached or high-traffic workloads.
- Recommended fix: Lazy-resolve assets only during enqueue, and cache the decoded manifest in a request-static value plus an opcode/object cache keyed by file modification time.
- Task statement: Remove manifest I/O from normal plugin boot and make asset resolution lazy and cached.

#### MEDIUM-22: Status polling and admin-bar hooks cause repeated full-table work

- Area: Optimization
- Confidence: High
- File:line: `src/js/Components/UI/ReviewPrompt.vue:77-90,142-146`; `includes/Classes/AdminAjaxHandler.php:1050-1080,1526-1544`; `includes/Models/Supporters.php:101-107`; `buy-me-coffee.php:177-179`; `includes/Classes/AccountPage.php:14-19,87-90,159-200`
- Evidence: Review/status requests repeatedly recalculate supporter counts and status data, and the admin-bar account hook performs supporter-related queries on ordinary frontend pages for logged-in users. These paths lack a shared cheap summary cache or early page-specific gate.
- Impact: Background UI activity and unrelated frontend requests generate repeated database work that grows with plugin data.
- Recommended fix: Cache compact status counters, invalidate on canonical mutations, debounce/poll only while visible, and gate admin-bar data loading until the menu is actually eligible and needed.
- Task statement: Replace repeated status/admin-bar scans with cached counters and early request guards.

#### MEDIUM-23: Stripe confirmation performs duplicate remote and detail-heavy local reads

- Area: Optimization
- Confidence: High
- File:line: `includes/Builder/Methods/Stripe/Stripe.php:92-116,129-141,229-235`; `includes/Helpers/PaymentHelper.php:30-35,85-97,165-182`; `includes/Models/Supporters.php:129-280`; `includes/Builder/Methods/Stripe/StripeSubscriptions.php:294-296,543-546`
- Evidence: Confirmation paths fetch the same Stripe intent/subscription more than once through nested validation/update helpers. They also use `Supporters::find()`, which assembles transaction, subscription, access, and activity detail even where only identity/payment fields are consumed.
- Impact: Checkout latency and provider quota consumption rise, and transient provider failures can occur after an earlier successful fetch.
- Recommended fix: Fetch and validate each provider object once, pass the verified object through the transition service, and add narrow repository methods for the fields required by payment handlers.
- Task statement: Make Stripe confirmation reuse one verified provider response and minimal local projections.

#### MEDIUM-24: Membership cache misses begin with an unbounded supporter-ID query

- Area: Optimization
- Confidence: High
- File:line: `includes/global_functions.php:105-117,169-205`; `includes/Models/MembershipAccess.php:217-252,275-297`; `includes/Controllers/MonetizationController.php:17-49`
- Evidence: Entitlement lookup first materializes every supporter ID associated with a user/email, then builds follow-up access queries. The same check can be invoked multiple times in one protected-content render after a cache miss.
- Impact: Accounts with long donation histories create large arrays and IN clauses on a latency-sensitive content authorization path.
- Recommended fix: Query valid access directly with joins/EXISTS, add a request-local memo, cap/batch legacy fan-in, and retain precise invalidation on access changes.
- Task statement: Replace unbounded supporter-ID materialization with one indexed entitlement query and request-local memoization.

#### MEDIUM-25: Test-data cleanup is an unbounded AJAX job without supporting mode indexes

- Area: Optimization
- Confidence: High
- File:line: `includes/Classes/AdminAjaxHandler.php:478-525,1356-1520`; `includes/Classes/Activator.php:168-238`
- Evidence: The deletion handler loops through all matching test data in one AJAX request and performs dependent cleanup without a resumable cursor. The relevant payment-mode cleanup predicates do not have dedicated supporting indexes.
- Impact: Large test datasets can time out, lock tables, leave a partially deleted graph, and retry expensive scans from the beginning.
- Recommended fix: Use a transaction-aware, resumable batch job with a durable cursor/progress response and add indexes justified by the actual cleanup predicates.
- Task statement: Make test-data deletion bounded, resumable, indexed, and safe to retry.

#### MEDIUM-26: Supporter activity lookup materializes an unbounded transaction-ID list

- Area: Optimization
- Confidence: High
- File:line: `includes/Classes/ActivityLogger.php:159-183,194-209`
- Evidence: The supporter timeline loads every related transaction ID into PHP and uses that full list in a subsequent `IN` query before activity pagination.
- Impact: High-volume supporters can create large memory use, SQL packets, and slow detail-page responses.
- Recommended fix: Join activities to transactions or use an indexed subquery keyed by supporter, then paginate before materializing event rows.
- Task statement: Replace supporter activity ID fan-out with one indexed, paginated database query.

#### MEDIUM-27: The configured default avatar asset is missing

- Area: Dead Code
- Confidence: High
- File:line: `includes/Classes/DemoPage.php:74-76`; `src/js/Components/Onboarding.vue:73`; `src/js/Components/Settings.vue:281,463`
- Evidence: Defaults reference `src/images/profile.png`/`assets/images/profile.png`, but neither file exists in the repository or built asset tree.
- Impact: Fresh installs and reset/default states can show broken profile images in admin and public output.
- Recommended fix: Add one canonical runtime asset and resolve it through the manifest/plugin URL, or use a deliberate inline/default-avatar fallback.
- Task statement: Restore a valid packaged default avatar and cover fresh/reset rendering.

#### MEDIUM-28: Vite's missing-manifest-entry guard is unreachable in production

- Area: Dead Code
- Confidence: High
- File:line: `includes/Classes/Vite.php:56-58,75-77,125-130`
- Evidence: Production code indexes manifest entries through `getFileFromManifest()`, but the missing-entry exception is conditional on development mode. The call is only meaningful in production, so a missing key produces undefined-index/broken-asset behavior instead of the intended diagnostic.
- Impact: A bad or stale release manifest fails nondeterministically and can break the admin/public application without a clear error.
- Recommended fix: Validate manifest structure and required entries unconditionally in production, return a controlled admin notice/fallback, and fail release builds when entries are absent.
- Task statement: Make production manifest validation deterministic and test missing/corrupt manifest behavior.

#### MEDIUM-29: Frontend build dependencies contain unresolved high-severity advisories

- Area: Security
- Confidence: High
- File:line: `package.json`; `package-lock.json`
- Evidence: `npm audit --json` reports five high advisories affecting `brace-expansion`, `immutable`, `nanoid`, `postcss`, and `vite`. The production plugin ZIP does not ship `node_modules`, so these are build/development-chain exposures rather than confirmed WordPress runtime exploits.
- Impact: A developer preview server or CI/build process can be exposed to known denial-of-service, path-serving, or malformed-input issues depending on how those tools are invoked.
- Recommended fix: Update the dependency graph to fixed versions, rerun build/tests/audit, and document any temporarily accepted transitive advisory with reachability and expiration.
- Task statement: Eliminate or explicitly risk-accept every reachable npm advisory in the frontend build chain.

### Suggestion

#### SUGGESTION-01: Payment updates increment cache versions redundantly

- Area: Optimization
- Confidence: High
- File:line: `includes/Models/Supporters.php:121-126,647-656`; `buy-me-coffee.php:168-169`; `includes/Helpers/PaymentHelper.php:67-97`
- Evidence: Model updates and canonical payment hooks can both bump report/public cache versions for one logical transition.
- Impact: Extra option writes and unnecessary cache misses add noise to payment hot paths.
- Recommended fix: Assign cache invalidation ownership to the transition service and execute it once after commit.
- Task statement: Deduplicate cache-version increments per logical payment mutation.

#### SUGGESTION-02: Admin media and font assets are loaded more broadly than needed

- Area: Optimization
- Confidence: High
- File:line: `includes/Classes/AdminAppAssets.php:18-31`; `src/scss/admin/app.scss:3-7`
- Evidence: `wp_enqueue_media()` is loaded for the whole plugin app, while media use is confined to settings/onboarding; Inter is also loaded through overlapping asset paths.
- Impact: Admin pages download and initialize avoidable scripts/styles.
- Recommended fix: Route-split media dependencies and load one canonical font source only where required.
- Task statement: Restrict media/font loading to consuming routes and remove duplicate font delivery.

#### SUGGESTION-03: Stripe webhook secrets are collected but never used

- Area: Dead Code
- Confidence: High
- File:line: `src/js/Components/Stripe.vue:66-74,178-209`; `includes/Builder/Methods/Stripe/Stripe.php:436-440,583-619`; `includes/Builder/Methods/Stripe/StripeSettings.php:48-55`
- Evidence: The UI stores webhook secrets and provides a getter, but webhook authentication re-fetches the event from Stripe and no caller reads the configured secret.
- Impact: Administrators manage a security-sensitive value that has no effect, increasing confusion and secret-handling surface.
- Recommended fix: Either verify Stripe signatures with the stored secret and preserve API re-fetch as defense in depth, or remove the unused setting and migration-safe stored value.
- Task statement: Give Stripe webhook secrets one documented security purpose or remove them end to end.

#### SUGGESTION-04: The admin framework is instantiated and mixed in twice

- Area: Dead Code
- Confidence: High
- File:line: `includes/Classes/AdminAppAssets.php:33-53`; `src/js/boot.js:1-5`; `src/js/main.js:46`; `src/js/plugin_main_js_file.js:12-56`
- Evidence: Boot code constructs a global app/framework instance, then main code constructs another and installs overlapping mixins.
- Impact: Initialization order is harder to reason about and can duplicate global behavior or plugin registration.
- Recommended fix: Keep one application bootstrap and import shared helpers through explicit modules.
- Task statement: Collapse admin startup into one framework instance and one mixin/plugin registration path.

#### SUGGESTION-05: Orphaned components and copied images increase maintenance and package size

- Area: Dead Code
- Confidence: High
- File:line: `src/js/Components/UI/BreadcrumbNav.vue`; `src/js/Components/Email/Email.vue`; `src/images/`; `assets/images/`
- Evidence: Repository reference tracing found two unimported components and 13 unreferenced copied images totaling roughly 260 KB.
- Impact: Dead assets enlarge source/release review surface and can mislead future maintainers about supported UI.
- Recommended fix: Delete confirmed orphans or add explicit imports/tests for intended consumers; make the release build source assets from one canonical directory.
- Task statement: Remove unreferenced UI/assets after a release-bundle reference check.

#### SUGGESTION-06: The email activity producer has no caller

- Area: Dead Code
- Confidence: High
- File:line: `includes/Classes/ActivityLogger.php:94-97,194-208`; `includes/Classes/EmailNotifications.php:153-195`
- Evidence: Activity filtering supports email records and a producer exists, but email-send paths do not call it and repository search found no other caller.
- Impact: The activity contract promises an event category that cannot be produced by normal plugin behavior.
- Recommended fix: Call the producer from centralized mail delivery with redacted metadata, or remove the dormant category and code.
- Task statement: Connect email activity logging to real sends or remove the unused producer/filter.

#### SUGGESTION-07: Unused access predicates duplicate the live entitlement rules

- Area: Dead Code
- Confidence: High
- File:line: `includes/global_functions.php:123-154`; `includes/Models/MembershipAccess.php:217-297`; `includes/Models/Subscriptions.php`
- Evidence: Repository search found no callers for legacy global access predicates; they duplicate rules now implemented by membership-access and subscription models.
- Impact: Dormant authorization logic can drift and be accidentally reused with outdated semantics.
- Recommended fix: Remove the unused functions or make one canonical policy their documented implementation target with direct tests.
- Task statement: Delete legacy access predicates after confirming extension compatibility, leaving one authoritative entitlement policy.

#### SUGGESTION-08: Package metadata is stale and still uses a boilerplate identity

- Area: Traceability
- Confidence: High
- File:line: `package.json:2-4`; `package-lock.json:2-9`; `buy-me-coffee.php:7,37`; `readme.txt:6`
- Evidence: WordPress runtime/readme metadata reports Buy Me Coffee 1.2.8, while npm identifies the project as `plugin_name` version 1.2.6 with a generic boilerplate description. Build/test output therefore reports the wrong package and version.
- Impact: CI artifacts, dependency dashboards, release evidence, and future automation can be attributed to the wrong identity/version even though the WordPress ZIP itself contains the correct runtime header.
- Recommended fix: Set one canonical release version/name and add a CI assertion that plugin header, constant, readme stable tag, package metadata, and release artifact agree.
- Task statement: Synchronize npm and WordPress release metadata and fail builds on version drift.

## 4) Prioritized Backlog (Quick Wins First)

1. [ ] Remove PayPal's verification-disable path; require verified sandbox IPNs and exact receiver identity (HIGH-01).
2. [ ] Map Stripe events through a typed, terminal-state-aware transition service and cover refunds/replays (HIGH-03).
3. [ ] Block supporter deletion until every active remote subscription is cancelled and verified (HIGH-02).
4. [ ] Rebuild supporter aggregates from pre-aggregated transaction/subscription subqueries and add scale fixtures (HIGH-05).
5. [ ] Convert access backfill to a resumable locked background migration (HIGH-04).
6. [ ] Make checkout writes atomic with remote compensation; then route all gateway success paths through the same transition service (MEDIUM-03, MEDIUM-04, MEDIUM-10).
7. [ ] Fix dashboard predicates/data contract, currency buckets, and complete CSV export (MEDIUM-05, MEDIUM-06, MEDIUM-07, MEDIUM-20).
8. [ ] Add public endpoint rate limits, body limits, idempotency, and raw-payload redaction (MEDIUM-01, MEDIUM-02).
9. [ ] Add truthful invite delivery and manual-access revoke workflows (MEDIUM-08, MEDIUM-17).
10. [ ] Fix the basic shortcode and multi-modal DOM contract (MEDIUM-18, MEDIUM-19).
11. [ ] Align delegated navigation/action visibility and status options with backend permissions/contracts (MEDIUM-11, MEDIUM-12).
12. [ ] Implement or remove inert supporter/membership settings and unify membership preview/recovery semantics (MEDIUM-13 through MEDIUM-16).
13. [ ] Bound/correct recurring query hot spots, cleanup jobs, activity lookup, and manifest loading (MEDIUM-21 through MEDIUM-26).
14. [ ] Restore the default avatar, fail cleanly on invalid manifests, and update vulnerable build dependencies (MEDIUM-27 through MEDIUM-29).
15. [ ] Clean redundant cache/asset/bootstrap/dead-code paths and synchronize release metadata (SUGGESTION-01 through SUGGESTION-08).
16. [ ] Execute every unchecked provider, browser, email, scale, accessibility, and upgrade scenario in `tests/FEATURE-MATRIX.md` before release.

## 5) Needs Manual Verification

- Finding key: HIGH-01
  - Area: Security
  - File:line: `includes/Builder/Methods/PayPal/IPN.php:42-50`; `includes/Builder/Methods/PayPal/PayPal.php:399-485`
  - Why uncertain: The exploit chain is statically complete, but a real sandbox `VERIFIED` response and receiver fields cannot be exercised without merchant credentials.
  - Manual test to confirm: In an isolated sandbox, attempt the forged test IPN with the current toggle, then deliver a genuine PayPal sandbox IPN; the forged event must be rejected with no state mutation and the genuine event must transition exactly once.

- Finding key: HIGH-03
  - Area: Security
  - File:line: `includes/Builder/Methods/Stripe/Stripe.php:420-565`
  - Why uncertain: Stripe's documented/refetched Charge shape supports the static path, but local tests do not call a real Stripe account.
  - Manual test to confirm: Refund a metadata-bearing one-time membership PaymentIntent in Stripe test mode, replay the authentic `charge.refunded` event, and confirm the transaction remains refunded and access remains revoked.

- Finding key: MEDIUM-02
  - Area: Security
  - File:line: `includes/Helpers/PaymentHelper.php:67-70`; `includes/Models/Supporters.php:129-280`
  - Why uncertain: Exposure of the raw column is confirmed; the sensitivity of exact keys varies by real gateway payload/version.
  - Manual test to confirm: Complete one Stripe and each PayPal flow, inspect stored `payment_note` and the delegated supporter-view response, and classify/redact every returned provider field.

- Finding key: HIGH-04 and HIGH-05
  - Area: Optimization
  - File:line: `includes/Classes/Activator.php:307-438`; `includes/Models/Supporters.php:406-477,537-582`
  - Why uncertain: Query topology is confirmed, but production impact depends on host resources, indexes, and row distribution.
  - Manual test to confirm: Benchmark upgrade and supporter/report endpoints at 10k, 100k, and 1m representative rows with concurrent requests; record query count, p95 latency, locks, peak memory, and timeout/retry behavior.

- Finding key: MEDIUM-29
  - Area: Security
  - File:line: `package-lock.json`
  - Why uncertain: Advisories are confirmed, but reachability depends on CI inputs, preview-server exposure, and whether affected parsers process untrusted content.
  - Manual test to confirm: Inventory CI/build invocation and network exposure, map each advisory to reachable commands, update dependencies, and require a clean or explicitly accepted `npm audit` result.

- Finding key: Full feature release gate
  - Area: Traceability
  - File:line: `tests/FEATURE-MATRIX.md`
  - Why uncertain: Automated tests deliberately avoid external payments, mail delivery, destructive production-like data, browser interaction, accessibility tooling, and real upgrade volumes.
  - Manual test to confirm: Execute and record every sandbox/staging checkbox in the matrix, including payment event/order IDs, tester, date, expected state transition, PHP/browser logs, and rollback result.
