<template>
  <Teleport to="body">
    <Transition name="bmc-review-prompt">
      <aside
        v-if="visible"
        class="bmc-review-card"
        role="dialog"
        aria-modal="false"
        aria-labelledby="bmc-review-title"
      >
        <button class="bmc-review-card__close" type="button" aria-label="Close" @click="snoozePrompt">
          <X :size="16" />
        </button>

        <div class="bmc-review-card__eyebrow">
          <Heart :size="14" />
          <span>A small favor?</span>
        </div>

        <h3 id="bmc-review-title" class="bmc-review-card__title">
          {{ reviewTitle }}
        </h3>

        <div class="bmc-review-card__stat">
          <span class="bmc-review-card__stat-value">{{ statValue }}</span>
          <span class="bmc-review-card__stat-label">{{ statLabel }}</span>
        </div>

        <p class="bmc-review-card__text">
          {{ reviewMessage }}
        </p>

        <div class="bmc-review-card__actions">
          <button class="bmc-review-card__primary" type="button" @click="leaveReview">
            <Star :size="15" />
            <span>Leave a review</span>
          </button>
          <button class="bmc-review-card__secondary" type="button" @click="snoozePrompt">
            Maybe later
          </button>
        </div>
      </aside>
    </Transition>
  </Teleport>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Heart, Star, X } from 'lucide-vue-next';

const route = useRoute();
const visible = ref(false);
const paidCount = ref(0);
const supporterLabel = ref('donations');
const moodReason = ref('');
const reviewTitle = ref('You just turned support into something real.');
const reviewMessage = ref('If this plugin helped create that moment, a kind review would genuinely mean a lot to us.');
const statLabel = ref('Successful donations');
const statValue = ref('0');
const reviewUrl = ref(window.BuyMeCoffeeAdmin?.review_url || 'https://wordpress.org/support/plugin/buy-me-coffee/reviews/#new-post');
let showTimer = null;
let requestToken = 0;

function clearShowTimer() {
  if (showTimer) {
    window.clearTimeout(showTimer);
    showTimer = null;
  }
}

function hidePrompt() {
  clearShowTimer();
  visible.value = false;
}

function requestPrompt() {
  hidePrompt();
  requestToken += 1;
  const currentToken = requestToken;
  const routeName = route.name || '';

  jQuery.get(window.BuyMeCoffeeAdmin.ajaxurl, {
    action: 'buymecoffee_admin_ajax',
    route: 'get_review_prompt',
    data: {
      route_name: routeName,
    },
    buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
  }).then((response) => {
    if (currentToken !== requestToken || route.name !== routeName) {
      return;
    }

    const payload = response?.data || {};
    if (!payload.visible) {
      return;
    }

    paidCount.value = payload.paid_count || 0;
    supporterLabel.value = payload.supporter_label || 'donations';
    moodReason.value = payload.mood_reason || moodReason.value;
    reviewTitle.value = payload.review_title || reviewTitle.value;
    reviewMessage.value = payload.review_message || reviewMessage.value;
    statLabel.value = payload.stat_label || statLabel.value;
    statValue.value = String(payload.stat_value || paidCount.value || 0);
    reviewUrl.value = payload.review_url || reviewUrl.value;

    const delay = 900 + Math.floor(Math.random() * 1200);
    showTimer = window.setTimeout(() => {
      if (currentToken === requestToken && route.name === routeName) {
        visible.value = true;
      }
    }, delay);
  });
}

function saveAction(reviewAction) {
  return jQuery.post(window.BuyMeCoffeeAdmin.ajaxurl, {
    action: 'buymecoffee_admin_ajax',
    route: 'review_prompt_action',
    data: {
      review_action: reviewAction,
    },
    buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
  });
}

function leaveReview() {
  saveAction('reviewed').always(() => {
    window.open(reviewUrl.value, '_blank', 'noopener');
    hidePrompt();
  });
}

function snoozePrompt() {
  saveAction('snooze').always(() => {
    hidePrompt();
  });
}

watch(() => route.name, requestPrompt);

onMounted(() => {
  requestPrompt();
});
</script>

<style scoped>
.bmc-review-card {
  position: fixed;
  right: 24px;
  bottom: 24px;
  z-index: 99997;
  width: min(420px, calc(100vw - 32px));
  background:
    radial-gradient(circle at top right, rgba(250, 204, 21, 0.18), transparent 32%),
    linear-gradient(180deg, #ffffff 0%, #fffbeb 100%);
  border: 1px solid rgba(245, 158, 11, 0.18);
  border-radius: 18px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.16);
  padding: 22px 22px 18px;
}

.bmc-review-card__close {
  position: absolute;
  top: 14px;
  right: 14px;
  width: 30px;
  height: 30px;
  border: 0;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.8);
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.bmc-review-card__eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #b45309;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 12px;
}

.bmc-review-card__title {
  margin: 0 28px 10px 0;
  color: #111827;
  font-size: 22px;
  line-height: 1.25;
  font-weight: 700;
}

.bmc-review-card__text {
  margin: 0;
  color: #475569;
  font-size: 14px;
  line-height: 1.65;
}

.bmc-review-card__stat {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 12px;
  padding: 7px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(245, 158, 11, 0.16);
}

.bmc-review-card__stat-value {
  min-width: 24px;
  height: 24px;
  padding: 0 8px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #f59e0b;
  color: #fff;
  font-size: 12px;
  font-weight: 800;
}

.bmc-review-card__stat-label {
  color: #92400e;
  font-size: 12px;
  font-weight: 700;
}

.bmc-review-card__actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 18px;
}

.bmc-review-card__primary,
.bmc-review-card__secondary {
  height: 40px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}

.bmc-review-card__primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 0;
  padding: 0 16px;
  background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
  color: #fff;
  box-shadow: 0 10px 24px rgba(217, 119, 6, 0.24);
}

.bmc-review-card__secondary {
  border: 1px solid rgba(148, 163, 184, 0.28);
  padding: 0 14px;
  background: rgba(255, 255, 255, 0.82);
  color: #475569;
}

.bmc-review-card__primary:hover,
.bmc-review-card__secondary:hover,
.bmc-review-card__close:hover {
  transform: translateY(-1px);
}

.bmc-review-prompt-enter-active,
.bmc-review-prompt-leave-active {
  transition: opacity 160ms ease, transform 160ms ease;
}

.bmc-review-prompt-enter-from,
.bmc-review-prompt-leave-to {
  opacity: 0;
  transform: translateY(12px);
}

@media (max-width: 640px) {
  .bmc-review-card {
    right: 16px;
    bottom: 16px;
    left: 16px;
    width: auto;
    padding: 20px 18px 18px;
  }

  .bmc-review-card__title {
    font-size: 20px;
  }

  .bmc-review-card__actions {
    flex-direction: column;
    align-items: stretch;
  }

  .bmc-review-card__primary,
  .bmc-review-card__secondary {
    width: 100%;
  }
}
</style>
