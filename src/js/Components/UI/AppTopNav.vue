<template>
    <header class="bmc-topnav">
        <div class="bmc-topnav__brand">
            <span class="bmc-topnav__logo">
                <Coffee :size="15" />
            </span>
            <span class="bmc-topnav__brand-text">Buy Me Coffee</span>
        </div>

        <nav class="bmc-topnav__scroll" aria-label="Buy Me Coffee navigation">
            <ul class="bmc-topnav__list" role="list">
                <li v-for="item in mainItems" :key="item.route">
                    <router-link
                        :to="item.route"
                        class="bmc-topnav__item"
                        :class="{ 'bmc-topnav__item--active': isActive(item) }"
                    >
                        <component :is="item.icon" :size="15" />
                        <span>{{ item.label }}</span>
                    </router-link>
                </li>

                <li v-for="item in topConfigItems" :key="item.route">
                    <div
                        v-if="item.children"
                        class="bmc-topnav__menu"
                        :class="{ 'bmc-topnav__menu--closed': closedMenuRoute === item.route }"
                        @mouseleave="closedMenuRoute = null"
                        @focusin="closedMenuRoute = null"
                    >
                        <router-link
                            :to="item.route"
                            class="bmc-topnav__item"
                            :class="{ 'bmc-topnav__item--active': isActive(item) }"
                        >
                            <component :is="item.icon" :size="15" />
                            <span>{{ item.label }}</span>
                            <ChevronDown :size="13" class="bmc-topnav__chevron" />
                        </router-link>

                        <div class="bmc-topnav__dropdown">
                            <router-link
                                v-for="child in item.children"
                                :key="child.label"
                                :to="getChildRoute(item, child)"
                                class="bmc-topnav__dropdown-item"
                                :class="{ 'bmc-topnav__dropdown-item--active': isChildActive(child) }"
                                @click="hideDesktopSubmenu(item.route, $event)"
                            >
                                <component :is="child.icon" :size="14" />
                                <span>{{ child.label }}</span>
                            </router-link>
                        </div>
                    </div>

                    <router-link
                        v-else
                        :to="item.route"
                        class="bmc-topnav__item"
                        :class="{ 'bmc-topnav__item--active': isActive(item) }"
                    >
                        <component :is="item.icon" :size="15" />
                        <span>{{ item.label }}</span>
                    </router-link>
                </li>

                <li v-if="showQuickSetup">
                    <router-link
                        to="/quick-setup"
                        class="bmc-topnav__item bmc-topnav__item--setup"
                        :class="{ 'bmc-topnav__item--active': isActive({ activeNames: ['Onboarding'] }) }"
                    >
                        <Sparkles :size="15" />
                        <span>Quick Setup</span>
                    </router-link>
                </li>
            </ul>
        </nav>

        <div class="bmc-topnav__compact">
            <button
                type="button"
                class="bmc-topnav__menu-btn"
                :aria-expanded="isCompactOpen ? 'true' : 'false'"
                aria-controls="bmc-topnav-compact-menu"
                @click="isCompactOpen = !isCompactOpen"
            >
                <Menu :size="15" />
                <span>Menu</span>
                <ChevronDown :size="13" class="bmc-topnav__menu-btn-chevron" />
            </button>

            <div
                id="bmc-topnav-compact-menu"
                class="bmc-topnav__compact-panel"
                :class="{ 'bmc-topnav__compact-panel--open': isCompactOpen }"
            >
                <router-link
                    v-for="item in mainItems"
                    :key="item.route"
                    :to="item.route"
                    class="bmc-topnav__compact-item"
                    :class="{ 'bmc-topnav__compact-item--active': isActive(item) }"
                    @click="isCompactOpen = false"
                >
                    <component :is="item.icon" :size="15" />
                    <span>{{ item.label }}</span>
                </router-link>

                <div class="bmc-topnav__compact-group">
                    <span class="bmc-topnav__compact-heading">Settings</span>
                    <router-link
                        v-for="child in topConfigItems[0].children"
                        :key="child.label"
                        :to="getChildRoute(topConfigItems[0], child)"
                        class="bmc-topnav__compact-item"
                        :class="{ 'bmc-topnav__compact-item--active': isChildActive(child) }"
                        @click="isCompactOpen = false"
                    >
                        <component :is="child.icon" :size="15" />
                        <span>{{ child.label }}</span>
                    </router-link>
                </div>

                <router-link
                    v-if="showQuickSetup"
                    to="/quick-setup"
                    class="bmc-topnav__compact-item bmc-topnav__compact-item--setup"
                    :class="{ 'bmc-topnav__compact-item--active': isActive({ activeNames: ['Onboarding'] }) }"
                    @click="isCompactOpen = false"
                >
                    <Sparkles :size="15" />
                    <span>Quick Setup</span>
                </router-link>
            </div>
        </div>

        <div class="bmc-topnav__actions">
            <button class="bmc-topnav__icon-btn" type="button" @click="toggleTheme" :title="isDark ? 'Light mode' : 'Dark mode'">
                <Moon v-if="!isDark" :size="15" />
                <Sun v-else :size="15" />
            </button>
            <a class="bmc-topnav__icon-btn" :href="previewUrl" target="_blank" rel="noopener" title="Preview Page">
                <ExternalLink :size="15" />
            </a>
            <a class="bmc-topnav__icon-btn bmc-topnav__icon-btn--primary" :href="fullPageUrl" title="Open full-screen dashboard">
                <Maximize2 :size="15" />
            </a>
        </div>
    </header>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import {
    ChevronDown,
    Coffee,
    ExternalLink,
    Maximize2,
    Menu,
    Moon,
    Sparkles,
    Sun,
} from 'lucide-vue-next';
import { useTheme } from '../../composables/useTheme';
import { configItems, mainItems } from './navigation';

const route = useRoute();
const quickSetupDismissed = ref(false);
const isCompactOpen = ref(false);
const closedMenuRoute = ref(null);
const { isDark, toggleTheme } = useTheme();

const previewUrl = computed(() => window.BuyMeCoffeeAdmin?.preview_url || '#');
const showQuickSetup = computed(() => !window.BuyMeCoffeeAdmin?.setup_completed && !quickSetupDismissed.value);
const fullPageUrl = computed(() => `${window.location.origin}/?buymecoffee_admin`);
const topConfigItems = computed(() => {
    const settings = configItems[0];
    const nestedItems = [
        ...settings.children,
        ...configItems.slice(1).map((item) => ({
            label: item.label,
            route: item.route,
            icon: item.icon,
            activeNames: item.activeNames,
        })),
    ];

    return [{
        ...settings,
        activeNames: [
            ...settings.activeNames,
            ...configItems.slice(1).flatMap((item) => item.activeNames || []),
        ],
        children: nestedItems,
    }];
});

function isActive(item) {
    return item.activeNames?.includes(route.name) || false;
}

function isSubActive(child) {
    const currentTab = route.query.tab || 'general';
    return currentTab === child.query.tab;
}

function isChildActive(child) {
    return child.activeNames ? isActive(child) : isSubActive(child);
}

function getChildRoute(parent, child) {
    if (child.route) {
        return child.route;
    }

    return { path: parent.route, query: child.query };
}

function hideDesktopSubmenu(route, event) {
    closedMenuRoute.value = route;
    event?.currentTarget?.blur?.();
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
    quickSetupDismissed.value = hasQuickSetupDismissed();
    window.addEventListener('buymecoffee:data-saved', syncQuickSetupState);
});

onBeforeUnmount(() => {
    window.removeEventListener('buymecoffee:data-saved', syncQuickSetupState);
});
</script>

<style scoped>
.bmc-topnav {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 20;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: start;
    gap: 10px;
    height: 48px;
    padding: 7px 12px;
    overflow: visible;
    background: var(--bg-sidebar);
    border-bottom: 1px solid var(--sidebar-border);
    box-shadow: none;
}

.bmc-topnav__brand {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 168px;
    padding-top: 2px;
}

.bmc-topnav__logo {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--color-primary-500) 0%, var(--color-accent-pink) 100%);
    color: #fff;
    flex: 0 0 auto;
}

.bmc-topnav__brand-text {
    display: inline-block;
    max-width: 140px;
    opacity: 1;
    color: var(--sidebar-text);
    font-family: var(--font-display);
    font-size: 14px;
    font-weight: 700;
    white-space: nowrap;
}

.bmc-topnav__scroll {
    min-width: 0;
    overflow: visible;
    scrollbar-width: none;
}

.bmc-topnav__scroll::-webkit-scrollbar {
    display: none;
}

.bmc-topnav__list {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: 4px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.bmc-topnav__item {
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    gap: 7px;
    width: auto;
    height: 34px;
    max-width: 180px;
    padding: 0 10px;
    border-radius: var(--radius-sm);
    color: var(--sidebar-text-muted);
    font-family: var(--font-sans);
    font-size: 13px;
    font-weight: 500;
    line-height: 1;
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.15s ease, color 0.15s ease;
}

.bmc-topnav__item span {
    display: inline-block;
    max-width: 128px;
    opacity: 1;
    overflow: hidden;
}

.bmc-topnav__item:hover {
    background: var(--bg-sidebar-hover);
    color: var(--sidebar-text);
}

.bmc-topnav__item--active {
    background: var(--bg-sidebar-active);
    color: var(--sidebar-active-text);
}

.bmc-topnav__item--setup {
    color: var(--color-accent-orange);
}

.bmc-topnav__chevron {
    width: 13px;
    opacity: 1;
    flex: 0 0 auto;
}

.bmc-topnav__menu {
    position: relative;
}

.bmc-topnav__dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    visibility: hidden;
    min-width: 190px;
    padding: 8px 6px 6px;
    border: 1px solid var(--sidebar-border);
    border-radius: 8px;
    background: var(--bg-sidebar);
    box-shadow: var(--shadow-lg);
    opacity: 0;
    pointer-events: none;
    transform: translateY(-4px);
    transition: opacity 0.16s ease, transform 0.16s ease, visibility 0.16s ease;
}

.bmc-topnav__menu:hover .bmc-topnav__dropdown,
.bmc-topnav__menu:focus-within .bmc-topnav__dropdown {
    visibility: visible;
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
}

.bmc-topnav__menu--closed:hover .bmc-topnav__dropdown,
.bmc-topnav__menu--closed:focus-within .bmc-topnav__dropdown {
    visibility: hidden;
    opacity: 0;
    pointer-events: none;
    transform: translateY(-4px);
}

.bmc-topnav__dropdown-item {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 34px;
    padding: 0 9px;
    border-radius: 7px;
    color: var(--sidebar-text-muted);
    font-size: 13px;
    text-decoration: none;
    white-space: nowrap;
}

.bmc-topnav__dropdown-item:hover {
    background: var(--bg-sidebar-hover);
    color: var(--sidebar-text);
}

.bmc-topnav__dropdown-item--active {
    color: var(--sidebar-active-text);
    background: var(--bg-sidebar-active);
}

.bmc-topnav__actions {
    display: flex;
    align-items: center;
    gap: 5px;
    padding-top: 1px;
}

.bmc-topnav__compact {
    display: none;
    position: relative;
    min-width: 0;
}

.bmc-topnav__menu-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    height: 34px;
    padding: 0 10px;
    border: 0;
    border-radius: var(--radius-sm);
    background: var(--sidebar-util-bg);
    color: var(--sidebar-text);
    cursor: pointer;
    font-family: var(--font-sans);
    font-size: 13px;
    font-weight: 600;
}

.bmc-topnav__menu-btn-chevron {
    transition: transform 0.16s ease;
}

.bmc-topnav__menu-btn[aria-expanded="true"] .bmc-topnav__menu-btn-chevron {
    transform: rotate(180deg);
}

.bmc-topnav__compact-panel {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    display: grid;
    gap: 3px;
    width: min(300px, calc(100vw - 28px));
    max-height: min(70vh, 540px);
    padding: 8px;
    overflow-y: auto;
    border: 1px solid var(--sidebar-border);
    border-radius: 8px;
    background: var(--bg-sidebar);
    box-shadow: var(--shadow-lg);
    opacity: 0;
    pointer-events: none;
    transform: translateY(-6px);
    transition: opacity 0.16s ease, transform 0.16s ease;
}

.bmc-topnav__compact-panel--open {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
}

.bmc-topnav__compact-item {
    display: flex;
    align-items: center;
    gap: 9px;
    min-height: 36px;
    padding: 0 10px;
    border-radius: 7px;
    color: var(--sidebar-text-muted);
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
}

.bmc-topnav__compact-item:hover {
    background: var(--bg-sidebar-hover);
    color: var(--sidebar-text);
}

.bmc-topnav__compact-item--active {
    background: var(--bg-sidebar-active);
    color: var(--sidebar-active-text);
}

.bmc-topnav__compact-item--setup {
    color: var(--color-accent-orange);
}

.bmc-topnav__compact-group {
    display: grid;
    gap: 3px;
    margin-top: 4px;
    padding-top: 6px;
    border-top: 1px solid var(--sidebar-border);
}

.bmc-topnav__compact-heading {
    padding: 0 10px 3px;
    color: var(--sidebar-text-label);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.bmc-topnav__icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 7px;
    background: var(--sidebar-util-bg);
    color: var(--sidebar-text-muted);
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;
}

.bmc-topnav__icon-btn:hover {
    background: var(--bg-sidebar-hover);
    color: var(--sidebar-text);
}

.bmc-topnav__icon-btn--primary {
    color: var(--sidebar-active-text);
    background: var(--bg-sidebar-active);
}

@media (max-width: 1200px) {
    .bmc-topnav {
        grid-template-columns: 1fr auto;
        height: 86px;
    }

    .bmc-topnav__brand {
        min-width: 0;
    }

    .bmc-topnav__brand-text {
        max-width: 140px;
        opacity: 1;
        transform: none;
    }

    .bmc-topnav__scroll {
        grid-column: 1 / -1;
        order: 3;
        overflow: visible;
    }

    .bmc-topnav__list {
        min-width: max-content;
    }

}

@media (max-width: 900px) {
    .bmc-topnav {
        grid-template-columns: auto minmax(0, 1fr) auto;
        height: 48px;
        align-items: center;
    }

    .bmc-topnav__scroll {
        display: none;
    }

    .bmc-topnav__compact {
        display: block;
    }

    .bmc-topnav__brand {
        min-width: 0;
    }
}

@media (max-width: 782px) {
    .bmc-topnav {
        height: 48px;
        padding: 8px 10px;
    }

    .bmc-topnav__brand-text {
        font-size: 13px;
    }

    .bmc-topnav__actions {
        gap: 3px;
    }

    .bmc-topnav__icon-btn {
        width: 30px;
        height: 30px;
    }
}

@media (max-width: 560px) {
    .bmc-topnav {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .bmc-topnav__brand-text {
        max-width: 108px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .bmc-topnav__compact {
        grid-column: 1 / -1;
        order: 3;
    }

    .bmc-topnav__menu-btn {
        width: 100%;
    }

    .bmc-topnav__compact-panel {
        width: calc(100vw - 20px);
    }

    .bmc-topnav__actions {
        justify-content: flex-end;
    }
}
</style>
