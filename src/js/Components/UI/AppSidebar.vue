<template>
    <aside class="bmc-sidebar" :class="{ 'bmc-sidebar--collapsed': isCollapsed }">
        <!-- Brand -->
        <div class="bmc-sidebar__brand">
            <div class="bmc-sidebar__logo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/>
                </svg>
            </div>
            <span v-show="!isCollapsed" class="bmc-sidebar__brand-text">Buy Me Coffee</span>
        </div>

        <!-- Navigation -->
        <nav class="bmc-sidebar__nav" aria-label="Main navigation">
            <div class="bmc-sidebar__section">
                <span v-show="!isCollapsed" class="bmc-sidebar__section-label">Main</span>
                <ul class="bmc-sidebar__list" role="list">
                    <li v-for="item in mainItems" :key="item.route">
                        <router-link :to="item.route" class="bmc-sidebar__item" :class="{ 'bmc-sidebar__item--active': isActive(item) }">
                            <component :is="item.icon" :size="20" class="bmc-sidebar__icon" />
                            <span v-show="!isCollapsed" class="bmc-sidebar__label">{{ item.label }}</span>
                        </router-link>
                    </li>
                </ul>
            </div>

            <div class="bmc-sidebar__section">
                <span v-show="!isCollapsed" class="bmc-sidebar__section-label">Configuration</span>
                <ul class="bmc-sidebar__list" role="list">
                    <template v-for="item in configItems" :key="item.route">
                        <!-- Parent item -->
                        <li>
                            <router-link
                                :to="item.route"
                                class="bmc-sidebar__item"
                                :class="{ 'bmc-sidebar__item--active': isActive(item) && !item.children }"
                            >
                                <component :is="item.icon" :size="20" class="bmc-sidebar__icon" />
                                <span v-show="!isCollapsed" class="bmc-sidebar__label">{{ item.label }}</span>
                                <component
                                    v-if="item.children && !isCollapsed"
                                    :is="isActive(item) ? ChevronDown : ChevronRight"
                                    :size="14"
                                    class="bmc-sidebar__chevron"
                                />
                            </router-link>
                        </li>

                        <!-- Sub-items (only when parent is active and not collapsed) -->
                        <template v-if="item.children && isActive(item) && !isCollapsed">
                            <li v-for="child in item.children" :key="child.label">
                                <router-link
                                    :to="{ path: item.route, query: child.query }"
                                    class="bmc-sidebar__subitem"
                                    :class="{ 'bmc-sidebar__subitem--active': isSubActive(child) }"
                                >
                                    <component :is="child.icon" :size="15" class="bmc-sidebar__subicon" />
                                    {{ child.label }}
                                </router-link>
                            </li>
                        </template>
                    </template>
                </ul>
            </div>

            <!-- Bottom actions -->
            <div class="bmc-sidebar__section bmc-sidebar__section--bottom">
                <ul class="bmc-sidebar__list" role="list">
                    <li>
                        <a :href="previewUrl" target="_blank" rel="noopener" class="bmc-sidebar__item">
                            <ExternalLink :size="20" class="bmc-sidebar__icon" />
                            <span v-show="!isCollapsed" class="bmc-sidebar__label">Preview Page</span>
                        </a>
                    </li>
                    <li>
                        <router-link to="/quick-setup" class="bmc-sidebar__item" :class="{ 'bmc-sidebar__item--active': isActive({ activeNames: ['Onboarding'] }) }">
                            <Sparkles :size="20" class="bmc-sidebar__icon" />
                            <span v-show="!isCollapsed" class="bmc-sidebar__label">Quick Setup</span>
                        </router-link>
                    </li>
                    <li v-if="!isWpAdmin">
                        <a :href="wpAdminUrl" class="bmc-sidebar__item bmc-sidebar__item--muted">
                            <ArrowLeft :size="20" class="bmc-sidebar__icon" />
                            <span v-show="!isCollapsed" class="bmc-sidebar__label">WP Admin</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Collapse toggle -->
        <button class="bmc-sidebar__toggle" @click="toggleCollapse" :title="isCollapsed ? 'Expand' : 'Collapse'">
            <component :is="isCollapsed ? ChevronsRight : ChevronsLeft" :size="16" />
        </button>
    </aside>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
    LayoutDashboard, Heart, Settings, Palette, Code2,
    CreditCard, Bell, ExternalLink, Sparkles, ArrowLeft,
    ChevronsLeft, ChevronsRight, ChevronDown, ChevronRight,
    RefreshCw,
} from 'lucide-vue-next';

const COLLAPSE_KEY = '__buymecoffee_sidebar_collapsed';

const props = defineProps({ collapsed: { type: Boolean, default: false } });
const emit = defineEmits(['update:collapsed']);

const route = useRoute();
const isCollapsed = ref(props.collapsed);

watch(isCollapsed, (val) => emit('update:collapsed', val));

const previewUrl = computed(() => window.BuyMeCoffeeAdmin?.preview_url || '#');
const wpAdminUrl = computed(() => (window.BuyMeCoffeeAdmin?.wp_admin_url || '/wp-admin/') + 'admin.php?page=buy-me-coffee.php');
const isWpAdmin = computed(() => !!window.BuyMeCoffeeAdmin?.is_wp_admin);

const mainItems = [
    { label: 'Dashboard',     route: '/',             icon: LayoutDashboard, activeNames: ['Dashboard'] },
    { label: 'Supporters',    route: '/supporters',   icon: Heart,           activeNames: ['Supporters', 'Supporter'] },
    { label: 'Subscriptions', route: '/subscriptions', icon: RefreshCw,      activeNames: ['Subscriptions', 'SubscriptionDetail'] },
];

const configItems = [
    {
        label: 'Settings',
        route: '/settings',
        icon: Settings,
        activeNames: ['Settings'],
        children: [
            { label: 'General',    icon: Settings, query: { tab: 'general' } },
            { label: 'Appearance', icon: Palette,  query: { tab: 'appearance' } },
            { label: 'Shortcodes', icon: Code2,    query: { tab: 'shortcodes' } },
        ],
    },
    { label: 'Gateways',      route: '/gateway',       icon: CreditCard, activeNames: ['Gateway', 'stripe', 'paypal'] },
    { label: 'Notifications', route: '/notifications', icon: Bell,       activeNames: ['Notifications', 'Emails', 'Webhook'] },
];

function isActive(item) {
    return item.activeNames?.includes(route.name) || false;
}

function isSubActive(child) {
    const currentTab = route.query.tab || 'general';
    return currentTab === child.query.tab;
}

function toggleCollapse() {
    isCollapsed.value = !isCollapsed.value;
    localStorage.setItem(COLLAPSE_KEY, String(isCollapsed.value));
}

onMounted(() => {
    const stored = localStorage.getItem(COLLAPSE_KEY);
    if (stored !== null) isCollapsed.value = stored === 'true';
});
</script>

<style scoped>
.bmc-sidebar {
    width: 220px;
    min-width: 220px;
    height: 100vh;
    background: var(--bg-primary);
    border-right: 1px solid var(--border-primary);
    display: flex;
    flex-direction: column;
    transition: width 0.2s ease, min-width 0.2s ease;
    overflow: hidden;
}
.bmc-sidebar--collapsed {
    width: 60px;
    min-width: 60px;
}

.bmc-sidebar__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 18px 16px;
    border-bottom: 1px solid var(--border-secondary);
}
.bmc-sidebar__logo {
    flex-shrink: 0;
    color: var(--color-primary-500);
}
.bmc-sidebar__brand-text {
    font-family: var(--font-sans);
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}

.bmc-sidebar__nav {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 10px 8px;
    gap: 2px;
    overflow-y: auto;
}
.bmc-sidebar__section { margin-bottom: 14px; }
.bmc-sidebar__section--bottom {
    margin-top: auto;
    margin-bottom: 0;
    padding-top: 10px;
    border-top: 1px solid var(--border-secondary);
}
.bmc-sidebar__section-label {
    display: block;
    padding: 4px 12px 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
    white-space: nowrap;
}
.bmc-sidebar__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

/* ── Parent items ── */
.bmc-sidebar__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 8px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 500;
    font-family: var(--font-sans);
    transition: background 0.15s ease, color 0.15s ease;
    cursor: pointer;
    white-space: nowrap;
}
.bmc-sidebar__item:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
}
.bmc-sidebar__item--active {
    background: var(--color-primary-50);
    color: var(--color-primary-700);
}
.bmc-sidebar__item--active .bmc-sidebar__icon {
    color: var(--color-primary-500);
}
.bmc-sidebar__item--muted {
    color: var(--text-tertiary);
    font-size: 13px;
}

.bmc-sidebar__icon {
    flex-shrink: 0;
    color: var(--text-tertiary);
    transition: color 0.15s ease;
}
.bmc-sidebar__item:hover .bmc-sidebar__icon {
    color: var(--text-secondary);
}
.bmc-sidebar__label {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
}
.bmc-sidebar__chevron {
    flex-shrink: 0;
    color: var(--text-tertiary);
    margin-left: auto;
}

/* ── Sub-items ── */
.bmc-sidebar__subitem {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px 6px 38px;
    border-radius: 7px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 400;
    font-family: var(--font-sans);
    transition: background 0.15s ease, color 0.15s ease;
    cursor: pointer;
    white-space: nowrap;
    position: relative;
}

/* vertical guide line */
.bmc-sidebar__subitem::before {
    content: '';
    position: absolute;
    left: 21px;
    top: 50%;
    transform: translateY(-50%);
    width: 5px;
    height: 1px;
    background: var(--border-primary);
}

.bmc-sidebar__subitem:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
}
.bmc-sidebar__subitem--active {
    color: var(--color-primary-700);
    font-weight: 500;
}
.bmc-sidebar__subitem--active::before {
    background: var(--color-primary-500);
}

.bmc-sidebar__subicon {
    flex-shrink: 0;
    color: var(--text-tertiary);
}
.bmc-sidebar__subitem--active .bmc-sidebar__subicon,
.bmc-sidebar__subitem:hover .bmc-sidebar__subicon {
    color: var(--color-primary-500);
}

/* ── Collapse toggle ── */
.bmc-sidebar__toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 8px;
    padding: 8px;
    border: 1px solid var(--border-primary);
    border-radius: 8px;
    background: var(--bg-primary);
    color: var(--text-tertiary);
    cursor: pointer;
    transition: all 0.15s ease;
}
.bmc-sidebar__toggle:hover {
    background: var(--bg-hover);
    color: var(--text-secondary);
}

:global(html.dark) .bmc-sidebar__item--active {
    background: rgba(13, 148, 136, 0.15);
    color: var(--color-primary-400);
}
:global(html.dark) .bmc-sidebar__subitem--active {
    color: var(--color-primary-400);
}

@media (max-width: 768px) {
    .bmc-sidebar { width: 60px; min-width: 60px; }
}
</style>
