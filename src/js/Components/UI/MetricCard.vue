<template>
    <div class="bmc-metric">
        <div class="bmc-metric__icon" :class="'bmc-metric__icon--' + color">
            <component :is="iconComponent" :size="20" />
        </div>
        <div class="bmc-metric__content">
            <span class="bmc-metric__label">{{ label }}</span>
            <span class="bmc-metric__value">{{ value }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { DollarSign, Users, Coffee, Clock } from 'lucide-vue-next';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '0' },
    icon: { type: String, default: 'DollarSign' },
    color: { type: String, default: 'primary' } // primary, violet, amber, sky
});

const iconMap = { DollarSign, Users, Coffee, Clock };
const iconComponent = computed(() => iconMap[props.icon] || DollarSign);
</script>

<style scoped>
.bmc-metric {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px;
    background: var(--bg-primary);
    border: 1px solid var(--border-primary);
    border-radius: 12px;
    transition: box-shadow 0.2s ease, transform 0.2s ease;
}
.bmc-metric:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}
.bmc-metric__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 10px;
    flex-shrink: 0;
}
.bmc-metric__icon--primary {
    background: var(--color-primary-50);
    color: var(--color-primary-600);
}
.bmc-metric__icon--violet {
    background: #f5f3ff;
    color: #7c3aed;
}
.bmc-metric__icon--amber {
    background: var(--color-warning-50);
    color: var(--color-warning-600);
}
.bmc-metric__icon--sky {
    background: var(--color-info-50);
    color: var(--color-info-600);
}
.bmc-metric__content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.bmc-metric__label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
}
.bmc-metric__value {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.2;
}
</style>
