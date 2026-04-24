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
                    <li v-for="item in configItems" :key="item.route">
                        <router-link :to="item.route" class="bmc-sidebar__item" :class="{ 'bmc-sidebar__item--active': isActive(item) }">
                            <component :is="item.icon" :size="20" class="bmc-sidebar__icon" />
                            <span v-show="!isCollapsed" class="bmc-sidebar__label">{{ item.label }}</span>
                        </router-link>
                    </li>
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
    LayoutDashboard, Heart, Settings, CreditCard, Bell,
    ExternalLink, Sparkles, ArrowLeft, ChevronsLeft, ChevronsRight,
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
    { label: 'Dashboard', route: '/', icon: LayoutDashboard, activeNames: ['Dashboard'] },
    { label: 'Supporters', route: '/supporters', icon: Heart, activeNames: ['Supporters', 'Supporter'] },
];

const configItems = [
    { label: 'Settings', route: '/settings', icon: Settings, activeNames: ['Settings'] },
    { label: 'Gateways', route: '/gateway', icon: CreditCard, activeNames: ['Gateway', 'stripe', 'paypal'] },
    { label: 'Notifications', route: '/notifications', icon: Bell, activeNames: ['Notifications', 'Emails', 'Webhook'] },
];

function isActive(item) {
    return item.activeNames?.includes(route.name) || false;
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
    width: 240px;
    min-width: 240px;
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
    font-size: 16px;
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

.bmc-sidebar__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 8px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 14px;
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
    white-space: nowrap;
    overflow: hidden;
}

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

@media (max-width: 768px) {
    .bmc-sidebar { width: 60px; min-width: 60px; }
}
</style>
