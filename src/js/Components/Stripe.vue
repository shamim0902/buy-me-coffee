<template>
    <div class="relative min-h-[200px]">
        <CoffeeLoader :loading="fetching" />
        <!-- Header card: back + title + enable toggle -->
        <div class="bg-white rounded-xl border border-neutral-200 shadow-xs px-5 py-4 mb-4 flex items-center gap-4">
            <button
                class="flex items-center gap-1 text-sm font-medium cursor-pointer border-0 bg-transparent flex-shrink-0"
                style="color: var(--text-secondary)"
                @click="$router.push({ name: 'Gateway' })"
            >
                <ArrowLeft :size="15" />
            </button>
            <div class="flex-1 min-w-0">
                <h3 class="text-sm font-semibold text-[var(--text-primary)]">Stripe</h3>
                <p class="text-xs text-[var(--text-secondary)]">Accept payments via Stripe</p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <span class="text-xs text-[var(--text-secondary)]">{{ settings.enable === 'yes' ? 'Enabled' : 'Disabled' }}</span>
                <el-switch
                    v-model="settings.enable"
                    active-value="yes"
                    inactive-value="no"
                />
            </div>
        </div>

        <div :class="settings.enable !== 'yes' ? 'opacity-50 pointer-events-none' : ''">
            <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-5 mb-4 space-y-4">
                <!-- Mode row -->
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-sm font-medium text-[var(--text-primary)]">
                            {{ settings.payment_mode === 'live' ? 'Live' : 'Test' }} API Keys
                        </span>
                        <p v-if="settings.payment_mode === 'test'" class="text-xs text-amber-600 mt-0.5 flex items-center gap-1 m-0">
                            <AlertTriangle :size="12" /> Test mode — no real charges
                        </p>
                    </div>
                    <el-radio-group v-model="settings.payment_mode" size="small">
                        <el-radio-button value="test">Test</el-radio-button>
                        <el-radio-button value="live">Live</el-radio-button>
                    </el-radio-group>
                </div>

                <div class="h-px bg-neutral-100"></div>

                <!-- Keys -->
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1">Publishable Key</label>
                        <el-input
                            v-model="activePublishableKey"
                            type="password"
                            :placeholder="settings.payment_mode === 'live' ? 'pk_live_...' : 'pk_test_...'"
                            show-password
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1">Secret Key</label>
                        <el-input
                            v-model="activeSecretKey"
                            type="password"
                            :placeholder="settings.payment_mode === 'live' ? 'sk_live_...' : 'sk_test_...'"
                            show-password
                        />
                    </div>
                </div>

                <div class="h-px bg-neutral-100"></div>

                <!-- Webhook URL -->
                <div>
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div>
                            <span class="text-sm font-medium text-[var(--text-primary)]">Webhook URL</span>
                            <p class="text-xs text-[var(--text-secondary)] mt-0.5">Add to your Stripe dashboard to receive payment events.</p>
                        </div>
                        <a
                            href="https://dashboard.stripe.com/account/webhooks"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 text-xs font-medium text-[var(--color-primary)] hover:underline flex-shrink-0 mt-0.5"
                        >
                            Dashboard <ExternalLink :size="11" />
                        </a>
                    </div>
                    <CodeBlock :code="webhook_url" />
                </div>
            </div>
        </div>

        <!-- Save -->
        <div class="flex justify-end">
            <el-button type="primary" :loading="saving" @click="saveSettings()">
                Save Stripe Settings
            </el-button>
        </div>
    </div>
</template>

<script>
import { Eye, EyeOff, AlertTriangle, Check, ExternalLink, ArrowLeft } from 'lucide-vue-next';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import CodeBlock from './UI/CodeBlock.vue';

export default {
    name: 'StripeSettings',
    components: { Eye, EyeOff, AlertTriangle, Check, ExternalLink, ArrowLeft, CodeBlock, CoffeeLoader },
    data() {
        return {
            settings: {
                enable: 'no',
                payment_mode: 'test',
                test_pub_key: '',
                test_secret_key: '',
                live_pub_key: '',
                live_secret_key: ''
            },
            saving: false,
            fetching: false,
            webhook_url: '',
            showPubKey: false,
            showSecretKey: false
        }
    },
    computed: {
        activePublishableKey: {
            get() {
                return this.settings.payment_mode === 'live'
                    ? this.settings.live_pub_key
                    : this.settings.test_pub_key;
            },
            set(val) {
                if (this.settings.payment_mode === 'live') {
                    this.settings.live_pub_key = val;
                } else {
                    this.settings.test_pub_key = val;
                }
            }
        },
        activeSecretKey: {
            get() {
                return this.settings.payment_mode === 'live'
                    ? this.settings.live_secret_key
                    : this.settings.test_secret_key;
            },
            set(val) {
                if (this.settings.payment_mode === 'live') {
                    this.settings.live_secret_key = val;
                } else {
                    this.settings.test_secret_key = val;
                }
            }
        }
    },
    methods: {
        getSettings() {
            this.fetching = true;
            this.$get({
                action: 'buymecoffee_admin_ajax',
                route: 'get_data',
                data: { method: 'stripe' },
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
            })
                .then((response) => {
                    this.settings = response.data.settings;
                    this.webhook_url = response.data.webhook_url;
                })
                .fail(error => {
                    const message = error?.responseJSON?.data?.message || 'Failed to load Stripe settings';
                    this.$message.error(message);
                })
                .always(() => { this.fetching = false; });
        },
        saveSettings() {
            this.saving = true;
            this.$post({
                action: 'buymecoffee_admin_ajax',
                data: { settings: this.settings, method: 'stripe' },
                route: 'save_payment_settings',
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
            })
                .then(response => { this.$handleSuccess(response.data.message); })
                .fail(error => {
                    const message = error?.responseJSON?.data?.message || 'Failed to save Stripe settings';
                    this.$message.error(message);
                })
                .always(() => { this.saving = false; });
        }
    },
    watch: {
        'settings.payment_mode'() {
            this.showPubKey = false;
            this.showSecretKey = false;
        }
    },
    mounted() { this.getSettings(); }
}
</script>
