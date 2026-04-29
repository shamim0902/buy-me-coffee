<template>
  <div class="bmc-dashboard relative min-h-[200px]">
    <CoffeeLoader :loading="fetching" />

    <!-- Header -->
    <div class="bmc-dash-header">
      <div class="bmc-dash-header__left">
        <h1 class="bmc-dash-header__greeting">Good morning, {{ userName }}</h1>
        <p class="bmc-dash-header__subtitle">Here's what's happening with your donations today</p>
      </div>
      <div class="bmc-dash-header__right">
        <el-dropdown trigger="click" @command="setDateRange" class="bmc-date-dropdown">
          <div class="bmc-dash-header__date">
            <Calendar :size="16" />
            <span>{{ currentRangeLabel }}</span>
            <ChevronDown :size="14" />
          </div>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item
                v-for="r in dateRanges"
                :key="r.value"
                :command="r.value"
                :class="{ 'bmc-range-active': dateRange === r.value }"
              >{{ r.label }}</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <button class="bmc-dash-header__export" :disabled="exporting" @click="exportData">
          <Download :size="15" />
          <span>{{ exporting ? 'Exporting…' : 'Export' }}</span>
        </button>
      </div>
    </div>

    <!-- Quick Setup Banner -->
    <div
      v-if="!supporters.length && !guidedTour && !fetching"
      class="bmc-setup-banner"
    >
      <svg class="bmc-setup-banner__deco" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/>
      </svg>
      <div class="flex items-center gap-3">
        <div class="bmc-setup-banner__icon">
          <Rocket :size="20" />
        </div>
        <div>
          <p class="bmc-setup-banner__title">Get started with Buy Me Coffee</p>
          <p class="bmc-setup-banner__desc">
            Start collecting donations in minutes.
            <a class="bmc-setup-banner__link" @click="$router.push('quick-setup')">
              Run the quick setup guide
            </a>
          </p>
        </div>
      </div>
      <button class="bmc-setup-banner__close" @click="setStore">
        <X :size="16" />
      </button>
    </div>

    <!-- Metric Cards (4-column) -->
    <div class="bmc-metrics-grid">
      <MetricCard
        label="Total Revenue"
        :value="primaryRevenue"
        icon="DollarSign"
        color="purple"
        :trend="revenueTrend"
      />
      <MetricCard
        label="Supporters"
        :value="String(reportData.total_supporters || 0)"
        icon="Users"
        color="teal"
        :trend="supportersTrend"
      />
      <MetricCard
        label="Active Subscriptions"
        :value="String(subscriptionStats.active_count || 0)"
        icon="RefreshCw"
        color="blue"
      />
      <MetricCard
        label="Monthly Recurring"
        :value="mrrFormatted"
        icon="TrendingUp"
        color="green"
      />
    </div>

    <!-- Middle Row: Chart + Payment Breakdown -->
    <div class="bmc-dash-row">
      <!-- Revenue Chart -->
      <div class="bmc-card bmc-card--flex" v-loading="chartLoading">
        <div class="bmc-card__header">
          <div>
            <h2 class="bmc-card__title">Revenue Overview</h2>
            <p class="bmc-card__subtitle" v-if="dummyChart">
              Sample data shown. This chart updates once you receive donations.
            </p>
          </div>
          <el-select
            v-if="currencyOptions.length > 1"
            v-model="selectedCurrency"
            size="small"
            class="bmc-currency-select"
            @change="onCurrencyChange"
          >
            <el-option
              v-for="opt in currencyOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </div>
        <div class="bmc-chart-container">
          <ChartRenderer
            v-if="renderChart"
            :chartProps="totalRevenue"
            :chartOptions="overviewOptions"
            height="260"
          />
        </div>
      </div>

      <!-- Payment Methods Breakdown -->
      <div class="bmc-card bmc-card--side">
        <h2 class="bmc-card__title">Payment Methods</h2>
        <div class="bmc-payment-breakdown">
          <div class="bmc-donut-placeholder">
            <div class="bmc-donut-ring">
              <span class="bmc-donut-total">{{ primaryRevenue }}</span>
            </div>
          </div>
          <div class="bmc-payment-legend">
            <div class="bmc-payment-legend__item" v-for="method in paymentMethods" :key="method.name">
              <span class="bmc-payment-legend__dot" :style="{ background: method.color }"></span>
              <span class="bmc-payment-legend__name">{{ method.name }}</span>
              <span class="bmc-payment-legend__value">{{ method.value }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Bottom Row: Table + Sidebar -->
    <div class="bmc-dash-row">
      <!-- Recent Supporters -->
      <div class="bmc-card bmc-card--flex bmc-card--no-pad">
        <div class="bmc-card__header bmc-card__header--padded">
          <div>
            <h2 class="bmc-card__title">Recent Transactions</h2>
            <p class="bmc-card__subtitle">Latest donations received</p>
          </div>
          <a
            v-if="supporters.length"
            class="bmc-view-all"
            @click="$router.push({ name: 'RecentTransactions' })"
          >
            View all
            <ChevronRight :size="14" />
          </a>
        </div>

        <el-table
          v-if="supporters.length"
          :data="recentSupporters"
          class="bmc-supporters-table"
          :show-header="true"
          row-class-name="bmc-supporters-table__row"
          @row-click="(row) => goToSupporter(row.id)"
        >
          <el-table-column label="Supporter" min-width="220">
            <template #default="{ row }">
              <div class="flex items-center gap-3">
                <img
                  v-if="row.supporters_image"
                  :src="row.supporters_image"
                  :alt="row.supporters_name"
                  class="bmc-avatar"
                />
                <div
                  v-else
                  class="bmc-avatar-placeholder"
                  :class="getAvatarColor(row.supporters_name)"
                >
                  {{ getInitials(row.supporters_name) }}
                </div>
                <div class="min-w-0">
                  <p class="bmc-supporter-name">{{ row.supporters_name }}</p>
                  <p class="bmc-supporter-email" v-if="row.supporters_email">{{ row.supporters_email }}</p>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="Amount" width="120">
            <template #default="{ row }">
              <span class="bmc-amount">{{ stripHtml(row.amount_formatted) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="Status" width="110">
            <template #default="{ row }">
              <StatusBadge :status="row.payment_status" />
            </template>
          </el-table-column>
          <el-table-column label="Method" width="110">
            <template #default="{ row }">
              <span class="bmc-method">{{ row.payment_method || '--' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="Date" width="110">
            <template #default="{ row }">
              <span class="bmc-date">{{ formatDate(row.created_at) }}</span>
            </template>
          </el-table-column>
        </el-table>

        <EmptyState
          v-else-if="!fetching"
          title="No supporters yet"
          description="Share your donation page to start receiving support from your audience."
          icon="Heart"
        />
      </div>

      <!-- Right column -->
      <div class="bmc-dash-right-col">
        <!-- Active Subscriptions -->
        <div class="bmc-card">
          <div class="bmc-card__header">
            <h2 class="bmc-card__title">Active Subscriptions</h2>
            <a class="bmc-view-all" @click="$router.push({ name: 'Subscriptions' })">
              View all
            </a>
          </div>
          <div class="bmc-sub-list">
            <div class="bmc-sub-item" v-for="sub in activeSubscriptions" :key="sub.id" @click="$router.push({ name: 'SubscriptionDetail', params: { id: sub.id } })">
              <div class="bmc-sub-item__avatar">{{ getInitials(sub.name) }}</div>
              <div class="bmc-sub-item__info">
                <span class="bmc-sub-item__name">{{ sub.name }}</span>
                <span class="bmc-sub-item__plan">{{ sub.amount }}</span>
              </div>
              <span class="bmc-sub-item__badge">Active</span>
            </div>
            <div v-if="!activeSubscriptions.length" class="bmc-sub-empty">
              No active subscriptions
            </div>
          </div>
        </div>

        <!-- Quick Stats -->
        <div class="bmc-card">
          <h2 class="bmc-card__title">Quick Stats</h2>
          <div class="bmc-quick-stats">
            <div class="bmc-quick-stat">
              <span class="bmc-quick-stat__label">Avg. Donation</span>
              <span class="bmc-quick-stat__value">{{ avgDonation }}</span>
            </div>
            <div class="bmc-quick-stat">
              <span class="bmc-quick-stat__label">Total Coffees</span>
              <span class="bmc-quick-stat__value">{{ reportData.total_coffee || 0 }}</span>
            </div>
            <div class="bmc-quick-stat">
              <span class="bmc-quick-stat__label">Pending</span>
              <span class="bmc-quick-stat__value">{{ primaryPending }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { Rocket, X, ChevronRight, ChevronDown, Calendar, Download } from 'lucide-vue-next';
import MetricCard from './UI/MetricCard.vue';
import StatusBadge from './UI/StatusBadge.vue';
import EmptyState from './UI/EmptyState.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import ChartRenderer from './Parts/ChartRenderer.vue';

const AVATAR_COLORS = ['purple', 'teal', 'orange', 'blue', 'pink', 'green'];

export default {
  name: 'Dashboard',
  components: {
    Rocket, X, ChevronRight, ChevronDown, Calendar, Download,
    MetricCard, StatusBadge, EmptyState, ChartRenderer, CoffeeLoader
  },
  data() {
    return {
      fetching: true,
      chartLoading: true,
      exporting: false,
      guidedTour: true,
      dateRange: '30d',
      dateRanges: [
        { label: 'Last 7 days',  value: '7d',  days: 7 },
        { label: 'Last 30 days', value: '30d', days: 30 },
        { label: 'Last 90 days', value: '90d', days: 90 },
        { label: 'Last year',    value: '1y',  days: 365 },
      ],
      limit: 20,
      posts_per_page: 10,
      current: 0,
      total: 0,
      supporters: [],
      renderChart: false,
      dummyChart: true,
      top_paid_currency: 'USD',
      selectedCurrency: '',
      currencyOptions: [],
      chartDataMap: {},
      subscriptionStats: {
        active_count: 0,
        mrr: 0,
      },
      activeSubscriptions: [],
      reportData: {
        total_supporters: 0,
        total_coffee: 0,
        currency_total: [],
        currency_total_pending: []
      },
      totalRevenue: {
        id: 'revenue_chart',
        type: 'line',
        height: '260',
        title: 'Total Revenue',
        color: 'rgba(139, 92, 246, 0.8)',
        backgroundColor: 'rgba(139, 92, 246, 0.08)',
        data: [20, 18, 20, 20, 25],
        label: ['January', 'February', 'March', 'April', 'May']
      },
      overviewOptions: {
        responsive: true,
        maintainAspectRatio: false,
        elements: {
          line: { tension: 0.35, borderWidth: 2 },
          point: { radius: 4, hoverRadius: 6 }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 12 } } },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.04)' },
            ticks: { font: { size: 12 } }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(0,0,0,0.8)',
            padding: 10,
            cornerRadius: 8,
            titleFont: { size: 13 },
            bodyFont: { size: 13 }
          }
        }
      }
    };
  },
  computed: {
    userName() {
      return window.BuyMeCoffeeAdmin?.user_name || 'there';
    },
    currentRangeLabel() {
      return this.dateRanges.find(r => r.value === this.dateRange)?.label || 'Last 30 days';
    },
    dateFrom() {
      const days = this.dateRanges.find(r => r.value === this.dateRange)?.days || 30;
      const d = new Date(Date.now() - days * 86400 * 1000);
      return d.toISOString().split('T')[0];
    },
    recentSupporters() {
      return this.supporters.slice(0, 5);
    },
    primaryRevenue() {
      if (!this.reportData.currency_total || !this.reportData.currency_total.length) return '$0.00';
      if (this.reportData.currency_total.length === 1) return this.stripHtml(this.reportData.currency_total[0].formatted_total);
      return this.reportData.currency_total.map(c => this.stripHtml(c.formatted_total)).join(' / ');
    },
    primaryPending() {
      if (!this.reportData.currency_total_pending || !this.reportData.currency_total_pending.length) return '$0.00';
      if (this.reportData.currency_total_pending.length === 1) return this.stripHtml(this.reportData.currency_total_pending[0].formatted_total);
      return this.reportData.currency_total_pending.map(c => this.stripHtml(c.formatted_total)).join(' / ');
    },
    mrrFormatted() {
      const mrr = this.subscriptionStats.mrr || 0;
      return '$' + (mrr / 100).toFixed(2);
    },
    avgDonation() {
      const total = this.reportData.currency_total?.[0]?.total_amount || 0;
      const count = this.reportData.total_supporters || 0;
      if (!count) return '$0.00';
      return '$' + (total / count / 100).toFixed(2);
    },
    revenueTrend() {
      return '';
    },
    supportersTrend() {
      return '';
    },
    paymentMethods() {
      return [
        { name: 'Stripe', value: '--', color: '#8B5CF6' },
        { name: 'PayPal', value: '--', color: '#F59E0B' },
      ];
    }
  },
  methods: {
    stripHtml(str) {
      if (!str) return '';
      const div = document.createElement('div');
      div.innerHTML = str;
      return div.textContent || div.innerText || '';
    },
    getInitials(name) {
      if (!name) return '?';
      return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
    },
    getAvatarColor(name) {
      if (!name) return 'bmc-avatar--purple';
      const idx = name.charCodeAt(0) % AVATAR_COLORS.length;
      return 'bmc-avatar--' + AVATAR_COLORS[idx];
    },
    formatDate(date) {
      if (!date) return '--';
      const d = new Date(date);
      return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    },
    goToSupporter(id) {
      this.$router.push({ name: 'Supporter', params: { id } });
    },
    setDateRange(value) {
      this.dateRange = value;
      this.getSupporters();
      this.getWeeklyRevenue();
    },
    exportData() {
      this.exporting = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_supporters',
        data: {
          date_from: this.dateFrom,
          page: 0,
          posts_per_page: 9999,
        },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      }).then((response) => {
        const rows = (response.data.supporters || []).map(s => [
          s.supporters_name || '',
          s.supporters_email || '',
          this.stripHtml(s.amount_formatted || ''),
          s.payment_status || '',
          s.payment_method || '',
          s.payment_mode || '',
          s.created_at || '',
        ]);
        const header = ['Name', 'Email', 'Amount', 'Status', 'Method', 'Mode', 'Date'];
        const csv = [header, ...rows]
          .map(row => row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','))
          .join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `supporters-${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      }).fail(() => {
        this.$message.error('Export failed. Please try again.');
      }).always(() => {
        this.exporting = false;
      });
    },
    setStore() {
      this.guidedTour = true;
      this.$saveData('buymecoffee_guided_tour', 'done');
    },
    getSupporters() {
      this.fetching = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_supporters',
        data: {
          filter_top: 'yes',
          date_from: this.dateFrom,
          limit: this.limit,
          page: this.current,
          posts_per_page: this.posts_per_page
        },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
        .then((response) => {
          this.supporters = response.data.supporters;
          this.total = response.data.total;
          this.reportData = response.data.reports;
          this.fetching = false;
        })
        .fail((error) => {
          this.$message.error(error.responseJSON.data.message);
        })
        .always(() => {
          this.fetching = false;
        });
    },
    getWeeklyRevenue() {
      this.chartLoading = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_weekly_revenue',
        data: { date_from: this.dateFrom },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
        .then((response) => {
          this.top_paid_currency = response?.data?.topPaidCurrency || response?.data?.top_paid_currency || 'USD';
          this.chartDataMap = response?.data?.chartData || {};
          this.currencyOptions = response?.data?.options || [];
          this.selectedCurrency = this.top_paid_currency;
          const graphData = this.chartDataMap[this.top_paid_currency];
          if (graphData) {
            this.totalRevenue.data = graphData.data;
            this.totalRevenue.label = graphData.label;
            this.dummyChart = false;
          }
          this.renderChart = true;
          this.chartLoading = false;
        })
        .catch((e) => {
          this.chartLoading = false;
          this.$handleError(e);
        });
    },
    getSubscriptionStats() {
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_subscription_stats',
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      }).then((response) => {
        if (response?.data) {
          this.subscriptionStats = response.data;
          this.activeSubscriptions = response.data.recent || [];
        }
      });
    },
    onCurrencyChange(currency) {
      this.renderChart = false;
      this.top_paid_currency = currency;
      const graphData = this.chartDataMap[currency];
      if (graphData) {
        this.totalRevenue.data = graphData.data;
        this.totalRevenue.label = graphData.label;
        this.dummyChart = false;
      } else {
        this.totalRevenue.data = [20, 18, 20, 20, 25];
        this.totalRevenue.label = ['January', 'February', 'March', 'April', 'May'];
        this.dummyChart = true;
      }
      this.$nextTick(() => { this.renderChart = true; });
    }
  },
  mounted() {
    this.getSupporters();
    this.getWeeklyRevenue();
    this.getSubscriptionStats();
    if (window.localStorage) {
      this.guidedTour = !!this.$getData('buymecoffee_guided_tour');
    }
  }
};
</script>

<style scoped>
.bmc-dashboard {
  max-width: 1280px;
}

/* ── Header ── */
.bmc-dash-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
}
.bmc-dash-header__greeting {
  font-family: var(--font-display);
  font-size: 24px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.3;
}
.bmc-dash-header__subtitle {
  font-size: 14px;
  color: var(--text-secondary);
  margin: 4px 0 0;
}
.bmc-dash-header__right {
  display: flex;
  align-items: center;
  gap: 12px;
}
.bmc-dash-header__date {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 14px;
  height: 38px;
  border-radius: var(--radius-sm);
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
}
.bmc-dash-header__export {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 16px;
  height: 38px;
  border-radius: var(--radius-sm);
  background: linear-gradient(180deg, var(--color-primary-500) 0%, var(--color-primary-600) 100%);
  border: none;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s ease;
}
.bmc-dash-header__export:hover {
  opacity: 0.9;
}

/* ── Metrics Grid ── */
.bmc-metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}
@media (max-width: 1024px) {
  .bmc-metrics-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .bmc-metrics-grid { grid-template-columns: 1fr; }
}

/* ── Dashboard Row ── */
.bmc-dash-row {
  display: flex;
  gap: 20px;
  margin-bottom: 24px;
}
@media (max-width: 960px) {
  .bmc-dash-row { flex-direction: column; }
}

/* ── Card ── */
.bmc-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-secondary);
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.bmc-card--flex {
  flex: 1;
  min-width: 0;
}
.bmc-card--side {
  width: 360px;
  flex-shrink: 0;
}
.bmc-card--no-pad {
  padding: 0;
}
.bmc-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 20px;
}
.bmc-card__header--padded {
  padding: 20px 24px;
  margin-bottom: 0;
}
.bmc-card__title {
  font-family: var(--font-display);
  font-size: 16px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.4;
}
.bmc-card__subtitle {
  font-size: 12px;
  color: var(--text-tertiary);
  margin: 4px 0 0;
}

/* ── Chart ── */
.bmc-chart-container {
  min-height: 260px;
  position: relative;
}
.bmc-currency-select {
  width: 130px;
}

/* ── Payment Breakdown ── */
.bmc-payment-breakdown {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 24px;
}
.bmc-donut-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 160px;
  height: 160px;
}
.bmc-donut-ring {
  width: 140px;
  height: 140px;
  border-radius: 50%;
  border: 16px solid var(--color-primary-500);
  border-right-color: var(--color-accent-orange);
  display: flex;
  align-items: center;
  justify-content: center;
}
.bmc-donut-total {
  font-family: var(--font-display);
  font-size: 20px;
  font-weight: 700;
  color: var(--text-primary);
}
.bmc-payment-legend {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.bmc-payment-legend__item {
  display: flex;
  align-items: center;
  gap: 10px;
}
.bmc-payment-legend__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.bmc-payment-legend__name {
  flex: 1;
  font-size: 13px;
  color: var(--text-secondary);
}
.bmc-payment-legend__value {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary);
  font-variant-numeric: tabular-nums;
}

/* ── Right Column ── */
.bmc-dash-right-col {
  width: 360px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
}
@media (max-width: 960px) {
  .bmc-card--side,
  .bmc-dash-right-col { width: 100%; }
}

/* ── Subscriptions list ── */
.bmc-sub-list {
  display: flex;
  flex-direction: column;
}
.bmc-sub-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid var(--border-secondary);
  cursor: pointer;
  transition: background 0.15s ease;
  border-radius: var(--radius-sm);
}
.bmc-sub-item:last-child {
  border-bottom: none;
}
.bmc-sub-item:hover {
  background: var(--bg-secondary);
}
.bmc-sub-item__avatar {
  flex-shrink: 0;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--color-primary-50);
  color: var(--color-primary-600);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
}
.bmc-sub-item__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.bmc-sub-item__name {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bmc-sub-item__plan {
  font-size: 11px;
  color: var(--text-tertiary);
  font-variant-numeric: tabular-nums;
}
.bmc-sub-item__badge {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 600;
  background: #dcfce7;
  color: #166534;
}
.bmc-sub-empty {
  padding: 16px 0;
  font-size: 13px;
  color: var(--text-tertiary);
  text-align: center;
}

/* ── Quick Stats ── */
.bmc-quick-stats {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-top: 4px;
}
.bmc-quick-stat {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px solid var(--border-secondary);
}
.bmc-quick-stat:last-child {
  border-bottom: none;
}
.bmc-quick-stat__label {
  font-size: 13px;
  color: var(--text-secondary);
}
.bmc-quick-stat__value {
  font-size: 14px;
  font-weight: 700;
  color: var(--text-primary);
  font-variant-numeric: tabular-nums;
}

/* ── Setup Banner ── */
.bmc-setup-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 20px;
  margin-bottom: 24px;
  background: linear-gradient(135deg, var(--color-primary-50) 0%, var(--color-coffee-50) 100%);
  border: 1px solid var(--color-primary-200);
  border-radius: 16px;
  position: relative;
  overflow: hidden;
}
.bmc-setup-banner__deco {
  position: absolute;
  right: 20px;
  top: 50%;
  transform: translateY(-50%);
  width: 64px;
  height: 64px;
  color: var(--color-coffee-300);
  opacity: 0.5;
  pointer-events: none;
  animation: bmc-bob 3s ease-in-out infinite;
}
@keyframes bmc-bob {
  0%, 100% { transform: translateY(-50%); }
  50%       { transform: translateY(calc(-50% - 5px)); }
}
@media (prefers-reduced-motion: reduce) {
  .bmc-setup-banner__deco { animation: none; }
}
.bmc-setup-banner__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  background: var(--color-primary-100);
  color: var(--color-primary-600);
  border-radius: 10px;
  flex-shrink: 0;
}
.bmc-setup-banner__title {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
}
.bmc-setup-banner__desc {
  font-size: 13px;
  color: var(--text-secondary);
  margin: 2px 0 0;
}
.bmc-setup-banner__link {
  color: var(--color-primary-600);
  font-weight: 500;
  cursor: pointer;
  text-decoration: none;
}
.bmc-setup-banner__link:hover {
  text-decoration: underline;
}
.bmc-setup-banner__close {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  color: var(--text-secondary);
  border-radius: 6px;
  cursor: pointer;
  flex-shrink: 0;
  transition: background 0.15s ease;
}
.bmc-setup-banner__close:hover {
  background: rgba(0,0,0,0.05);
}

/* ── View all ── */
.bmc-view-all {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 0 12px;
  height: 30px;
  border: 1px solid var(--border-primary);
  border-radius: var(--radius-sm);
  font-size: 12px;
  font-weight: 500;
  color: var(--color-primary-500);
  cursor: pointer;
  text-decoration: none;
  transition: all 0.15s ease;
}
.bmc-view-all:hover {
  background: var(--color-primary-50);
}

/* ── Table ── */
.bmc-supporters-table {
  --el-table-border-color: var(--border-secondary);
  --el-table-header-bg-color: var(--bg-secondary);
  --el-table-row-hover-bg-color: var(--bg-secondary);
  width: 100%;
}
:deep(.bmc-supporters-table__row) {
  cursor: pointer;
  transition: background 0.15s ease;
}
:deep(.bmc-supporters-table .el-table__header th) {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-tertiary);
  padding: 10px 24px;
  white-space: nowrap;
}
:deep(.bmc-supporters-table .el-table__header th .cell) {
  white-space: nowrap;
  overflow: visible;
}
:deep(.bmc-supporters-table .el-table__body td) {
  padding: 12px 24px;
  border-bottom: 1px solid var(--border-secondary);
}

/* ── Avatars ── */
.bmc-avatar {
  width: 30px;
  height: 30px;
  border-radius: 9999px;
  object-fit: cover;
  flex-shrink: 0;
}
.bmc-avatar-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
}
.bmc-avatar--purple {
  background: var(--color-accent-purple-light);
  color: var(--color-accent-purple);
}
.bmc-avatar--teal {
  background: var(--color-accent-teal-light);
  color: var(--color-accent-teal);
}
.bmc-avatar--orange {
  background: var(--color-accent-orange-light);
  color: var(--color-accent-orange);
}
.bmc-avatar--blue {
  background: var(--color-accent-blue-light);
  color: var(--color-accent-blue);
}
.bmc-avatar--pink {
  background: var(--color-accent-pink-light);
  color: var(--color-accent-pink);
}
.bmc-avatar--green {
  background: var(--color-accent-green-light);
  color: var(--color-accent-green);
}

.bmc-supporter-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.4;
}
.bmc-supporter-email {
  font-size: 11px;
  color: var(--text-tertiary);
  margin: 1px 0 0;
  line-height: 1.4;
}
.bmc-amount {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary);
  font-variant-numeric: tabular-nums;
}
.bmc-method {
  font-size: 13px;
  color: var(--text-secondary);
  text-transform: capitalize;
  white-space: nowrap;
}
.bmc-date {
  font-size: 13px;
  color: var(--text-secondary);
  white-space: nowrap;
}

@media (max-width: 768px) {
  .bmc-dash-header { flex-direction: column; gap: 12px; align-items: flex-start; }
  .bmc-dash-header__right { flex-wrap: wrap; }
}
</style>
