# How We Redesigned WordPress Donation Plugin Admin UI Like a Pro — Without Any Design Skills

*A developer's honest breakdown of tools, decisions, and code patterns that transformed a basic WordPress Buy Me Coffee plugin admin panel into a modern SPA dashboard — using Pencil, Claude Code, Vue 3, and a CSS token architecture.*

---

## Why I Built a Buy Me Coffee Plugin for WordPress (and Why the UI Sucked)

I'm a WordPress plugin developer. I built **Buy Me Coffee** — a free WordPress donation plugin that lets content creators, bloggers, and small businesses accept one-time donations and recurring subscriptions via Stripe and PayPal. Think of it as a self-hosted alternative to platforms like Buy Me a Coffee or Ko-fi, but running on your own WordPress site with zero platform fees.

The plugin had solid functionality from day one:
- **Stripe and PayPal payment gateways** with live/test mode switching
- **Donation button shortcodes** (`[buymecoffee_button]`, `[buymecoffee_form]`)
- **A Gutenberg block** for the WordPress block editor
- **Supporter management** with transaction history
- **Recurring subscription support** via Stripe
- **Email notifications** for donors and admins
- **A standalone donation page** that works outside your WordPress theme

But the admin dashboard? It was ugly. Default WordPress admin styles bleeding in. Inconsistent spacing. Random Tailwind classes thrown at elements without a system. No dark mode. The sidebar was a flat list of links that looked like it belonged in 2014.

It *worked*, but it didn't feel like a product. If you're a WordPress developer building donation plugins, membership plugins, or any admin-heavy plugin — you know this pain. The wp-admin environment fights your CSS at every turn.

Here's how I fixed it, what tools I used, why I picked each one, and why this approach works even if you can't tell the difference between `padding` and `margin` on first glance.

---

## The Architecture Decision: Why a Full-Page SPA Inside WordPress

Before touching any design, I made the most important technical call: **rip the entire admin UI out of wp-admin and make it a standalone Vue 3 SPA**.

### The Problem with wp-admin

If you've built WordPress plugin admin pages, you know:
- WordPress injects its own CSS (`wp-admin.css`, `common.css`) that overrides your styles
- The admin bar eats 32px of vertical space
- Focus rings, button resets, and form styles fight your components
- You're trapped in a `<div class="wrap">` container with WordPress padding

### My Solution

The Buy Me Coffee plugin registers a custom URL parameter (`?buymecoffee_admin`). When WordPress detects it, the plugin serves a clean HTML document — no admin bar, no WordPress menu, no inherited styles. The Vue 3 app mounts into a `#buy-me-coffee_app` div with hash-based routing.

```
yoursite.com/?buymecoffee_admin        → Dashboard
yoursite.com/?buymecoffee_admin#/supporters  → Supporter list
yoursite.com/?buymecoffee_admin#/gateway     → Payment settings
```

It also works inside `wp-admin/admin.php?page=buy-me-coffee.php` for users who prefer the traditional WordPress admin. We neutralize wp-admin's padding, focus outlines, and button styles with a targeted reset.

This architectural pattern is borrowed from plugins like FluentCRM, Jetstash, and other modern WordPress admin SPAs. If you're building a WordPress plugin with a complex admin UI — donation plugin, membership plugin, form builder, CRM — this is the pattern to follow.

---

## The Toolchain: What I Used and Why Each Tool Matters

Here's every tool in the stack, why I chose it over alternatives, and what it's actually doing.

### Pencil (via MCP) — The Secret Weapon for Non-Designers

**What it is:** Pencil is a design tool that you control through natural language via MCP (Model Context Protocol). It creates `.pen` design files with proper layout, components, typography, and spacing.

**Why it's better than Figma for developers:**

If you're a developer who doesn't know Figma, Sketch, or Adobe XD, you face a chicken-and-egg problem: you can't design because you don't know the tool, and you can't learn the tool because you don't have design intuition.

Pencil removes the tool barrier entirely. Instead of dragging rectangles and manually aligning elements, you describe what you want:

> "Design a dashboard with 4 metric cards in a row, a revenue line chart below, and a recent supporters table"

Pencil creates a proper design with:
- Correct flexbox layout hierarchy (frames inside frames)
- Consistent spacing using design variables
- Reusable components (the sidebar is a component used in every screen)
- Typography that follows a scale

**My workflow was:**
1. Describe a screen to Pencil
2. Screenshot the result
3. Iterate: "make the sidebar more compact", "add trend badges to the metric cards", "change the color scheme to purple"
4. Once approved, translate the design into Vue components

I designed **12 complete screens** this way: Dashboard, Supporters, Subscriptions, Activity Log, Settings (3 tabs), Payment Gateways, Stripe Settings, PayPal Settings, Notifications, and the Sidebar. Not one pixel was placed manually.

**Why this matters for WordPress plugin developers:** You can now design a professional-grade admin panel for your donation plugin, membership plugin, or any WordPress plugin — without ever opening Figma.

### Claude Code — AI That Writes Production Vue Components

**What it is:** Claude Code is Anthropic's CLI-based coding agent. It reads files, understands project structure, and writes code that fits your existing patterns.

**Why I used it with Pencil:** After designing each screen in Pencil, Claude Code translated the design into Vue 3 components. It read the design file, understood the layout structure, and generated components with:
- Correct BEM class names
- CSS custom properties referencing my design token system
- Proper Vue 3 template structure (Options API for data-heavy pages, Composition API for UI components)
- Element Plus component integration
- Lucide icons matching the design

The killer combination is **Pencil for design + Claude Code for implementation**. You design conversationally, then implement conversationally. No context switching between tools.

### Vue 3 — The Component Framework

**Why Vue over React:** The WordPress ecosystem has more Vue-based admin SPAs than React ones. FluentCRM, FluentForms, FluentCart, and many other plugins use Vue. The Options API is readable for PHP developers transitioning to JavaScript, and `<script setup>` with Composition API is clean for interactive components.

For the Buy Me Coffee plugin admin, I use both:
- **Options API** for data-heavy pages (Dashboard, Supporters, Subscriptions) where `data()`, `computed`, `methods`, and `mounted()` read cleanly
- **Composition API (`<script setup>`)** for UI components (Sidebar, Layout, MetricCard) where reactive refs and composables keep things tight

### Element Plus — The UI Component Library

**Why Element Plus:** It gives you production-ready tables, dialogs, form inputs, switches, tabs, and pagination out of the box. For a WordPress plugin admin that needs sortable supporter tables, payment settings forms, and notification configuration dialogs — it saves weeks of work.

**The catch:** Element Plus ships with its own design language. Making it match your custom design requires overriding ~50 CSS custom properties. I have a dedicated section in both `design-tokens.scss` (light mode) and `dark-mode.scss` (dark mode) that maps Element Plus variables to my token system:

```scss
--el-color-primary:        var(--color-primary-500);
--el-bg-color:             var(--bg-primary);
--el-text-color-primary:   var(--text-primary);
--el-border-color:         var(--border-primary);
```

### Vite — The Build System

**Why Vite over Webpack:** Speed. Vite's dev server starts in under 300ms. Hot module replacement is instant. The Buy Me Coffee plugin's `vite.config.js` defines multiple entry points (admin SPA, public donation button, Gutenberg block, Stripe checkout, PayPal checkout), and Vite handles all of them.

In production, Vite outputs hashed filenames with a `manifest.json`. The plugin's `Vite.php` class reads this manifest to enqueue the correct asset URLs.

### Lucide Vue Next — Icons

**Why Lucide over Heroicons or Font Awesome:** Tree-shakeable, consistent 24x24 grid, MIT licensed, and the Vue component API is clean: `<LayoutDashboard :size="16" />`. Every icon in the Buy Me Coffee admin is a Lucide component.

### Tailwind CSS + SCSS — The Styling Approach

**My hybrid approach:** Tailwind for quick layout utilities (`flex`, `gap-4`, `items-center`), SCSS for the design token system and component styles. Most components use BEM-named classes (`bmc-sidebar__item`, `bmc-gateway-card__badge`) that reference CSS custom properties.

Why not pure Tailwind? Because `bg-[var(--bg-sidebar)]` gets ugly fast, and dark mode with Tailwind's `dark:` prefix doesn't compose well with a runtime theme toggle. CSS custom properties are more powerful here.

---

## The Design Token System — The Foundation That Makes Everything Work

This is the single most impactful architectural decision. Every WordPress plugin developer building a custom admin UI should do this.

### Three Layers of Tokens

```scss
:root {
  // Layer 1: Primitives — raw values
  --color-primary-500: #8b5cf6;
  --color-neutral-900: #0f172a;

  // Layer 2: Semantic — purpose-based
  --bg-primary:     var(--color-neutral-0);    // white
  --text-primary:   var(--color-neutral-900);  // near-black
  --border-primary: var(--color-neutral-200);  // light gray

  // Layer 3: Component — scoped to UI areas
  --bg-sidebar:          var(--color-neutral-0);
  --sidebar-text:        var(--color-neutral-900);
  --sidebar-active-text: var(--color-primary-600);
}
```

### Why This Matters

1. **Dark mode is free.** Swap the semantic layer, and every component updates:
```scss
html.dark {
  --bg-primary:     var(--color-neutral-900);
  --text-primary:   var(--color-neutral-50);
  --bg-sidebar:     var(--color-neutral-950);
  --sidebar-text:   var(--color-neutral-50);
}
```

2. **Consistency is automatic.** Every card, button, and input uses the same border color because they all reference `--border-primary`. Change it once, everything updates.

3. **Element Plus integration.** Map your tokens to Element Plus variables, and the library matches your design instead of fighting it.

The Buy Me Coffee plugin's token system has 80+ variables organized into: colors (primary purple scale, neutrals, semantic colors, accents), typography (3 font stacks, 8 size steps), spacing (8px base grid), radius (sm/md/lg/xl/pill/full), shadows (5 levels), and motion (4 duration + 4 easing curves).

---

## Building Each Screen: Developer Notes

### Dashboard — The Donation Analytics Hub

The Buy Me Coffee plugin dashboard shows donation analytics at a glance:

- **Personalized greeting:** "Good morning, {name}" with today's date context
- **4 metric cards:** Total Revenue, Supporters Count, Active Subscriptions, Monthly Recurring Revenue — each with trend badges
- **Revenue chart:** Weekly donation revenue using Chart.js with a purple gradient fill
- **Payment method breakdown:** Donut chart showing Stripe vs. PayPal split
- **Recent supporters table:** Latest donations with colorful avatar placeholders generated from name hashes
- **Active subscriptions sidebar:** List of recurring supporters with next renewal dates

The avatar color system deserves a note — instead of generic gray circles, each supporter gets a color from a 6-color palette based on a hash of their name. It's a tiny detail that makes the supporter list feel alive.

### Supporters — Donation Management

The supporter management page handles the core CRUD for the WordPress donation plugin:

- **Status filter pills:** All, Paid, Pending, Failed, Refunded — with counts
- **Search:** Real-time filtering by name or email
- **Table columns:** Supporter name/email, amount, status badge, payment method, mode (live/test), date, actions
- **Pagination:** Custom pagination with visible page numbers

### Payment Gateways — Stripe and PayPal Configuration

The Buy Me Coffee plugin supports two payment gateways:

- **Gateway overview page:** 3 stat cards (connected count, total transactions, total processed) + gateway cards with status, API mode indicator, and configuration details
- **Stripe settings:** API key management (publishable, secret, webhook signing secret), live/test mode toggle, webhook URL with copyable code block, required webhook events list
- **PayPal settings:** Standard (email) and Pro (API keys) modes, IPN URL, sandbox toggle

### Settings — Donation Form Configuration

Three tabs cover the full WordPress donation plugin configuration:

- **General:** Form title, donor name/email/message collection toggles, default donation amount, currency selection (30+ currencies), recurring subscription toggle with interval options
- **Appearance:** Button style, colors, corner radius, shadow — with a live preview panel
- **Shortcodes:** Copy-paste code blocks for `[buymecoffee_button]`, `[buymecoffee_form]`, `[buymecoffee_basic]`, and the standalone donation page URL

### Notifications — Email and Webhook Management

- **Email notifications:** Donation confirmation (to donor), admin notification, subscription renewal, subscription cancelled — each with enable toggle and edit dialog (subject, body, shortcode insertion, test email)
- **Webhook configuration:** Coming soon card with production/staging endpoint management UI

---

## The Sidebar: Compact Navigation That Adapts to Light and Dark

The sidebar deserved its own section because it's a reusable component that appears on every screen.

### Technical Details

- **Width:** 210px expanded, 52px collapsed (icons only)
- **Height:** 100vh, sticky positioning
- **Logo:** 26x26px gradient square (purple-to-pink) with a coffee icon
- **Nav items:** 36px height, 13.5px font, 16px icons, 2px gap between items
- **Section labels:** 10.5px uppercase with letter-spacing
- **Utility bar:** 28x28px buttons for dark mode toggle, docs link, expand button
- **Collapse:** Persisted to `localStorage`

### The Light/Dark Mode Pattern

The sidebar uses dedicated `--sidebar-*` tokens instead of the generic `--text-primary`/`--bg-primary` tokens. This is deliberate — the sidebar's visual treatment differs from the content area in both modes:

```css
/* Light mode: white sidebar, dark text */
--bg-sidebar:          var(--color-neutral-0);
--sidebar-text-muted:  var(--color-neutral-500);

/* Dark mode: near-black sidebar, light text */
--bg-sidebar:          var(--color-neutral-950);
--sidebar-text-muted:  var(--color-neutral-400);
```

This was a bug I caught during development — the sidebar was always dark (hardcoded `#0F172A` background) even in light mode. The fix was extracting all sidebar colors into theme-aware tokens.

---

## Lessons for WordPress Plugin Developers

### 1. Break out of wp-admin early

If your WordPress plugin has more than 3 admin screens, build a full-page SPA. The CSS reset alone saves you hours. The Buy Me Coffee plugin works both inside wp-admin and as a standalone page — give your users the choice.

### 2. Design tokens before components

Don't style a donation button and then try to make your settings page match. Define your color scale, spacing grid, and typography stack first. Then every component references the same system.

### 3. Use AI design tools if you're not a designer

Pencil + Claude Code is the most productive design workflow I've found as a developer. Pencil is better than Figma for developers because you don't need to learn a design tool — you just describe what you want. The output is a proper design file with components, variables, and layout structure that translates directly to code.

If you're building a WordPress donation plugin, a membership plugin, a WooCommerce addon, or any plugin with a complex admin panel — this approach saves weeks of design iteration.

### 4. Invest in dark mode from day one

With a token system, dark mode costs almost nothing. The Buy Me Coffee plugin's entire `dark-mode.scss` is just variable reassignments plus Element Plus overrides. If you add dark mode as an afterthought, you'll spend days hunting hardcoded colors. If you build with tokens from the start, it's a few hours of work.

### 5. Consistency compounds

The Buy Me Coffee admin uses exactly one card pattern (16px radius, subtle border, barely-there shadow) across every screen. One active pill style. One table layout. One button pattern. The visual consistency makes it feel designed by a professional, even though it was built by a developer using AI tools.

### 6. Small details make the difference

- Colorful avatar placeholders instead of gray circles
- Gradient logo instead of a flat icon
- Trend badges on metric cards showing percentage change
- Live/test mode dot indicators on payment gateway cards
- Personalized greeting on the dashboard

None of these alone is impressive. Together, they make your WordPress plugin admin feel like a SaaS product.

---

## Before and After: The Numbers

| Metric | Before | After |
|---|---|---|
| Sidebar width | 220px, light only | 210px, light/dark, collapsible to 52px |
| Card border-radius | Mixed (8px, 12px, 16px) | 16px everywhere |
| Font families | 1 (system default) | 3 (Plus Jakarta Sans, Inter, JetBrains Mono) |
| CSS custom properties | ~10 ad-hoc | 80+ organized in scales |
| Dark mode | None | Full coverage with runtime toggle |
| Screens designed | 0 mockups | 12 complete screen designs |
| CSS architecture | Scattered Tailwind utilities | BEM + 3-layer token system |
| Design tool used | None (coded by feel) | Pencil (AI-assisted design) |

---

## The Full Tech Stack

| Tool | Role | Why This One |
|---|---|---|
| **Pencil (MCP)** | AI design tool | No Figma skills needed — describe, iterate, implement |
| **Claude Code** | AI coding agent | Translates designs to production Vue components |
| **Vue 3** | Component framework | WordPress ecosystem standard, readable for PHP developers |
| **Vue Router** | SPA routing | Hash-based routing works inside WordPress |
| **Element Plus** | UI component library | Production tables, dialogs, forms out of the box |
| **Lucide Vue Next** | Icons | Tree-shakeable, consistent, clean API |
| **Tailwind CSS** | Utility styles | Quick layout, but secondary to the token system |
| **SCSS** | Token system + overrides | Powers dark mode, Element Plus theming, component styles |
| **Vite** | Build system | Sub-second HMR, multi-entry builds, manifest output |
| **Chart.js** | Data visualization | Revenue charts and payment method donut |

---

## Wrapping Up

You don't need to be a designer to build a beautiful WordPress plugin admin panel. You need:

1. **The right tools** — Pencil for design, Claude Code for implementation, Vue 3 + Element Plus for the frontend
2. **A token system** — so every color, spacing, and radius decision is deliberate and reusable
3. **Consistency** — one card pattern, one button pattern, one table layout, everywhere
4. **Dark mode from day one** — because the token system makes it nearly free

The Buy Me Coffee WordPress donation plugin admin went from "it works" to "it looks like a SaaS product" — and I'm still the same developer who can't draw a straight line. The difference isn't design talent. It's the system and the tools.

If you're building a WordPress plugin — whether it's a donation plugin like Buy Me Coffee, a membership plugin, a payment gateway integration, a form builder, or a CRM — this approach works. Design with AI, build with tokens, ship with confidence.

---

*The Buy Me Coffee plugin is a free WordPress donation plugin for accepting one-time and recurring payments via Stripe and PayPal. Built with Vue 3, Element Plus, and Vite. Designed with Pencil and Claude Code.*

*Keywords: WordPress donation plugin, buy me coffee plugin, accept donations WordPress, Stripe WordPress plugin, PayPal donations WordPress, WordPress admin UI design, Vue 3 WordPress plugin, WordPress plugin dark mode, donation button WordPress, recurring donations WordPress, WordPress payment plugin, buy me a coffee alternative WordPress*
