=== Buy Me a Coffee button & widgets - Fundraise with Stripe and PayPal ===
Contributors: wpminers, hasanuzzamanshamim
Tags: buy me a coffee, donation, membership, paywall, stripe payments
Requires at least: 5.7
Tested up to: 6.9
Stable tag: 1.2.6
Requires PHP: 7.4
License: GPLv2 or later
Text Domain: buy-me-coffee
Monetize posts with paid memberships, member-only content, donations, tips, and recurring subscriptions via Stripe and PayPal.

== Description ==

[User Guide](https://wpminers.com/buymecoffee/docs/getting-started/quick-setup/) | [Demo](https://wpminers.com/buymecoffee-demo) | [Visit Plugin Site](https://wpminers.com/buymecoffee)

**Buy Me Coffee** is a free, lightweight WordPress content monetization and donation plugin that lets you sell paid memberships, protect member-only posts, accept tips, receive one-time donations, and run recurring monthly or yearly subscriptions directly through your own Stripe and PayPal accounts — with **zero commission** and no third-party platform in between.

**New in 1.2.6: Memberships and content monetization.** Create paid membership levels, lock premium posts or pages, show teaser content with a built-in paywall CTA, and let supporters become paying members without sending them to a third-party creator platform.

Unlike Buy Me a Coffee, Ko-fi, or Patreon, which route payments through their own platforms and take a cut, this plugin sends every payment directly to your merchant account. Your supporters pay you — not a middleman.

[youtube https://www.youtube.com/watch?v=m3T5LQ1DOEc&ab_channel=WPMiners]

= Who Is This For? =

Buy Me Coffee is built for anyone who creates value online and wants a direct, self-hosted way to accept donations and tips:

* **Bloggers & Writers** — Add a "buy me a coffee" tip jar to any post or page
* **Artists & Musicians** — Accept donations and one-time tips for your creative work
* **Podcasters & YouTubers** — Let your audience support you directly without a middleman
* **Open-Source Developers** — Fundraise for your project without a SaaS dependency
* **Nonprofits & Charities** — Collect donations with Stripe or PayPal, zero commission
* **Educators & Coaches** — Accept monthly recurring support from your community
* **Freelancers & Consultants** — Let clients or fans tip your work
* **Church & Community Organizations** — Accept recurring giving on your own WordPress site
* **Membership Site Owners** — Monetize premium posts, tutorials, newsletters, lessons, downloads, or private updates with paid access levels

= Why Choose Buy Me Coffee? =

* **Memberships and content monetization** — Create paid membership levels, restrict premium posts, and convert readers into recurring members.
* **Zero commission, zero fees** — We never take a percentage of your donations. Free means free, forever.
* **Direct payments** — Stripe and PayPal deposit funds directly into YOUR bank account. No platform holding your money.
* **No third-party account required** — Supporters don't need to sign up anywhere. They just donate.
* **Self-hosted & private** — All donor data stays on your WordPress site. No external tracking, no data harvesting.
* **Recurring subscriptions built-in** — Monthly and yearly Stripe subscriptions included free. No paid addon required.
* **Modern admin dashboard** — A beautiful Vue 3-powered admin panel with dark mode, revenue charts, and activity logs.
* **One-click refunds** — Refund any Stripe or PayPal transaction from inside WordPress — no need to log into your gateway dashboard.
* **Full activity logging** — Every payment, renewal, cancellation, refund, and webhook event is recorded with a searchable timeline.

= Key Features =

**Memberships & Content Monetization**
Turn your WordPress site into a self-hosted membership platform. Create paid membership levels with monthly or yearly pricing, descriptions, and reward bullets. Mark posts or pages as members-only from the editor, choose which membership levels can access each piece of content, and show non-members a word-limited preview followed by a polished paywall CTA. Active members get access automatically based on their subscription status.

**Accept Donations via Stripe & PayPal**
On-site Stripe checkout and PayPal integration let your visitors donate without ever leaving your website. Supports 135+ currencies via Stripe and 20+ via PayPal. Credit cards, debit cards, Apple Pay, and Google Pay — whatever your visitors prefer.

**Recurring Monthly & Yearly Donations (Stripe)**
Let your supporters become long-term monthly or yearly backers with Stripe recurring subscriptions. Full lifecycle management is included: automatic renewals via webhook, admin cancellation from WordPress, subscription status tracking, and a dedicated subscriber account page. No paid addon required.

**Membership Members Dashboard**
Manage active members from a dedicated Memberships screen. Search members by name or email, review their level, billing interval, renewal date, and subscription status, then jump directly to subscription details or cancel a membership when needed.

**Supporter Wall & Donor Leaderboard**
Display a beautiful ranked leaderboard of your top donors on any page with the `[buymecoffee_supporters]` shortcode. Gold, silver, and bronze badges highlight your top 3 supporters. Choose which fields to display (name, avatar, amount, message) from the admin settings.

**Gutenberg Block & Shortcodes**
Add a donation button, inline donation form, or full donation page anywhere on your site using the native Gutenberg block or classic-editor shortcodes:

* `[buymecoffee_button]` — Donation button that opens a modal or links to your donation page
* `[buymecoffee_form]` — Inline donation form embedded on any post or page
* `[buymecoffee_basic]` — Full-page donation template with banner, profile, supporter wall, and form
* `[buymecoffee_supporters]` — Public supporter wall / ranked donor leaderboard
* `[buymecoffee_account]` — Subscriber self-service dashboard for logged-in recurring supporters

**One-Click Refunds**
Issue full refunds for Stripe and PayPal transactions directly from your WordPress admin. A confirmation modal displays transaction details and live gateway response — no need to visit your Stripe or PayPal dashboard.

**Subscriber Account Page**
Recurring supporters are optionally assigned a WordPress user account linked to their subscription. Place the `[buymecoffee_account]` shortcode on any page to give them a self-service dashboard showing active subscriptions, billing history, and payment status.

**Complete Activity Logging**
Every event is logged — payments, refunds, subscription renewals, cancellations, webhook events, and outgoing emails. Each supporter and subscription has its own chronological activity timeline. A global Activity Log page provides a filterable, paginated view across your entire donation history.

**Email Notifications**
Automatically send a branded thank-you email to donors after every successful payment, and receive an admin notification for each new donation. Both templates are fully customizable with dynamic placeholders: `{{donor_name}}`, `{{amount}}`, `{{payment_method}}`, and more.

**Supporters Admin Hub**
A dedicated admin hub with everything in one place:

* **Metric cards** — Total supporters, lifetime revenue, active subscribers, average donation amount
* **Top supporters ranking** — Your highest-value donors at a glance
* **Configurable display settings** — Control exactly what appears on the public supporter wall
* **Shortcode documentation** — Copy-paste shortcodes with descriptions, inline in the admin
* **Privacy controls** — Mask email addresses, allow anonymous donations, hide donor amounts

**Customizable Donation Page Appearance**
Personalize your donation page with a profile image, banner photo, brand accent color, and a custom quote. A live preview updates in real time as you make changes. Styling uses CSS custom properties — no bloated override rules.

**Modern Vue 3 Admin Panel**
The entire admin interface is a single-page application built with Vue 3, featuring sidebar navigation, breadcrumbs, a revenue chart dashboard, dark mode with system theme detection, and a responsive layout that works on desktop and mobile.

**WordPress Multisite Compatible**
Activate network-wide on WordPress Multisite — each site gets its own isolated tables, supporter records, and plugin settings.

**Guided Quick Setup Wizard**
A 5-step onboarding wizard (Welcome → Profile → Form → Payment → Launch) gets you from zero to accepting donations in under two minutes. Includes Stripe API key verification and PayPal credentials setup.

**Test Mode & Safe Data Reset**
Switch between Stripe test mode and live mode without changing your configuration. A "Delete all test data" button removes test transactions, supporters, and subscriptions in one click so your dashboard stays clean.

= Compare: Buy Me Coffee vs. Alternatives =

Buy Me Coffee is built for direct, self-hosted donations and memberships instead of routing supporters through an external platform.

* **Commission:** Buy Me Coffee takes 0%. Official Buy Me a Coffee takes a platform fee. GiveWP Free takes 0%. Ko-fi may charge platform fees for some features.
* **Payment routing:** Buy Me Coffee sends payments directly to your Stripe or PayPal account. Official Buy Me a Coffee routes payments through its platform. GiveWP routes payments directly. Ko-fi routes payments through Ko-fi.
* **Content monetization:** Buy Me Coffee includes paid membership levels, members-only content controls, and a built-in paywall CTA for premium posts and pages.
* **Recurring subscriptions:** Buy Me Coffee includes Stripe subscriptions free. Official Buy Me a Coffee requires a paid plan. GiveWP requires a paid addon. Ko-fi subscriptions stay on Ko-fi.
* **Admin refunds:** Buy Me Coffee supports Stripe and PayPal refunds from WordPress. The compared free alternatives do not include the same built-in WordPress refund workflow.
* **Activity logging:** Buy Me Coffee includes a full payment, refund, subscription, webhook, and email event log.
* **Supporter wall:** Buy Me Coffee includes a built-in `[buymecoffee_supporters]` shortcode.
* **Admin UI:** Buy Me Coffee uses a modern Vue-powered WordPress admin screen with dark mode.
* **Data storage:** Buy Me Coffee stores supporter and payment records on your WordPress site.
* **Price:** Buy Me Coffee is free forever with no plugin commission.

= Get Started in 2 Minutes =

1. Install and activate the plugin from the WordPress plugin directory
2. Run the Quick Setup wizard (Dashboard → Buy Me Coffee → Quick Setup)
3. Connect your Stripe or PayPal account in Settings
4. Add `[buymecoffee_button]` to any post, page, or widget area
5. Start receiving donations directly to your account — zero commission

== Installation ==

1. Download and upload the plugin files to `/wp-content/plugins/buy-me-coffee`, or install directly through the WordPress plugin screen using **Plugins → Add New → Search "Buy Me Coffee"**.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Dashboard → Buy Me Coffee → Quick Setup** to connect your payment gateway and configure your donation form.

== Frequently Asked Questions ==

= Is this plugin really free? =
Yes. Buy Me Coffee is 100% free with no premium version, no paid addons, and no commission on donations. We will never charge you a percentage of your donations or earnings.

= Is this a Buy Me a Coffee alternative? =
Yes. Unlike the official Buy Me a Coffee platform which routes payments through their servers and charges a 5% fee, this plugin sends donations directly to your own Stripe or PayPal account with zero platform fees.

= Is this a Ko-fi alternative? =
Yes. Unlike Ko-fi which requires your supporters to use the Ko-fi platform and routes payments through their service, this plugin keeps everything on your own WordPress site with direct payments to your accounts.

= Is this a Patreon alternative? =
Yes. If you want to accept recurring monthly or yearly support from your community without giving Patreon a cut, this plugin provides built-in recurring subscriptions via Stripe — no third-party platform required.

= Can I monetize members-only posts? =
Yes. Version 1.2.6 adds membership levels and post/page access controls. Create paid levels, mark content as Paid from the editor, choose allowed levels, and show non-members a teaser plus paywall CTA.

= Can I create multiple membership tiers? =
Yes. You can create multiple active membership levels with monthly or yearly pricing, descriptions, reward bullets, and content access rules.

= How do I accept donations? =
Connect your Stripe or PayPal account in the Settings page, then add the `[buymecoffee_button]` shortcode or Gutenberg block to any page. Your visitors can donate via credit card, debit card, Apple Pay, Google Pay, or PayPal.

= Do you support recurring donations? =
Yes. Stripe recurring subscriptions are fully built in — supporters can choose monthly or yearly billing intervals. Renewals, cancellations, payment failures, and status changes are handled automatically via Stripe webhooks. No paid addon is required.

= What currencies are supported? =
Stripe supports 135+ currencies. PayPal supports USD, EUR, GBP, CAD, AUD, and 15+ more. You can select your preferred currency in the plugin settings.

= Can I display my supporters publicly? =
Yes. Use the `[buymecoffee_supporters]` shortcode to display a ranked supporter wall and donor leaderboard on any page. Configure which fields to show (name, avatar, amount, message) and privacy settings from the Supporters admin page.

= Do donors need to create an account? =
No. One-time donations require no account anywhere. For recurring subscribers, the plugin can optionally auto-create a WordPress user account so they can manage their subscription via the `[buymecoffee_account]` shortcode.

= Can I issue refunds from WordPress? =
Yes. Open any supporter's profile, click Refund Transaction, and the refund is processed through Stripe or PayPal in real time. The confirmation modal shows the live gateway response and updated transaction status.

= Is there a tip jar shortcode? =
Yes. The `[buymecoffee_button]` shortcode creates a donation/tip button on any page. The `[buymecoffee_form]` shortcode embeds an inline donation form. Both work as tip jars for blog posts, portfolios, or any page.

= How do I add a donation button to a blog post? =
Add `[buymecoffee_button]` anywhere inside the post content. You can also use the native Gutenberg block — search "Buy Me Coffee" in the block inserter to add it visually without a shortcode.

= Can I set a suggested or default donation amount? =
Yes. You can configure preset donation amounts and a default selected amount in the plugin's Form Settings. Donors can also enter a custom amount.

= Is it compatible with page builders like Elementor or Divi? =
Yes. The shortcodes work in any shortcode-capable widget or page builder area. The Gutenberg block works natively in the WordPress block editor.

= Is it secure? =
Yes. All payments are processed directly by Stripe and PayPal — no card numbers or sensitive payment data ever touches your server. The admin panel is protected by WordPress nonces and user capability checks. Stripe webhook payloads are verified by re-fetching the event from the Stripe API rather than relying on signature alone.

= Does it work with my theme? =
Yes. The donation button, form, and supporter wall are designed to work with any properly coded WordPress theme. Styles use scoped CSS classes and CSS custom properties that do not conflict with your theme's styles.

= Does it support WordPress Multisite? =
Yes. The plugin can be activated network-wide. Each site in the network gets its own isolated database tables, settings, supporters, and donation records.

= Can I customize the donation page appearance? =
Yes. Upload a banner image, set a profile photo, choose your brand accent color, and write a custom quote. Changes appear in a live preview before you save. The `?share_coffee` URL parameter loads your full-page branded donation experience.

= What email notifications does it send? =
The plugin sends a thank-you email to the donor after each successful payment and an admin notification email to you. Both templates support custom HTML and dynamic merge tags including donor name, amount, and payment method.

= Can I see a history of all donation activity? =
Yes. Every payment, renewal, refund, cancellation, and webhook event is recorded in an activity log. Each supporter profile has its own timeline. A global Activity Log page shows your complete donation history with filters and pagination.

== Screenshots ==

1. Admin Dashboard — Revenue chart, recent transactions, quick stats, and subscription overview
2. Supporters Hub — Metric cards, top supporter ranking, display settings, and shortcode docs
3. Supporter Profile — Transaction details, subscription info, payment history, and activity timeline
4. Subscription Management — Status, billing interval, payment history, and renewal tracking
5. Memberships — Create membership levels, manage active members, and configure content monetization
6. Members-Only Paywall — Teaser content with membership levels, pricing, rewards, and join CTA
7. Donation Page — Customizable public template with banner, profile, supporter wall, and form
8. Payment Settings — Stripe and PayPal configuration with test/live mode switching
9. Guided Quick Setup — Step-by-step wizard to get started in minutes
10. Global Settings — Form fields, currency, appearance, and email notification templates

== Changelog ==

= 1.2.6 May 03, 2026 =
- Adds Memberships as the headline monetization feature for paid content.
- Adds membership levels with monthly or yearly pricing, descriptions, rewards, status controls, and checkout preview links.
- Adds members-only post and page access controls in the editor with allowed membership levels and preview word counts.
- Adds paywall CTA for restricted content with teaser content, level pricing, reward bullets, and return-to-content checkout flow.
- Adds active members dashboard with search, level, renewal date, billing interval, subscription status, and quick subscription actions.
- Adds membership members API and subscription detail links from member records.
- Adds top navigation and admin UI refinements for a cleaner membership workflow.
- Improves supporter/subscription links by using safer link tokens in admin tables.
- Improves frontend route guards and disables development mode for production builds.
- Fixes PayPal live-mode checkout and currency handling improvements from the release range.

= 1.2.5 May 02, 2026 =
- Fixes Security audits
- Improves different modules.
- Fixes Stripe subscription confirmation mismatch errors and subscriber account creation emails.
- Fixes PayPal live-mode checkout by using the current live REST endpoint, safer redirect URL generation, single-item Standard checkout, and supported-currency validation.

= 1.2.4 May 01, 2026 =
- Adds Deactivation feedback modal with WPMiners feedback collection
- Improves Quick Setup visibility so it hides after setup is complete
- Improves Buy Me Coffee admin page by suppressing stray WordPress notices
- Fixes release compatibility issue for translated placeholder text

= 1.2.3 May 01, 2026 =
- Adds Currency and number formatting
- Adds Safe "Delete all test data" in one click

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

= 1.2.6 =
Major content monetization release. Adds paid membership levels, members-only post/page access, paywall CTA, member management, and admin UI improvements. Recommended for creators monetizing premium content.

= 1.2.2 =
Redesigned onboarding wizard with PayPal setup, Stripe key verification, form configuration. New Supporters hub, public supporter wall, refund modal, and 15+ bug fixes. Recommended for all users.

= 1.2.1 =
Supporter wall shortcode, admin supporters hub with metrics and rankings, refund modal with gateway response, webhook fixes, and multiple bug fixes.
