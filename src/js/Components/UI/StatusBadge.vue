<template>
    <span class="bmc-badge" :class="'bmc-badge--' + status">{{ label }}</span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: 'pending' }
});

const label = computed(() => {
    const map = {
        paid: 'Paid',
        pending: 'Pending',
        failed: 'Failed',
        refunded: 'Refunded',
        cancelled: 'Cancelled',
        'paid-initially': 'Paid'
    };
    return map[props.status] || props.status;
});
</script>

<style scoped>
.bmc-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 10px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 500;
    font-family: var(--font-sans);
    line-height: 1.6;
    white-space: nowrap;
    text-transform: capitalize;
}
.bmc-badge--paid, .bmc-badge--paid-initially {
    background: var(--color-success-50);
    color: var(--color-success-700);
    border: 1px solid var(--color-success-500);
}
.bmc-badge--pending {
    background: var(--color-warning-50);
    color: var(--color-warning-700);
    border: 1px solid var(--color-warning-500);
}
.bmc-badge--failed {
    background: var(--color-error-50);
    color: var(--color-error-700);
    border: 1px solid var(--color-error-500);
}
.bmc-badge--refunded {
    background: var(--color-neutral-100);
    color: var(--color-neutral-600);
    border: 1px solid var(--color-neutral-300);
}
.bmc-badge--cancelled {
    background: var(--color-error-100);
    color: var(--color-error-700);
    border: 1px solid var(--color-error-500);
}
</style>
