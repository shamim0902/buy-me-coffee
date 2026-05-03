<template>
    <aside class="bmc-sidebar" :class="{ 'bmc-sidebar--collapsed': isCollapsed }">
        <!-- Brand -->
        <div class="bmc-sidebar__brand">
            <div class="bmc-sidebar__logo">
                <Coffee :size="14" />
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
                            <component :is="item.icon" :size="16" class="bmc-sidebar__icon" />
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
                                <component :is="item.icon" :size="16" class="bmc-sidebar__icon" />
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
                            <ExternalLink :size="14" class="bmc-sidebar__icon" />
                            <span v-show="!isCollapsed" class="bmc-sidebar__label">Preview Page</span>
                        </a>
                    </li>
                    <li v-if="showQuickSetup">
                        <router-link to="/quick-setup" class="bmc-sidebar__item" :class="{ 'bmc-sidebar__item--active': isActive({ activeNames: ['Onboarding'] }) }">
                            <Sparkles :size="14" class="bmc-sidebar__icon bmc-sidebar__icon--sparkle" />
                            <span v-show="!isCollapsed" class="bmc-sidebar__label">Quick Setup</span>
                        </router-link>
                    </li>
                    <li v-if="!isWpAdmin">
                        <a :href="wpAdminUrl" class="bmc-sidebar__item bmc-sidebar__item--muted">
                            <ArrowLeft :size="14" class="bmc-sidebar__icon" />
                            <span v-show="!isCollapsed" class="bmc-sidebar__label">WP Admin</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Utility icons row -->
        <div class="bmc-sidebar__utils" v-show="!isCollapsed">
            <button class="bmc-sidebar__util-btn" @click="toggleTheme" :title="isDark ? 'Light mode' : 'Dark mode'">
                <Moon v-if="!isDark" :size="14" />
                <Sun v-else :size="14" />
            </button>
            <a v-if="isWpAdmin" :href="fullPageUrl" class="bmc-sidebar__util-btn" title="Open full-screen dashboard">
                <Maximize2 :size="14" />
            </a>
        </div>

        <!-- Collapse toggle -->
        <button class="bmc-sidebar__toggle" @click="toggleCollapse" :title="isCollapsed ? 'Expand' : 'Collapse'">
            <component :is="isCollapsed ? ChevronsRight : ChevronsLeft" :size="16" />
        </button>
    </aside>
</template>

<script setup>
import { ref, computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
    ExternalLink, Sparkles, ArrowLeft,
    ChevronsLeft, ChevronsRight, ChevronDown, ChevronRight,
    Coffee,
    Moon, Sun, Maximize2,
} from 'lucide-vue-next';
import { useTheme } from '../../composables/useTheme';
import { configItems, mainItems } from './navigation';

const COLLAPSE_KEY = '__buymecoffee_sidebar_collapsed';

const props = defineProps({ collapsed: { type: Boolean, default: false } });
const emit = defineEmits(['update:collapsed']);

const route = useRoute();
const isCollapsed = ref(props.collapsed);
const quickSetupDismissed = ref(false);
const { isDark, toggleTheme } = useTheme();

watch(isCollapsed, (val) => emit('update:collapsed', val));

const previewUrl = computed(() => window.BuyMeCoffeeAdmin?.preview_url || '#');
const wpAdminUrl = computed(() => (window.BuyMeCoffeeAdmin?.wp_admin_url || '/wp-admin/') + 'admin.php?page=buy-me-coffee.php');
const isWpAdmin = computed(() => !!window.BuyMeCoffeeAdmin?.is_wp_admin);
const showQuickSetup = computed(() => !window.BuyMeCoffeeAdmin?.setup_completed && !quickSetupDismissed.value);
const fullPageUrl = computed(() => {
    const base = window.location.origin;
    return base + '/?buymecoffee_admin';
});

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

function hasQuickSetupDismissed() {
    try {
        const stored = JSON.parse(localStorage.getItem('__buymecoffee_data') || '{}');
        return !!stored.buymecoffee_guided_tour;
    } catch (error) {
        return false;
    }
}

function syncQuickSetupState(event) {
    if (!event || event.detail?.key === 'buymecoffee_guided_tour') {
        quickSetupDismissed.value = hasQuickSetupDismissed();
    }
}

onMounted(() => {
    const stored = localStorage.getItem(COLLAPSE_KEY);
    if (stored !== null) isCollapsed.value = stored === 'true';
    quickSetupDismissed.value = hasQuickSetupDismissed();
    window.addEventListener('buymecoffee:data-saved', syncQuickSetupState);
});

onBeforeUnmount(() => {
    window.removeEventListener('buymecoffee:data-saved', syncQuickSetupState);
});
</script>

<style scoped>
.bmc-sidebar {
    width: 210px;
    min-width: 210px;
    height: 100vh;
    background: var(--bg-sidebar);
    border-right: 1px solid var(--sidebar-border);
    display: flex;
    flex-direction: column;
    transition: width 0.2s ease, min-width 0.2s ease;
    overflow: hidden;
}
.bmc-sidebar--collapsed {
    width: 52px;
    min-width: 52px;
}

/* ── Brand ── */
.bmc-sidebar__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 14px;
    height: 46px;
    flex-shrink: 0;
}
.bmc-sidebar__logo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--color-primary-500) 0%, var(--color-accent-pink) 100%);
    color: #fff;
    flex-shrink: 0;
}
.bmc-sidebar__brand-text {
    font-family: var(--font-display);
    font-size: 13px;
    font-weight: 700;
    color: var(--sidebar-text);
    white-space: nowrap;
}

/* ── Nav ── */
.bmc-sidebar__nav {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 0;
    overflow-y: auto;
}
.bmc-sidebar__section {
    padding: 12px 8px;
}
.bmc-sidebar__section--bottom {
    margin-top: auto;
    padding-top: 6px;
    padding-bottom: 6px;
    border-top: 1px solid var(--sidebar-border);
}
.bmc-sidebar__section-label {
    display: block;
    padding: 0 8px 7px;
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--sidebar-text-label);
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
    gap: 9px;
    padding: 0 10px;
    height: 36px;
    border-radius: var(--radius-sm);
    color: var(--sidebar-text-muted);
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 500;
    font-family: var(--font-sans);
    transition: background 0.15s ease, color 0.15s ease;
    cursor: pointer;
    white-space: nowrap;
}
.bmc-sidebar__item:hover {
    background: var(--bg-sidebar-hover);
    color: var(--sidebar-text);
}
.bmc-sidebar__item--active {
    background: var(--bg-sidebar-active);
    color: var(--sidebar-active-text);
}
.bmc-sidebar__item--active .bmc-sidebar__icon {
    color: var(--sidebar-active-text);
}
.bmc-sidebar__item--muted {
    color: var(--sidebar-text-muted);
    font-size: 13px;
}

.bmc-sidebar__icon {
    flex-shrink: 0;
    color: var(--sidebar-text-muted);
    transition: color 0.15s ease;
}
.bmc-sidebar__icon--sparkle {
    color: var(--color-accent-orange);
}
.bmc-sidebar__item:hover .bmc-sidebar__icon {
    color: var(--sidebar-text);
}
.bmc-sidebar__item--active .bmc-sidebar__icon {
    color: var(--sidebar-active-text);
}
.bmc-sidebar__label {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
}
.bmc-sidebar__chevron {
    flex-shrink: 0;
    color: var(--sidebar-text-muted);
    margin-left: auto;
}

/* ── Sub-items ── */
.bmc-sidebar__subitem {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px 6px 34px;
    border-radius: 7px;
    color: var(--sidebar-text-muted);
    text-decoration: none;
    font-size: 13px;
    font-weight: 400;
    font-family: var(--font-sans);
    transition: background 0.15s ease, color 0.15s ease;
    cursor: pointer;
    white-space: nowrap;
    position: relative;
}
.bmc-sidebar__subitem::before {
    content: '';
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    width: 5px;
    height: 1px;
    background: var(--sidebar-border);
}
.bmc-sidebar__subitem:hover {
    background: var(--bg-sidebar-hover);
    color: var(--sidebar-text);
}
.bmc-sidebar__subitem--active {
    color: var(--sidebar-active-text);
    font-weight: 500;
}
.bmc-sidebar__subitem--active::before {
    background: var(--sidebar-active-text);
}
.bmc-sidebar__subicon {
    flex-shrink: 0;
    color: var(--sidebar-text-muted);
}
.bmc-sidebar__subitem--active .bmc-sidebar__subicon,
.bmc-sidebar__subitem:hover .bmc-sidebar__subicon {
    color: var(--sidebar-active-text);
}

/* ── Utility icons ── */
.bmc-sidebar__utils {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 6px 8px 8px;
}
.bmc-sidebar__util-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 6px;
    background: var(--sidebar-util-bg);
    color: var(--sidebar-text-muted);
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}
.bmc-sidebar__util-btn:hover {
    background: var(--bg-sidebar-hover);
    color: var(--sidebar-text);
}

/* ── Collapse toggle ── */
.bmc-sidebar__toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 6px;
    padding: 6px;
    border: 1px solid var(--sidebar-border);
    border-radius: 8px;
    background: transparent;
    color: var(--sidebar-text-muted);
    cursor: pointer;
    transition: all 0.15s ease;
}
.bmc-sidebar__toggle:hover {
    background: var(--bg-sidebar-hover);
    color: var(--sidebar-text);
}

@media (max-width: 768px) {
    .bmc-sidebar { width: 52px; min-width: 52px; }
}
</style>
