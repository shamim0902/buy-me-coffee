# Buy Me a Coffee — WordPress Plugin

> Accept one-time donations and recurring subscriptions via Stripe and PayPal — directly into your own account, with zero commission.

**Current version:** 1.2.0 · [Full Changelog →](CHANGELOG.md)

[Plugin Site](https://wpminers.com/buymecoffee/) · [User Guide](https://wpminers.com/buymecoffee/docs/getting-started/quick-setup/) · [Demo](https://wpminers.com/buymecoffee-demo) · [WordPress.org](https://wordpress.org/plugins/buy-me-a-coffee/)

---

## What's New in 1.2.0

- **Stripe Recurring Subscriptions** — monthly or yearly billing with full webhook lifecycle management
- **Refunds** — issue Stripe and PayPal refunds directly from the admin panel
- **Activity Log** — persistent event timeline for every payment, subscription, email, and webhook event

See [CHANGELOG.md](CHANGELOG.md) for the complete version history.

---

## Features

| Feature | Details |
|---|---|
| One-time donations | Stripe (on-site) and PayPal Pro |
| Recurring subscriptions | Monthly or yearly via Stripe with webhook lifecycle |
| Refunds | Stripe and PayPal refunds from the admin |
| Activity Log | Per-supporter, per-subscription timeline + global log page |
| Donation forms | Customizable buttons, forms, and page templates |
| Shortcodes & blocks | Gutenberg block + classic shortcodes |
| Supporter profiles | Full transaction and activity history per donor |
| Dashboard | Donation statistics and recent activity |
| Email notifications | Fully customizable donor and admin email templates |
| Dark mode | Full admin dark mode with system preference detection |
| Admin SPA | Full-page Vue 3 admin with sidebar navigation |

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

## License

GPLv2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
