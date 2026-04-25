<template>
  <div class="bmc-activity">

    <!-- Section heading -->
    <div class="bmc-activity__header">
      <h3 class="bmc-activity__heading">Activity</h3>
      <button
        v-if="logs.length > 0 || !loading"
        class="bmc-activity__refresh"
        title="Refresh"
        @click="fetchLogs(true)"
      >
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
          <path d="M21 3v5h-5"/>
          <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
          <path d="M8 16H3v5"/>
        </svg>
      </button>
    </div>

    <div class="bmc-activity__header-divider" />

    <!-- Loading -->
    <div v-if="loading && logs.length === 0" class="bmc-activity__loading">
      <CoffeeLoader :loading="true" />
    </div>

    <!-- Empty -->
    <div v-else-if="!loading && logs.length === 0" class="bmc-activity__empty">
      <EmptyState
        title="No activity yet"
        description="Events will appear here as they happen."
        icon="FileText"
      />
    </div>

    <!-- Feed -->
    <div v-else class="bmc-activity__list">
      <div
        v-for="log in logs"
        :key="log.id"
        class="bmc-activity__item"
      >
        <!-- Absolute check/status icon -->
        <div class="bmc-activity__icon" :class="iconClass(log.status)">
          <svg v-if="log.status !== 'failed' && log.status !== 'warning'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          <svg v-else-if="log.status === 'failed'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        </div>

        <!-- Details container -->
        <div class="bmc-activity__details-container">
          <div class="bmc-activity__details" :class="`bmc-activity__status--${log.status}`">
            <span class="bmc-activity__date">{{ formatDate(log.created_at) }}</span>
            <div class="bmc-activity__event-title">
              <span>{{ log.title }}</span>
              <el-tag type="info" size="small" round>{{ pillLabel(log) }}</el-tag>
            </div>
            <div
              v-if="log.description"
              class="bmc-activity__event-text"
            >{{ toPlainText(log.description) }}</div>
          </div>
        </div>
      </div>

      <!-- Load more -->
      <div v-if="hasMore" class="bmc-activity__more">
        <button class="bmc-activity__load-btn" :disabled="loading" @click="fetchLogs(false)">
          {{ loading ? 'Loading…' : 'Load more' }}
        </button>
      </div>
    </div>

  </div>
</template>

<script>
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
  system:           null,
};

export default {
  name: 'ActivityTimeline',
  components: { CoffeeLoader, EmptyState },

  props: {
    objectType:  { type: String,  default: 'all' },
    objectId:    { type: Number,  default: 0 },
    supporterId: { type: Number,  default: 0 },
    showModule:  { type: Boolean, default: false },
    perPage:     { type: Number,  default: 20 },
  },

  data() {
    return {
      logs:    [],
      total:   0,
      page:    0,
      loading: false,
    };
  },

  computed: {
    hasMore() {
      return this.logs.length < this.total;
    },
  },

  watch: {
    supporterId(n, o) { if (n !== o) this.fetchLogs(true); },
    objectId(n, o)    { if (n !== o) this.fetchLogs(true); },
    objectType(n, o)  { if (n !== o) this.fetchLogs(true); },
  },

  mounted() {
    this.fetchLogs(true);
  },

  methods: {
    fetchLogs(reset = true) {
      if (this.loading) return;
      if (reset) { this.page = 0; this.logs = []; }
      this.loading = true;

      const data = this.supporterId > 0
        ? { supporter_id: this.supporterId, per_page: this.perPage, page: this.page }
        : { object_type: this.objectType, object_id: this.objectId, per_page: this.perPage, page: this.page };

      this.$get({
        action: 'buymecoffee_admin_ajax',
        route:  'get_activities',
        data,
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        const d = response?.data || {};
        this.logs  = reset
          ? (d.logs || [])
          : [...this.logs, ...(d.logs || [])];
        this.total = d.total || 0;
        this.page  = (this.page || 0) + 1;
      }).fail((err) => {
        this.$handleError && this.$handleError(err);
      }).always(() => {
        this.loading = false;
      });
    },

    pillLabel(log) {
      const by = log.created_by || '';
      if (by in CREATOR_LABELS) {
        const mapped = CREATOR_LABELS[by];
        if (mapped) return mapped;
        return MODULE_LABELS[log.object_type] || log.object_type || 'System';
      }
      if (by) return by;
      return MODULE_LABELS[log.object_type] || log.object_type || 'System';
    },

    iconClass(status) {
      const map = {
        success: 'bmc-activity__icon--success',
        info:    'bmc-activity__icon--success',
        failed:  'bmc-activity__icon--failed',
        warning: 'bmc-activity__icon--warning',
      };
      return map[status] || 'bmc-activity__icon--success';
    },

    formatDate(dateStr) {
      if (!dateStr || dateStr === '0000-00-00 00:00:00') return '--';
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
/* ── Wrapper ──────────────────────────────────────────────── */
.bmc-activity {
  width: 100%;
}

/* ── Header ───────────────────────────────────────────────── */
.bmc-activity__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 20px 16px;
}

.bmc-activity__heading {
  font-size: 15px;
  font-weight: 700;
  margin: 0;
  color: var(--text-primary, #111827);
  letter-spacing: -0.01em;
}

.bmc-activity__refresh {
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  color: var(--text-tertiary, #9ca3af);
  display: flex;
  align-items: center;
  transition: color 0.15s;
  border-radius: 4px;
}
.bmc-activity__refresh:hover { color: var(--text-secondary, #6b7280); }

.bmc-activity__header-divider {
  height: 1px;
  background: var(--border-primary, #e5e7eb);
}

/* ── States ───────────────────────────────────────────────── */
.bmc-activity__loading,
.bmc-activity__empty {
  padding: 28px 20px;
}
.bmc-activity__loading {
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ── List ─────────────────────────────────────────────────── */
.bmc-activity__list {
  padding: 0 20px 4px;
}

/* ── Single item ──────────────────────────────────────────── */
.bmc-activity__item {
  display: flex;
  flex-direction: column;
  position: relative;
  padding-top: 14px;
}

/* Dashed vertical connector line (hidden on last child) */
.bmc-activity__item::before {
  content: "";
  position: absolute;
  top: 30px;
  left: 6px;
  height: calc(100% - 16px);
  border-right: 1px dashed #d1d5db;
}
.bmc-activity__item:last-of-type::before {
  display: none;
}

/* ── Status icon (absolute, left) ─────────────────────────── */
.bmc-activity__icon {
  position: absolute;
  left: 0;
  top: 14px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  flex-shrink: 0;
}
.bmc-activity__icon svg {
  width: 10px;
  height: 10px;
}

.bmc-activity__icon--success {
  background: #16a34a;
  box-shadow: 0 0 0 3px rgba(209, 234, 228, 1);
}
.bmc-activity__icon--failed {
  background: #dc2626;
  box-shadow: 0 0 0 3px rgba(254, 202, 202, 1);
}
.bmc-activity__icon--warning {
  background: #d97706;
  box-shadow: 0 0 0 3px rgba(253, 230, 138, 1);
}

/* ── Details container ────────────────────────────────────── */
.bmc-activity__details-container {
  display: flex;
  width: 100%;
  justify-content: space-between;
}

/* Content area: left margin clears the icon */
.bmc-activity__details {
  margin-left: 32px;
  flex: 1;
  padding-bottom: 14px;
  margin-bottom: 0;
  border-bottom: 1px solid var(--border-primary, #e5e7eb);
}
.bmc-activity__item:last-of-type .bmc-activity__details {
  border-bottom: none;
  padding-bottom: 6px;
}

/* ── Date ─────────────────────────────────────────────────── */
.bmc-activity__date {
  display: block;
  font-size: 12px;
  color: var(--text-tertiary, #9ca3af);
  line-height: 16px;
  margin-bottom: 5px;
}

/* ── Title + tag row ──────────────────────────────────────── */
.bmc-activity__event-title {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
  font-size: 13.5px;
  font-weight: 400;
  color: var(--text-primary, #111827);
  line-height: 20px;
  margin-bottom: 4px;
}

/* ── Description ──────────────────────────────────────────── */
.bmc-activity__event-text {
  font-size: 13px;
  color: var(--text-secondary, #4b5563);
  line-height: 18px;
  margin-top: 2px;
}

/* Status-based description colour */
.bmc-activity__status--warning .bmc-activity__event-text { color: #c07609; }
.bmc-activity__status--failed  .bmc-activity__event-text { color: #dc2626; }

/* ── Load more ────────────────────────────────────────────── */
.bmc-activity__more {
  padding: 16px 0 8px;
  text-align: center;
}
.bmc-activity__load-btn {
  font-size: 13px;
  padding: 6px 20px;
  border-radius: 8px;
  border: 1px solid var(--border-primary, #e5e7eb);
  background: var(--bg-primary, #fff);
  color: var(--text-secondary, #4b5563);
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}
.bmc-activity__load-btn:hover {
  background: #f9fafb;
  color: var(--text-primary, #111827);
}
.bmc-activity__load-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
