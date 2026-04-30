<template>
  <Teleport to="body">
    <Transition name="wn-backdrop">
      <div v-if="visible" class="wn-backdrop" @click.self="dismiss" />
    </Transition>

    <Transition name="wn-modal">
      <div v-if="visible" class="wn-modal" role="dialog" aria-modal="true" aria-labelledby="wn-title">

        <!-- Top accent bar -->
        <div class="wn-accent" />

        <!-- Close button -->
        <button class="wn-close" @click="dismiss" title="Close">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>

        <!-- Header -->
        <div class="wn-header">
          <div class="wn-version-badge">Version {{ version }}</div>
          <h2 id="wn-title" class="wn-title">What's New</h2>
          <p class="wn-subtitle">Here's what's new and improved.</p>
        </div>

        <!-- Changelog -->
        <div class="wn-changelog">
          <section class="wn-release">
            <div class="wn-release__head">
              <span class="wn-release__version">What's New in 1.2.x</span>
            </div>
            <ul class="wn-release__list">
              <li>Redesigned 5-step onboarding wizard with Stripe and PayPal setup.</li>
              <li>Stripe recurring subscriptions with full webhook lifecycle management.</li>
              <li>Supporters hub with metrics, top supporter rankings, and public wall shortcode.</li>
              <li>One-click refunds with real-time gateway response and cancel subscription option.</li>
              <li>Activity logging with per-supporter and per-subscription timelines.</li>
              <li>Email notifications with customizable templates and dynamic placeholders.</li>
              <li>Subscriber accounts with subscription dashboard and auto user creation.</li>
              <li>Dark mode, full-page admin SPA, and modern design system.</li>
              <li>Add many more improvements and fixes, visit the changelog for more details.</li>
            </ul>
          </section>
        </div>

        <!-- Footer CTA -->
        <div class="wn-footer">
          <button class="wn-btn-primary" @click="dismiss">Got it — let's go!</button>
          <a href="https://wpminers.com/whats-new-in-buy-me-a-coffee-1-2-0/" target="_blank" rel="noopener" class="wn-more-link">View full changelog →</a>
        </div>

      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const visible = ref(false);
const version = window.BuyMeCoffeeAdmin?.plugin_version || '';

onMounted(() => {
  const forceShow = new URLSearchParams(window.location.search).get('bmc_whats_new') === '1';
  const showFlag = window.BuyMeCoffeeAdmin?.show_whats_new;
  const shouldShow = forceShow || showFlag === true || showFlag === 1 || showFlag === '1';
  if (!shouldShow) return;
  // Slight delay so the dashboard finishes rendering first
  setTimeout(() => { visible.value = true; }, 1400);
});

function dismiss() {
  visible.value = false;
  // Fire-and-forget — mark as seen for this user
  jQuery.post(
    window.BuyMeCoffeeAdmin.ajaxurl,
    {
      action: 'buymecoffee_admin_ajax',
      route:  'dismiss_whats_new',
      buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
    }
  );
}
</script>

<style scoped>
/* ── Backdrop ─────────────────────────────────────────────── */
.wn-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  backdrop-filter: blur(2px);
  z-index: 99998;
}

/* ── Modal ────────────────────────────────────────────────── */
.wn-modal {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 99999;
  width: 520px;
  max-width: calc(100vw - 40px);
  background: #fff;
  border-radius: 20px;
  overflow: hidden;
  box-shadow:
    0 32px 64px -12px rgba(0,0,0,0.25),
    0 0 0 1px rgba(0,0,0,0.06);
}

/* Accent gradient bar at top */
.wn-accent {
  height: 5px;
  background: linear-gradient(90deg, #16a34a 0%, #0ea5e9 50%, #8b5cf6 100%);
}

/* ── Close button ─────────────────────────────────────────── */
.wn-close {
  position: absolute;
  top: 18px;
  right: 18px;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: none;
  background: #f3f4f6;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}
.wn-close:hover {
  background: #e5e7eb;
  color: #111827;
}

/* ── Header ───────────────────────────────────────────────── */
.wn-header {
  padding: 28px 32px 20px;
  text-align: center;
}

.wn-version-badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 12px;
  border-radius: 9999px;
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid #bbf7d0;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 12px;
  letter-spacing: 0.02em;
}

.wn-title {
  font-size: 26px;
  font-weight: 800;
  color: #111827;
  margin: 0 0 8px;
  letter-spacing: -0.02em;
  line-height: 1.2;
}

.wn-subtitle {
  font-size: 14px;
  color: #6b7280;
  margin: 0;
  line-height: 1.5;
}

/* ── Changelog list ───────────────────────────────────────── */
.wn-changelog {
  padding: 4px 28px 18px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.wn-release {
  border: 1px solid #eef2f7;
  border-radius: 12px;
  background: #f9fafb;
  padding: 12px 14px;
}

.wn-release__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.wn-release__version {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
}

.wn-release__date {
  font-size: 12px;
  color: #6b7280;
}

.wn-release__list {
  margin: 0;
  padding-left: 18px;
  display: grid;
  gap: 6px;
}

.wn-release__list li {
  font-size: 13px;
  color: #6b7280;
  line-height: 1.45;
}

/* ── Footer ───────────────────────────────────────────────── */
.wn-footer {
  padding: 16px 32px 28px;
  text-align: center;
}

.wn-btn-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 44px;
  padding: 0 36px;
  border-radius: 10px;
  border: none;
  background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
  color: #fff;
  font-size: 14.5px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s, transform 0.15s;
  box-shadow: 0 4px 14px -2px rgba(22, 163, 74, 0.45);
}
.wn-btn-primary:hover {
  opacity: 0.92;
  transform: translateY(-1px);
}
.wn-btn-primary:active {
  transform: translateY(0);
}

.wn-more-link {
  display: block;
  margin-top: 12px;
  font-size: 13px;
  color: #6b7280;
  text-decoration: none;
  outline: none;
  transition: color 0.15s;
}
.wn-more-link:hover {
  color: #16a34a;
}

/* ── Animations ───────────────────────────────────────────── */
.wn-backdrop-enter-active,
.wn-backdrop-leave-active {
  transition: opacity 0.25s ease;
}
.wn-backdrop-enter-from,
.wn-backdrop-leave-to {
  opacity: 0;
}

.wn-modal-enter-active {
  transition: opacity 0.3s ease, transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.wn-modal-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.wn-modal-enter-from {
  opacity: 0;
  transform: translate(-50%, calc(-50% + 24px)) scale(0.94);
}
.wn-modal-leave-to {
  opacity: 0;
  transform: translate(-50%, calc(-50% - 12px)) scale(0.96);
}
</style>
