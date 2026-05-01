=== Buy Me Coffee - Free Donation & Subscription Plugin for Stripe & PayPal ===
Contributors: wpminers, hasanuzzamanshamim
Tags: donate, donation, stripe, fundraising, paypal
Requires at least: 5.7
Tested up to: 6.9
Stable tag: 1.2.3
Requires PHP: 7.4
License: GPLv2 or later
Text Domain: buy-me-coffee
Accept donations and recurring subscriptions with Stripe & PayPal. No commission, no third-party platform. Supporter wall, Gutenberg block, tip jar.

== Description ==

[User Guide](https://wpminers.com/buymecoffee/docs/getting-started/quick-setup/) | [Demo](https://wpminers.com/buymecoffee-demo) | [Visit Plugin Site](https://wpminers.com/buymecoffee)

**Buy Me Coffee** is a free, lightweight WordPress donation plugin that lets you accept tips, donations, and recurring subscriptions directly to your own Stripe and PayPal accounts — with zero commission and no third-party platform in between.

Unlike services like Buy Me a Coffee, Ko-fi, or Patreon that route payments through their platforms and take a cut, this plugin sends every penny directly to your merchant account. Your supporters pay you, not a middleman.

[youtube https://www.youtube.com/watch?v=m3T5LQ1DOEc&ab_channel=WPMiners]

= Who Is This For? =

Buy Me Coffee is built for anyone who creates value online and wants a simple way to accept support:

* **Bloggers & Writers** — Add a "buy me a coffee" tip jar to any post or page
* **Artists & Musicians** — Accept donations for your creative work
* **Podcasters & YouTubers** — Let your audience support you directly
* **Open-Source Developers** — Fundraise for your project without a SaaS dependency
* **Nonprofits & Charities** — Collect donations with Stripe or PayPal
* **Educators & Coaches** — Accept recurring support from your community

= Why Choose Buy Me Coffee? =

* **Zero commission, zero fees** — We never take a percentage of your donations. Free means free.
* **Direct payments** — Stripe and PayPal deposit funds directly into YOUR account. No middleman platform, no delayed payouts.
* **No account required** — Your supporters don't need to create an account on a third-party site. They just donate.
* **Self-hosted & private** — All data stays on your WordPress site. No external tracking, no data harvesting.
* **Built-in recurring subscriptions** — Monthly and yearly subscriptions via Stripe, included free. No paid addon required.
* **Modern admin dashboard** — A beautiful Vue-powered admin panel with dark mode, not a dated WordPress settings page.

= Key Features =

**Accept Donations via Stripe & PayPal**
On-site Stripe checkout and PayPal Pro let your visitors pay without ever leaving your site. Supports 135+ currencies via Stripe and 20+ via PayPal. Credit cards, debit cards, Apple Pay, Google Pay — whatever Stripe supports.

**Recurring Subscriptions (Stripe)**
Let your supporters become long-term backers with monthly or yearly recurring donations. Full lifecycle management — automatic renewals via webhook, admin cancellation, status tracking, and a dedicated subscription management page.

**Supporter Wall & Donor Leaderboard**
Display a beautiful ranked leaderboard of your top supporters on any page with the `[buymecoffee_supporters]` shortcode. Gold, silver, and bronze badges for your top 3 donors. Fully configurable — choose what to show (name, avatar, amount, message) from the admin settings.

**Gutenberg Block & Shortcodes**
Add a donation button or form anywhere using the native Gutenberg block, or use shortcodes for classic editors and widgets:

* `[buymecoffee_button]` — Donation button (opens modal or links to donation page)
* `[buymecoffee_form]` — Inline donation form
* `[buymecoffee_basic]` — Full-page donation template with banner, profile, and form
* `[buymecoffee_supporters]` — Public supporter wall / donor leaderboard
* `[buymecoffee_account]` — Subscriber account dashboard for logged-in supporters

**One-Click Refunds**
Issue full refunds for Stripe and PayPal transactions directly from your admin panel. A confirmation modal shows transaction details and gateway response in real time — no need to log into your payment dashboard.

**Subscriber Account Page**
Recurring supporters automatically get a WordPress user account linked to their subscription. Place the `[buymecoffee_account]` shortcode on any page to give them a self-service dashboard showing their active subscriptions and payment history.

**Activity Logging**
Every event is recorded — payments, refunds, subscription renewals, cancellations, webhook events, and emails. Each supporter and subscription has its own activity timeline. A global Activity Log page gives you a filterable, paginated view of everything happening across your site.

**Email Notifications**
Automatically send a thank-you email to donors after each successful payment and notify yourself with an admin alert. Both templates are fully editable with dynamic placeholders like `{{donor_name}}`, `{{amount}}`, and `{{payment_method}}`.

**Supporters Admin Hub**
A dedicated admin page with:

* **Metric cards** — Total supporters, lifetime revenue, active subscribers, average donation
* **Top supporters ranking** — Your biggest backers at a glance
* **Configurable display settings** — Control what appears on the public supporter wall
* **Shortcode documentation** — Copy-paste shortcodes with descriptions
* **Privacy controls** — Hide emails, allow anonymous donations

**Customizable Appearance**
Personalize your donation page with your profile image, banner photo, brand colors, and custom quote. A live preview updates as you make changes. All styling is handled through CSS variables — no bloated CSS overrides.

**Modern Admin Panel**
The entire admin interface is built as a single-page application with Vue 3, featuring sidebar navigation, breadcrumbs, dark mode with system theme detection, and a responsive design that works on any screen size.

**Multisite Compatible**
Activate network-wide on WordPress Multisite — each site gets its own isolated data, supporters, and settings.

= Compare: Buy Me Coffee vs. Alternatives =

**Buy Me Coffee (this plugin)**

* 0% commission — you keep every cent
* Direct payments to your own Stripe or PayPal account
* Recurring subscriptions included free
* Admin refunds from WordPress
* Activity logging for every event
* Supporter wall shortcode built in
* Modern Vue SPA admin with dark mode
* Self-hosted data — nothing stored externally
* Free forever, no paid addons

**Official Buy Me a Coffee Plugin**

* 5% commission on every donation
* Payments routed through their platform
* Recurring subscriptions require a paid plan
* No admin refunds, no activity logging
* Basic widget — no full admin UI
* Data stored on external servers

**GiveWP Free**

* 0% commission, direct payments
* Recurring subscriptions require a paid addon
* Supporter wall requires an addon
* Traditional WordPress admin UI
* Self-hosted data
* Free core + paid addons for advanced features

**Ko-fi Plugin**

* Ko-fi takes 0% but the platform takes 5% on some features
* Payments routed through Ko-fi platform
* Recurring support via Ko-fi only
* No admin refunds or activity logging
* No WordPress admin UI — redirects to Ko-fi
* Data stored on external servers

= Get Started in 2 Minutes =

1. Install and activate the plugin
2. Run the Quick Setup wizard (Dashboard → Buy Me Coffee → Quick Setup)
3. Connect your Stripe or PayPal account
4. Add `[buymecoffee_button]` to any post or page
5. Start receiving donations directly to your account

== Installation ==
1. Download and upload the plugin files to `/wp-content/plugins/buy-me-coffee`, or install directly through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Dashboard → Buy Me Coffee → Quick Setup** to connect your payment gateway and configure your first donation form.

== Frequently Asked Questions ==

= Is this plugin really free? =
Yes. Buy Me Coffee is 100% free with no premium version, no paid addons, and no commission on donations. We will never charge you a percentage of your earnings.

= How do I accept donations? =
Connect your Stripe or PayPal account in Settings, then add the `[buymecoffee_button]` shortcode or Gutenberg block to any page. Your visitors can donate via credit card, debit card, or PayPal.

= Do you support recurring donations? =
Yes. Stripe recurring subscriptions are built in — supporters can choose monthly or yearly billing. Renewals, cancellations, and status changes are handled automatically via webhooks.

= Is this a Buy Me a Coffee alternative? =
Yes. Unlike the official Buy Me a Coffee platform which routes payments through their servers and takes a 5% cut, this plugin sends donations directly to your own Stripe or PayPal account with zero fees.

= What currencies are supported? =
Stripe supports 135+ currencies. PayPal supports USD, EUR, GBP, CAD, AUD, and 15+ more. You can select your preferred currency in the plugin settings.

= Can I display my supporters on the frontend? =
Yes. Use the `[buymecoffee_supporters]` shortcode to display a ranked supporter wall / donor leaderboard. Configure which fields to show (name, avatar, amount, message) from the Supporters admin page.

= Do supporters need an account? =
No. One-time donations require no account. For recurring subscribers, the plugin can optionally auto-create a WordPress user account so they can manage their subscription via the `[buymecoffee_account]` shortcode.

= Can I issue refunds from WordPress? =
Yes. Open any supporter's profile, click Refund Transaction, and the refund is processed through Stripe or PayPal in real time. The modal shows the gateway response and updated transaction status.

= Is it secure? =
Yes. All payments are processed by Stripe and PayPal — we never see or store card details. The admin panel is protected by WordPress nonces and capability checks. Webhook payloads are verified by re-fetching the event from the Stripe API.

= Does it work with my theme? =
Yes. The donation button, form, and supporter wall are designed to work with any properly-coded WordPress theme. Styles use scoped CSS classes that don't conflict with your theme.

= Does it support WordPress Multisite? =
Yes. The plugin can be activated network-wide. Each site in the network gets its own isolated database tables, settings, and supporter data.

== Screenshots ==
1. Admin Dashboard — Revenue chart, recent transactions, quick stats, and subscription overview
2. Supporters Hub — Metric cards, top supporter ranking, display settings, and shortcode docs
3. Supporter Profile — Transaction details, subscription info, payment history, and activity timeline
4. Subscription Management — Status, billing interval, payment history, and renewal tracking
5. Donation Page — Customizable public template with banner, profile, supporter wall, and form
6. Payment Settings — Stripe and PayPal configuration with test/live mode switching
7. Guided Quick Setup — Step-by-step wizard to get started in minutes
8. Global Settings — Form fields, currency, appearance, and email notification templates

== Changelog ==

= 1.2.3 May 01, 2026 =
- Adds Currency and number formatting
- Adds Safe “Delete all test data” in one click

= 1.2.2 April 30, 2026 =
- Adds Redesigned 5-step onboarding wizard (Welcome, Profile, Form, Payment, Launch)
- Adds Stripe API key verification ("Verify Connection") with backend validation
- Adds Supporters admin hub with metric cards, top supporters ranking, and display settings
- Adds Supporter wall shortcode [buymecoffee_supporters] with ranked leaderboard and top-3 badges
- Adds Public wall display settings (name, avatar, amount, message toggles) and privacy controls
- Adds Refund confirmation modal with real-time gateway response and transaction details
- Adds Cancel subscription option during refund for recurring payments
- Adds Transactions page (renamed from Supporters) for browsing all donation records
- Fixes Stripe webhook verification (re-fetch event from API instead of signature-only)
- Fixes Stripe subscription period_end using wrong field (invoice timestamp vs subscription period)
- Fixes Revenue stats not including renewal transaction amounts
- Fixes Delete supporter not cascading to activity logs, subscriptions, and transactions
- Improves Subscriber account history view
- Improves Admin loader and onboarding progress bar experience
- Fixes All modules security audits and improvements

= 1.2.1 April 26, 2026 =
- Adds Supporter wall shortcode [buymecoffee_supporters] with ranked leaderboard
- Adds Supporters admin hub with metrics, top supporters, and display settings
- Adds Refund confirmation modal with real-time gateway response
- Adds Cancel subscription option during refund for recurring payments
- Adds Subscription info card on transaction detail page
- Adds Fetch subscription from Stripe to sync missing transactions
- Adds Debug logging for Stripe webhook flow (BUYMECOFFEE_DEBUG)
- Adds Subscription management with full Stripe webhook lifecycle
- Adds Account creation for recurring subscribers
- Adds Coffee theme and admin UI polish
- Fixes Stripe webhook verification (re-fetch from API instead of signature-only)
- Fixes Stripe subscription period_end using invoice timestamp instead of subscription period
- Fixes Supporters list showing duplicates (LEFT JOIN replaced with subquery)
- Fixes Dashboard Avg Donation showing $0.00 (wrong field name)
- Fixes Revenue stats not including renewal transactions
- Fixes Total coffees counting unpaid entries
- Fixes Delete supporter not cascading to activity logs and subscriptions
- Fixes Transactions::delete() ignoring $column parameter
- Fixes handleError crash (this.$notify not a function)
- Fixes SQL injection via LIKE wildcards in invoice_id queries
- Fixes PHPCS WordPress.DB.PreparedSQL warnings
- Improves Subscriber account history view
- Improves Admin loader experience
- Redesigns All page styling with design token system

= 1.2.0 April 26, 2026 =
- Adds Stripe recurring subscriptions with monthly or yearly billing
- Adds Subscription management page with status, billing, renewal date, and cancel option
- Adds Stripe subscription webhooks for renewals, cancellations, and status updates
- Adds Refund module for Stripe and PayPal transactions
- Adds Activity Log for payments, subscriptions, refunds, webhooks, and emails
- Adds Activity timeline on supporter and subscription pages
- Adds Global Activity Log page with filters and pagination
- Adds Gateway logos in payment method selector
- Fixes Stripe IPN and PayPal API validation issues
- Fixes Subscription confirmation mismatch on payment return

= 1.1.0 April 25, 2026 =
- Adds Redesigned public donation page with two-column layout, banner, about card, and supporter list
- Adds Inline page editor for admins (edit cover, profile, name, bio, and accent color on the public page)
- Adds Banner image upload with drag-and-drop support and WP media picker
- Adds Share button on public page with native share sheet and clipboard fallback
- Adds Recent supporters list on the public donation page
- Adds Email notifications with customizable donor and admin templates
- Adds Dark mode support across the entire admin panel
- Adds Full-page admin SPA with collapsible sidebar navigation and design token system
- Adds Settings sub-navigation (General, Appearance, Shortcodes) integrated into the sidebar
- Fixes Stripe checkout hiding form fields when card element appears
- Fixes Currency symbol rendering (HTML entities now decoded before JSON output)
- Fixes WordPress admin padding and focus ring bleed into the plugin admin page
- Fixes Payment receipt page layout and design

= 1.0.6 January 11, 2026 =
- Adds PayPal settings validation
- Fixes Translation issue
- Enhanced security

= 1.0.5 April 10, 2025 =
- Fixes Chart height issue
- Fixes Styling issues

= 1.0.4 December 01, 2024 =
- Adds PayPal Standard Payment on site confirmation
- Adds New Supporters/Donor profile page
- Adds Supporters table filter and search
- Fixes Styling issues

= 1.0.3 August 26, 2024 =
- Fixes Styling issue
- Fixes Customizer module issue
- Fixes Checkout button issue

= 1.0.2 March 10, 2024 =
- Adds Realtime theme customizer

= 1.0.1 March 07, 2024 =
- Adds PayPal Pro Payment Gateway

= 1.0.0 March 03, 2024 =
- Initial release

== Upgrade Notice ==

= 1.2.2 =
Redesigned onboarding wizard with PayPal setup, Stripe key verification, form configuration. New Supporters hub, public supporter wall, refund modal, and 15+ bug fixes. Recommended for all users.

= 1.2.1 =
Supporter wall shortcode, admin supporters hub with metrics and rankings, refund modal with gateway response, webhook fixes, and multiple bug fixes.

# Development Docs

#### CDN used for Payments:
* [Stripe SDK](https://js.stripe.com/v3/)
  is used to create a Stripe payment element and collect donations from your visitors. There is clear documentation on Stripe's website about how Stripe manages user data.

* [PayPal SDK](https://developer.paypal.com/sdk/js/reference/)
  is used to create a PayPal donation button and collect donations from your visitors. There is clear documentation on PayPal's website about how PayPal manages user data.

#### 3rd Party services:
* [Stripe](https://www.stripe.com)
  is used to process payments. The client SDK is loaded from [js.stripe.com/v3/](https://js.stripe.com/v3/) and the server communicates with the [Stripe API](https://api.stripe.com/v1/). No card information is stored on your server — only the Stripe public key and secret key are saved in your WordPress database.
  [Stripe Privacy Policy](https://stripe.com/privacy) | [Stripe Terms of Service](https://stripe.com/legal)

* [PayPal](https://www.paypal.com/)
  is used to process PayPal donations. The SDK is loaded from [paypal.com/sdk/js](https://www.paypal.com/sdk/js?client-id=) with your Client ID. IPN verification uses [ipnpb.paypal.com](https://ipnpb.paypal.com/cgi-bin/webscr) (live) and [ipnpb.sandbox.paypal.com](https://ipnpb.sandbox.paypal.com/cgi-bin/webscr) (test).
  [PayPal Privacy Policy](https://www.paypal.com/us/legalhub/privacy-full) | [PayPal User Agreement](https://www.paypal.com/us/legalhub/useragreement-full)

#### PHP library:
* [WP Fluent DB](https://github.com/hasanuzzamanbe/wp-fluent/) — A lightweight database query builder for WordPress inspired by Laravel's Eloquent. Bundled in the plugin, no external data collection.

#### NPM Packages:
* [vue](https://www.npmjs.com/package/vue) — Vue 3 framework
* [vue-router](https://www.npmjs.com/package/vue-router) — Client-side routing for the admin SPA
* [element-plus](https://www.npmjs.com/package/element-plus) — Vue 3 UI component library
* [@element-plus/icons-vue](https://www.npmjs.com/package/@element-plus/icons-vue) — Element Plus icon set
* [lucide-vue-next](https://www.npmjs.com/package/lucide-vue-next) — Lucide icon components for Vue 3
* [@fontsource/inter](https://www.npmjs.com/package/@fontsource/inter) — Self-hosted Inter typeface
* [@wordpress/hooks](https://www.npmjs.com/package/@wordpress/hooks) — WordPress hooks system for JS
* [chart.js](https://www.npmjs.com/package/chart.js) — Chart rendering for the dashboard
* [clipboard](https://www.npmjs.com/package/clipboard) — Clipboard copy utility
* [lodash](https://www.npmjs.com/package/lodash) — Utility library
* [lodash-es](https://www.npmjs.com/package/lodash-es) — ES module build of lodash
* [nanoid](https://www.npmjs.com/package/nanoid) — Tiny unique ID generator

#### Contribution:
Visit the [GitHub Repository](https://github.com/hasanuzzamanbe/buy-me-coffee) for source code, build scripts, and contribution guidelines.
