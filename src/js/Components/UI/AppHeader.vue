<template>
    <header class="bmc-header">
        <div class="bmc-header__left">
            <BreadcrumbNav />
        </div>
        <div class="bmc-header__right">
            <button class="bmc-header__action" @click="toggleTheme" :title="isDark ? 'Light mode' : 'Dark mode'">
                <Moon v-if="!isDark" :size="18" />
                <Sun v-else :size="18" />
            </button>
            <a :href="previewUrl" target="_blank" rel="noopener" class="bmc-header__action" title="Preview donation page">
                <ExternalLink :size="18" />
            </a>
            <a href="https://wpminers.com/buymecoffee/docs/" target="_blank" rel="noopener" class="bmc-header__action" title="Documentation">
                <HelpCircle :size="18" />
            </a>
        </div>
    </header>
</template>

<script setup>
import { computed } from 'vue';
import { Moon, Sun, ExternalLink, HelpCircle } from 'lucide-vue-next';
import { useTheme } from '../../composables/useTheme';
import BreadcrumbNav from './BreadcrumbNav.vue';

const { isDark, toggleTheme } = useTheme();
const previewUrl = computed(() => window.BuyMeCoffeeAdmin?.preview_url || '#');
</script>

<style scoped>
.bmc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 52px;
    padding: 0 28px;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border-primary);
    flex-shrink: 0;
}
.bmc-header__left {
    display: flex;
    align-items: center;
}
.bmc-header__right {
    display: flex;
    align-items: center;
    gap: 4px;
}
.bmc-header__action {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--text-tertiary);
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}
.bmc-header__action:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
}
</style>
