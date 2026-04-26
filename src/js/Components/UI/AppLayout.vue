<template>
    <div class="bmc-layout">
        <AppSidebar v-model:collapsed="sidebarCollapsed" />
        <main class="bmc-layout__main">
            <router-view v-slot="{ Component }">
                <Transition name="page-fade" mode="out-in">
                    <component :is="Component" />
                </Transition>
            </router-view>
        </main>
        <WhatsNew />
    </div>
</template>

<script setup>
import { ref } from 'vue';
import AppSidebar from './AppSidebar.vue';
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
    min-width: 0;
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
    .bmc-layout__main {
        padding: 16px;
    }
}
</style>
