<template>
  <div class="relative min-h-[200px]">
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
        <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-4 mb-5">
          <div class="flex flex-wrap items-center gap-3">
            <div class="relative">
              <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2" style="color: var(--text-tertiary)" />
              <input
                v-model="search"
                type="text"
                placeholder="Search supporters..."
                class="bmc-search-input"
                @keyup.enter="current = 0; getSupporters()"
              />
            </div>
            <div class="flex gap-2">
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
            <span class="ml-auto text-sm" style="color: var(--text-tertiary)">
              {{ total }} supporter{{ total !== 1 ? 's' : '' }}
            </span>
          </div>
        </div>

        <div v-if="supporters.length" class="bmc-supporters-grid">
          <div
            v-for="supporter in supporters"
            :key="supporter.supporters_email || supporter.latest_entry_id"
            class="bmc-supporter-card"
            @click="viewSupporter(supporter)"
          >
            <div class="bmc-supporter-card__top">
              <img v-if="supporter.avatar" :src="supporter.avatar" class="bmc-supporter-card__avatar" />
              <div v-else class="bmc-supporter-card__avatar-placeholder">
                {{ getInitials(supporter.supporters_name) }}
              </div>
              <div class="bmc-supporter-card__info">
                <h3 class="bmc-supporter-card__name">{{ supporter.supporters_name || 'Anonymous' }}</h3>
                <p v-if="supporter.supporters_email" class="bmc-supporter-card__email">{{ supporter.supporters_email }}</p>
              </div>
              <span v-if="supporter.has_subscription" class="bmc-supporter-card__sub-badge" title="Active subscriber">
                <RefreshCw :size="11" />
              </span>
            </div>
            <div class="bmc-supporter-card__stats">
              <div class="bmc-supporter-card__stat">
                <span class="bmc-supporter-card__stat-value" v-html="supporter.total_formatted"></span>
                <span class="bmc-supporter-card__stat-label">Lifetime</span>
              </div>
              <div class="bmc-supporter-card__stat">
                <span class="bmc-supporter-card__stat-value">{{ supporter.donation_count }}</span>
                <span class="bmc-supporter-card__stat-label">Payments</span>
              </div>
              <div class="bmc-supporter-card__stat">
                <span class="bmc-supporter-card__stat-value">{{ formatDate(supporter.last_donation_date) }}</span>
                <span class="bmc-supporter-card__stat-label">Last</span>
              </div>
            </div>
            <div class="bmc-supporter-card__actions">
              <button class="bmc-supporter-card__action" title="View details" @click.stop="viewSupporter(supporter)">
                <Eye :size="14" /> View
              </button>
              <a
                v-if="supporter.supporters_email"
                :href="'mailto:' + supporter.supporters_email"
                class="bmc-supporter-card__action"
                title="Send email"
                @click.stop
              >
                <Mail :size="14" /> Email
              </a>
            </div>
          </div>
        </div>

        <EmptyState
          v-if="!supporters.length"
          title="No supporters yet"
          description="Supporters will appear here once someone makes a contribution."
          icon="Heart"
        />

        <div v-if="lastPage > 1" class="flex items-center justify-center gap-2 mt-6">
          <button class="bmc-page-btn" :disabled="current <= 0" @click="current--; getSupporters()">
            <ChevronLeft :size="16" />
          </button>
          <button
            v-for="page in lastPage"
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
  Search, ChevronLeft, ChevronRight, RefreshCw, Eye, Mail,
  Users, Trophy, Settings2, Code2, Copy, Shield,
} from 'lucide-vue-next';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import PageTitle from './UI/PageTitle.vue';
import MetricCard from './UI/MetricCard.vue';
import EmptyState from './UI/EmptyState.vue';

export default {
  name: 'Supporters',
  components: {
    Search, ChevronLeft, ChevronRight, RefreshCw, Eye, Mail,
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

/* ── Supporter Cards Grid ── */
.bmc-supporters-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
  gap: 14px;
}

.bmc-supporter-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  border-radius: 14px;
  padding: 18px;
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.bmc-supporter-card:hover {
  border-color: var(--color-primary-300);
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
}

.bmc-supporter-card__top { display: flex; align-items: center; gap: 12px; }
.bmc-supporter-card__avatar {
  width: 42px; height: 42px; border-radius: 50%; object-fit: cover;
  border: 2px solid var(--border-secondary); flex-shrink: 0;
}
.bmc-supporter-card__avatar-placeholder {
  width: 42px; height: 42px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700; text-transform: uppercase; flex-shrink: 0;
  background: var(--color-primary-50); color: var(--color-primary-600);
  border: 2px solid var(--border-secondary);
}
.bmc-supporter-card__info { min-width: 0; flex: 1; }
.bmc-supporter-card__name {
  font-size: 14px; font-weight: 600; margin: 0; color: var(--text-primary);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bmc-supporter-card__email {
  font-size: 11px; margin: 2px 0 0; color: var(--text-tertiary);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bmc-supporter-card__sub-badge {
  display: flex; align-items: center; justify-content: center;
  width: 26px; height: 26px; border-radius: 50%;
  background: #dcfce7; color: #166534; flex-shrink: 0;
}

.bmc-supporter-card__stats {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  padding-top: 12px; border-top: 1px solid var(--border-secondary);
}
.bmc-supporter-card__stat { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.bmc-supporter-card__stat-value { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.bmc-supporter-card__stat-label { font-size: 10px; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.04em; }

.bmc-supporter-card__actions {
  display: flex; gap: 8px; padding-top: 10px; border-top: 1px solid var(--border-secondary);
}
.bmc-supporter-card__action {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 10px; font-size: 12px; font-weight: 500;
  border: 1px solid var(--border-primary); border-radius: 6px;
  background: var(--bg-primary); color: var(--text-secondary);
  cursor: pointer; text-decoration: none; transition: all 0.15s;
}
.bmc-supporter-card__action:hover { background: var(--bg-tertiary); color: var(--text-primary); }

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
</style>
