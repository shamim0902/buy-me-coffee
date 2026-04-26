<template>
  <div class="relative min-h-[200px]">
    <CoffeeLoader :loading="loading" />
    <PageTitle title="Subscriptions" subtitle="Manage recurring donations" />

    <!-- Status Pills -->
    <div v-if="!loading" class="flex flex-wrap gap-2 mb-6">
      <button
        v-for="pill in statusPills"
        :key="pill.value"
        class="bmc-pill"
        :class="{ 'bmc-pill--active': filter_status === pill.value }"
        @click="filter_status = pill.value; current = 0; getSubscriptions()"
      >
        {{ pill.label }}
        <span class="bmc-pill__count">{{ pill.count }}</span>
      </button>
    </div>

    <!-- Table Card -->
    <div class="bmc-card">
      <!-- Filters Row -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="relative">
          <Search :size="16" class="bmc-search-icon" />
          <input
            v-model="search"
            type="text"
            placeholder="Search by name or email..."
            class="bmc-search-input"
            @keyup.enter="current = 0; getSubscriptions()"
          />
        </div>
      </div>

      <!-- Table -->
      <div v-loading="fetching">
        <el-table
          v-if="subscriptions.length"
          :data="subscriptions"
          style="width: 100%"
          :show-header="true"
          row-class-name="bmc-table-row bmc-table-row--clickable"
          @row-click="(row) => $router.push({ name: 'SubscriptionDetail', params: { id: row.id } })"
        >
          <el-table-column label="Supporter" min-width="200">
            <template #default="{ row }">
              <div class="flex items-center gap-3">
                <img
                  v-if="row.supporters_image"
                  :src="row.supporters_image"
                  :alt="row.supporters_name"
                  class="bmc-avatar"
                />
                <div v-else class="bmc-avatar-placeholder">
                  {{ getInitials(row.supporters_name) }}
                </div>
                <div class="min-w-0">
                  <p class="bmc-name">{{ row.supporters_name || 'Anonymous' }}</p>
                  <p class="bmc-email" v-if="row.supporters_email">{{ row.supporters_email }}</p>
                </div>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="Amount" width="140">
            <template #default="{ row }">
              <span class="bmc-amount">{{ formatAmount(row.amount, row.currency) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="Interval" width="110">
            <template #default="{ row }">
              <span class="bmc-interval">{{ row.interval_type === 'year' ? 'Yearly' : 'Monthly' }}</span>
            </template>
          </el-table-column>

          <el-table-column label="Status" width="130">
            <template #default="{ row }">
              <span class="bmc-status-badge" :class="'bmc-status-badge--' + row.status">
                {{ statusLabel(row.status) }}
              </span>
            </template>
          </el-table-column>

          <el-table-column label="Next Renewal" width="160">
            <template #default="{ row }">
              <span class="bmc-date">{{ formatDate(row.current_period_end) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="Started" width="140">
            <template #default="{ row }">
              <span class="bmc-date">{{ formatDate(row.created_at) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="" width="60" fixed="right">
            <template #default="{ row }">
              <a
                v-if="row.stripe_subscription_id"
                :href="stripeSubUrl(row)"
                target="_blank"
                rel="noopener"
                class="bmc-btn-action bmc-btn-link"
                title="View on Stripe"
                @click.stop
              >
                <ExternalLink :size="14" />
              </a>
            </template>
          </el-table-column>
        </el-table>

        <EmptyState
          v-else-if="!fetching"
          title="No subscriptions yet"
          description="Recurring subscriptions will appear here once supporters enable the recurring option at checkout."
          icon="RefreshCw"
        />
      </div>

      <!-- Pagination -->
      <div v-if="total > 0" class="bmc-pagination">
        <span class="bmc-pagination__info">
          Showing {{ paginationStart }}–{{ paginationEnd }} of {{ total }}
        </span>
        <div class="flex items-center gap-1">
          <button class="bmc-pagination__btn" :disabled="current === 0" @click="current--; getSubscriptions()">
            <ChevronLeft :size="16" />
          </button>
          <button
            v-for="page in visiblePages"
            :key="page"
            class="bmc-pagination__btn"
            :class="{ 'bmc-pagination__btn--active': page - 1 === current }"
            @click="current = page - 1; getSubscriptions()"
          >
            {{ page }}
          </button>
          <button class="bmc-pagination__btn" :disabled="current >= lastPage - 1" @click="current++; getSubscriptions()">
            <ChevronRight :size="16" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { Search, ChevronLeft, ChevronRight, ExternalLink } from 'lucide-vue-next';
import PageTitle from '../UI/PageTitle.vue';
import CoffeeLoader from '../UI/CoffeeLoader.vue';
import EmptyState from '../UI/EmptyState.vue';

export default {
  name: 'Subscriptions',
  components: { Search, ChevronLeft, ChevronRight, ExternalLink, PageTitle, CoffeeLoader, EmptyState },
  data() {
    return {
      current: 0,
      total: 0,
      posts_per_page: 10,
      search: '',
      filter_status: 'all',
      loading: false,
      fetching: false,
      subscriptions: [],
      stats: { active_count: 0, mrr: 0 },
    };
  },
  computed: {
    lastPage() { return Math.ceil(this.total / this.posts_per_page) || 1; },
    paginationStart() { return this.total === 0 ? 0 : this.current * this.posts_per_page + 1; },
    paginationEnd() {
      const end = (this.current + 1) * this.posts_per_page;
      return end > this.total ? this.total : end;
    },
    visiblePages() {
      const pages = [];
      const total = this.lastPage;
      const current = this.current + 1;
      let start = Math.max(1, current - 2);
      let end = Math.min(total, start + 4);
      if (end - start < 4) start = Math.max(1, end - 4);
      for (let i = start; i <= end; i++) pages.push(i);
      return pages;
    },
    statusPills() {
      return [
        { label: 'All',       value: 'all',       count: this.total },
        { label: 'Active',    value: 'active',    count: this.stats.active_count || 0 },
        { label: 'Cancelled', value: 'cancelled', count: 0 },
        { label: 'Past Due',  value: 'past_due',  count: 0 },
      ];
    },
  },
  methods: {
    getSubscriptions() {
      this.fetching = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_subscriptions',
        data: {
          search: this.search,
          filter_status: this.filter_status,
          page: this.current,
          posts_per_page: this.posts_per_page,
        },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        this.subscriptions = response?.data?.subscriptions || [];
        this.total         = response?.data?.total || 0;
      }).fail((error) => {
        this.$handleError(error);
      }).always(() => {
        this.fetching = false;
      });
    },
    getStats() {
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_subscription_stats',
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        if (response?.data) this.stats = response.data;
      });
    },
    formatAmount(cents, currency) {
      if (!cents) return '--';
      const symbol = currency ? currency.toUpperCase() : 'USD';
      return (cents / 100).toFixed(2) + ' ' + symbol;
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
    stripeSubUrl(row) {
      const mode = row.payment_mode === 'live' ? '' : 'test/';
      return 'https://dashboard.stripe.com/' + mode + 'subscriptions/' + row.stripe_subscription_id;
    },
  },
  mounted() {
    this.getSubscriptions();
    this.getStats();
  },
};
</script>

<style scoped>
.bmc-avatar { width: 36px; height: 36px; border-radius: 9999px; object-fit: cover; flex-shrink: 0; }
.bmc-avatar-placeholder {
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border-radius: 9999px;
  background: var(--color-primary-50); color: var(--color-primary-600);
  font-size: 13px; font-weight: 600; flex-shrink: 0;
}
.bmc-name { font-size: 14px; font-weight: 500; color: var(--text-primary); margin: 0; }
.bmc-email { font-size: 12px; color: var(--text-secondary); margin: 1px 0 0; }
.bmc-amount { font-size: 14px; font-weight: 600; color: var(--text-primary); }
.bmc-interval { font-size: 13px; color: var(--text-secondary); }
.bmc-date { font-size: 13px; color: var(--text-secondary); }

.bmc-status-badge {
  display: inline-flex; align-items: center;
  padding: 2px 10px; border-radius: 9999px;
  font-size: 12px; font-weight: 500;
  background: var(--color-neutral-100); color: var(--text-secondary);
}
.bmc-status-badge--active    { background: #dcfce7; color: #166534; }
.bmc-status-badge--cancelled { background: #fee2e2; color: #991b1b; }
.bmc-status-badge--incomplete{ background: #fef9c3; color: #854d0e; }
.bmc-status-badge--past_due  { background: #ffedd5; color: #9a3412; }

.bmc-btn-action {
  padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500;
  border: 1px solid var(--border-primary); background: var(--bg-primary);
  color: var(--text-primary); cursor: pointer; transition: all 0.15s ease;
}
.bmc-btn-action:hover { background: var(--bg-tertiary); }
.bmc-btn-action--danger { color: #dc2626; border-color: #fca5a5; }
.bmc-btn-action--danger:hover { background: #fee2e2; }
.bmc-btn-link { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; color: var(--text-secondary); }

/* Status pills */
.bmc-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: var(--radius-full);
  font-size: var(--text-sm); font-weight: 500; font-family: var(--font-sans);
  border: 1px solid var(--border-primary); background: var(--bg-primary);
  color: var(--text-secondary); cursor: pointer; transition: all 0.15s ease;
}
.bmc-pill:hover { background: var(--bg-tertiary); color: var(--text-primary); }
.bmc-pill--active {
  background: linear-gradient(180deg, var(--color-primary-500) 0%, var(--color-primary-600) 100%);
  color: #fff;
  border-color: var(--color-primary-500);
}
.bmc-pill--active:hover {
  opacity: 0.9;
  color: #fff;
  background: linear-gradient(180deg, var(--color-primary-500) 0%, var(--color-primary-600) 100%);
}
/* Card */
.bmc-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-secondary);
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.bmc-pill__count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 20px; padding: 0 5px;
  border-radius: 9999px; font-size: 11px; font-weight: 600;
  background: var(--color-neutral-100); color: var(--text-secondary);
}

/* Search */
.bmc-search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); pointer-events: none; }
.bmc-search-input {
  width: 280px; padding: 7px 12px 7px 32px;
  border: 1px solid var(--border-primary); border-radius: var(--radius-md);
  font-size: var(--text-base); color: var(--text-primary); background: var(--bg-primary); outline: none;
}
.bmc-search-input:focus { border-color: var(--border-focus); box-shadow: var(--shadow-ring); }

/* Pagination */
.bmc-pagination { display: flex; align-items: center; justify-content: space-between; padding-top: 16px; margin-top: 16px; border-top: 1px solid var(--border-secondary); }
.bmc-pagination__info { font-size: var(--text-sm); color: var(--text-secondary); }
.bmc-pagination__btn {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 32px; height: 32px; padding: 0 6px;
  border: 1px solid var(--border-primary); border-radius: var(--radius-md);
  background: var(--bg-primary); color: var(--text-secondary);
  font-size: var(--text-sm); cursor: pointer; transition: all 0.15s ease;
}
.bmc-pagination__btn:hover:not(:disabled) { background: var(--bg-tertiary); }
.bmc-pagination__btn:disabled { opacity: 0.4; cursor: not-allowed; }
.bmc-pagination__btn--active { background: var(--color-primary-500); color: var(--text-inverse); border-color: var(--color-primary-500); }

/* Clickable rows */
:deep(.bmc-table-row--clickable) { cursor: pointer; }
:deep(.bmc-table-row--clickable:hover td) { background: var(--bg-secondary) !important; }
</style>
