#!/usr/bin/env bash

set -euo pipefail

BMC_BASE_URL="${BMC_BASE_URL:-https://cart.test}"
BMC_TEST_TMP="$(mktemp -d)"
trap 'rm -r "${BMC_TEST_TMP}"' EXIT

request_status() {
    local url="$1"
    local output="$2"
    shift 2
    curl -ksS "$@" -o "$output" -w '%{http_code}' "$url"
}

assert_status() {
    local expected="$1"
    local actual="$2"
    local label="$3"
    if [[ "$expected" != "$actual" ]]; then
        printf 'FAIL  %s (expected HTTP %s, received %s)\n' "$label" "$expected" "$actual" >&2
        exit 1
    fi
    printf 'PASS  %s\n' "$label"
}

assert_markup() {
    local pattern="$1"
    local file="$2"
    local label="$3"
    if ! rg -q "$pattern" "$file"; then
        printf 'FAIL  %s\n' "$label" >&2
        exit 1
    fi
    printf 'PASS  %s\n' "$label"
}

public_status="$(request_status "${BMC_BASE_URL}/?share_coffee=1" "${BMC_TEST_TMP}/public.html")"
assert_status 200 "$public_status" 'standalone donation page responds'
assert_markup 'class="buymecoffee_form"' "${BMC_TEST_TMP}/public.html" 'donation form is rendered'
assert_markup 'name="wpm_submit_button"' "${BMC_TEST_TMP}/public.html" 'donation submit control is rendered'
assert_markup 'buymecoffee_payment_processor' "${BMC_TEST_TMP}/public.html" 'gateway mount point is rendered'
assert_markup 'buymecoffee_general' "${BMC_TEST_TMP}/public.html" 'frontend nonce/config is localized'

admin_status="$(request_status "${BMC_BASE_URL}/?buymecoffee_admin=1" "${BMC_TEST_TMP}/admin.html" -L)"
assert_status 200 "$admin_status" 'logged-out admin request reaches login page'
assert_markup 'id="loginform"' "${BMC_TEST_TMP}/admin.html" 'admin route requires authentication'

submit_status="$(request_status "${BMC_BASE_URL}/wp-admin/admin-ajax.php?action=buymecoffee_submit" "${BMC_TEST_TMP}/submit.json")"
assert_status 403 "$submit_status" 'submission rejects a missing nonce'
assert_markup 'Invalid request nonce' "${BMC_TEST_TMP}/submit.json" 'submission returns the expected nonce error'

stripe_status="$(request_status "${BMC_BASE_URL}/wp-admin/admin-ajax.php?action=buymecoffee_payment_confirmation_stripe&intentId=pi_invalid" "${BMC_TEST_TMP}/stripe.json")"
assert_status 403 "$stripe_status" 'Stripe confirmation rejects a missing nonce'

paypal_status="$(request_status "${BMC_BASE_URL}/wp-admin/admin-ajax.php?action=buymecoffee_payment_confirmation_paypal&charge_id=invalid&hash=invalid" "${BMC_TEST_TMP}/paypal.json")"
assert_status 403 "$paypal_status" 'PayPal confirmation rejects a missing nonce'

# A real, nonce-bearing submission is refused when it carries no idempotency
# key, which is what an automated caller sends. Neither request below gets past
# that check, so nothing is written to the site under test.
bmc_nonce="$(rg -No '"buymecoffee_nonce":"([A-Za-z0-9]+)"' -r '$1' "${BMC_TEST_TMP}/public.html" | head -n 1 || true)"
if [[ -z "$bmc_nonce" ]]; then
    printf 'FAIL  donation form exposes a submission nonce\n' >&2
    exit 1
fi
printf 'PASS  donation form exposes a submission nonce\n'

submit_form=(
    -X POST
    --data-urlencode "action=buymecoffee_submit"
    --data-urlencode "buymecoffee_nonce=${bmc_nonce}"
    --data-urlencode "payment_method=stripe"
    --data-urlencode "form_data[0][name]=wpm-supporter-name"
    --data-urlencode "form_data[0][value]=HTTP Smoke Donor"
)

keyless_status="$(request_status "${BMC_BASE_URL}/wp-admin/admin-ajax.php" "${BMC_TEST_TMP}/keyless.json" "${submit_form[@]}")"
assert_status 400 "$keyless_status" 'submission requires an idempotency key'
assert_markup 'idempotency_key_required' "${BMC_TEST_TMP}/keyless.json" 'submission names the missing idempotency key'

malformed_status="$(request_status "${BMC_BASE_URL}/wp-admin/admin-ajax.php" "${BMC_TEST_TMP}/malformed.json" "${submit_form[@]}" --data-urlencode 'idempotency_key=short')"
assert_status 400 "$malformed_status" 'submission rejects a malformed idempotency key'
assert_markup 'invalid_idempotency_key' "${BMC_TEST_TMP}/malformed.json" 'submission names the malformed idempotency key'

# The unauthenticated webhook listeners enforce their body ceiling before they
# parse anything or contact a provider.
head -c 600000 /dev/zero | tr '\0' 'a' > "${BMC_TEST_TMP}/oversized.bin"

stripe_hook_big="$(request_status "${BMC_BASE_URL}/?buymecoffee_ipn_listener=1&method=stripe" "${BMC_TEST_TMP}/stripe_big.txt" -X POST -H 'Content-Type: application/json' --data-binary "@${BMC_TEST_TMP}/oversized.bin")"
assert_status 413 "$stripe_hook_big" 'Stripe webhook refuses an oversized body'

paypal_hook_big="$(request_status "${BMC_BASE_URL}/?buymecoffee_ipn_listener=1&method=paypal" "${BMC_TEST_TMP}/paypal_big.txt" -X POST --data-binary "@${BMC_TEST_TMP}/oversized.bin")"
assert_status 413 "$paypal_hook_big" 'PayPal IPN refuses an oversized body'

stripe_hook_bad="$(request_status "${BMC_BASE_URL}/?buymecoffee_ipn_listener=1&method=stripe" "${BMC_TEST_TMP}/stripe_bad.txt" -X POST -H 'Content-Type: application/json' --data '{"not":"an event"}')"
assert_status 400 "$stripe_hook_bad" 'Stripe webhook rejects an unreadable payload'

printf '\nHTTP smoke suite passed for %s\n' "$BMC_BASE_URL"
