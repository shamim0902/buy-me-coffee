<template>
  <div class="relative min-h-[200px]">
    <CoffeeLoader :loading="loading && logs.length === 0" />
    <PageTitle title="Activity Log" subtitle="Full event history across payments, subscriptions, and emails." />

    <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6">

      <!-- Filter row -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <el-select
          v-model="filterType"
          style="width: 180px"
          @change="onFilterChange"
        >
          <el-option label="All Events"    value="all" />
          <el-option label="Payments"      value="payment" />
          <el-option label="Subscriptions" value="subscription" />
          <el-option label="Submissions"   value="submission" />
          <el-option label="Emails"        value="email" />
        </el-select>
      </div>

      <!-- Table -->
      <el-table
        v-loading="loading"
        element-loading-text="Loading activity…"
        :data="logs"
        class="bmc-table"
        :row-class-name="() => 'bmc-table__row'"
      >
        <!-- Date -->
        <el-table-column label="Date" width="160">
          <template #default="{ row }">
            <span class="bmc-act-date">{{ formatDate(row.created_at) }}</span>
          </template>
        </el-table-column>

        <!-- Event title + description -->
        <el-table-column label="Event" min-width="260">
          <template #default="{ row }">
            <div class="bmc-act-event">
              <span class="bmc-act-event__title">{{ row.title }}</span>
              <span v-if="row.description" class="bmc-act-event__desc">{{ toPlainText(row.description) }}</span>
            </div>
          </template>
        </el-table-column>

        <!-- Type -->
        <el-table-column label="Type" width="130">
          <template #default="{ row }">
            <span class="bmc-act-type" :class="`bmc-act-type--${row.object_type}`">
              {{ moduleLabel(row.object_type) }}
            </span>
          </template>
        </el-table-column>

        <!-- Status -->
        <el-table-column label="Status" width="110">
          <template #default="{ row }">
            <span class="bmc-act-status" :class="`bmc-act-status--${row.status}`">
              {{ row.status }}
            </span>
          </template>
        </el-table-column>

        <!-- By -->
        <el-table-column label="By" width="130">
          <template #default="{ row }">
            <span class="bmc-act-by">{{ creatorLabel(row.created_by, row.object_type) }}</span>
          </template>
        </el-table-column>

        <!-- Empty state -->
        <template #empty>
          <EmptyState
            v-if="!loading"
            title="No activity found"
            description="Events will appear here as they happen."
            icon="FileText"
          />
        </template>
      </el-table>

      <!-- Pagination -->
      <div v-if="total > 0" class="bmc-pagination">
        <span class="bmc-pagination__info">
          Showing {{ paginationStart }}–{{ paginationEnd }} of {{ total }}
        </span>
        <div class="flex items-center gap-1">
          <button
            class="bmc-pagination__btn"
            :disabled="current === 0"
            @click="current--; fetchLogs()"
          >
            <ChevronLeft :size="16" />
          </button>
          <button
            v-for="page in visiblePages"
            :key="page"
            class="bmc-pagination__btn"
            :class="{ 'bmc-pagination__btn--active': page - 1 === current }"
            @click="current = page - 1; fetchLogs()"
          >
            {{ page }}
          </button>
          <button
            class="bmc-pagination__btn"
            :disabled="current >= lastPage - 1"
            @click="current++; fetchLogs()"
          >
            <ChevronRight :size="16" />
          </button>
        </div>
      </div>

    </div>
  </div>
</template>

<script>
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import PageTitle from './UI/PageTitle.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import EmptyState from './UI/EmptyState.vue';

const MODULE_LABELS = {
  payment:      'Payment',
  subscription: 'Subscription',
  submission:   'Submission',
  email:        'Email',
};

const CREATOR_LABELS = {
  'webhook:stripe': 'Stripe',
  'webhook:paypal': 'PayPal',
};

export default {
  name: 'ActivityLog',
  components: { ChevronLeft, ChevronRight, PageTitle, CoffeeLoader, EmptyState },

  data() {
    return {
      logs:        [],
      total:       0,
      current:     0,
      perPage:     20,
      loading:     false,
      filterType:  'all',
    };
  },

  computed: {
    lastPage() {
      return Math.ceil(this.total / this.perPage) || 1;
    },
    paginationStart() {
      return this.total === 0 ? 0 : this.current * this.perPage + 1;
    },
    paginationEnd() {
      const end = (this.current + 1) * this.perPage;
      return end > this.total ? this.total : end;
    },
    visiblePages() {
      const pages = [];
      const total = this.lastPage;
      const cur = this.current + 1;
      let start = Math.max(1, cur - 2);
      let end   = Math.min(total, start + 4);
      if (end - start < 4) start = Math.max(1, end - 4);
      for (let i = start; i <= end; i++) pages.push(i);
      return pages;
    },
  },

  mounted() {
    this.fetchLogs();
  },

  methods: {
    onFilterChange() {
      this.current = 0;
      this.fetchLogs();
    },

    fetchLogs() {
      this.loading = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route:  'get_activities',
        data: {
          object_type: this.filterType,
          object_id:   0,
          per_page:    this.perPage,
          page:        this.current,
        },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        const d = response?.data || {};
        this.logs  = d.logs  || [];
        this.total = d.total || 0;
      }).fail((err) => {
        this.$handleError && this.$handleError(err);
      }).always(() => {
        this.loading = false;
      });
    },

    moduleLabel(type) {
      return MODULE_LABELS[type] || type || '—';
    },

    creatorLabel(by, objectType) {
      if (!by) return MODULE_LABELS[objectType] || 'System';
      if (by in CREATOR_LABELS) return CREATOR_LABELS[by];
      if (by === 'system') return MODULE_LABELS[objectType] || 'System';
      return by;
    },

    formatDate(dateStr) {
      if (!dateStr || dateStr === '0000-00-00 00:00:00') return '—';
      const d = new Date(dateStr);
      return d.toLocaleString(undefined, {
        month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit',
      });
    },

    toPlainText(html) {
      if (!html) return '';
      const container = document.createElement('div');
      container.innerHTML = html;
      return container.textContent || container.innerText || '';
    },
  },
};
</script>

<style scoped>
/* ── Table base ───────────────────────────────────────────── */
.bmc-table {
  --el-table-border-color: var(--border-secondary);
  --el-table-header-bg-color: var(--bg-secondary);
  --el-table-header-text-color: var(--text-secondary);
  --el-table-row-hover-bg-color: var(--bg-secondary);
  --el-table-text-color: var(--text-primary);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.bmc-table :deep(th .cell) {
  font-size: var(--text-xs);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-family: var(--font-sans);
}

.bmc-table :deep(td .cell) {
  font-size: var(--text-base);
  font-family: var(--font-sans);
}

/* ── Date ─────────────────────────────────────────────────── */
.bmc-act-date {
  font-size: 13px;
  color: var(--text-secondary);
  white-space: nowrap;
}

/* ── Event ────────────────────────────────────────────────── */
.bmc-act-event {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.bmc-act-event__title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text-primary);
  line-height: 1.3;
}
.bmc-act-event__desc {
  font-size: 12px;
  color: var(--text-tertiary);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* ── Type badge ───────────────────────────────────────────── */
.bmc-act-type {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 500;
  background: #f3f4f6;
  color: var(--text-secondary);
  border: 1px solid #e5e7eb;
}
.bmc-act-type--payment      { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
.bmc-act-type--subscription { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
.bmc-act-type--submission   { background: #faf5ff; color: #7e22ce; border-color: #e9d5ff; }
.bmc-act-type--email        { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }

/* ── Status badge ─────────────────────────────────────────── */
.bmc-act-status {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 500;
  text-transform: capitalize;
  background: #f3f4f6;
  color: var(--text-secondary);
  border: 1px solid #e5e7eb;
}
.bmc-act-status--success { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
.bmc-act-status--info    { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
.bmc-act-status--warning { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.bmc-act-status--failed  { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

/* ── By ───────────────────────────────────────────────────── */
.bmc-act-by {
  font-size: 13px;
  color: var(--text-secondary);
}

/* ── Pagination ───────────────────────────────────────────── */
.bmc-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 16px;
  margin-top: 16px;
  border-top: 1px solid var(--border-secondary);
}
.bmc-pagination__info {
  font-size: var(--text-sm);
  color: var(--text-secondary);
  font-family: var(--font-sans);
}
.bmc-pagination__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 32px;
  height: 32px;
  padding: 0 6px;
  border: 1px solid var(--border-primary);
  border-radius: var(--radius-md);
  background: var(--bg-primary);
  color: var(--text-secondary);
  font-size: var(--text-sm);
  font-weight: 500;
  font-family: var(--font-sans);
  cursor: pointer;
  transition: all var(--duration-normal) var(--ease-default);
}
.bmc-pagination__btn:hover:not(:disabled) {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}
.bmc-pagination__btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
.bmc-pagination__btn--active {
  background: var(--color-primary-500);
  color: var(--text-inverse);
  border-color: var(--color-primary-500);
}
.bmc-pagination__btn--active:hover:not(:disabled) {
  background: var(--color-primary-600);
}
</style>
