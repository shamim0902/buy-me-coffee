# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.1] — 2026-04-26

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

---

## [1.2.0] — 2026-04-26

### Added

#### Stripe Recurring Subscriptions
- Supporters can opt into monthly or yearly recurring billing at checkout via a new interval selector on the donation form
- Stripe subscription created and stored in a new `buymecoffee_subscriptions` table with status, interval, next renewal date, and Stripe subscription ID
- Dedicated **Subscriptions** admin page — list view with status badges, billing interval, amount, and next renewal; full detail page with cancel action
- Stripe webhooks handle the full subscription lifecycle automatically:
  - `invoice.payment_succeeded` — records renewal transactions and activates newly created subscriptions
  - `customer.subscription.deleted` — marks subscription as cancelled
  - `customer.subscription.updated` — reflects plan or status changes
- Admin can cancel a subscription from the detail page; cancellation is sent to Stripe and reflected immediately

#### Refund Module
- New **Refund** button on the supporter/transaction detail page
- Supports full refunds for both Stripe (payment intent or charge ID) and PayPal transactions
- Refund result is reflected on the transaction status immediately; guarded against double-refund with a lock
- Refund events (`refund_initiated`, `refund_failed`, `refund_completed`) are written to the activity log

#### Activity Log
- New `buymecoffee_activities` database table (`object_type`, `object_id`, `event`, `status`, `title`, `description`, `context`, `created_by`, `created_at`)
- `ActivityLogger` static service with typed convenience wrappers: `logPayment()`, `logSubscription()`, `logSubmission()`, `logEmail()`
- `ActivityLogHooks` listener class registers on `buymecoffee_payment_status_updated` (priority 20) and `buymecoffee_after_supporters_data_insert`
- Inline log calls added to: `StripeSubscriptions` (5 subscription lifecycle events), `Stripe::updateStatus()` (webhook receipt), `AdminAjaxHandler::cancelSubscription()`, `AdminAjaxHandler::refundTransaction()`, `EmailNotifications` (donor + admin email sent), `SubmissionHandler` (payment initiated)
- `get_activities` AJAX endpoint supports querying by `object_type + object_id` or by `supporter_id` (OR query spanning submission, email, and payment records)
- **ActivityTimeline** Vue component — fluent-cart-style vertical timeline with absolute-positioned status icon (green/red/amber), dashed connector line, date, title, creator tag, and description; embedded in supporter profiles and subscription detail pages
- **Activity Log** admin page — paginated `el-table` with Date, Event, Type (color-coded pill), Status (color-coded badge), and By columns; filterable by event type

### Fixed
- Stripe IPN signature verification hardened; duplicate event processing prevented with `add_option` lock per event ID
- PayPal API response handling improved for edge-case IPN payloads
- Subscription confirmation mismatch returns a clear 403 error instead of silently activating the wrong subscription
- Various form and admin AJAX validation patches

---

## [1.1.0] — 2026-04-25

### Added
- Redesigned public donation page — two-column layout with banner image, about card, recent supporters list, and donation form
- Inline page editor for admins — edit cover photo, profile image, name, bio, and accent color directly on the public page
- Banner image upload with drag-and-drop support and WP media picker
- Share button on public page with native share sheet and clipboard fallback
- Recent supporters list on the public donation page
- Email notifications — donor confirmation and admin alert emails with fully customizable templates and dynamic placeholders
- Dark mode support across the entire admin panel (follows system preference, manually toggleable)
- Full-page admin SPA with collapsible sidebar navigation and CSS design token system
- Settings sub-navigation (General, Appearance, Shortcodes) integrated into the sidebar
- CoffeeLoader — animated loading overlay with viewport-centered positioning
- Banner image upload in admin Appearance settings

### Fixed
- Stripe checkout no longer hides form fields when the card element mounts
- Currency symbols now decoded from HTML entities before JSON output
- WordPress admin padding and focus ring no longer bleed into the plugin admin page
- Payment receipt page layout and design
- Supporter detail page back button hover style
- PHPCS translation comment and direct access warnings

---

## [1.0.6] — 2026-01-11

- Adds PayPal settings validation
- Fixes translation issue
- Enhanced security

---

## [1.0.5] — 2025-04-10

- Fixes chart height issue
- Fixes styling issues

---

## [1.0.4] — 2024-12-01

- Adds PayPal Standard on-site payment confirmation
- Adds new Supporters/Donor profile page
- Adds supporters table filter and search
- Fixes styling issues

---

## [1.0.3] — 2024-08-26

- Fixes styling issue
- Fixes customizer module issue
- Fixes checkout button issue

---

## [1.0.2] — 2024-03-10

- Adds real-time theme customizer

---

## [1.0.1] — 2024-03-07

- Adds PayPal Pro payment gateway

---

## [1.0.0] — 2024-03-03

- Initial release
