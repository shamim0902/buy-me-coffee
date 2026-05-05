<template>
    <div class="bmc-layout" :class="{ 'bmc-layout--topnav': isWpAdmin }">
        <AppTopNav v-if="isWpAdmin" />
        <AppSidebar v-else v-model:collapsed="sidebarCollapsed" />
        <main class="bmc-layout__main">
            <router-view v-slot="{ Component }">
                <Transition name="page-fade" mode="out-in">
                    <component :is="Component" />
                </Transition>
            </router-view>
        </main>
        <GuidedTour />
        <WhatsNew />
        <ReviewPrompt />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import AppSidebar from './AppSidebar.vue';
import AppTopNav from './AppTopNav.vue';
import WhatsNew from './WhatsNew.vue';
import ReviewPrompt from './ReviewPrompt.vue';
import GuidedTour from './GuidedTour.vue';

const sidebarCollapsed = ref(false);
const isWpAdmin = computed(() => !!window.BuyMeCoffeeAdmin?.is_wp_admin);
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

.bmc-layout--topnav {
    position: relative;
    flex-direction: column;
    height: calc(100vh - 32px);
    min-height: 640px;
    padding-top: 48px;
}

.bmc-layout__main {
    flex: 1;
    min-width: 0;
    padding: 24px 32px;
    overflow-y: auto;
    overflow-x: hidden;
}

.bmc-layout--topnav .bmc-layout__main {
    padding: 24px;
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

    .bmc-layout--topnav .bmc-layout__main {
        padding: 16px;
    }
}

@media (max-width: 1200px) {
    .bmc-layout--topnav {
        padding-top: 86px;
    }
}

@media (max-width: 900px) {
    .bmc-layout--topnav {
        padding-top: 48px;
    }
}

@media (max-width: 782px) {
    .bmc-layout--topnav {
        height: calc(100vh - 46px);
        min-height: 620px;
        padding-top: 48px;
    }
}

@media (max-width: 560px) {
    .bmc-layout--topnav {
        padding-top: 92px;
    }
}
</style>
