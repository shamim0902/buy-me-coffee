<template>
    <div class="bmc-metric" :data-color="color">
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
import { DollarSign, Users, Coffee, Clock, RefreshCw, TrendingUp } from 'lucide-vue-next';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '0' },
    icon: { type: String, default: 'DollarSign' },
    color: { type: String, default: 'primary' } // primary, violet, amber, sky, green, emerald
});

const iconMap = { DollarSign, Users, Coffee, Clock, RefreshCw, TrendingUp };
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

/* Gradient card wash per color */
.bmc-metric[data-color="primary"]  { background: var(--gradient-card-wash-teal); }
.bmc-metric[data-color="violet"]   { background: var(--gradient-card-wash-violet); }
.bmc-metric[data-color="amber"]    { background: var(--gradient-card-wash-amber); }
.bmc-metric[data-color="sky"]      { background: var(--gradient-card-wash-sky); }
.bmc-metric[data-color="green"],
.bmc-metric[data-color="emerald"]  { background: var(--gradient-card-wash-green); }

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
    background: radial-gradient(circle at 30% 30%, var(--color-primary-100) 0%, var(--color-primary-50) 100%);
    color: var(--color-primary-600);
    box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.12);
}
.bmc-metric__icon--violet {
    background: radial-gradient(circle at 30% 30%, #ede9fe 0%, #f5f3ff 100%);
    color: #7c3aed;
    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.10);
}
.bmc-metric__icon--amber {
    background: radial-gradient(circle at 30% 30%, var(--color-coffee-100) 0%, var(--color-coffee-50) 100%);
    color: var(--color-coffee-600);
    box-shadow: 0 0 0 4px rgba(217, 119, 6, 0.12);
}
.bmc-metric__icon--sky {
    background: radial-gradient(circle at 30% 30%, #e0f2fe 0%, #f0f9ff 100%);
    color: var(--color-info-600);
    box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.10);
}
.bmc-metric__icon--green,
.bmc-metric__icon--emerald {
    background: radial-gradient(circle at 30% 30%, #dcfce7 0%, #f0fdf4 100%);
    color: #16a34a;
    box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.10);
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
