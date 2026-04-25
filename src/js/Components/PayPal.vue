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
                <h3 class="text-sm font-semibold text-[var(--text-primary)]">PayPal</h3>
                <p class="text-xs text-[var(--text-secondary)]">Accept payments via PayPal</p>
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
                        <span class="text-sm font-medium text-[var(--text-primary)]">Payment Mode</span>
                        <p v-if="settings.payment_mode === 'test'" class="text-xs text-amber-600 mt-0.5 flex items-center gap-1 m-0">
                            <AlertTriangle :size="12" /> Sandbox — no real charges
                        </p>
                    </div>
                    <el-radio-group v-model="settings.payment_mode" size="small">
                        <el-radio-button value="test">Test</el-radio-button>
                        <el-radio-button value="live">Live</el-radio-button>
                    </el-radio-group>
                </div>

                <div class="h-px bg-neutral-100"></div>

                <!-- Type row -->
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-[var(--text-primary)]">Payment Type</span>
                    <el-radio-group v-model="settings.payment_type" size="small">
                        <el-radio-button value="pro">Pro</el-radio-button>
                        <el-radio-button value="standard">Standard</el-radio-button>
                    </el-radio-group>
                </div>

                <div class="h-px bg-neutral-100"></div>

                <!-- Pro: API Keys -->
                <div v-if="settings.payment_type === 'pro'" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1">
                            {{ settings.payment_mode === 'live' ? 'Live' : 'Test' }} Public Key
                        </label>
                        <el-input
                            v-model="activePublicKey"
                            type="password"
                            placeholder="Public key from PayPal dashboard"
                            show-password
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1">
                            {{ settings.payment_mode === 'live' ? 'Live' : 'Test' }} Secret Key
                        </label>
                        <el-input
                            v-model="activeSecretKey"
                            type="password"
                            :placeholder="secretKeyPlaceholder"
                            show-password
                        />
                    </div>
                </div>

                <!-- Standard: Email + IPN toggle -->
                <div v-if="settings.payment_type === 'standard'" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1">PayPal Email Address</label>
                        <el-input
                            v-model="settings.paypal_email"
                            type="text"
                            placeholder="your-email@example.com"
                        />
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-neutral-50 border border-neutral-200">
                        <div>
                            <span class="text-sm font-medium text-[var(--text-primary)]">Disable IPN Verification</span>
                            <p class="text-xs text-[var(--text-secondary)] mt-0.5">
                                Available in test mode only.
                                <span v-if="settings.payment_mode === 'live'">Live mode always enforces verification.</span>
                            </p>
                        </div>
                        <el-switch
                            v-model="settings.disable_ipn_verification"
                            active-value="yes"
                            inactive-value="no"
                            :disabled="settings.payment_mode === 'live'"
                            class="ml-4 flex-shrink-0"
                        />
                    </div>
                </div>

                <div class="h-px bg-neutral-100"></div>

                <!-- IPN URL -->
                <div>
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div>
                            <span class="text-sm font-medium text-[var(--text-primary)]">IPN URL</span>
                            <p class="text-xs text-[var(--text-secondary)] mt-0.5">Add to your PayPal account to receive payment notifications.</p>
                        </div>
                        <a
                            href="https://developer.paypal.com/dashboard/"
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
                Save PayPal Settings
            </el-button>
        </div>
    </div>
</template>

<script>
import { AlertTriangle, ExternalLink, ArrowLeft } from 'lucide-vue-next';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import CodeBlock from './UI/CodeBlock.vue';

export default {
    name: 'PayPalSettings',
    components: { AlertTriangle, ExternalLink, ArrowLeft, CodeBlock, CoffeeLoader },
    data() {
        return {
            settings: {
                enable: 'no',
                payment_mode: 'test',
                payment_type: 'standard',
                test_public_key: '',
                test_secret_key: '',
                live_public_key: '',
                live_secret_key: '',
                paypal_email: '',
                disable_ipn_verification: 'no',
                has_test_secret_key: false,
                has_live_secret_key: false
            },
            saving: false,
            fetching: false,
            webhook_url: '',
        }
    },
    computed: {
        activePublicKey: {
            get() {
                return this.settings.payment_mode === 'live'
                    ? this.settings.live_public_key
                    : this.settings.test_public_key;
            },
            set(val) {
                if (this.settings.payment_mode === 'live') {
                    this.settings.live_public_key = val;
                } else {
                    this.settings.test_public_key = val;
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
        },
        secretKeyPlaceholder() {
            const hasExisting = this.settings.payment_mode === 'live'
                ? this.settings.has_live_secret_key
                : this.settings.has_test_secret_key;
            if (hasExisting) {
                return 'Saved secret key (leave blank to keep)';
            }
            return 'Secret key from PayPal dashboard';
        }
    },
    methods: {
        getSettings() {
            this.fetching = true;
            this.$get({
                action: 'buymecoffee_admin_ajax',
                route: 'get_data',
                data: { method: 'paypal' },
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
            })
                .then((response) => {
                    this.settings = response.data.settings;
                    this.webhook_url = response.data.webhook_url;
                })
                .fail(error => {
                    const message = error?.responseJSON?.data?.message || 'Failed to load PayPal settings';
                    this.$message.error(message);
                })
                .always(() => { this.fetching = false; });
        },
        saveSettings() {
            this.saving = true;
            this.$post({
                action: 'buymecoffee_admin_ajax',
                route: 'save_payment_settings',
                data: { method: 'paypal', settings: this.settings },
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
            })
                .then(response => { this.$handleSuccess(response.data.message); })
                .fail(error => {
                    const message = error?.responseJSON?.data?.message || 'Failed to save PayPal settings';
                    this.$message.error(message);
                })
                .always(() => { this.saving = false; });
        }
    },
    watch: {
        'settings.payment_mode'(value) {
            if (value === 'live') {
                this.settings.disable_ipn_verification = 'no';
            }
        }
    },
    mounted() { this.getSettings(); }
}
</script>
