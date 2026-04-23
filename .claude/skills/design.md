---
name: design
description: Design system rules and component patterns for the Buy Me Coffee admin UI. Use when creating or modifying any Vue component, SCSS, or Tailwind code.
---

# Buy Me Coffee — Design System

Apply these rules whenever creating or modifying admin UI components.

## Colors — Use CSS Variables Only

```
Primary:    var(--color-primary-50) through var(--color-primary-900)  — teal brand
Neutral:    var(--color-neutral-50) through var(--color-neutral-950)  — slate grays
Success:    var(--color-success-500) #22c55e
Warning:    var(--color-warning-500) #f59e0b
Error:      var(--color-error-500) #ef4444
Info:       var(--color-info-500) #3b82f6
```

Semantic tokens (auto-switch in dark mode):
```
Backgrounds:  var(--bg-primary), var(--bg-secondary), var(--bg-hover)
Text:         var(--text-primary), var(--text-secondary), var(--text-tertiary)
Borders:      var(--border-primary), var(--border-secondary)
```

**NEVER** hardcode hex colors like `#f6fffc`, `#ebfffea3`, `#0cc7c0`. Always use CSS vars or Tailwind color classes (`text-primary-500`, `bg-neutral-50`).

## Typography

- Font: `font-sans` (Inter) for all UI text, `font-mono` for code/amounts
- Sizes: `text-xs` 12px, `text-sm` 13px, `text-base` 14px, `text-lg` 16px, `text-xl` 18px, `text-2xl` 24px
- **NEVER** use `font-family: cursive` or `font-family: monospace` inline

## Icons — Lucide Only

```vue
import { Heart, Settings, CreditCard } from 'lucide-vue-next';
<Heart :size="20" />
```

**NEVER** use Element Plus icons (`<el-icon><Coffee/></el-icon>`) or PNG image icons.

## Layout & Spacing — Tailwind Classes

Use Tailwind utility classes for layout. No inline `style=""` for spacing/layout.

```html
<div class="flex items-center gap-4 p-6">         ✅
<div style="display:flex; padding:24px; gap:16px"> ❌
```

## Card Pattern

Every content section uses this card pattern:

```html
<div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6">
  <h2 class="text-lg font-semibold text-neutral-900 mb-4">Title</h2>
  <!-- content -->
</div>
```

Dark mode: cards auto-adapt via `var(--bg-primary)` and `var(--border-primary)`.

## Status Badges

Always use the `StatusBadge.vue` component:

```vue
import StatusBadge from './UI/StatusBadge.vue';
<StatusBadge status="paid" />
<StatusBadge status="pending" />
```

Valid statuses: `paid`, `pending`, `failed`, `refunded`, `cancelled`

## Page Titles

Always use the `PageTitle.vue` component:

```vue
import PageTitle from './UI/PageTitle.vue';
<PageTitle title="Supporters" subtitle="Manage your donors" />
```

## Code Blocks / Copy

Use `CodeBlock.vue` for shortcodes, URLs, API keys:

```vue
import CodeBlock from './UI/CodeBlock.vue';
<CodeBlock label="Shortcode" code='[buymecoffee_button]' />
```

For programmatic copy, use the `useClipboard` composable:
```js
import { useClipboard } from '../../composables/useClipboard';
const { copy } = useClipboard();
copy(text);
```

**NEVER** use ClipboardJS or jQuery for copy.

## Metric Cards

Use `MetricCard.vue` for dashboard stats:

```vue
import MetricCard from './UI/MetricCard.vue';
<MetricCard label="Revenue" value="$1,234" icon="DollarSign" color="primary" />
```

## Empty States

Use `EmptyState.vue` when data is empty:

```vue
import EmptyState from './UI/EmptyState.vue';
<EmptyState title="No supporters yet" description="Share your page to get started" />
```

## Tables

Use Element Plus `el-table` but with consistent styling:
- Amounts: `class="font-mono"` 
- Status column: use `StatusBadge`
- Date column: relative time ("2h ago") with full date in tooltip
- Actions: icon buttons with tooltips, not text buttons

## Forms

- Use `el-form` with `label-position="top"`
- Toggle settings: use `el-switch` in a horizontal row with label + description
- Inputs: use `el-input` with proper `placeholder`
- Save buttons: primary teal, bottom of card

## Loading States

Use `v-loading` directive on the content container. For initial load, show content skeleton.

## AJAX Calls

Existing pattern (keep using until full migration):
```js
this.$post({
  action: 'buymecoffee_admin_ajax',
  route: 'route_name',
  data: { ... },
  buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
})
```

## File Locations

- Design tokens: `src/scss/admin/design-tokens.scss`
- Dark mode: `src/scss/admin/dark-mode.scss`
- Tailwind config: `tailwind.config.js`
- UI components: `src/js/Components/UI/`
- Composables: `src/js/composables/`
- Page components: `src/js/Components/`
