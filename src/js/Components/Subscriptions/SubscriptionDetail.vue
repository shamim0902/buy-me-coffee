<template>
  <div class="bmc-subscription-page relative min-h-[200px]">
    <CoffeeLoader :loading="loading" />

    <!-- Back Button -->
    <button class="bmc-back-btn" @click="$router.push({ name: 'Subscriptions' })">
      <ArrowLeft :size="16" />
      Back to Subscriptions
    </button>

    <template v-if="!loading && subscription.id">
      <div class="bmc-subscription-layout">
        <aside class="bmc-subscription-sidebar">
          <div class="bmc-subscription-profile-card">
            <img
              v-if="subscription.supporters_image"
              :src="subscription.supporters_image"
              :alt="subscription.supporters_name"
              class="bmc-subscription-avatar"
            />
            <div
              v-else
              class="bmc-subscription-avatar bmc-subscription-avatar--placeholder"
            >
              <span>{{ getInitials(subscription.supporters_name) }}</span>
            </div>

            <h2 class="bmc-subscription-name">
              {{ subscription.supporters_name || 'Anonymous' }}
            </h2>
            <p v-if="subscription.supporters_email" class="bmc-subscription-email">
              {{ subscription.supporters_email }}
            </p>

            <div class="bmc-subscription-badges">
              <span class="bmc-status-badge" :class="'bmc-status-badge--' + subscription.status">
                {{ statusLabel(subscription.status) }}
              </span>
              <span class="bmc-subscription-pill">
                {{ subscription.interval_type === 'year' ? 'Yearly' : 'Monthly' }}
              </span>
            </div>

            <p class="bmc-subscription-created">Since {{ formatDate(subscription.created_at) }}</p>

            <div class="bmc-subscription-actions">
              <a
                v-if="subscription.supporters_email"
                :href="'mailto:' + subscription.supporters_email"
                class="bmc-subscription-action"
              >
                <Mail :size="14" />
                Send Email
              </a>
              <a
                v-if="subscription.stripe_subscription_id"
                :href="stripeSubUrl()"
                target="_blank"
                rel="noopener"
                class="bmc-subscription-action"
              >
                <ExternalLink :size="14" />
                View on Stripe
              </a>
            </div>
          </div>

          <div class="bmc-subscription-sidebar-card">
            <h3 class="bmc-sidebar-section-title">Subscription Summary</h3>
            <div class="bmc-subscription-stat-list">
              <div class="bmc-subscription-stat">
                <span class="bmc-subscription-stat__label">Amount</span>
                <span class="bmc-subscription-stat__value">{{ formatAmount(subscription.amount, subscription.currency) }}</span>
              </div>
              <div class="bmc-subscription-stat">
                <span class="bmc-subscription-stat__label">Next Renewal</span>
                <span class="bmc-subscription-stat__value">{{ formatDate(subscription.current_period_end) }}</span>
              </div>
              <div class="bmc-subscription-stat">
                <span class="bmc-subscription-stat__label">Payment Mode</span>
                <span class="bmc-subscription-stat__value bmc-text-capitalize">{{ subscription.payment_mode || '--' }}</span>
              </div>
            </div>
          </div>
        </aside>

        <section class="bmc-subscription-main">
          <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6 mb-6">
            <div class="bmc-card-header-actions">
              <h3 class="text-sm font-semibold uppercase tracking-wide mt-0 mb-0" style="color: var(--text-secondary)">
                Subscription Details
              </h3>

              <div v-if="subscription.stripe_subscription_id" class="bmc-more-menu" ref="moreMenu">
                <button class="bmc-card-more-btn" title="Subscription actions" @click.stop="moreOpen = !moreOpen">
                  <MoreVertical :size="16" />
                </button>
                <div v-if="moreOpen" class="bmc-more-dropdown">
                  <button class="bmc-more-item" :disabled="fetching" @click="confirmFetchSubscription">
                    <RefreshCw :size="14" :class="{ 'bmc-spin': fetching }" />
                    {{ fetching ? 'Fetching...' : 'Fetch Subscription' }}
                  </button>
                  <el-popconfirm
                    v-if="subscription.status !== 'cancelled'"
                    title="Cancel this subscription? This cannot be undone."
                    confirm-button-text="Yes, cancel"
                    cancel-button-text="No"
                    :width="260"
                    @confirm="cancelSubscription"
                  >
                    <template #reference>
                      <button class="bmc-more-item bmc-more-item--danger" :disabled="cancelling">
                        <XCircle :size="14" />
                        {{ cancelling ? 'Cancelling...' : 'Cancel Subscription' }}
                      </button>
                    </template>
                  </el-popconfirm>
                </div>
              </div>
            </div>

            <div class="bmc-detail-grid">
              <div class="bmc-detail-item">
                <span class="bmc-detail-label">Amount</span>
                <span class="bmc-detail-value">{{ formatAmount(subscription.amount, subscription.currency) }}</span>
              </div>
              <div class="bmc-detail-item">
                <span class="bmc-detail-label">Interval</span>
                <span class="bmc-detail-value">{{ subscription.interval_type === 'year' ? 'Yearly' : 'Monthly' }}</span>
              </div>
              <div class="bmc-detail-item">
                <span class="bmc-detail-label">Status</span>
                <span class="bmc-status-badge" :class="'bmc-status-badge--' + subscription.status">
                  {{ statusLabel(subscription.status) }}
                </span>
              </div>
              <div class="bmc-detail-item">
                <span class="bmc-detail-label">Next Renewal</span>
                <span class="bmc-detail-value">{{ formatDate(subscription.current_period_end) }}</span>
              </div>
              <div class="bmc-detail-item">
                <span class="bmc-detail-label">Payment Mode</span>
                <span class="bmc-detail-value bmc-text-capitalize">{{ subscription.payment_mode || '--' }}</span>
              </div>
              <div class="bmc-detail-item" v-if="subscription.stripe_subscription_id">
                <span class="bmc-detail-label">Subscription ID</span>
                <a
                  :href="stripeSubUrl()"
                  target="_blank"
                  rel="noopener"
                  class="bmc-charge-link bmc-subscription-id-link"
                >
                  {{ subscription.stripe_subscription_id }}
                  <ExternalLink :size="12" />
                </a>
              </div>
              <div class="bmc-detail-item" v-if="subscription.cancelled_at">
                <span class="bmc-detail-label">Cancelled At</span>
                <span class="bmc-detail-value">{{ formatDate(subscription.cancelled_at) }}</span>
              </div>
            </div>
          </div>

      <!-- Payment History -->
      <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6">
        <h3 class="text-base font-semibold mb-4 m-0" style="color: var(--text-primary)">Payment History</h3>

        <el-table
          v-if="subscription.transactions && subscription.transactions.length"
          :data="subscription.transactions"
          style="width: 100%"
          :show-header="true"
        >
          <el-table-column label="Date" width="180">
            <template #default="{ row }">
              <span class="bmc-date">{{ formatDate(row.created_at) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="Amount" width="160">
            <template #default="{ row }">
              <span class="bmc-amount">{{ formatAmount(row.payment_total, row.currency) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="Status" width="130">
            <template #default="{ row }">
              <span class="bmc-status-badge" :class="'bmc-status-badge--' + row.status">
                {{ row.status }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="Transaction ID" min-width="220">
            <template #default="{ row }">
              <a
                v-if="row.charge_id"
                :href="stripeChargeUrl(row)"
                target="_blank"
                rel="noopener"
                class="bmc-charge-link"
              >
                {{ row.charge_id }}
                <ExternalLink :size="12" />
              </a>
              <span v-else style="color: var(--text-tertiary)">--</span>
            </template>
          </el-table-column>
        </el-table>

        <EmptyState
          v-else
          title="No payments yet"
          description="Renewal payments will appear here as they are processed."
          icon="CreditCard"
        />
      </div>

      <!-- Activity Log -->
      <div class="mt-6 bg-white rounded-xl border border-neutral-200 shadow-xs overflow-hidden">
        <ActivityTimeline
          object-type="subscription"
          :object-id="Number(subscription.id)"
        />
      </div>
        </section>
      </div>
    </template>

    <!-- Fetch Subscription Dialog -->
    <el-dialog
      v-model="fetchDialogVisible"
      title="Fetch Subscription"
      width="460"
      :close-on-click-modal="!fetching"
      :close-on-press-escape="!fetching"
      :show-close="!fetching"
      append-to-body
    >
      <div class="flex items-start gap-3">
        <div class="flex-shrink-0 mt-0.5" style="color: var(--color-primary-600)">
          <RefreshCw :size="20" />
        </div>
        <p class="m-0 text-sm" style="color: var(--text-secondary); line-height: 1.6">
          This will fetch the subscription from Stripe and sync all transactions.
          Any missing renewal payments will be created locally.
        </p>
      </div>
      <template #footer>
        <el-button :disabled="fetching" @click="fetchDialogVisible = false">Cancel</el-button>
        <el-button type="primary" :loading="fetching" @click="fetchSubscription">
          {{ fetching ? 'Fetching...' : 'Fetch Now' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import { ArrowLeft, Mail, XCircle, ExternalLink, MoreVertical, RefreshCw } from 'lucide-vue-next';
import CoffeeLoader from '../UI/CoffeeLoader.vue';
import EmptyState from '../UI/EmptyState.vue';
import ActivityTimeline from '../ActivityTimeline.vue';

export default {
  name: 'SubscriptionDetail',
  components: { ArrowLeft, Mail, XCircle, ExternalLink, MoreVertical, RefreshCw, CoffeeLoader, EmptyState, ActivityTimeline },
  data() {
    return {
      loading: true,
      cancelling: false,
      fetching: false,
      fetchDialogVisible: false,
      moreOpen: false,
      subscription: {},
    };
  },
  methods: {
    getSubscription() {
      this.loading = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_subscription',
        data: { id: this.$route.params.id },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        this.subscription = response?.data || {};
      }).fail((error) => {
        this.$handleError(error);
      }).always(() => {
        this.loading = false;
      });
    },
    cancelSubscription() {
      this.moreOpen = false;
      this.cancelling = true;
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'cancel_subscription',
        data: { id: this.subscription.id },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then(() => {
        this.subscription.status = 'cancelled';
        this.subscription.cancelled_at = new Date().toISOString();
        this.$handleSuccess('Subscription cancelled');
      }).fail((error) => {
        this.$handleError(error);
      }).always(() => {
        this.cancelling = false;
      });
    },
    confirmFetchSubscription() {
      this.moreOpen = false;
      this.fetchDialogVisible = true;
    },
    fetchSubscription() {
      this.fetching = true;
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'fetch_subscription',
        data: { id: this.subscription.id },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then(() => {
        this.fetchDialogVisible = false;
        this.$handleSuccess('Subscription fetched successfully from Stripe');
        this.getSubscription();
      }).fail((error) => {
        this.$handleError(error);
      }).always(() => {
        this.fetching = false;
      });
    },
    formatAmount(cents, currency) {
      return this.$formatAmount(cents, currency, { empty: '--' });
    },
    formatDate(dateStr) {
      if (!dateStr || dateStr === '0000-00-00 00:00:00') return '--';
      return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    },
    statusLabel(status) {
      const labels = { active: 'Active', cancelled: 'Cancelled', incomplete: 'Incomplete', past_due: 'Past Due' };
      return labels[status] || status;
    },
    getInitials(name) {
      if (!name) return '?';
      return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
    },
    stripeChargeUrl(row) {
      const mode = row.payment_mode === 'live' ? '' : 'test/';
      return 'https://dashboard.stripe.com/' + mode + 'charges/' + row.charge_id;
    },
    stripeSubUrl() {
      const mode = this.subscription.payment_mode === 'live' ? '' : 'test/';
      return 'https://dashboard.stripe.com/' + mode + 'subscriptions/' + this.subscription.stripe_subscription_id;
    },
    onClickOutside(e) {
      if (this.$refs.moreMenu && !this.$refs.moreMenu.contains(e.target)) {
        this.moreOpen = false;
      }
    },
  },
  mounted() {
    this.getSubscription();
    document.addEventListener('click', this.onClickOutside);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.onClickOutside);
  },
};
</script>

<style scoped>
.bmc-subscription-page {
  max-width: 1440px;
}

.bmc-subscription-layout {
  display: grid;
  grid-template-columns: minmax(280px, 320px) minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}

.bmc-subscription-sidebar {
  display: flex;
  flex-direction: column;
  gap: 14px;
  position: sticky;
  top: 0;
}

.bmc-subscription-profile-card,
.bmc-subscription-sidebar-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-secondary);
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.bmc-subscription-profile-card {
  padding: 20px;
}

.bmc-subscription-sidebar-card {
  padding: 16px;
}

.bmc-subscription-avatar {
  width: 64px;
  height: 64px;
  border-radius: 999px;
  border: 2px solid var(--border-secondary);
  object-fit: cover;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.bmc-subscription-avatar--placeholder {
  background: var(--color-primary-50);
  color: var(--color-primary-600);
}

.bmc-subscription-avatar--placeholder span {
  font-size: 20px;
  font-weight: 800;
  text-transform: uppercase;
}

.bmc-subscription-name {
  margin: 14px 0 0;
  color: var(--text-primary);
  font-size: 20px;
  font-weight: 700;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.bmc-subscription-email {
  margin: 4px 0 0;
  color: var(--text-secondary);
  font-size: 13px;
  overflow-wrap: anywhere;
}

.bmc-subscription-badges {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.bmc-subscription-pill {
  display: inline-flex;
  align-items: center;
  height: 22px;
  padding: 0 8px;
  border-radius: 999px;
  border: 1px solid var(--border-secondary);
  color: var(--text-secondary);
  font-size: 12px;
  font-weight: 500;
}

.bmc-subscription-created {
  margin: 10px 0 0;
  color: var(--text-tertiary);
  font-size: 12px;
}

.bmc-subscription-actions {
  display: grid;
  grid-template-columns: 1fr;
  gap: 8px;
  margin-top: 16px;
}

.bmc-subscription-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 36px;
  padding: 0 12px;
  border-radius: 8px;
  border: 1px solid var(--border-primary);
  background: var(--bg-primary);
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 600;
  line-height: 1.2;
  text-decoration: none;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.bmc-subscription-action:hover {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}

.bmc-sidebar-section-title {
  margin: 0 0 12px;
  color: var(--text-secondary);
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.bmc-subscription-stat-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.bmc-subscription-stat {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--border-secondary);
}

.bmc-subscription-stat:last-child {
  border-bottom: none;
}

.bmc-subscription-stat__label {
  color: var(--text-secondary);
  font-size: 13px;
}

.bmc-subscription-stat__value {
  color: var(--text-primary);
  font-size: 14px;
  font-weight: 700;
  text-align: right;
}

.bmc-subscription-main {
  min-width: 0;
}

.bmc-card-header-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.bmc-card-more-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--border-primary);
  background: var(--bg-primary);
  color: var(--text-secondary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.bmc-card-more-btn:hover {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}

.bmc-text-capitalize {
  text-transform: capitalize;
}

.bmc-back-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; margin-bottom: 16px;
  border: 1px solid var(--border-primary); border-radius: 8px;
  background: var(--bg-primary); color: var(--text-secondary);
  font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.15s ease;
}
.bmc-back-btn:hover { background: var(--bg-hover); color: var(--text-primary); }

.bmc-status-badge {
  display: inline-flex; align-items: center;
  padding: 2px 10px; border-radius: 9999px;
  font-size: 12px; font-weight: 500;
  max-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; justify-content: center;
  background: var(--color-neutral-100); color: var(--text-secondary);
}
.bmc-status-badge--active    { background: #dcfce7; color: #166534; }
.bmc-status-badge--cancelled { background: #fee2e2; color: #991b1b; }
.bmc-status-badge--incomplete{ background: #fef9c3; color: #854d0e; }
.bmc-status-badge--past_due  { background: #ffedd5; color: #9a3412; }
.bmc-status-badge--paid      { background: #dcfce7; color: #166534; }
.bmc-status-badge--pending   { background: #fef9c3; color: #854d0e; }

.bmc-detail-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
@media (max-width: 768px) {
  .bmc-detail-grid { grid-template-columns: repeat(2, 1fr); }
}
.bmc-detail-item { display: flex; flex-direction: column; gap: 4px; }
.bmc-detail-label { font-size: 12px; font-weight: 500; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.04em; }
.bmc-detail-value { font-size: 14px; font-weight: 500; color: var(--text-primary); }

.bmc-amount { font-size: 14px; font-weight: 600; color: var(--text-primary); }
.bmc-date { font-size: 13px; color: var(--text-secondary); }

.bmc-charge-link {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 12px; color: var(--color-primary-600);
  text-decoration: none; font-family: monospace;
}
.bmc-charge-link:hover { text-decoration: underline; }
.bmc-subscription-id-link {
  max-width: 100%;
  overflow-wrap: anywhere;
  line-height: 1.4;
}

.bmc-action-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; font-size: 13px; font-weight: 500;
  border: 1px solid var(--border-primary); border-radius: 8px;
  background: var(--bg-primary); color: var(--text-primary);
  cursor: pointer; transition: all 0.15s ease; white-space: nowrap;
}
.bmc-action-btn:hover { background: var(--bg-tertiary); }
.bmc-action-btn--icon { padding: 6px 8px; color: var(--text-secondary); }

.bmc-more-menu { position: relative; }
.bmc-more-dropdown {
  position: absolute; right: 0; top: calc(100% + 4px); z-index: 100;
  min-width: 180px; background: var(--bg-primary);
  border: 1px solid var(--border-primary); border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 4px;
}
.bmc-more-item {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 8px 10px; border-radius: 6px;
  font-size: 13px; font-weight: 500; border: none;
  background: transparent; cursor: pointer; transition: background 0.15s;
  color: var(--text-primary); text-align: left;
}
.bmc-more-item:hover { background: var(--bg-secondary); }
.bmc-more-item--danger { color: #dc2626; }
.bmc-more-item--danger:hover { background: #fee2e2; }

.bmc-spin { animation: bmc-spin 1s linear infinite; }
@keyframes bmc-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

@media (max-width: 1180px) {
  .bmc-subscription-layout {
    grid-template-columns: 1fr;
  }

  .bmc-subscription-sidebar {
    position: static;
  }

  .bmc-subscription-profile-card {
    display: grid;
    grid-template-columns: 64px minmax(0, 1fr);
    gap: 0 16px;
    align-items: start;
  }

  .bmc-subscription-name,
  .bmc-subscription-email,
  .bmc-subscription-badges,
  .bmc-subscription-created {
    grid-column: 2;
  }

  .bmc-subscription-actions {
    grid-column: 1 / -1;
  }
}

@media (max-width: 640px) {
  .bmc-subscription-profile-card {
    display: block;
  }

  .bmc-subscription-layout {
    gap: 16px;
  }

  .bmc-detail-grid {
    grid-template-columns: 1fr;
  }
}
</style>
