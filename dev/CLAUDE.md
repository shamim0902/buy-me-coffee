# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Buy Me a Coffee is a WordPress plugin for collecting donations via Stripe and PayPal. It provides shortcodes, a Gutenberg block, and a standalone donation page. The admin UI is a Vue 3 SPA served inside wp-admin.

## Build Commands

```bash
npm install          # Install dependencies
npm run dev          # Start Vite dev server (HMR on localhost:3000)
npm run build        # Production build (outputs to assets/)
npm run package      # Build + create distributable zip via wp dist-archive
```

The build system uses **Vite** with Vue and React plugins. Entry points are defined in `vite.config.js` — the `inputs` object maps output paths to source files. Built assets go to `assets/` with a `manifest.json` for cache-busted URLs.

Dev mode is toggled by `src/env/development_mode.js` / `production_mode.js`, which set `BUYMECOFFEE_DEVELOPMENT` in the main plugin file.

There are no tests (no PHPUnit, no Jest/Vitest).

## Architecture

### PHP (includes/)

- **Entry point:** `buy-me-coffee.php` boots on `plugins_loaded`, wiring admin hooks, shortcodes, payment methods, and IPN listeners.
- **Autoloader:** Custom PSR-4 in `includes/autoload.php`. Namespace `BuyMeCoffee\Foo\Bar` maps to `includes/Foo/Bar.php`.
- **Models** (`Models/`): `Supporters`, `Transactions`, `Buttons` — use the bundled WP Fluent query builder (`includes/libs/wp-fluent/`). Access via `buyMeCoffeeQuery()` global helper.
- **Controllers** (`Controllers/`): `SubmissionHandler` (public form submissions), `PaymentHandler` (gateway orchestration).
- **Admin AJAX** (`Classes/AdminAjaxHandler.php`): Single endpoint `wp_ajax_buymecoffee_admin_ajax` with a `route` parameter dispatching to methods. No REST API.
- **Payment Gateways** (`Builder/Methods/`): Each gateway extends `BaseMethods` abstract class and lives in its own directory (e.g., `Stripe/`, `PayPal/`) containing the main class, Settings, API, and IPN handler.
- **Views** (`views/`): PHP templates rendered via `View` class with data extraction.
- **Helpers** (`Helpers/`): `PaymentHelper`, `ArrayHelper`, `Currencies`, `SanitizeHelper`, `BuilderHelper`.

### Frontend (src/)

- **Admin SPA:** Vue 3 + Vue Router + Element Plus. Entry at `src/js/main.js`, routes in `src/js/routes.js`. Components in `src/js/Components/`.
- **Public JS:** `BmcPublic.js` (donation button), `BmcFormHandler.js` (form handling).
- **Payment checkout:** `PaymentMethods/stripe-checkout.js`, `PaymentMethods/paypal-checkout.js`.
- **Gutenberg block:** `Editor/gutenBlock.jsx` (React/JSX).
- **Styles:** SCSS in `src/scss/` — admin uses Element Plus + Tailwind CSS, public has separate stylesheets.

### Database

Two custom tables created on activation (`Classes/Activator.php`):
- `{prefix}_buymecoffee_supporters` — donor records
- `{prefix}_buymecoffee_transactions` — payment transactions linked via `entry_id`

Settings stored in `wp_options` as `buymecoffee_payment_setting`.

### Hook Naming Conventions

- `buymecoffee_make_payment_{method}` — initiate payment
- `buymecoffee_ipn_endpoint_{method}` — webhook/IPN listener
- `buymecoffee_render_component_{method}` — render payment UI
- `buymecoffee_before/after_supporters_data_insert` — data lifecycle

### Shortcodes

- `[buymecoffee_button]` — donation button with popup
- `[buymecoffee_form]` — inline form only
- `[buymecoffee_basic]` — basic template page
- `?share_coffee` URL parameter — standalone donation page

## Key Conventions

- PHP 7.4+ required. WordPress 4.5+.
- Text domain: `buy-me-coffee`. All user-facing strings use `__()` / `_e()`.
- No Composer — the WP Fluent query builder is bundled in `includes/libs/`.
- Asset enqueueing goes through `Classes/Vite.php` which reads `manifest.json` in production or proxies to the dev server.
- Admin AJAX uses nonce `buymecoffee_nonce` for all requests.
- Payment amounts are stored as integers (cents).
- Audit exceptions and accepted intentional behaviors are documented in `dev/AUDIT_RULES.md`; check that file before raising security findings.

## Adding a New Payment Gateway

1. Create a directory under `includes/Builder/Methods/YourGateway/`.
2. Extend `BaseMethods` and implement: `isEnabled()`, `render()`, `getPaymentSettings()`, `getSettings()`, `sanitize()`, `getTransactionUrl()`.
3. Add IPN handler class with `verifyIpn()`.
4. Instantiate in `buy-me-coffee.php::commonActions()`.
5. Add frontend checkout script in `src/js/PaymentMethods/` and register in `vite.config.js` inputs.
