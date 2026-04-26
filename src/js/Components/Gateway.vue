<template>
    <div class="relative min-h-[200px]">
        <CoffeeLoader :loading="loading" />
        <PageTitle title="Payment Gateways" subtitle="Configure and manage your payment processing methods." />

        <!-- Stats Row -->
        <div v-if="!loading" class="bmc-gw-stats">
            <div class="bmc-gw-stat">
                <div class="bmc-gw-stat__icon bmc-gw-stat__icon--purple">
                    <CreditCard :size="18" />
                </div>
                <div>
                    <p class="bmc-gw-stat__value">{{ connectedCount }} Connected</p>
                    <p class="bmc-gw-stat__label">Active gateways</p>
                </div>
            </div>
            <div class="bmc-gw-stat">
                <div class="bmc-gw-stat__icon bmc-gw-stat__icon--blue">
                    <ArrowUpDown :size="18" />
                </div>
                <div>
                    <p class="bmc-gw-stat__value">{{ stats.transaction_count || 0 }} Transactions</p>
                    <p class="bmc-gw-stat__label">Total processed</p>
                </div>
            </div>
            <div class="bmc-gw-stat">
                <div class="bmc-gw-stat__icon bmc-gw-stat__icon--green">
                    <DollarSign :size="18" />
                </div>
                <div>
                    <p class="bmc-gw-stat__value">{{ formatTotal(stats.total_amount) }}</p>
                    <p class="bmc-gw-stat__label">Total volume</p>
                </div>
            </div>
        </div>

        <!-- Gateway Cards -->
        <div v-if="!loading" class="bmc-gateways">
            <div
                v-for="(gateway, index) in gatewayList"
                :key="index"
                class="bmc-gateway-card"
            >
                <div class="bmc-gateway-card__top">
                    <div class="bmc-gateway-card__icon">
                        <img
                            v-if="gateway.image"
                            :src="gateway.image"
                            :alt="gateway.title + ' logo'"
                            class="bmc-gateway-card__logo"
                        />
                        <CreditCard v-else :size="22" />
                    </div>
                    <div class="bmc-gateway-card__info">
                        <h3 class="bmc-gateway-card__name">{{ gateway.title }}</h3>
                        <span v-if="gateway.status" class="bmc-gateway-card__badge bmc-gateway-card__badge--connected">
                            Connected
                        </span>
                        <span v-else class="bmc-gateway-card__badge bmc-gateway-card__badge--inactive">
                            Not Connected
                        </span>
                    </div>
                    <button
                        class="bmc-gateway-card__btn"
                        @click="$router.push({ name: gateway.route })"
                    >
                        Configure
                    </button>
                </div>

                <p class="bmc-gateway-card__desc">{{ gateway.description }}</p>

                <div class="bmc-gateway-card__details">
                    <div class="bmc-gateway-card__detail">
                        <span class="bmc-gateway-card__detail-label">API Mode</span>
                        <span class="bmc-gateway-card__detail-value">
                            <span class="bmc-gateway-card__dot" :class="gateway.payment_mode === 'live' ? 'bmc-gateway-card__dot--live' : 'bmc-gateway-card__dot--test'"></span>
                            {{ gateway.payment_mode === 'live' ? 'Live' : 'Test' }}
                        </span>
                    </div>
                    <div class="bmc-gateway-card__detail">
                        <span class="bmc-gateway-card__detail-label">Currencies</span>
                        <span class="bmc-gateway-card__detail-value">{{ gateway.currencies || '—' }}</span>
                    </div>
                    <div class="bmc-gateway-card__detail">
                        <span class="bmc-gateway-card__detail-label">Last Transaction</span>
                        <span class="bmc-gateway-card__detail-value">{{ gateway.last_transaction || '—' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { CreditCard, ArrowUpDown, DollarSign } from 'lucide-vue-next';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import PageTitle from './UI/PageTitle.vue';

export default {
    name: 'Gateway',
    components: { CreditCard, ArrowUpDown, DollarSign, PageTitle, CoffeeLoader },
    data() {
        return {
            gateways: {},
            stats: {},
            loading: true
        };
    },
    computed: {
        gatewayList() {
            if (Array.isArray(this.gateways)) return this.gateways;
            return Object.values(this.gateways);
        },
        connectedCount() {
            return this.gatewayList.filter(g => g.status).length;
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
                if (response.data?.stats) {
                    this.stats = response.data.stats;
                }
            })
            .catch((error) => {
                console.log(error);
            })
            .always(() => {
                this.loading = false;
            });
        },
        formatTotal(cents) {
            if (!cents) return '$0.00';
            return '$' + (cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    },
    mounted() {
        this.getAllMethods();
    }
};
</script>

<style scoped>
/* Stats Row */
.bmc-gw-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
.bmc-gw-stat {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px;
    background: var(--bg-primary);
    border: 1px solid var(--border-secondary);
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.bmc-gw-stat__icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.bmc-gw-stat__icon--purple {
    background: var(--color-accent-purple-light);
    color: var(--color-accent-purple);
}
.bmc-gw-stat__icon--blue {
    background: var(--color-accent-blue-light);
    color: var(--color-accent-blue);
}
.bmc-gw-stat__icon--green {
    background: var(--color-accent-green-light);
    color: var(--color-accent-green);
}
.bmc-gw-stat__value {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    font-family: var(--font-display);
}
.bmc-gw-stat__label {
    font-size: 12px;
    color: var(--text-tertiary);
    margin: 2px 0 0;
}

/* Gateway Cards */
.bmc-gateways {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
}
.bmc-gateway-card {
    padding: 24px;
    background: var(--bg-primary);
    border: 1px solid var(--border-secondary);
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.bmc-gateway-card:hover {
    border-color: var(--color-primary-300);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
}

.bmc-gateway-card__top {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.bmc-gateway-card__icon {
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-secondary);
}
.bmc-gateway-card__logo {
    width: 28px;
    height: 28px;
    object-fit: contain;
}
.bmc-gateway-card__info {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.bmc-gateway-card__name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}
.bmc-gateway-card__badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 600;
}
.bmc-gateway-card__badge--connected {
    background: var(--color-success-100);
    color: var(--color-success-700);
}
.bmc-gateway-card__badge--inactive {
    background: var(--color-neutral-100);
    color: var(--text-tertiary);
}

.bmc-gateway-card__btn {
    flex-shrink: 0;
    padding: 6px 16px;
    border-radius: var(--radius-md);
    font-size: 13px;
    font-weight: 500;
    border: 1px solid var(--border-primary);
    background: var(--bg-primary);
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.15s ease;
}
.bmc-gateway-card__btn:hover {
    background: var(--bg-tertiary);
}

.bmc-gateway-card__desc {
    font-size: 13px;
    color: var(--text-secondary);
    margin: 0 0 16px;
    line-height: 1.5;
}

/* Detail rows */
.bmc-gateway-card__details {
    border-top: 1px solid var(--border-secondary);
    padding-top: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.bmc-gateway-card__detail {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.bmc-gateway-card__detail-label {
    font-size: 13px;
    color: var(--text-tertiary);
}
.bmc-gateway-card__detail-value {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.bmc-gateway-card__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.bmc-gateway-card__dot--live {
    background: var(--color-success-500);
}
.bmc-gateway-card__dot--test {
    background: var(--color-warning-500);
}

@media (max-width: 480px) {
    .bmc-gw-stats {
        grid-template-columns: 1fr;
    }
    .bmc-gateways {
        grid-template-columns: 1fr;
    }
}
</style>
