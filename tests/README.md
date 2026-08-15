# Buy Me Coffee feature tests

This suite boots WordPress core plus this plugin, then exercises the plugin against the local WordPress database. Every test runs inside a database transaction and rolls back its fixtures.

Covered feature groups:

- Plugin hooks, shortcodes, AJAX registrations, and gateway/webhook wiring
- Required custom tables and performance-critical indexes
- Gateway registration and enabled-state normalization
- Stripe normal/zero-decimal currency conversion
- Input, email, HTML, color, and template sanitization
- Donation amount calculation and quantity bounds
- Server-side membership checkout binding (price, interval, quantity, and recurring mode)
- One-time membership activation, cache refresh, and refund revocation
- Subscription access-expiry rules
- Guest/member paywall behavior and preview length
- Public button, form, supporter wall, and account rendering
- Delegated admin capability boundaries
- Per-post metadata authorization

See [FEATURE-MATRIX.md](FEATURE-MATRIX.md) for the complete automated and sandbox/staging release checklist.

Run from the plugin directory:

```bash
npm test
```

Run the read-only HTTP smoke tests against the local site:

```bash
npm run test:http
# Or: BMC_BASE_URL=https://another-site.test npm run test:http
```

Run PHP features, HTTP smoke checks, and the production build together:

```bash
npm run test:all
```

The default WordPress root is inferred when the plugin lives in `wp-content/plugins/buy-me-coffee`. Otherwise:

```bash
BMC_WP_ROOT=/path/to/wordpress php tests/run.php
```

For safety, the runner accepts only `localhost`, loopback, `.test`, or `.local` WordPress hosts. A deliberately isolated non-local test database can be enabled with `BMC_ALLOW_NONLOCAL_TEST_DB=1`.

The suite never sends a payment or calls Stripe/PayPal APIs. Real gateway checkout, signed webhooks, refunds, email delivery, and browser-specific flows remain staging/UAT tests.

The current local WordPress stack runs the feature suite on PHP 8.1–8.5. All plugin and test PHP files also pass PHP 7.4 syntax lint. Because the local WordPress dependency stack requires PHP 8.1, functional PHP 7.4/8.0 coverage must run against a compatible isolated WordPress fixture before claiming the plugin's declared PHP 7.4 support.
