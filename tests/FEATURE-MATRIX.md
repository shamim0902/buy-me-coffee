# Buy Me Coffee feature verification matrix

Use this matrix as the release gate. `npm test` and `npm run test:http` cover deterministic local behavior; payment-provider and email rows require sandbox/staging credentials.

## Automated release gate

| Feature area | Automated coverage | Command |
|---|---|---|
| Plugin boot | Shortcodes, public/private AJAX hooks, gateway confirmations, IPN hooks, paywall filter, account cancellation registration | `npm test` |
| Database | Seven custom tables and critical supporter/transaction/subscription/access indexes | `npm test` |
| Donation input | Field sanitization, email handling, amount calculation, invalid amount fallback, quantity cap | `npm test` |
| Gateways | Stripe/PayPal registration, enabled-state normalization, advertised subscription support | `npm test` |
| Currency | Stripe normal- and zero-decimal conversion round trips | `npm test` |
| Membership checkout | Saved level overrides attacker-controlled amount, quantity, interval, and recurring mode | `npm test` |
| Entitlements | Pending one-time access, activation, cache refresh, refund revocation, subscription expiry/cancellation rules | `npm test` |
| Paywall | Guest teaser/CTA, global preview fallback, entitled-member full content | `npm test` |
| Public rendering | Button escaping, donation form, supporter wall, logged-out account form | `npm test` |
| Authorization | Delegated menu/supporter/settings/financial boundaries and exact-post meta authorization | `npm test` |
| Supporter deletion | Remote cancellation confirmed before any local delete, stored payment-mode credentials, retained rows on provider/key failure, terminal and local-only skips, partial-failure retry, single cache invalidation | `npm test` |
| Supporter aggregates | Exact lifetime totals and leaderboard ranking under transaction/subscription fan-out, shared-email and anonymous grouping, search and subscriber/one-time filters, stable multi-page ordering, page-bounded paid-history queries with no subscription join | `npm test` |
| Public request guard | Route ceiling refused with 413 against the body that actually arrived (missing and understated `Content-Length`), exact rate-limit boundary with no lost or duplicated increment, lapsed-window reset, forwarded headers ignored without an explicit trusted resolver, hashed-only storage, lease ownership a stale worker cannot use, conservative per-route claim lifetimes, bounded cleanup, 503 `guard_unavailable` on every protected route when the guard table is unusable | `npm test` |
| Submission idempotency | Key mandatory (keyless and malformed refused, narrow opt-out filter only); retried key refused without a second supporter, transaction or provider payment even after an address change; a request still in flight refused; validation failures leave the key reusable | `npm test` |
| Gateway replay safety | Stripe confirmation resolved to a local transaction before any lease or provider call, subscription binding validated, settled result replayed from rows, two confirmations of one intent from two addresses serialized, failed confirmation released and retryable; duplicate Stripe event neither re-fetched nor re-applied while an in-flight one stays retryable (409); duplicate VERIFIED PayPal IPN applied once and distinct notifications never merged; two PayPal orders for one donation cannot both capture | `npm test` |
| Provider retryability | Unauthenticated Stripe/PayPal deliveries bounded per address by a budget charged only on failed authentication, so genuine high-volume deliveries from shared provider addresses are never refused; replayed IPN body throttled before PayPal | `npm test` |
| Live local HTTP | Standalone form, gateway mount, localized config, logged-out admin redirect, missing-nonce rejection, mandatory submission idempotency key | `npm run test:http` |
| Frontend/admin assets | Vue, React editor panels, SCSS, route chunks, manifest and static assets compile | `npm run build` |

## Sandbox and staging acceptance tests

These checks intentionally are not automated against real provider accounts. Record the date, tester, gateway event/order IDs, and result for every release candidate.

### Installation and admin shell

- [ ] Fresh activation creates all plugin tables, seeds one default membership level, and shows onboarding once.
- [ ] Upgrade from the previous released DB version completes without timeout, duplicate access rows, or concurrent migration races.
- [ ] Dashboard, Recent Transactions, Supporters, Subscriptions, Memberships, Gateways, Activity, Emails, Settings, and Onboarding routes load without console/PHP errors.
- [ ] Admin navigation, dark mode, guided tour, review prompt, pagination, search, filters, empty states, and responsive layouts work.
- [ ] A user with each custom delegated capability sees only the corresponding routes/data/actions.

### Form, shortcodes, block, and customization

- [ ] `[buymecoffee_button]` works in page and modal modes.
- [ ] `[buymecoffee_form]`, `[buymecoffee_basic]`, `[buymecoffee_supporters]`, and `[buymecoffee_account]` render in classic and block themes.
- [ ] Gutenberg block inserts and renders the expected form/button.
- [ ] Name, email, message, quantity, custom amount, recurring interval, currency display, and validation behave correctly on mobile and desktop.
- [ ] Appearance changes, profile/banner images, positioning, zoom, colors, quote, and reset-to-default survive reload and render safely.
- [ ] Supporter wall limits, amount visibility, ranking, avatar, and empty-state settings match the saved configuration.

### Stripe one-time payments

- [ ] Successful test payment remains pending until server confirmation, then updates supporter/transaction once and shows the correct receipt.
- [ ] Declined, cancelled, processing, duplicate-confirmation, wrong intent, wrong amount, wrong currency, and replayed intent cases do not grant paid status.
- [ ] USD/EUR and one Stripe zero-decimal currency charge and display the intended amount.
- [ ] Signed webhook success, invalid signature/event ID, duplicate event, refund, and restored-payment events produce the expected idempotent state and activity records.
- [ ] Full and partial refund behavior matches the gateway result before local state becomes refunded.

### Stripe subscriptions and memberships

- [ ] Monthly and yearly subscriptions create one supporter, local subscription, transaction, and entitlement bound to the chosen active level.
- [ ] Tampered level ID, amount, currency, interval, inactive/deleted level, missing email, and paused-membership requests are rejected server-side.
- [ ] Initial invoice, renewal, payment failure, cancellation, cancellation-at-period-end, expiry, and reactivation update access on the correct dates.
- [ ] One-time membership purchase grants permanent access until refunded; recurring access expires at the recorded period end.
- [ ] Existing linked users gain newly purchased levels without stale cache; users with multiple supporter rows retain the union of valid access.
- [ ] Protected posts honor explicit allowed levels, post-type/category rules, preview length, and the global fallback.

### PayPal Standard and Pro

- [ ] PayPal Standard sandbox IPNs are always verified by PayPal before any local state mutation.
- [ ] Forged, pending, failed, wrong receiver, wrong amount, wrong currency, wrong transaction ID, and replayed IPNs do not mark a transaction paid.
- [ ] PayPal Pro order capture is re-fetched and matches local hash, reference, amount, currency, and completed status.
- [ ] Standard and Pro success/cancel redirects remain same-origin and receipts match the local transaction.
- [ ] Refund success, pending, failure, duplicate refund, and provider timeout keep local and remote state consistent.

### Supporters, subscriptions, activity, and exports

- [ ] Supporter list/detail editing, search, method/status/date filters, deletion, totals, payment history, and membership history are accurate.
- [ ] Dashboard and supporter CSV exports include every matching row, not only the first page.
- [ ] Subscription list/detail, search/filter, fetch, cancellation, stats, and renewal history match gateway/local records.
- [ ] Activity filters and supporter timelines include payment, subscription, webhook, refund, email, and admin actions with safe text rendering.
- [ ] Test-data cleanup removes only test-mode rows and related activity/access records, in bounded resumable work.
- [ ] Deleting a supporter with a live sandbox subscription cancels it at Stripe first; with Stripe unreachable nothing is deleted and the retry finishes the deletion.

### Accounts and email

- [ ] Account-enabled successful membership creates or links the correct Subscriber, sends one account setup email, and never logs in an existing email owner.
- [ ] Donor account shows only the logged-in user's supporter records, subscriptions, transactions, access, and cancellation controls.
- [ ] Self-service cancellation rejects another user's subscription ID and preserves access until the paid period ends.
- [ ] Donor/admin payment emails, test emails, membership invites, template variables, enabled toggles, and mail failures behave as shown in the UI.

### Public endpoint abuse controls

- [ ] A donation retried from a real browser after a dropped response creates one supporter row and one provider payment; the retry is answered `submission_already_completed` and reloading the page starts a clean attempt.
- [ ] A browser with Web Crypto disabled refuses to submit and says so, rather than sending an unprotected donation.
- [ ] Two tabs confirming the same Stripe payment, and two PayPal orders for one donation, produce exactly one capture; the losing tab sees `confirmation_in_progress` and recovers on retry.
- [ ] Sustained automated submissions from one address are answered 429 with `Retry-After` while other visitors keep donating normally.
- [ ] Behind the production proxy/CDN, `buymecoffee_trusted_client_ip` resolves the real visitor address and per-client limits apply to visitors rather than to the proxy.
- [ ] Live Stripe webhook deliveries and PayPal IPNs are never throttled in normal operation; tuned thresholds are recorded per host.
- [ ] The `buymecoffee_request_guard` table exists on every site of the network after upgrade, carries the `owner_hash` column, and stays bounded in size.
- [ ] With the guard table deliberately renamed on a staging site, donations and confirmations answer 503 and provider deliveries are redelivered rather than applied twice; restoring the table recovers without an admin action.

### Release and non-functional gates

- [ ] Run the integration suite on every declared supported PHP version. The current local WordPress test stack runs it on PHP 8.1–8.5; PHP 7.4 passes syntax lint, while PHP 7.4/8.0 functional coverage needs a compatible isolated WordPress fixture.
- [ ] `npm audit` is reviewed and all reachable production/build-chain advisories are fixed or explicitly accepted.
- [ ] PHP error log and browser console stay clean through the complete matrix.
- [ ] Query counts and response times are measured with representative supporter/transaction/subscription volumes.
- [ ] Keyboard navigation, focus, labels, contrast, screen-reader names, reduced motion, and narrow viewport behavior meet the release target.
- [ ] Release ZIP contains only runtime assets/files, installs on a clean site, and reports consistent plugin/package/readme versions.
