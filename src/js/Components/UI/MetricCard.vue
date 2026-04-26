<template>
    <div class="bmc-metric" :data-color="color">
        <div class="bmc-metric__top">
            <span class="bmc-metric__label">{{ label }}</span>
            <div class="bmc-metric__icon" :class="'bmc-metric__icon--' + color">
                <component :is="iconComponent" :size="18" />
            </div>
        </div>
        <div class="bmc-metric__bottom">
            <span class="bmc-metric__value">{{ value }}</span>
            <div v-if="trend" class="bmc-metric__trend" :class="trendDirection === 'down' ? 'bmc-metric__trend--down' : ''">
                <component :is="trendDirection === 'down' ? TrendingDown : TrendingUp" :size="13" />
                <span>{{ trend }}</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { DollarSign, Users, Coffee, Clock, RefreshCw, TrendingUp, TrendingDown } from 'lucide-vue-next';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '0' },
    icon: { type: String, default: 'DollarSign' },
    color: { type: String, default: 'purple' },
    trend: { type: String, default: '' },
    trendDirection: { type: String, default: 'up' }
});

const iconMap = { DollarSign, Users, Coffee, Clock, RefreshCw, TrendingUp };
const iconComponent = computed(() => iconMap[props.icon] || DollarSign);
</script>

<style scoped>
.bmc-metric {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 20px;
    background: var(--bg-primary);
    border: 1px solid var(--border-secondary);
    border-radius: 16px;
    box-shadow: 0 2px 8px var(--shadow-color, rgba(0, 0, 0, 0.04));
    transition: box-shadow 0.2s ease, transform 0.2s ease;
}
.bmc-metric:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.bmc-metric__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.bmc-metric__label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
}

.bmc-metric__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    flex-shrink: 0;
}
.bmc-metric__icon--purple {
    background: var(--color-accent-purple-light);
    color: var(--color-accent-purple);
}
.bmc-metric__icon--blue {
    background: var(--color-accent-blue-light);
    color: var(--color-accent-blue);
}
.bmc-metric__icon--teal {
    background: var(--color-accent-teal-light);
    color: var(--color-accent-teal);
}
.bmc-metric__icon--green {
    background: var(--color-accent-green-light);
    color: var(--color-accent-green);
}
.bmc-metric__icon--amber,
.bmc-metric__icon--orange {
    background: var(--color-accent-orange-light);
    color: var(--color-accent-orange);
}
.bmc-metric__icon--pink {
    background: var(--color-accent-pink-light);
    color: var(--color-accent-pink);
}
.bmc-metric__icon--red {
    background: var(--color-accent-red-light);
    color: var(--color-accent-red);
}

.bmc-metric__bottom {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
}

.bmc-metric__value {
    font-family: var(--font-display);
    font-size: 28px;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.1;
}

.bmc-metric__trend {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 8px;
    height: 24px;
    border-radius: var(--radius-pill);
    background: var(--color-accent-green-light);
    color: var(--color-accent-green);
    font-size: 11px;
    font-weight: 600;
}
.bmc-metric__trend--down {
    background: var(--color-accent-red-light);
    color: var(--color-accent-red);
}
</style>
