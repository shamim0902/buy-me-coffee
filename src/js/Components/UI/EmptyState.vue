<template>
    <div class="bmc-empty">
        <component :is="iconComponent" :size="48" class="bmc-empty__icon" />
        <h3 class="bmc-empty__title">{{ title }}</h3>
        <p v-if="description" class="bmc-empty__desc">{{ description }}</p>
        <slot />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Inbox, Heart, Bell, FileText } from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, default: 'No data yet' },
    description: { type: String, default: '' },
    icon: { type: String, default: 'Inbox' }
});

const iconMap = { Inbox, Heart, Bell, FileText };
const iconComponent = computed(() => iconMap[props.icon] || Inbox);
</script>

<style scoped>
.bmc-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 24px;
    text-align: center;
}
.bmc-empty__icon {
    color: var(--color-neutral-300);
    margin-bottom: 16px;
}
.bmc-empty__title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 6px;
}
.bmc-empty__desc {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0;
    max-width: 320px;
}
</style>
