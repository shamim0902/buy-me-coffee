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
          <p class="wn-subtitle">Here's what we shipped in this release of Buy Me a Coffee.</p>
        </div>

        <!-- Features -->
        <div class="wn-features">

          <div class="wn-feature">
            <div class="wn-feature__icon wn-feature__icon--green">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2v4"/><path d="m4.93 4.93 2.83 2.83"/><path d="M2 12h4"/><path d="m4.93 19.07 2.83-2.83"/><path d="M12 18v4"/><path d="m19.07 19.07-2.83-2.83"/><path d="M18 12h4"/><path d="m19.07 4.93-2.83 2.83"/>
                <circle cx="12" cy="12" r="4"/>
              </svg>
            </div>
            <div class="wn-feature__body">
              <h3 class="wn-feature__title">Stripe Recurring Subscriptions</h3>
              <p class="wn-feature__desc">Supporters can now back you with monthly or yearly subscriptions. Full lifecycle management — creation, renewals, and cancellations — handled automatically via Stripe webhooks.</p>
            </div>
          </div>

          <div class="wn-feature">
            <div class="wn-feature__icon wn-feature__icon--blue">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>
                <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>
              </svg>
            </div>
            <div class="wn-feature__body">
              <h3 class="wn-feature__title">Instant Refunds</h3>
              <p class="wn-feature__desc">Issue full refunds for Stripe and PayPal payments directly from the supporter profile — no need to log into your payment dashboard.</p>
            </div>
          </div>

          <div class="wn-feature">
            <div class="wn-feature__icon wn-feature__icon--purple">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
              </svg>
            </div>
            <div class="wn-feature__body">
              <h3 class="wn-feature__title">Activity Log</h3>
              <p class="wn-feature__desc">Every payment, subscription change, refund, email, and webhook event is now recorded. Browse the full history on each supporter profile or from the new Activity Log page.</p>
            </div>
          </div>

        </div>

        <!-- Footer CTA -->
        <div class="wn-footer">
          <button class="wn-btn-primary" @click="dismiss">Got it — let's go!</button>
          <a href="https://wpminers.com/whats-new-in-buy-me-a-coffee-1-2-0/" target="_blank" rel="noopener" class="wn-more-link">See full release notes →</a>
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
  if (!window.BuyMeCoffeeAdmin?.show_whats_new) return;
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

/* ── Feature list ─────────────────────────────────────────── */
.wn-features {
  padding: 4px 28px 20px;
  display: flex;
  flex-direction: column;
  gap: 0;
}

.wn-feature {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  padding: 16px 0;
  border-bottom: 1px solid #f3f4f6;
}
.wn-feature:last-child {
  border-bottom: none;
}

.wn-feature__icon {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.wn-feature__icon--green  { background: #f0fdf4; color: #16a34a; }
.wn-feature__icon--blue   { background: #eff6ff; color: #2563eb; }
.wn-feature__icon--purple { background: #faf5ff; color: #7c3aed; }

.wn-feature__body {
  flex: 1;
  min-width: 0;
}

.wn-feature__title {
  font-size: 14.5px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 4px;
  line-height: 1.3;
}

.wn-feature__desc {
  font-size: 13px;
  color: #6b7280;
  margin: 0;
  line-height: 1.55;
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
