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
                <h3 class="bmc-settings-header__title">Stripe</h3>
                <p class="bmc-settings-header__subtitle">Accept payments via Stripe</p>
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
                        <span class="bmc-settings-row__label">
                            {{ settings.payment_mode === 'live' ? 'Live' : 'Test' }} API Keys
                        </span>
                        <p v-if="settings.payment_mode === 'test'" class="bmc-settings-row__hint bmc-settings-row__hint--warn">
                            <AlertTriangle :size="12" /> Test mode — no real charges
                        </p>
                    </div>
                    <el-radio-group v-model="settings.payment_mode" size="small">
                        <el-radio-button value="test">Test</el-radio-button>
                        <el-radio-button value="live">Live</el-radio-button>
                    </el-radio-group>
                </div>

                <div class="bmc-divider"></div>

                <!-- Keys -->
                <div class="bmc-field-group">
                    <div class="bmc-field">
                        <label class="bmc-field__label">Publishable Key</label>
                        <el-input
                            v-model="activePublishableKey"
                            type="password"
                            :placeholder="settings.payment_mode === 'live' ? 'pk_live_...' : 'pk_test_...'"
                            show-password
                        />
                    </div>
                    <div class="bmc-field">
                        <label class="bmc-field__label">Secret Key</label>
                        <el-input
                            v-model="activeSecretKey"
                            type="password"
                            :placeholder="secretKeyPlaceholder"
                            show-password
                        />
                    </div>
                    <div class="bmc-field">
                        <label class="bmc-field__label">Webhook Signing Secret</label>
                        <el-input
                            v-model="activeWebhookSecret"
                            type="password"
                            :placeholder="webhookSecretPlaceholder"
                            show-password
                        />
                    </div>
                </div>

                <div class="bmc-divider"></div>

                <!-- Webhook URL -->
                <div>
                    <div class="bmc-settings-row" style="margin-bottom: 12px;">
                        <div>
                            <span class="bmc-settings-row__label">Webhook URL</span>
                            <p class="bmc-settings-row__hint">Add to your Stripe dashboard to receive payment events.</p>
                        </div>
                        <a
                            href="https://dashboard.stripe.com/account/webhooks"
                            target="_blank"
                            rel="noopener"
                            class="bmc-ext-link"
                        >
                            Dashboard <ExternalLink :size="11" />
                        </a>
                    </div>
                    <CodeBlock :code="webhook_url" />
                    <div class="bmc-webhook-events">
                        <p class="bmc-webhook-events__title">Required webhook events:</p>
                        <ul class="bmc-webhook-events__list">
                            <li>• <code>charge.succeeded</code></li>
                            <li>• <code>invoice.payment_succeeded</code> — recurring renewal payments</li>
                            <li>• <code>customer.subscription.deleted</code> — subscription cancellations</li>
                            <li>• <code>customer.subscription.updated</code> — status changes</li>
                        </ul>
                    </div>
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
                live_secret_key: '',
                test_webhook_secret: '',
                live_webhook_secret: '',
                has_live_secret_key: false,
                has_test_secret_key: false,
                has_live_webhook_secret: false,
                has_test_webhook_secret: false
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
        },
        activeWebhookSecret: {
            get() {
                return this.settings.payment_mode === 'live'
                    ? this.settings.live_webhook_secret
                    : this.settings.test_webhook_secret;
            },
            set(val) {
                if (this.settings.payment_mode === 'live') {
                    this.settings.live_webhook_secret = val;
                } else {
                    this.settings.test_webhook_secret = val;
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
            return this.settings.payment_mode === 'live' ? 'sk_live_...' : 'sk_test_...';
        },
        webhookSecretPlaceholder() {
            const hasExisting = this.settings.payment_mode === 'live'
                ? this.settings.has_live_webhook_secret
                : this.settings.has_test_webhook_secret;
            if (hasExisting) {
                return 'Saved webhook secret (leave blank to keep)';
            }
            return this.settings.payment_mode === 'live' ? 'whsec_live_...' : 'whsec_test_...';
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

.bmc-webhook-events {
    margin-top: 14px;
    padding: 14px;
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    border: 1px solid var(--border-secondary);
}
.bmc-webhook-events__title {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    margin: 0 0 8px;
}
.bmc-webhook-events__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    color: var(--text-tertiary);
}
.bmc-webhook-events__list code {
    font-size: 12px;
    font-family: var(--font-mono);
}
</style>
