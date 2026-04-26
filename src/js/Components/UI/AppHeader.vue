<template>
    <header class="bmc-header">
        <div class="bmc-header__left">
            <BreadcrumbNav />
        </div>
    </header>
</template>

<script setup>
import BreadcrumbNav from './BreadcrumbNav.vue';
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
</style>
