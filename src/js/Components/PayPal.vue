<template>
    <div class="buymecoffee_main_container" v-loading="fetching">
        <div class="buymecoffee_wrapper buymecoffee_payment_settings">
            <div class="buymecoffee_header">
                <h3 class="buymecoffee_title">
                    <router-link style="text-decoration: none;" :to="{name: 'Gateway'}"></router-link>PayPal Gateway Settings:
                </h3>
            </div>
            <div style="margin-bottom: 23px;">
                <label>Enable PayPal Payment
                    <el-switch active-value="yes" inactive-value="no" active-text="Enable PayPal" v-model="settings.enable"></el-switch>
                </label>
            </div>
            <div class="buymecoffee_section_body" :class="settings.enable !== 'yes' ? 'payment-inactive' : ''">
                <el-form :label-position="labelPosition" rel="paypal_settings" :model="settings" label-width="220px">
                  <el-form-item label="PayPal Payment Mode">
                    <el-radio-group v-model="settings.payment_mode">
                      <el-radio label="test">Sandbox Mode</el-radio>
                      <el-radio label="live">Live Mode</el-radio>
                    </el-radio-group>
                  </el-form-item>
                  <el-tabs v-model="settings.payment_type" class="demo-tabs">
                    <el-tab-pane label="PayPal pro" name="pro">
                      <div v-if="settings.payment_mode === 'test'">
                        <el-form-item label="Test Public key">
                          <el-input type="text" size="small" v-model="settings.test_public_key"
                                    placeholder="Public key from paypal dashboard"/>
                        </el-form-item>
                        <el-form-item label="Test Secret key">
                          <el-input type="password" size="small" v-model="settings.test_secret_key"
                                    placeholder="Public key from paypal dashboard"/>
                        </el-form-item>
                      </div>
                      <div v-else>
                        <el-form-item label="Live Public key">
                          <el-input type="text" size="small" v-model="settings.live_public_key"
                                    placeholder="Public key from paypal dashboard"/>
                        </el-form-item>
                        <el-form-item label="Live Secret key">
                          <el-input type="password" size="small" v-model="settings.live_secret_key"
                                    placeholder="Public key from paypal dashboard"/>
                        </el-form-item>
                      </div>
                    </el-tab-pane>
                    <el-tab-pane label="Paypal Standard" name="standard">
                        <el-form-item label="Paypal Email">
                          <el-input type="text" size="small" v-model="settings.paypal_email"
                                    placeholder="Paypal Email Address"/>
                        </el-form-item>
                        <el-form-item label="Disable PayPal IPN Verification">
                          <el-switch active-value="yes" inactive-value="no" v-model="settings.disable_ipn_verification"/>
                          <p>If you are unable to use Payment Data Transfer and payments are not getting marked as
                            complete, then check this box. This forces the site to use a slightly less secure method of
                            verifying purchases.</p>
                        </el-form-item>
                    </el-tab-pane>
                  </el-tabs>
                  <div class="buymecoffee_settings_section">
                    <p>Please use IPN url to get marked paid on you site.</p>
                    <b>IPN URL: </b>
                    <el-tooltip effect="dark"
                                content="Click to copy"
                                title="Click to copy"
                                placement="top">
                      <code class="copy"
                            data-clipboard-action="copy"
                            :data-clipboard-text='webhook_url'>
                        <i class="el-icon-document"></i> {{webhook_url}}
                      </code>
                    </el-tooltip>
                  </div>

                    <div class="action_right mt-4">
                        <el-button @click="saveSettings()" type="primary" size="default">Save PayPal Settings</el-button>
                    </div>
                </el-form>
            </div>
        </div>
    </div>
</template>
<script type="text/babel">
import ClipboardJS from 'clipboard';
    export default {
        name: 'paypal_settings',
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
                      method: 'paypal',
                    },
                    buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
                })
                    .then((response) => {
                        this.settings = response.data.settings;
                        this.webhook_url = response.data.webhook_url;
                        this.fetching = false;
                    })
                    .fail(error => {
                        ElMessage({
                            message: error?.responseJSON?.data?.message || 'Failed to load PayPal settings',
                            type: 'error',
                            offset: 40,
                            dangerouslyUseHTMLString: true,
                            duration: 5000
                        });
                    })
                    .always(() => {
                        this.fetching = false;
                    });
            },
            saveSettings() {
                this.saving = true;
                this.$post({
                    action: 'buymecoffee_admin_ajax',
                    route: 'save_payment_settings',
                    data: {
                      method: 'paypal',
                      settings: this.settings,
                    },
                    buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
                })
                    .then(response => {
                        ElMessage({
                            message: response?.data?.message || 'PayPal settings saved successfully',
                            type: 'success',
                            offset: 40,
                            dangerouslyUseHTMLString: true,
                            duration: 3000
                        });
                    })
                    .fail(error => {
                        ElMessage({
                            message: error?.responseJSON?.data?.message || 'Failed to save PayPal settings',
                            type: 'error',
                            offset: 40,
                            dangerouslyUseHTMLString: true,
                            duration: 5000
                        });
                    })
                    .always(() => {
                        this.saving = false;
                    });
            }
        },
        mounted() {
            this.getSettings();
            jQuery(document).ready(function($) {
              var clipboard = new ClipboardJS('.copy');
              clipboard.on('success', function(e) {
                $(e.trigger).text("Copied!");
                e.clearSelection();
                //todo: add timeout to revert text
                setTimeout(function() {
                  $(e.trigger).text(e.text);
                }, 1000);
              });
            });
        }
    }
</script>

<script setup>
import { ElMessage } from 'element-plus'
</script>
<style lang="scss">

</style>