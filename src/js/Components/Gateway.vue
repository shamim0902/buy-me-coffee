<template>
    <div class="relative min-h-[200px]">
        <CoffeeLoader :loading="loading" />
        <PageTitle title="Payment Gateways" subtitle="Configure your payment methods" />

        <div class="bmc-gateways">
            <div
                v-for="(gateway, index) in gatewayList"
                :key="index"
                class="bmc-gateway-card"
                @click="$router.push({ name: gateway.route })"
            >
                <div class="bmc-gateway-card__icon">
                    <img
                        v-if="gateway.image"
                        :src="gateway.image"
                        :alt="gateway.title + ' logo'"
                        class="bmc-gateway-card__logo"
                    />
                    <CreditCard v-else :size="22" />
                </div>
                <div class="bmc-gateway-card__body">
                    <div class="bmc-gateway-card__header">
                        <h3 class="bmc-gateway-card__name">{{ gateway.title }}</h3>
                        <span v-if="gateway.status" class="bmc-gateway-card__status bmc-gateway-card__status--connected">
                            <Check :size="14" /> Connected
                        </span>
                        <span v-else class="bmc-gateway-card__status bmc-gateway-card__status--inactive">
                            Not Connected
                        </span>
                    </div>
                    <p class="bmc-gateway-card__desc">{{ gateway.description }}</p>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { CreditCard, Check } from 'lucide-vue-next';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import PageTitle from './UI/PageTitle.vue';

export default {
    name: 'Gateway',
    components: { CreditCard, Check, PageTitle, CoffeeLoader },
    data() {
        return {
            gateways: {},
            loading: true
        };
    },
    computed: {
        gatewayList() {
            if (Array.isArray(this.gateways)) return this.gateways;
            return Object.values(this.gateways);
        }
    },
    methods: {
        getAllMethods() {
            this.loading = true;
            this.$get({
                action: 'buymecoffee_admin_ajax',
                route: 'gateways',
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
            })
            .then((response) => {
                this.gateways = response.data;
            })
            .catch((error) => {
                console.log(error);
            })
            .always(() => {
                this.loading = false;
            });
        }
    },
    mounted() {
        this.getAllMethods();
    }
};
</script>

<style scoped>
.bmc-gateways {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    min-height: 120px;
}

.bmc-gateway-card {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 24px;
    background: var(--bg-primary);
    border: 1px solid var(--border-primary);
    border-radius: 14px;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.bmc-gateway-card:hover {
    border-color: var(--color-primary-300, #a5b4fc);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    transform: translateY(-1px);
}

.bmc-gateway-card__icon {
    flex-shrink: 0;
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #475569;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.bmc-gateway-card__logo {
    width: 30px;
    height: 30px;
    object-fit: contain;
    display: block;
}
.bmc-gateway-card:hover .bmc-gateway-card__icon {
    background: var(--color-primary-50, #eef2ff);
    border-color: var(--color-primary-200, #c7d2fe);
    color: var(--color-primary-600, #4f46e5);
}

.bmc-gateway-card__body {
    flex: 1;
    min-width: 0;
}

.bmc-gateway-card__header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
}

.bmc-gateway-card__name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.bmc-gateway-card__status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    font-weight: 500;
}
.bmc-gateway-card__status--connected {
    color: var(--color-success-600);
}
.bmc-gateway-card__status--inactive {
    color: var(--text-tertiary);
}

.bmc-gateway-card__desc {
    font-size: 13px;
    color: var(--text-secondary);
    margin: 0;
    line-height: 1.5;
}

@media (max-width: 480px) {
    .bmc-gateways {
        grid-template-columns: 1fr;
    }
}
</style>
