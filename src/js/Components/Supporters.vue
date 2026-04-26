<template>
  <div class="relative min-h-[200px]">
    <CoffeeLoader :loading="loading" />
    <PageTitle title="Supporters" subtitle="Manage and view all your supporters" />


    <!-- Status Pills -->
    <div v-if="!loading" class="flex flex-wrap gap-2 mb-6">
      <button
        v-for="pill in statusPills"
        :key="pill.value"
        class="bmc-pill"
        :class="{ 'bmc-pill--active': filter_status === pill.value }"
        @click="filter_status = pill.value; current = 0; getSupporters()"
      >
        {{ pill.label }}
        <span class="bmc-pill__count">{{ pill.count }}</span>
      </button>
    </div>

    <!-- Filters Row -->
    <div class="bmc-card">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="relative">
          <Search :size="16" class="bmc-search-icon" />
          <input
            v-model="search"
            type="text"
            placeholder="Search supporters..."
            class="bmc-search-input"
            @keyup.enter="current = 0; getSupporters()"
          />
        </div>
        <div>
          <el-select
            v-model="filter_status"
            placeholder="Filter by status"
            style="width: 160px"
            @change="current = 0; getSupporters()"
          >
            <el-option
              v-for="filter in filters"
              :key="filter.value"
              :label="filter.label"
              :value="filter.value"
            />
          </el-select>
        </div>
      </div>

      <!-- Table -->
      <SupportersTable
        :supporters="supporters"
        :loading="fetching"
        @deleted="getSupporters"
      />

      <!-- Pagination -->
      <div v-if="total > 0" class="bmc-pagination">
        <span class="bmc-pagination__info">
          Showing {{ paginationStart }}–{{ paginationEnd }} of {{ total }}
        </span>
        <div class="flex items-center gap-1">
          <button
            class="bmc-pagination__btn"
            :disabled="current === 0"
            @click="current--; getSupporters()"
          >
            <ChevronLeft :size="16" />
          </button>
          <button
            v-for="page in visiblePages"
            :key="page"
            class="bmc-pagination__btn"
            :class="{ 'bmc-pagination__btn--active': page - 1 === current }"
            @click="current = page - 1; getSupporters()"
          >
            {{ page }}
          </button>
          <button
            class="bmc-pagination__btn"
            :disabled="current >= lastPage - 1"
            @click="current++; getSupporters()"
          >
            <ChevronRight :size="16" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { Search, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import PageTitle from './UI/PageTitle.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import SupportersTable from './SupportersTable.vue';

export default {
  name: 'Supporters',
  components: {
    Search,
    ChevronLeft,
    ChevronRight,
    PageTitle,
    SupportersTable,
    CoffeeLoader
  },
  data() {
    return {
      current: 0,
      total: 0,
      posts_per_page: 10,
      search: '',
      filter_status: 'all',
      loading: false,
      fetching: false,
      supporters: [],
      statusReportData: null,
      filters: [
        { label: 'All', value: 'all' },
        { label: 'Paid', value: 'paid' },
        { label: 'Pending', value: 'pending' },
        { label: 'Cancelled', value: 'cancelled' },
        { label: 'Refunded', value: 'refunded' },
        { label: 'Failed', value: 'failed' }
      ]
    };
  },
  computed: {
    lastPage() {
      return Math.ceil(this.total / this.posts_per_page) || 1;
    },
    paginationStart() {
      return this.total === 0 ? 0 : this.current * this.posts_per_page + 1;
    },
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
      if (end - start < 4) {
        start = Math.max(1, end - 4);
      }
      for (let i = start; i <= end; i++) {
        pages.push(i);
      }
      return pages;
    },
    statusPills() {
      if (!this.statusReportData) return [];
      const d = this.statusReportData;
      return [
        { label: 'All', value: 'all', count: d.total || 0 },
        { label: 'Paid', value: 'paid', count: d.total_paid || 0 },
        { label: 'Pending', value: 'pending', count: d.total_pending || 0 },
        { label: 'Failed', value: 'failed', count: d.total_failed || 0 },
        { label: 'Refunded', value: 'refunded', count: d.total_refunded || 0 }
      ];
    }
  },
  methods: {
    getSupporters() {
      this.fetching = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_supporters',
        data: {
          search: this.search,
          filter_status: this.filter_status,
          page: this.current,
          posts_per_page: this.posts_per_page
        },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
        .then((response) => {
          this.supporters = response?.data?.supporters || [];
          this.total = response?.data?.total || 0;
        })
        .fail((error) => {
          this.$handleError(error);
        })
        .always(() => {
          this.fetching = false;
        });
    },
    statusReport() {
      this.loading = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'status_report',
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
        .then((response) => {
          this.statusReportData = response.data;
        })
        .fail((error) => {
          this.$handleError(error);
        })
        .always(() => {
          this.loading = false;
        });
    }
  },
  mounted() {
    this.getSupporters();
    this.statusReport();
  }
};
</script>

<style scoped>
/* Card */
.bmc-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-secondary);
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

/* Status pills */
.bmc-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: var(--radius-full);
  font-size: var(--text-sm);
  font-weight: 500;
  font-family: var(--font-sans);
  border: 1px solid var(--border-primary);
  background: var(--bg-primary);
  color: var(--text-secondary);
  cursor: pointer;
  transition: all var(--duration-normal) var(--ease-default);
}
.bmc-pill:hover {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}
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
.bmc-pill__count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 5px;
  border-radius: var(--radius-full);
  font-size: var(--text-xs);
  font-weight: 600;
  background: var(--color-neutral-100);
  color: var(--text-secondary);
}
.bmc-pill--active .bmc-pill__count {
  background: rgba(255, 255, 255, 0.25);
  color: #fff;
}

/* Search input */
.bmc-search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-tertiary);
  pointer-events: none;
}
.bmc-search-input {
  width: 280px;
  padding: 7px 12px 7px 32px;
  border: 1px solid var(--border-primary);
  border-radius: var(--radius-md);
  font-size: var(--text-base);
  font-family: var(--font-sans);
  color: var(--text-primary);
  background: var(--bg-primary);
  outline: none;
  transition: border-color var(--duration-normal) var(--ease-default),
              box-shadow var(--duration-normal) var(--ease-default);
}
.bmc-search-input::placeholder {
  color: var(--text-tertiary);
}
.bmc-search-input:focus {
  border-color: var(--border-focus);
  box-shadow: var(--shadow-ring);
}

/* Pagination */
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
  color: var(--text-inverse);
}
</style>
