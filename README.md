# Buy Me a Coffee — WordPress Plugin

> Accept one-time donations and recurring subscriptions via Stripe and PayPal — directly into your own account, with zero commission.


<img width="2752" height="1536" alt="compress-main-banner" src="https://github.com/user-attachments/assets/910027c7-cc31-47a6-9928-c6c864a52705" />


<img width="1536" height="1024" alt="members-only-banner" src="https://github.com/user-attachments/assets/02a67f3d-383a-4777-ad4a-aa2684ed4f80" />


<img width="2752" height="1536" alt="example-section" src="https://github.com/user-attachments/assets/0b63171f-2212-4524-a079-4c36a506c629" />

**Current version:** `1.2.5` · [Full Changelog](CHANGELOG.md)

[Plugin Site](https://wpminers.com/buymecoffee/) · [User Guide](https://wpminers.com/buymecoffee/docs/getting-started/quick-setup/) · [Demo](https://wpminers.com/buymecoffee-demo) · [WordPress.org](https://wordpress.org/plugins/buy-me-a-coffee/)

## Quick Navigation

- [Why Buy Me a Coffee](#why-buy-me-a-coffee)
- [Whats New in 12x](#whats-new-in-12x)
- [Features](#features)
- [Quick Start](#quick-start)
- [Requirements](#requirements)
- [Installation](#installation)
- [Development](#development)
- [Third-Party Services](#third-party-services)
- [Contributing](#contributing)
- [License](#license)

---

## Why Buy Me a Coffee

- Accept one-time and recurring donations on your own WordPress site
- Receive payments directly to your Stripe or PayPal account
- Manage supporters, subscriptions, and activity from a modern admin dashboard
- Customize donation forms with shortcodes, block support, and page templates

---

## What's New in 1.2.x

### 1.2.1
- Adds Subscription management
- Adds Design Update v2
- Adds Coffee theme and UI polish
- Adds Account creation email details
- Adds Active subscription helper and synced user meta
- Fixes Subscription query issue
- Fixes Payment flow and release pipeline
- Improves Subscriber account history view
- Improves Admin loader experience
- Redesigns All Page styling

### 1.2.0
- **Stripe Recurring Subscriptions** — monthly or yearly billing with full webhook lifecycle management
- **Refunds** — issue Stripe and PayPal refunds directly from the admin panel
- **Activity Log** — persistent event timeline for every payment, subscription, email, and webhook event

See [CHANGELOG.md](CHANGELOG.md) for the complete version history.

---

## Features

| Capability | Details |
|---|---|
| Payments | Stripe (on-site) and PayPal Pro |
| Recurring subscriptions | Monthly or yearly via Stripe with full webhook lifecycle |
| Refunds | Stripe and PayPal refunds from the admin panel |
| Activity log | Per-supporter and per-subscription event timeline |
| Forms and templates | Customizable donation button, form, and page template |
| Shortcodes and block | Gutenberg block + classic shortcodes |
| Supporter profiles | Full supporter, transaction, and activity history |
| Analytics dashboard | Donation stats, recent activity, and summaries |
| Email notifications | Custom donor and admin email templates |
| Dark mode | Full admin dark mode with system preference detection |
| Admin SPA | Full-page Vue 3 app with sidebar navigation |

---

## Quick Start

1. Install and activate the plugin
2. Open **Buy Me a Coffee -> Quick Setup**
3. Connect Stripe and/or PayPal credentials
4. Add `[buymecoffee_button]` or `[buymecoffee_form]` to any page
5. Publish and start collecting donations

---

## Requirements

- WordPress 4.5+
- PHP 7.4+
- Stripe and/or PayPal account

---

## Installation

1. Download the latest release zip and upload via **Plugins → Add New → Upload Plugin**, or install directly from the WordPress plugin directory.
2. Activate the plugin.
3. Go to **Buy Me a Coffee → Quick Setup** to configure your payment gateways.

---

## Development

```bash
npm install       # install dependencies
npm run dev       # Vite dev server with HMR (localhost:3000)
npm run build     # production build → assets/
npm run package   # build + create distributable zip
```

Built assets output to `assets/` with a `manifest.json` for cache-busted URLs. Entry points are defined in `vite.config.js`.

**Stack:** Vue 3 · Vue Router · Element Plus · Vite · Tailwind CSS · PHP 7.4+

---

## Common Shortcodes

- `[buymecoffee_button]` - Donation button with popup
- `[buymecoffee_form]` - Inline donation form
- `[buymecoffee_basic]` - Basic donation template

<details>
<summary><strong>Need setup help?</strong></summary>

Use the [User Guide](https://wpminers.com/buymecoffee/docs/getting-started/quick-setup/) for a complete walkthrough, including gateway configuration and shortcode placement.

</details>

---

## Third-Party Services

- **[Stripe](https://stripe.com)** — payment processing. [Privacy Policy](https://stripe.com/privacy) · [JS SDK](https://js.stripe.com/v3/)
- **[PayPal](https://paypal.com)** — payment processing. [JS SDK](https://www.paypal.com/sdk/js)
- **[WP Fluent DB](https://github.com/hasanuzzamanbe/wp-fluent/)** — bundled database query builder (no external requests)

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Contributing

Go to the [GitHub repository](https://github.com/hasanuzzamanbe/buy-me-coffee) — see `package.json` for the full list of scripts and dependencies.

---

> [!TIP]
> **Design System Guide:** See [`dev/blog-post-ui-redesign.md`](dev/blog-post-ui-redesign.md) for the full UI redesign process, token architecture, and implementation approach.

## License

GPLv2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
