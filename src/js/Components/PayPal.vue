<template>
    <div class="relative min-h-[200px]">
        <CoffeeLoader :loading="fetching" />
        <!-- Header card: back + title + enable toggle -->
        <div class="bmc-card bmc-settings-header">
            <button
                class="bmc-back-btn"
                @click="$router.push({ name: 'Gateway' })"
            >
                <ArrowLeft :size="15" />
            </button>
            <div class="bmc-settings-header__info">
                <h3 class="bmc-settings-header__title">PayPal</h3>
                <p class="bmc-settings-header__subtitle">Accept payments via PayPal</p>
            </div>
            <div class="bmc-settings-header__toggle">
                <span class="bmc-settings-header__status">{{ settings.enable === 'yes' ? 'Enabled' : 'Disabled' }}</span>
                <el-switch
                    v-model="settings.enable"
                    active-value="yes"
                    inactive-value="no"
                />
            </div>
        </div>

        <div :class="settings.enable !== 'yes' ? 'opacity-50 pointer-events-none' : ''">
            <div class="bmc-card bmc-settings-body">
                <!-- Mode row -->
                <div class="bmc-settings-row">
                    <div>
                        <span class="bmc-settings-row__label">Payment Mode</span>
                        <p v-if="settings.payment_mode === 'test'" class="bmc-settings-row__hint bmc-settings-row__hint--warn">
                            <AlertTriangle :size="12" /> Sandbox — no real charges
                        </p>
                    </div>
                    <el-radio-group v-model="settings.payment_mode" size="small">
                        <el-radio-button value="test">Test</el-radio-button>
                        <el-radio-button value="live">Live</el-radio-button>
                    </el-radio-group>
                </div>

                <div class="bmc-divider"></div>

                <!-- Type row -->
                <div class="bmc-settings-row">
                    <span class="bmc-settings-row__label">Payment Type</span>
                    <el-radio-group v-model="settings.payment_type" size="small">
                        <el-radio-button value="pro">Pro</el-radio-button>
                        <el-radio-button value="standard">Standard</el-radio-button>
                    </el-radio-group>
                </div>

                <div class="bmc-divider"></div>

                <!-- Pro: API Keys -->
                <div v-if="settings.payment_type === 'pro'" class="bmc-field-group">
                    <div class="bmc-field">
                        <label class="bmc-field__label">
                            {{ settings.payment_mode === 'live' ? 'Live' : 'Test' }} Public Key
                        </label>
                        <el-input
                            v-model="activePublicKey"
                            type="password"
                            placeholder="Public key from PayPal dashboard"
                            show-password
                        />
                    </div>
                    <div class="bmc-field">
                        <label class="bmc-field__label">
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
                <div v-if="settings.payment_type === 'standard'" class="bmc-field-group">
                    <div class="bmc-field">
                        <label class="bmc-field__label">PayPal Email Address</label>
                        <el-input
                            v-model="settings.paypal_email"
                            type="text"
                            placeholder="your-email@example.com"
                        />
                    </div>
                    <div class="bmc-ipn-toggle">
                        <div>
                            <span class="bmc-settings-row__label">Disable IPN Verification</span>
                            <p class="bmc-settings-row__hint">
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

                <div class="bmc-divider"></div>

                <!-- IPN URL -->
                <div>
                    <div class="bmc-settings-row" style="margin-bottom: 12px;">
                        <div>
                            <span class="bmc-settings-row__label">IPN URL</span>
                            <p class="bmc-settings-row__hint">Add to your PayPal account to receive payment notifications.</p>
                        </div>
                        <a
                            href="https://developer.paypal.com/dashboard/"
                            target="_blank"
                            rel="noopener"
                            class="bmc-ext-link"
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
                    this.$handleError(error);
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
                    this.$handleError(error);
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

<style scoped>
.bmc-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-secondary);
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.bmc-settings-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 24px;
    margin-bottom: 16px;
}
.bmc-back-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    border: 0;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    flex-shrink: 0;
    padding: 4px;
    border-radius: 6px;
    transition: color 0.15s ease;
}
.bmc-back-btn:hover { color: var(--text-primary); }

.bmc-settings-header__info { flex: 1; min-width: 0; }
.bmc-settings-header__title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}
.bmc-settings-header__subtitle {
    font-size: 12px;
    color: var(--text-secondary);
    margin: 2px 0 0;
}
.bmc-settings-header__toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.bmc-settings-header__status {
    font-size: 12px;
    color: var(--text-secondary);
}

.bmc-settings-body {
    padding: 24px;
    margin-bottom: 16px;
}

.bmc-settings-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.bmc-settings-row__label {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}
.bmc-settings-row__hint {
    font-size: 12px;
    color: var(--text-secondary);
    margin: 4px 0 0;
}
.bmc-settings-row__hint--warn {
    color: var(--color-warning-600);
    display: flex;
    align-items: center;
    gap: 4px;
}

.bmc-divider {
    height: 1px;
    background: var(--border-secondary);
    margin: 20px 0;
}

.bmc-field-group {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.bmc-field__label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 6px;
}

.bmc-ipn-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px;
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    border: 1px solid var(--border-secondary);
}

.bmc-ext-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 500;
    color: var(--color-primary-600);
    text-decoration: none;
    flex-shrink: 0;
    margin-top: 2px;
}
.bmc-ext-link:hover { text-decoration: underline; }
</style>
