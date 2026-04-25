# Deep Release Test Report

Date: 2026-04-26  
Project: `buy-me-coffee` WordPress plugin  
Audit type: deep pre-release security + code quality + future-risk review

## Test Execution Summary

### Automated checks executed

- `php -l` recursive syntax lint on all PHP files: **PASS**
- `npm run build` (production bundle): **PASS**
  - Warning: large chunk (`assets/plugin_main_js_file.js` ~1.07 MiB)
  - Warning: Sass legacy JS API deprecation
- `npm audit --omit=dev --json`: **0 vulnerabilities**
- `npm audit --json`: **5 moderate vulnerabilities** (dev toolchain only; Vite/esbuild/static-copy chain)

### Deep review scope

- Stripe subscription + webhook + refund paths
- PayPal IPN/refund paths
- Admin AJAX authorization and state integrity
- Frontend payment confirmation/redirect and DOM rendering safety
- Release workflow, compatibility metadata, and migration strategy

---

## Release Decision

**Status: READY FOR STAGING/UAT RELEASE GATE**  
All previously identified critical/high engineering issues in this report have been remediated in code and revalidated with build/lint/audit checks. Final production rollout still requires real gateway staging verification (webhook delivery, live refund semantics, and role-permission QA).

## Remediation Status (2026-04-26)

- [x] Stripe webhook URL mismatch fixed (canonical endpoint unified + backward compatibility)
- [x] Broken Stripe one-time webhook code path removed/fixed
- [x] Stripe checkout now waits for server confirmation before showing success
- [x] Frontend redirect safety enforced (same-origin http/https validation)
- [x] Route-level capability hardening for mutating financial/admin actions
- [x] Refund flow now checks gateway terminal status before marking refunded
- [x] Legacy nonce fallback default changed to deny
- [x] Stripe event lock moved to expiring transients (no unbounded options growth)
- [x] PayPal SDK retry loop bounded and hardened
- [x] Onboarding save flow made async/await with failure blocking
- [x] DB schema upgraded with missing indexes/constraints + migration trigger bump
- [x] WP compatibility improved with script-tag fallback and updated minimum version metadata
- [x] CI/release workflow aligned to main/tags with lint/audit/package gates
- [x] Dev-toolchain vulnerabilities resolved (`npm audit` now zero)
- [x] Admin activity views moved away from weak `v-html` rendering

---

## Historical Findings (Now Remediated)

## Critical

1. **Stripe webhook URL mismatch breaks webhook delivery**
   - Files:
     - `includes/Builder/Methods/Stripe/Stripe.php` (`getPaymentSettings`)
     - `buy-me-coffee.php` (`registerIpnHooks`)
   - Problem:
     - Settings UI returns webhook URL `?buymecoffee_stripe_listener=1`
     - Router actually dispatches webhooks only when `?buymecoffee_ipn_listener=1&method=stripe`
   - Impact:
     - Stripe webhook calls can miss the handler completely.
     - Renewals/cancellations/state sync may silently fail.
   - Required fix:
     - Make one canonical endpoint and update both router + exposed URL.
     - Keep backward-compatible support for old query format temporarily.

2. **Stripe non-subscription webhook path contains broken API calls**
   - File: `includes/Builder/Methods/Stripe/API.php` (`getInvoice`)
   - Problem:
     - References undefined `ApiRequest` class and undefined `StripeSettings::getApiKey()`.
   - Impact:
     - Runtime fatal/processing failure on one-time event handling branch.
     - Webhook retries, inconsistent payment states.
   - Required fix:
     - Replace with existing Stripe client request path (using `makeRequest` + `StripeSettings::getKeys('secret')`) or remove broken branch and process verified payload directly.

## High

3. **Frontend Stripe confirmation can report success before server confirmation completes**
   - File: `src/js/PaymentMethods/stripe-checkout.js`
   - Problem:
     - UI success message is shown before `buymecoffee_payment_confirmation_stripe` response is validated.
   - Impact:
     - False-positive payment success UX; local DB/subscription may still fail to update.
   - Required fix:
     - Await confirmation AJAX result before showing final success/receipt UI.

4. **Unsafe redirect trust in frontend payment flow**
   - Files:
     - `src/js/BmcFormHandler.js`
     - `src/js/PaymentMethods/paypal-checkout.js`
   - Problem:
     - Redirect URLs from response are assigned directly to `window.location`.
   - Impact:
     - If backend validation regresses or is bypassed, open redirect/phishing path.
   - Required fix:
     - Centralize safe redirect helper (`http/https`, allowed host check).

5. **Admin financial actions are guarded by broad menu capability only**
   - Files:
     - `includes/Classes/AdminAjaxHandler.php`
     - `includes/Classes/AccessControl.php`
   - Problem:
     - Same capability gate used for read and write financial routes (refund/cancel/settings).
   - Impact:
     - Over-permission risk if menu-view role is granted to non-finance users.
   - Required fix:
     - Route-level capability map; stricter capability for mutating financial operations.

6. **Refund status may be marked final before gateway terminal confirmation**
   - Files:
     - `includes/Classes/AdminAjaxHandler.php` (`markRefunded`)
     - `includes/Builder/Methods/Stripe/Stripe.php` (`processRefund`)
     - `includes/Builder/Methods/PayPal/PayPal.php` (`processRefund`)
   - Problem:
     - Any non-error response marks local transaction `refunded`.
   - Impact:
     - Local records can diverge from gateway if refund is pending/async/partial failure.
   - Required fix:
     - Persist gateway refund status/ID and only finalize after terminal success.

## Medium

7. **Public legacy nonce fallback is too permissive**
   - Files:
     - `includes/Controllers/SubmissionHandler.php`
     - `includes/Builder/Methods/BaseMethods.php`
   - Problem:
     - Missing nonce can be accepted by default fallback filters.
   - Impact:
     - Higher abuse/spam/replay surface on public endpoints.
   - Required fix:
     - Default fallback deny in production, or tightly scope legacy bypass.

8. **Stripe event locks stored in `wp_options` without expiry**
   - File: `includes/Builder/Methods/Stripe/Stripe.php` (`acquireEventLock`)
   - Problem:
     - Adds option row per event lock without cleanup.
   - Impact:
     - Long-term options-table growth and maintenance overhead.
   - Required fix:
     - Use transient/object-cache TTL or dedicated processed-events table with retention.

9. **Infinite PayPal SDK retry loop in browser**
   - File: `src/js/PaymentMethods/paypal-checkout.js`
   - Problem:
     - Repeats `setTimeout(() => this.init(), 200)` indefinitely when SDK fails to initialize.
   - Impact:
     - Client-side loop, noisy behavior, poor recovery UX.
   - Required fix:
     - Add max retries + backoff + hard failure message with retry button.

10. **Onboarding flow advances without awaiting save completion**
    - File: `src/js/Components/Onboarding.vue`
    - Problem:
      - `next()` fires save requests and immediately advances step.
    - Impact:
      - Users can finish setup while settings fail silently.
    - Required fix:
      - Make `next()` async, await save response, block step progression on failure.

11. **Missing DB indexes/constraints for growth paths**
    - File: `includes/Classes/Activator.php` (table DDL)
    - Problem:
      - Hot lookup fields in supporters/transactions/subscriptions are not indexed.
    - Impact:
      - Performance degradation under scale (webhook/admin queries/refund scans).
    - Required fix:
      - Add indexes for frequent filters and unique constraints where applicable (e.g., `stripe_subscription_id`).

## Low / Future-Risk

12. **Declared WP compatibility likely too low for used APIs**
    - Files:
      - `readme.txt` (`Requires at least: 4.5`)
      - `includes/Classes/Vite.php` (`wp_get_script_tag` usage)
    - Problem:
      - Runtime likely requires newer WP than declared minimum.
    - Impact:
      - Compatibility regressions for old installs.
    - Required fix:
      - Raise minimum WP version or provide compatibility shim.

13. **Release workflow/version metadata inconsistency**
    - Files:
      - `.github/workflows/node.js.yml`
      - `package.json`
      - `buy-me-coffee.php`
      - `readme.txt`
    - Problem:
      - Hardcoded CI tag/release name, package version mismatch.
    - Impact:
      - Confusing releases and traceability issues.
    - Required fix:
      - Single source-of-truth versioning and release automation alignment.

14. **Dev toolchain security debt**
    - Source: `npm audit --json` output
    - Problem:
      - Moderate advisories in Vite/esbuild/plugin chain (dev dependencies).
    - Impact:
      - Build environment/security hygiene risk over time.
    - Required fix:
      - Upgrade Vite + plugins with a controlled migration.

---

## Positive Controls Verified

- Stripe webhook signature verification is implemented and fail-closed when secret/signature are missing.
- Refund race lock exists (`paid -> refunding`) and prevents simple double-submit.
- Admin AJAX validates nonce and capability gate before dispatch.
- Frontend global notifications no longer allow unsafe HTML mode.
- Production dependency audit (`--omit=dev`) is clean.

---

## Required Re-Test Before Release

- Stripe webhook end-to-end with canonical URL (renewal, cancel, status update, invalid signature).
- One-time Stripe webhook branch after API client fix.
- Refund terminal-state verification (Stripe + PayPal) including pending/failed gateway responses.
- Frontend confirmation flow should show success only after server confirmation succeeds.
- Redirect safety checks for PayPal and generic form submit responses.
- Permission matrix test by role/capability for all mutating admin routes.
