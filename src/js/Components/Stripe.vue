<template>
    <div class="buymecoffee_main_container" v-loading="fetching">
        <div class="buymecoffee_wrapper buymecoffee_payment_settings">
                <h3 class="buymecoffee_title">
                    <router-link style="text-decoration: none;" :to="{name: 'Gateway'}"></router-link>Stripe Gateway Settings:
                </h3>
            <div style="margin-bottom: 23px;">
                <label>Enable Stripe Payment
                    <el-switch active-value="yes" inactive-value="no" active-text="Enable stripe" v-model="settings.enable"></el-switch>
                </label>
            </div>
            <div class="buymecoffee_section_body" :class="settings.enable !== 'yes' ? 'payment-inactive' : ''">
                <el-form :label-position="labelPosition" rel="stripe_settings" :model="settings" label-width="220px">
                    <el-form-item label="Stripe Payment Mode">
                        <el-radio-group v-model="settings.payment_mode">
                            <el-radio label="test">Test Mode</el-radio>
                            <el-radio label="live">Live Mode</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <div v-if="settings.payment_mode !== 'live'" class="buymecoffee_settings_section">
                        <h3>Stripe Test Keys</h3>
                        <el-form-item label="Test Publishable key">
                            <el-input type="text" size="small" v-model="settings.test_pub_key"
                                      placeholder="Test Publishable key"/>
                        </el-form-item>
                        <el-form-item label="Test Secret key">
                            <el-input type="password" size="small" v-model="settings.test_secret_key"
                                      placeholder="Test Secret key"/>
                        </el-form-item>
                    </div>

                    <div v-else class="buymecoffee_settings_section">
                        <h3>Stripe Live Keys</h3>
                        <el-form-item label="Live Publishable key">
                            <el-input type="text" size="small" v-model="settings.live_pub_key"
                                      placeholder="Live Publishable key"/>
                        </el-form-item>
                        <el-form-item label="Live Secret key">
                            <el-input type="password" size="small" v-model="settings.live_secret_key"
                                      placeholder="Live Secret key"/>
                        </el-form-item>
                    </div>

                    <div class="buymecoffee_settings_section">
                        <p>In order for Stripe to function completely for subscription/recurring payments, you must configure your Stripe webhooks. Visit
                            your <a href="https://dashboard.stripe.com/account/webhooks" target="_blank" rel="noopener">account
                                dashboard</a> to configure them. Please add a webhook endpoint for the URL below.</p>
                        <p><b>Webhook URL: </b><code>{{webhook_url}}</code></p>
                    </div>

                    <div class="action_right mt-4">
                        <el-button @click="saveSettings()" type="primary" size="default">Save Settings</el-button>
                    </div>
                </el-form>
            </div>
        </div>
    </div>
</template>
<script type="text/babel">
    export default {
        name: 'settings',
        data() {
            return {
                settings: {},
                saving: false,
                fetching: false,
                labelPosition: 'right',
                webhook_url: ''
            }
        },
        methods: {
            getSettings() {
                this.fetching = true;
                this.$get({
                    action: 'buymecoffee_admin_ajax',
                    route: 'get_data',
                    data: {
                      method: 'stripe',
                    },
                    buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
                })
                    .then((response) => {
                        this.settings = response.data.settings;
                        this.webhook_url = response.data.webhook_url
                    })
                    .fail(error => {
                        const message = error?.responseJSON?.data?.message || 'Failed to load Stripe settings';
                        this.$message.error(message);
                    })
                    .always(() => {
                        this.fetching = false;
                    });
            },
            saveSettings() {
                this.saving = true;
                this.$post({
                    action: 'buymecoffee_admin_ajax',
                    data: {
                      settings: this.settings,
                      method: 'stripe',
                    },
                    route: 'save_payment_settings',
                    buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
                })
                    .then(response => {
                        this.$handleSuccess(response.data.message);
                    })
                    .fail(error => {
                        const message = error?.responseJSON?.data?.message || 'Failed to save Stripe settings';
                        this.$message.error(message);
                    })
                    .always(() => {
                        this.saving = false;
                    });
            }
        },
        mounted() {
            this.getSettings();
        }
    }
</script>

<style lang="scss">
   .buymecoffee_settings_wrap {
        max-width: 800px;
        margin: 0 auto;
        padding:23px 12px;
   }

   .payment-inactive {
        opacity: 0.7;
    }
</style>
