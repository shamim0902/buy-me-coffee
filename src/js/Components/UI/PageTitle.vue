<template>
    <div class="bmc-page-title">
        <div>
            <h1 class="bmc-page-title__heading">{{ title }}</h1>
            <p v-if="subtitle" class="bmc-page-title__subtitle">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="bmc-page-title__actions">
            <slot name="actions" />
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' }
});
</script>

<style scoped>
.bmc-page-title {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 24px;
}
.bmc-page-title__heading {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    line-height: 1.3;
    position: relative;
    padding-left: 14px;
}
.bmc-page-title__heading::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 70%;
    background: var(--gradient-brand);
    border-radius: 2px;
}
.bmc-page-title__subtitle {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 4px 0 0;
}
.bmc-page-title__actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
</style>
