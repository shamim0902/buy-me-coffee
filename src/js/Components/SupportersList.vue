<template>
  <div class="bmc-supporters-page relative min-h-[200px]">
    <CoffeeLoader :loading="loading" />
    <PageTitle title="Supporters" subtitle="All the people who have supported you" />

    <template v-if="!loading">
      <!-- Metric Cards -->
      <div class="bmc-metrics-grid mb-6">
        <MetricCard label="Total Supporters" :value="stats.total_supporters" icon="Users" color="purple" />
        <MetricCard label="Lifetime Revenue" :value="stats.lifetime_revenue" icon="DollarSign" color="teal" />
        <MetricCard label="Active Subscribers" :value="stats.active_subscribers" icon="RefreshCw" color="blue" />
        <MetricCard label="Avg. Donation" :value="stats.avg_donation" icon="Coffee" color="green" />
      </div>

      <!-- Tab Navigation -->
      <div class="bmc-tabs mb-6">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          class="bmc-tab"
          :class="{ 'bmc-tab--active': activeTab === tab.key }"
          @click="activeTab = tab.key"
        >
          <component :is="tab.icon" :size="15" />
          {{ tab.label }}
        </button>
      </div>

      <!-- Tab 1: All Supporters -->
      <div v-show="activeTab === 'all'">
        <div class="bmc-supporters-panel">
          <div class="bmc-supporters-toolbar">
            <div class="bmc-supporters-search">
              <Search :size="16" class="bmc-supporters-search__icon" />
              <input
                v-model="search"
                type="text"
                placeholder="Search supporters..."
                class="bmc-search-input"
                @keyup.enter="current = 0; getSupporters()"
              />
            </div>
            <div class="bmc-filter-group">
              <button
                v-for="f in filters"
                :key="f.value"
                class="bmc-filter-pill"
                :class="{ 'bmc-filter-pill--active': filter === f.value }"
                @click="filter = f.value; current = 0; getSupporters()"
              >
                {{ f.label }}
              </button>
            </div>
            <span class="bmc-supporters-count">
              {{ total }} supporter{{ total !== 1 ? 's' : '' }}
            </span>
          </div>

          <div v-if="supporters.length" class="bmc-supporters-list">
            <div class="bmc-supporters-list__head">
              <span>Supporter</span>
              <span>Lifetime</span>
              <span>Payments</span>
              <span>Last support</span>
              <span></span>
            </div>

            <div
              v-for="supporter in supporters"
              :key="supporter.supporters_email || supporter.latest_entry_id"
              class="bmc-supporter-row"
              @click="viewSupporter(supporter)"
            >
              <div class="bmc-supporter-row__person">
                <img v-if="supporter.avatar" :src="supporter.avatar" class="bmc-supporter-row__avatar" />
                <div v-else class="bmc-supporter-row__avatar bmc-supporter-row__avatar--placeholder">
                  {{ getInitials(supporter.supporters_name) }}
                </div>
                <div class="bmc-supporter-row__identity">
                  <div class="bmc-supporter-row__name-wrap">
                    <h3 class="bmc-supporter-row__name">{{ supporter.supporters_name || 'Anonymous' }}</h3>
                    <span v-if="supporter.has_subscription" class="bmc-supporter-row__sub-badge" title="Active subscriber">
                      <RefreshCw :size="11" />
                      Subscriber
                    </span>
                  </div>
                  <p v-if="supporter.supporters_email" class="bmc-supporter-row__email">{{ supporter.supporters_email }}</p>
                </div>
              </div>

              <div class="bmc-supporter-row__metric">
                <span class="bmc-supporter-row__metric-label">Lifetime</span>
                <span class="bmc-supporter-row__metric-value" v-html="supporter.total_formatted"></span>
              </div>

              <div class="bmc-supporter-row__metric">
                <span class="bmc-supporter-row__metric-label">Payments</span>
                <span class="bmc-supporter-row__metric-value">{{ supporter.donation_count }}</span>
              </div>

              <div class="bmc-supporter-row__metric">
                <span class="bmc-supporter-row__metric-label">Last support</span>
                <span class="bmc-supporter-row__metric-value">{{ formatDate(supporter.last_donation_date) }}</span>
              </div>

              <div class="bmc-supporter-row__actions">
                <button class="bmc-supporter-row__action" title="View details" @click.stop="viewSupporter(supporter)">
                  <Eye :size="14" />
                  View
                </button>
              </div>
            </div>
          </div>

          <EmptyState
            v-if="!supporters.length"
            title="No supporters yet"
            description="Supporters will appear here once someone makes a contribution."
            icon="Heart"
          />
        </div>

        <div v-if="lastPage > 1" class="flex items-center justify-center gap-2 mt-6">
          <button class="bmc-page-btn" :disabled="current <= 0" @click="current--; getSupporters()">
            <ChevronLeft :size="16" />
          </button>
          <button
            v-for="page in visiblePages"
            :key="page"
            class="bmc-page-btn"
            :class="{ 'bmc-page-btn--active': current === page - 1 }"
            @click="current = page - 1; getSupporters()"
          >
            {{ page }}
          </button>
          <button class="bmc-page-btn" :disabled="current >= lastPage - 1" @click="current++; getSupporters()">
            <ChevronRight :size="16" />
          </button>
        </div>
      </div>

      <!-- Tab 2: Top Supporters -->
      <div v-show="activeTab === 'top'">
        <div v-if="topSupporters.length" class="bmc-top-list">
          <div
            v-for="(supporter, index) in topSupporters"
            :key="supporter.supporters_email"
            class="bmc-top-item"
            :class="{ 'bmc-top-item--gold': index === 0, 'bmc-top-item--silver': index === 1, 'bmc-top-item--bronze': index === 2 }"
            @click="viewSupporter(supporter)"
          >
            <span class="bmc-top-item__rank">{{ index + 1 }}</span>
            <img v-if="supporter.avatar" :src="supporter.avatar" class="bmc-top-item__avatar" />
            <div v-else class="bmc-top-item__avatar-placeholder">{{ getInitials(supporter.supporters_name) }}</div>
            <div class="bmc-top-item__info">
              <span class="bmc-top-item__name">{{ supporter.supporters_name || 'Anonymous' }}</span>
              <span class="bmc-top-item__meta">{{ supporter.donation_count }} payment{{ supporter.donation_count !== 1 ? 's' : '' }}</span>
            </div>
            <span class="bmc-top-item__amount" v-html="supporter.total_formatted"></span>
          </div>
        </div>
        <EmptyState
          v-else
          title="No data yet"
          description="Top supporters will appear here once paid donations come in."
          icon="TrendingUp"
        />
      </div>

      <!-- Tab 3: Shortcodes & Settings -->
      <div v-show="activeTab === 'settings'">
        <!-- Shortcodes Documentation -->
        <div class="bmc-sc mb-5">
          <div class="bmc-sc__header">
            <div class="bmc-sc__icon bmc-sc__icon--purple"><Code2 :size="18" /></div>
            <div>
              <h3 class="bmc-sc__title">Shortcodes</h3>
              <p class="bmc-sc__desc">Use these shortcodes to display content on your site</p>
            </div>
          </div>
          <div class="bmc-shortcode-list">
            <div v-for="sc in shortcodes" :key="sc.code" class="bmc-shortcode-item">
              <div class="bmc-shortcode-item__info">
                <h4 class="bmc-shortcode-item__title">{{ sc.label }}</h4>
                <p class="bmc-shortcode-item__desc">{{ sc.description }}</p>
              </div>
              <div class="bmc-shortcode-item__code">
                <code>{{ sc.code }}</code>
                <button class="bmc-shortcode-item__copy" @click="copyShortcode(sc.code)" :title="'Copy ' + sc.code">
                  <Copy :size="14" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Public Wall Display Settings -->
        <div class="bmc-sc mb-5">
          <div class="bmc-sc__header">
            <div class="bmc-sc__icon bmc-sc__icon--blue"><Eye :size="18" /></div>
            <div>
              <h3 class="bmc-sc__title">Public Wall Display</h3>
              <p class="bmc-sc__desc">Control what's shown on the [buymecoffee_supporters] shortcode</p>
            </div>
          </div>
          <div class="bmc-sr" v-for="toggle in displayToggles" :key="toggle.key">
            <div>
              <span class="bmc-sr__label">{{ toggle.label }}</span>
              <span class="bmc-sr__hint">{{ toggle.hint }}</span>
            </div>
            <el-switch v-model="displaySettings[toggle.key]" active-value="yes" inactive-value="no" />
          </div>
          <div class="bmc-sr">
            <div>
              <span class="bmc-sr__label">Max supporters to display</span>
              <span class="bmc-sr__hint">Maximum number of supporters shown on the public wall</span>
            </div>
            <el-input-number v-model="displaySettings.max_supporters" :min="1" :max="100" size="small" />
          </div>
        </div>

        <!-- Privacy Settings -->
        <div class="bmc-sc mb-5">
          <div class="bmc-sc__header">
            <div class="bmc-sc__icon bmc-sc__icon--teal"><Shield :size="18" /></div>
            <div>
              <h3 class="bmc-sc__title">Privacy</h3>
              <p class="bmc-sc__desc">Control supporter privacy and data visibility</p>
            </div>
          </div>
          <div class="bmc-sr" v-for="toggle in privacyToggles" :key="toggle.key">
            <div>
              <span class="bmc-sr__label">{{ toggle.label }}</span>
              <span class="bmc-sr__hint">{{ toggle.hint }}</span>
            </div>
            <el-switch v-model="displaySettings[toggle.key]" active-value="yes" inactive-value="no" />
          </div>
        </div>

        <!-- Save Button -->
        <div class="flex justify-end">
          <button class="bmc-save-btn" :disabled="saving" @click="saveSettings">
            {{ saving ? 'Saving...' : 'Save Settings' }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import {
  Search, ChevronLeft, ChevronRight, RefreshCw, Eye,
  Users, Trophy, Settings2, Code2, Copy, Shield,
} from 'lucide-vue-next';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import PageTitle from './UI/PageTitle.vue';
import MetricCard from './UI/MetricCard.vue';
import EmptyState from './UI/EmptyState.vue';

export default {
  name: 'Supporters',
  components: {
    Search, ChevronLeft, ChevronRight, RefreshCw, Eye,
    Users, Trophy, Settings2, Code2, Copy, Shield,
    CoffeeLoader, PageTitle, MetricCard, EmptyState,
  },
  data() {
    return {
      loading: true,
      saving: false,
      activeTab: 'all',
      search: '',
      filter: 'all',
      current: 0,
      total: 0,
      lastPage: 1,
      postsPerPage: 12,
      supporters: [],
      topSupporters: [],
      stats: {
        total_supporters: 0,
        lifetime_revenue: '$0.00',
        active_subscribers: 0,
        avg_donation: '$0.00',
      },
      displaySettings: {
        show_name: 'yes',
        show_avatar: 'yes',
        show_amount: 'no',
        show_message: 'yes',
        max_supporters: 20,
        hide_email: 'yes',
        allow_anonymous: 'yes',
      },
      tabs: [
        { key: 'all', label: 'All Supporters', icon: Users },
        { key: 'top', label: 'Top Supporters', icon: Trophy },
        { key: 'settings', label: 'Shortcodes & Settings', icon: Settings2 },
      ],
      filters: [
        { label: 'All', value: 'all' },
        { label: 'Subscribers', value: 'subscribers' },
        { label: 'One-time', value: 'one-time' },
      ],
      displayToggles: [
        { key: 'show_name', label: 'Show supporter name', hint: 'Display the supporter\'s name on the public wall' },
        { key: 'show_avatar', label: 'Show avatar', hint: 'Display Gravatar profile picture' },
        { key: 'show_amount', label: 'Show donation amount', hint: 'Display how much each person donated' },
        { key: 'show_message', label: 'Show message', hint: 'Display the supporter\'s message' },
      ],
      privacyToggles: [
        { key: 'hide_email', label: 'Hide email from public display', hint: 'Never show supporter emails on the frontend' },
        { key: 'allow_anonymous', label: 'Allow anonymous donations', hint: 'Let supporters donate without providing a name' },
      ],
      shortcodes: [
        { code: '[buymecoffee_supporters]', label: 'Supporters Wall', description: 'Display a public wall of your supporters. Supports limit and show_amount attributes.' },
        { code: '[buymecoffee_account]', label: 'Subscriber Account', description: 'Dashboard for logged-in subscribers to view their subscriptions and payment history.' },
      ],
    };
  },
  computed: {
    visiblePages() {
      const total = Math.max(1, Number(this.lastPage) || 1);
      const current = Number(this.current) + 1;
      const pages = [];
      let start = Math.max(1, current - 2);
      let end = Math.min(total, start + 4);

      if (end - start < 4) {
        start = Math.max(1, end - 4);
      }

      for (let page = start; page <= end; page++) {
        pages.push(page);
      }

      return pages;
    },
  },
  methods: {
    ajax(route, data = {}) {
      return this.$get({
        action: 'buymecoffee_admin_ajax',
        route,
        data,
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      });
    },
    loadAll() {
      this.loading = true;
      let loaded = 0;
      const done = () => { loaded++; if (loaded >= 4) this.loading = false; };

      this.ajax('get_supporter_stats').then(r => { this.stats = r?.data || this.stats; }).always(done);
      this.ajax('get_top_supporters').then(r => { this.topSupporters = r?.data?.supporters || []; }).always(done);
      this.ajax('get_supporter_settings').then(r => { if (r?.data) this.displaySettings = { ...this.displaySettings, ...r.data }; }).always(done);
      this.getSupporters(done);
    },
    getSupporters(cb) {
      this.ajax('get_supporters_list', {
        page: this.current,
        posts_per_page: this.postsPerPage,
        search: this.search,
        filter: this.filter,
      }).then(r => {
        this.supporters = r?.data?.supporters || [];
        this.total = r?.data?.total || 0;
        this.lastPage = Math.ceil(this.total / this.postsPerPage);
      }).fail(e => this.$handleError(e)).always(() => { if (cb) cb(); });
    },
    saveSettings() {
      this.saving = true;
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'save_supporter_settings',
        data: this.displaySettings,
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then(() => {
        this.$handleSuccess('Settings saved');
      }).fail(e => this.$handleError(e)).always(() => { this.saving = false; });
    },
    viewSupporter(supporter) {
      this.$router.push({ name: 'Supporter', params: { id: supporter.latest_entry_id } });
    },
    getInitials(name) {
      if (!name) return '?';
      return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
    },
    formatDate(dateStr) {
      if (!dateStr) return '--';
      return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    },
    copyShortcode(code) {
      navigator.clipboard.writeText(code).then(() => {
        this.$handleSuccess('Copied to clipboard');
      });
    },
  },
  mounted() {
    this.loadAll();
  },
};
</script>

<style scoped>
.bmc-supporters-page {
  max-width: 1440px;
}

/* ── Metrics grid ── */
.bmc-metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}
@media (max-width: 1024px) { .bmc-metrics-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px) { .bmc-metrics-grid { grid-template-columns: 1fr; } }

/* ── Tabs ── */
.bmc-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 2px solid var(--border-secondary);
  padding-bottom: 0;
}
.bmc-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 16px;
  font-size: 13px;
  font-weight: 500;
  color: var(--text-secondary);
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  cursor: pointer;
  transition: all 0.15s;
}
.bmc-tab:hover { color: var(--text-primary); }
.bmc-tab--active {
  color: var(--color-primary-600);
  border-bottom-color: var(--color-primary-600);
}

/* ── Directory panel ── */
.bmc-supporters-panel {
  background: var(--bg-primary);
  border: 1px solid var(--border-secondary);
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.bmc-supporters-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px;
  border-bottom: 1px solid var(--border-secondary);
}

.bmc-supporters-search {
  position: relative;
  min-width: 260px;
}

.bmc-supporters-search__icon {
  position: absolute;
  left: 11px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-tertiary);
  pointer-events: none;
}

.bmc-filter-group {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.bmc-supporters-count {
  margin-left: auto;
  color: var(--text-tertiary);
  font-size: 13px;
  white-space: nowrap;
}

/* ── Search + Filter ── */
.bmc-search-input {
  padding: 8px 12px 8px 36px;
  border: 1px solid var(--border-primary);
  border-radius: 8px;
  font-size: 13px;
  background: var(--bg-primary);
  color: var(--text-primary);
  outline: none;
  width: 240px;
  transition: border-color 0.15s;
}
.bmc-search-input:focus { border-color: var(--color-primary-500); }

.bmc-filter-pill {
  padding: 5px 14px;
  font-size: 12px;
  font-weight: 500;
  border: 1px solid var(--border-primary);
  border-radius: 100px;
  background: var(--bg-primary);
  color: var(--text-secondary);
  cursor: pointer;
  transition: all 0.15s;
}
.bmc-filter-pill:hover { border-color: var(--color-primary-300); color: var(--text-primary); }
.bmc-filter-pill--active {
  background: var(--color-primary-600);
  border-color: var(--color-primary-600);
  color: #fff;
}

/* ── Supporter directory ── */
.bmc-supporters-list {
  display: flex;
  flex-direction: column;
}

.bmc-supporters-list__head,
.bmc-supporter-row {
  display: grid;
  grid-template-columns: minmax(280px, 1.7fr) minmax(120px, 0.7fr) minmax(100px, 0.55fr) minmax(120px, 0.65fr) minmax(150px, auto);
  align-items: center;
  column-gap: 18px;
}

.bmc-supporters-list__head {
  padding: 10px 16px;
  background: var(--bg-secondary);
  border-bottom: 1px solid var(--border-secondary);
  color: var(--text-tertiary);
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.bmc-supporter-row {
  min-height: 76px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--border-secondary);
  cursor: pointer;
  transition: background 0.15s;
}

.bmc-supporter-row:last-child {
  border-bottom: none;
}

.bmc-supporter-row:hover {
  background: var(--bg-secondary);
}

.bmc-supporter-row__person {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.bmc-supporter-row__avatar {
  width: 42px;
  height: 42px;
  border-radius: 999px;
  object-fit: cover;
  border: 2px solid var(--border-secondary);
  flex-shrink: 0;
}

.bmc-supporter-row__avatar--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
  text-transform: uppercase;
  background: var(--color-primary-50); color: var(--color-primary-600);
}

.bmc-supporter-row__identity {
  min-width: 0;
}

.bmc-supporter-row__name-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.bmc-supporter-row__name {
  margin: 0;
  color: var(--text-primary);
  font-size: 14px;
  font-weight: 700;
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.bmc-supporter-row__email {
  margin: 2px 0 0;
  color: var(--text-tertiary);
  font-size: 12px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.bmc-supporter-row__sub-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 22px;
  padding: 0 8px;
  border-radius: 999px;
  background: #dcfce7;
  color: #166534;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
}

.bmc-supporter-row__metric {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.bmc-supporter-row__metric-label {
  display: none;
  color: var(--text-tertiary);
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.bmc-supporter-row__metric-value {
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 700;
}

.bmc-supporter-row__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.bmc-supporter-row__action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  min-height: 32px;
  padding: 0 10px;
  border: 1px solid var(--border-primary);
  border-radius: 7px;
  background: var(--bg-primary);
  color: var(--text-secondary);
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.bmc-supporter-row__action:hover {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}

/* ── Top Supporters ── */
.bmc-top-list { display: flex; flex-direction: column; gap: 8px; max-width: 700px; }
.bmc-top-item {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 18px; border-radius: 12px; cursor: pointer;
  background: var(--bg-primary); border: 1px solid var(--border-primary);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.bmc-top-item:hover { border-color: var(--color-primary-300); box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.bmc-top-item__rank {
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; border-radius: 8px; flex-shrink: 0;
  background: var(--bg-tertiary); color: var(--text-secondary);
}
.bmc-top-item--gold .bmc-top-item__rank { background: #fef3c7; color: #92400e; }
.bmc-top-item--silver .bmc-top-item__rank { background: #f1f5f9; color: #475569; }
.bmc-top-item--bronze .bmc-top-item__rank { background: #fed7aa; color: #9a3412; }
.bmc-top-item__avatar {
  width: 38px; height: 38px; border-radius: 50%; object-fit: cover;
  border: 2px solid var(--border-secondary); flex-shrink: 0;
}
.bmc-top-item__avatar-placeholder {
  width: 38px; height: 38px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; text-transform: uppercase; flex-shrink: 0;
  background: var(--color-primary-50); color: var(--color-primary-600);
}
.bmc-top-item__info { flex: 1; min-width: 0; }
.bmc-top-item__name { display: block; font-size: 14px; font-weight: 600; color: var(--text-primary); }
.bmc-top-item__meta { font-size: 12px; color: var(--text-tertiary); }
.bmc-top-item__amount { font-size: 15px; font-weight: 700; color: var(--color-primary-600); white-space: nowrap; }

/* ── Settings Sections ── */
.bmc-sc {
  background: var(--bg-primary); border: 1px solid var(--border-primary);
  border-radius: 14px; overflow: hidden;
}
.bmc-sc__header {
  display: flex; align-items: center; gap: 14px;
  padding: 18px 20px; border-bottom: 1px solid var(--border-secondary);
}
.bmc-sc__icon {
  width: 38px; height: 38px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.bmc-sc__icon--purple { background: #f3e8ff; color: #7c3aed; }
.bmc-sc__icon--blue { background: #dbeafe; color: #2563eb; }
.bmc-sc__icon--teal { background: #ccfbf1; color: #0d9488; }
.bmc-sc__title { font-size: 15px; font-weight: 600; margin: 0; color: var(--text-primary); }
.bmc-sc__desc { font-size: 12px; margin: 2px 0 0; color: var(--text-tertiary); }

.bmc-sr {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding: 14px 20px; border-bottom: 1px solid var(--border-secondary);
}
.bmc-sr:last-child { border-bottom: none; }
.bmc-sr__label { display: block; font-size: 13px; font-weight: 500; color: var(--text-primary); }
.bmc-sr__hint { display: block; font-size: 11px; color: var(--text-tertiary); margin-top: 2px; }

/* ── Shortcode list ── */
.bmc-shortcode-list { padding: 0; }
.bmc-shortcode-item {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding: 14px 20px; border-bottom: 1px solid var(--border-secondary);
}
.bmc-shortcode-item:last-child { border-bottom: none; }
.bmc-shortcode-item__title { font-size: 13px; font-weight: 600; margin: 0; color: var(--text-primary); }
.bmc-shortcode-item__desc { font-size: 12px; margin: 3px 0 0; color: var(--text-tertiary); max-width: 400px; }
.bmc-shortcode-item__code {
  display: flex; align-items: center; gap: 6px;
  padding: 5px 10px; border-radius: 6px; flex-shrink: 0;
  background: var(--bg-tertiary); font-family: var(--font-mono, monospace);
}
.bmc-shortcode-item__code code { font-size: 12px; color: var(--color-primary-600); }
.bmc-shortcode-item__copy {
  background: none; border: none; cursor: pointer;
  color: var(--text-tertiary); padding: 2px; transition: color 0.15s;
}
.bmc-shortcode-item__copy:hover { color: var(--text-primary); }

/* ── Pagination ── */
.bmc-page-btn {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 32px; height: 32px; padding: 0 8px;
  border: 1px solid var(--border-primary); border-radius: 6px;
  background: var(--bg-primary); color: var(--text-secondary);
  font-size: 13px; cursor: pointer; transition: all 0.15s;
}
.bmc-page-btn:hover:not(:disabled) { background: var(--bg-hover); color: var(--text-primary); }
.bmc-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.bmc-page-btn--active { background: var(--color-primary-600); color: #fff; border-color: var(--color-primary-600); }

/* ── Save button ── */
.bmc-save-btn {
  padding: 8px 20px; font-size: 13px; font-weight: 600;
  border: none; border-radius: 8px; cursor: pointer;
  background: var(--color-primary-600); color: #fff;
  transition: background 0.15s;
}
.bmc-save-btn:hover:not(:disabled) { background: var(--color-primary-700); }
.bmc-save-btn:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 1180px) {
  .bmc-supporters-toolbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .bmc-supporters-search {
    width: 100%;
  }

  .bmc-search-input {
    width: 100%;
  }

  .bmc-supporters-count {
    margin-left: 0;
  }

  .bmc-supporters-list__head {
    display: none;
  }

  .bmc-supporter-row {
    grid-template-columns: minmax(0, 1fr) auto;
    row-gap: 12px;
  }

  .bmc-supporter-row__person {
    grid-column: 1 / -1;
  }

  .bmc-supporter-row__metric {
    min-width: 90px;
  }

  .bmc-supporter-row__metric-label {
    display: block;
  }

  .bmc-supporter-row__actions {
    grid-column: 1 / -1;
    justify-content: flex-start;
  }
}

@media (max-width: 640px) {
  .bmc-tabs {
    overflow-x: auto;
  }

  .bmc-tab {
    white-space: nowrap;
  }

  .bmc-supporter-row {
    grid-template-columns: 1fr;
  }

  .bmc-supporter-row__metric {
    display: grid;
    grid-template-columns: 100px minmax(0, 1fr);
    align-items: center;
  }

  .bmc-supporter-row__actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }

  .bmc-shortcode-item,
  .bmc-sr {
    align-items: flex-start;
    flex-direction: column;
  }

  .bmc-shortcode-item__code {
    width: 100%;
    justify-content: space-between;
  }
}
</style>
