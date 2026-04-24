<template>
  <div class="bmc-dashboard relative min-h-[200px]">
    <CoffeeLoader :loading="fetching" />
    <PageTitle title="Dashboard" subtitle="Overview of your donations" />

    <!-- Quick Setup Banner -->
    <div
      v-if="!supporters.length && !guidedTour && !fetching"
      class="bmc-setup-banner"
    >
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

    <!-- Metric Cards -->
    <div class="bmc-metrics-grid">
      <MetricCard
        label="Total Revenue"
        :value="primaryRevenue"
        icon="DollarSign"
        color="primary"
      />
      <MetricCard
        label="Supporters"
        :value="String(reportData.total_supporters || 0)"
        icon="Users"
        color="violet"
      />
      <MetricCard
        label="Total Coffees"
        :value="String(reportData.total_coffee || 0)"
        icon="Coffee"
        color="amber"
      />
      <MetricCard
        label="Pending"
        :value="primaryPending"
        icon="Clock"
        color="sky"
      />
    </div>

    <!-- Revenue Chart -->
    <div class="bmc-card" v-loading="chartLoading">
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
          height="400"
        />
      </div>
    </div>

    <!-- Recent Supporters -->
    <div class="bmc-card" >
      <div class="bmc-card__header">
        <div>
          <h2 class="bmc-card__title">Recent Supporters</h2>
          <p class="bmc-card__subtitle">Latest donations received</p>
        </div>
        <a
          v-if="supporters.length"
          class="bmc-view-all"
          @click="$router.push({ name: 'Supporters' })"
        >
          View all
          <ChevronRight :size="16" />
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
        <el-table-column label="Amount" width="160">
          <template #default="{ row }">
            <span class="bmc-amount" v-html="row.amount_formatted"></span>
          </template>
        </el-table-column>
        <el-table-column label="Status" width="120">
          <template #default="{ row }">
            <StatusBadge :status="row.payment_status" />
          </template>
        </el-table-column>
        <el-table-column label="Method" width="120">
          <template #default="{ row }">
            <span class="bmc-method">{{ row.payment_method || '--' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="Date" width="160">
          <template #default="{ row }">
            <span class="bmc-date">{{ row.created_at }}</span>
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
  </div>
</template>

<script>
import { Rocket, X, ChevronRight } from 'lucide-vue-next';
import PageTitle from './UI/PageTitle.vue';
import MetricCard from './UI/MetricCard.vue';
import StatusBadge from './UI/StatusBadge.vue';
import EmptyState from './UI/EmptyState.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import ChartRenderer from './Parts/ChartRenderer.vue';

export default {
  name: 'Dashboard',
  components: {
    Rocket,
    X,
    ChevronRight,
    PageTitle,
    MetricCard,
    StatusBadge,
    EmptyState,
    ChartRenderer,
    CoffeeLoader
  },
  data() {
    return {
      fetching: true,
      chartLoading: true,
      guidedTour: true,
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
      reportData: {
        total_supporters: 0,
        total_coffee: 0,
        currency_total: [],
        currency_total_pending: []
      },
      totalRevenue: {
        id: 'revenue_chart',
        type: 'line',
        height: '200',
        title: 'Total Revenue',
        color: 'rgba(99,102,241,0.8)',
        backgroundColor: 'rgba(99,102,241,0.08)',
        data: [20, 18, 20, 20, 25],
        label: ['January', 'February', 'March', 'April', 'May']
      },
      overviewOptions: {
        responsive: true,
        maintainAspectRatio: false,
        elements: {
          line: {
            tension: 0.35,
            borderWidth: 2
          },
          point: {
            radius: 4,
            hoverRadius: 6
          }
        },
        scales: {
          x: {
            grid: {
              display: false
            },
            ticks: {
              font: { size: 12 }
            }
          },
          y: {
            beginAtZero: true,
            grid: {
              color: 'rgba(0,0,0,0.04)'
            },
            ticks: {
              font: { size: 12 }
            }
          }
        },
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            backgroundColor: 'rgba(0,0,0,0.8)',
            padding: 10,
            cornerRadius: 8,
            titleFont: { size: 13 },
            bodyFont: { size: 13 },
            callbacks: {
              label: (context) => {
                return context.formattedValue + ' ' + this.top_paid_currency;
              }
            }
          }
        }
      }
    };
  },
  computed: {
    recentSupporters() {
      return this.supporters.slice(0, 10);
    },
    primaryRevenue() {
      if (!this.reportData.currency_total || !this.reportData.currency_total.length) {
        return '$0.00';
      }
      if (this.reportData.currency_total.length === 1) {
        return this.stripHtml(this.reportData.currency_total[0].formatted_total);
      }
      return this.reportData.currency_total
        .map(c => this.stripHtml(c.formatted_total))
        .join(' / ');
    },
    primaryPending() {
      if (!this.reportData.currency_total_pending || !this.reportData.currency_total_pending.length) {
        return '$0.00';
      }
      if (this.reportData.currency_total_pending.length === 1) {
        return this.stripHtml(this.reportData.currency_total_pending[0].formatted_total);
      }
      return this.reportData.currency_total_pending
        .map(c => this.stripHtml(c.formatted_total))
        .join(' / ');
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
      return name
        .split(' ')
        .map(w => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
    },
    goToSupporter(id) {
      this.$router.push({ name: 'Supporter', params: { id } });
    },
    setStore() {
      this.guidedTour = true;
      if (window.localStorage) {
        localStorage.setItem('buymecoffee_guided_tour', false);
      }
    },
    getSupporters() {
      this.fetching = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_supporters',
        data: {
          filter_top: 'yes',
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
      this.$nextTick(() => {
        this.renderChart = true;
      });
    }
  },
  mounted() {
    this.getSupporters();
    this.getWeeklyRevenue();
    if (window.localStorage) {
      this.guidedTour = !!window.localStorage.getItem('buymecoffee_guided_tour');
    }
  }
};
</script>

<style scoped>
.bmc-dashboard {
  max-width: 1200px;
}

/* Metric cards grid */
.bmc-metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}

@media (max-width: 1024px) {
  .bmc-metrics-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 600px) {
  .bmc-metrics-grid {
    grid-template-columns: 1fr;
  }
}

/* Card */
.bmc-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 24px;
}

.bmc-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 20px;
}

.bmc-card__title {
  font-size: 16px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.4;
}

.bmc-card__subtitle {
  font-size: 13px;
  color: var(--text-secondary);
  margin: 4px 0 0;
}

/* Chart container */
.bmc-chart-container {
  min-height: 300px;
  position: relative;
}

/* Currency selector */
.bmc-currency-select {
  width: 130px;
}

/* Setup banner */
.bmc-setup-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 20px;
  margin-bottom: 24px;
  background: var(--color-primary-50);
  border: 1px solid var(--color-primary-200, var(--border-primary));
  border-radius: 12px;
}

.bmc-setup-banner__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  background: var(--color-primary-100, rgba(99,102,241,0.1));
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
  background: var(--color-primary-100, rgba(0,0,0,0.05));
}

/* View all link */
.bmc-view-all {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-primary-600);
  cursor: pointer;
  text-decoration: none;
  transition: color 0.15s ease;
}

.bmc-view-all:hover {
  color: var(--color-primary-700, var(--color-primary-600));
  text-decoration: none;
}

/* Supporters table */
.bmc-supporters-table {
  --el-table-border-color: var(--border-primary);
  --el-table-header-bg-color: var(--color-neutral-50, #f9fafb);
  --el-table-row-hover-bg-color: var(--color-neutral-50, #f9fafb);
  width: 100%;
}

:deep(.bmc-supporters-table__row) {
  cursor: pointer;
  transition: background 0.15s ease;
}

:deep(.bmc-supporters-table .el-table__header th) {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--text-secondary);
  padding: 10px 12px;
}

:deep(.bmc-supporters-table .el-table__body td) {
  padding: 12px;
  border-bottom: 1px solid var(--border-primary);
}

.bmc-avatar {
  width: 36px;
  height: 36px;
  border-radius: 9999px;
  object-fit: cover;
  flex-shrink: 0;
}

.bmc-avatar-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 9999px;
  background: var(--color-primary-50);
  color: var(--color-primary-600);
  font-size: 13px;
  font-weight: 600;
  flex-shrink: 0;
}

.bmc-supporter-name {
  font-size: 14px;
  font-weight: 500;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.4;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.bmc-supporter-email {
  font-size: 12px;
  color: var(--text-secondary);
  margin: 1px 0 0;
  line-height: 1.4;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.bmc-amount {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-primary);
  font-variant-numeric: tabular-nums;
}

.bmc-method {
  font-size: 13px;
  color: var(--text-secondary);
  text-transform: capitalize;
}

.bmc-date {
  font-size: 13px;
  color: var(--text-secondary);
}
</style>
