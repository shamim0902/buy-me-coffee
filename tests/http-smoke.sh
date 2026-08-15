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

printf '\nHTTP smoke suite passed for %s\n' "$BMC_BASE_URL"
