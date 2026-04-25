<template>
    <div class="bmc-layout">
        <AppSidebar v-model:collapsed="sidebarCollapsed" />
        <div class="bmc-layout__main">
            <AppHeader />
            <main class="bmc-layout__content">
                <router-view v-slot="{ Component }">
                    <Transition name="page-fade" mode="out-in">
                        <component :is="Component" />
                    </Transition>
                </router-view>
            </main>
        </div>
        <WhatsNew />
    </div>
</template>

<script setup>
import { ref } from 'vue';
import AppSidebar from './AppSidebar.vue';
import AppHeader from './AppHeader.vue';
import WhatsNew from './WhatsNew.vue';

const sidebarCollapsed = ref(false);
</script>

<style scoped>
.bmc-layout {
    display: flex;
    height: 100vh;
    overflow: hidden;
    background: var(--bg-secondary);
    font-family: var(--font-sans);
    color: var(--text-primary);
}

.bmc-layout__main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}

.bmc-layout__content {
    flex: 1;
    padding: 24px 32px;
    overflow-y: auto;
    overflow-x: hidden;
}

/* Page transitions */
.page-fade-enter-active,
.page-fade-leave-active {
    transition: opacity 150ms ease;
}
.page-fade-enter-from,
.page-fade-leave-to {
    opacity: 0;
}

@media (max-width: 960px) {
    .bmc-layout__content {
        padding: 16px;
    }
}
</style>
