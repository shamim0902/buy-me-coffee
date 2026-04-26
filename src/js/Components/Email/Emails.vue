<template>
    <div class="relative min-h-[120px]">
        <CoffeeLoader :loading="fetching" />

        <!-- Notification rows -->
        <div class="bmc-notif-list">
            <div
                v-for="notif in notifications"
                :key="notif.id"
                class="bmc-notif-row"
            >
                <!-- Icon -->
                <div class="bmc-notif-row__icon" :class="iconClass(notif)">
                    <component
                        :is="iconComponent(notif)"
                        :size="17"
                    />
                </div>

                <!-- Label + trigger -->
                <div class="bmc-notif-row__body">
                    <p class="bmc-notif-row__title">{{ notif.label }}</p>
                    <p class="bmc-notif-row__desc">{{ notifDescription(notif) }}</p>
                </div>

                <!-- Enabled toggle -->
                <el-switch
                    v-model="notif.enabled"
                    @change="quickSave(notif)"
                />

                <!-- Edit button -->
                <button class="bmc-notif-row__edit" @click="openEdit(notif)">
                    <Pencil :size="13" />
                    Edit
                </button>
            </div>
        </div>

        <!-- Edit Dialog -->
        <el-dialog
            v-model="dialogVisible"
            :title="editTarget ? editTarget.label : ''"
            width="620px"
            :close-on-click-modal="false"
        >
            <div v-if="editTarget" class="bmc-dialog-form">
                <!-- Enabled -->
                <div class="bmc-dialog-row">
                    <span class="bmc-dialog-row__label">Enable this notification</span>
                    <el-switch v-model="editTarget.enabled" />
                </div>

                <div class="bmc-divider"></div>

                <!-- Subject -->
                <div class="bmc-field">
                    <label class="bmc-field__label">Subject</label>
                    <el-input v-model="editTarget.subject" placeholder="Email subject..." />
                </div>

                <!-- Body -->
                <div class="bmc-field">
                    <label class="bmc-field__label">Message Body</label>
                    <el-input
                        v-model="editTarget.body"
                        type="textarea"
                        :rows="8"
                        placeholder="Email body..."
                    />
                </div>

                <!-- Shortcode reference -->
                <div class="bmc-shortcode-ref">
                    <p class="bmc-shortcode-ref__title">Available shortcodes:</p>
                    <div class="bmc-shortcode-ref__list">
                        <code
                            v-for="sc in shortcodes"
                            :key="sc"
                            class="bmc-shortcode-ref__tag"
                            @click="insertShortcode(sc)"
                            :title="'Click to copy ' + sc"
                        >{{ sc }}</code>
                    </div>
                    <p class="bmc-shortcode-ref__hint">Click a shortcode to copy it to your clipboard.</p>
                </div>

                <!-- Test email -->
                <div class="bmc-test-email">
                    <el-input
                        v-model="testEmailTo"
                        placeholder="Test recipient email..."
                        style="flex: 1"
                        size="small"
                    />
                    <el-button
                        size="small"
                        :loading="sendingTest"
                        @click="sendTest"
                    >
                        <Send :size="13" style="margin-right: 4px;" />
                        Send Test
                    </el-button>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <el-button @click="dialogVisible = false">Cancel</el-button>
                    <el-button type="primary" :loading="saving" @click="saveNotification">
                        Save
                    </el-button>
                </div>
            </template>
        </el-dialog>
    </div>
</template>

<script>
import { Heart, ShieldCheck, Pencil, Send, Bell, RefreshCw } from 'lucide-vue-next';
import CoffeeLoader from '../UI/CoffeeLoader.vue';

const NOTIF_META = {
    donor:        { icon: 'Heart',       colorClass: 'bmc-notif-row__icon--blue',   desc: 'Sent to the donor on successful payment' },
    admin:        { icon: 'ShieldCheck', colorClass: 'bmc-notif-row__icon--amber',  desc: 'Sent to site admin on successful payment' },
    subscription: { icon: 'RefreshCw',   colorClass: 'bmc-notif-row__icon--green',  desc: 'Sent to subscriber on renewal or status change' },
    cancelled:    { icon: 'Bell',        colorClass: 'bmc-notif-row__icon--red',    desc: 'Sent when a subscription is cancelled' },
};

const ICON_MAP = { Heart, ShieldCheck, Bell, RefreshCw };

export default {
    name: 'Emails',
    components: { Heart, ShieldCheck, Pencil, Send, Bell, RefreshCw, CoffeeLoader },

    data() {
        return {
            fetching: false,
            saving: false,
            sendingTest: false,
            notifications: [],
            dialogVisible: false,
            editTarget: null,
            testEmailTo: '',
            shortcodes: [
                '{{donor_name}}',
                '{{donor_email}}',
                '{{amount}}',
                '{{payment_method}}',
                '{{site_name}}',
                '{{site_url}}',
                '{{admin_email}}',
            ],
        };
    },

    methods: {
        iconComponent(notif) {
            const meta = NOTIF_META[notif.id];
            return meta ? ICON_MAP[meta.icon] || Heart : Heart;
        },
        iconClass(notif) {
            const meta = NOTIF_META[notif.id];
            return meta ? meta.colorClass : 'bmc-notif-row__icon--blue';
        },
        notifDescription(notif) {
            const meta = NOTIF_META[notif.id];
            return meta ? meta.desc : '';
        },

        fetchNotifications() {
            this.fetching = true;
            this.$get({
                action: 'buymecoffee_admin_ajax',
                route: 'get_email_notifications',
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            }).then((res) => {
                this.notifications = res.data.notifications;
            }).always(() => {
                this.fetching = false;
            });
        },

        openEdit(notif) {
            this.editTarget = JSON.parse(JSON.stringify(notif));
            this.testEmailTo = window.BuyMeCoffeeAdmin?.admin_email || '';
            this.dialogVisible = true;
        },

        quickSave(notif) {
            this.$post({
                action: 'buymecoffee_admin_ajax',
                route: 'save_email_notification',
                data: notif,
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            });
        },

        saveNotification() {
            this.saving = true;
            this.$post({
                action: 'buymecoffee_admin_ajax',
                route: 'save_email_notification',
                data: this.editTarget,
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            }).then((res) => {
                this.$handleSuccess(res.data.message);
                const idx = this.notifications.findIndex(n => n.id === this.editTarget.id);
                if (idx !== -1) this.notifications[idx] = { ...this.editTarget };
                this.dialogVisible = false;
            }).fail((err) => {
                const msg = err?.responseJSON?.data?.message || 'Failed to save';
                this.$message.error(msg);
            }).always(() => {
                this.saving = false;
            });
        },

        sendTest() {
            this.sendingTest = true;
            this.$post({
                action: 'buymecoffee_admin_ajax',
                route: 'send_test_email',
                data: { id: this.editTarget.id, to: this.testEmailTo },
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            }).then((res) => {
                this.$handleSuccess(res.data.message);
            }).fail((err) => {
                const msg = err?.responseJSON?.data?.message || 'Failed to send test';
                this.$message.error(msg);
            }).always(() => {
                this.sendingTest = false;
            });
        },

        insertShortcode(sc) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(sc);
                this.$message({ message: sc + ' copied!', type: 'success', duration: 1500 });
            }
        },
    },

    mounted() {
        this.fetchNotifications();
    },
};
</script>

<style scoped>
/* Notification List */
.bmc-notif-list {
    display: flex;
    flex-direction: column;
}
.bmc-notif-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid var(--border-secondary);
}
.bmc-notif-row:last-child {
    border-bottom: 0;
}

.bmc-notif-row__icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.bmc-notif-row__icon--blue {
    background: var(--color-info-50);
    color: var(--color-info-500);
}
.bmc-notif-row__icon--amber {
    background: var(--color-warning-50);
    color: var(--color-warning-500);
}
.bmc-notif-row__icon--green {
    background: var(--color-success-50);
    color: var(--color-success-500);
}
.bmc-notif-row__icon--red {
    background: var(--color-error-50);
    color: var(--color-error-500);
}

.bmc-notif-row__body {
    flex: 1;
    min-width: 0;
}
.bmc-notif-row__title {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    margin: 0;
}
.bmc-notif-row__desc {
    font-size: 12px;
    color: var(--text-tertiary);
    margin: 2px 0 0;
}

.bmc-notif-row__edit {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 500;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-primary);
    background: var(--bg-primary);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
}
.bmc-notif-row__edit:hover {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

/* Dialog form */
.bmc-dialog-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.bmc-dialog-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.bmc-dialog-row__label {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}
.bmc-divider {
    height: 1px;
    background: var(--border-secondary);
}
.bmc-field__label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 6px;
}

/* Shortcode reference */
.bmc-shortcode-ref {
    padding: 14px;
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    border: 1px solid var(--border-secondary);
}
.bmc-shortcode-ref__title {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    margin: 0 0 8px;
}
.bmc-shortcode-ref__list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.bmc-shortcode-ref__tag {
    padding: 2px 8px;
    font-size: 12px;
    font-family: var(--font-mono);
    background: var(--bg-primary);
    border: 1px solid var(--border-primary);
    border-radius: 4px;
    color: var(--text-secondary);
    cursor: pointer;
    transition: background 0.15s ease;
}
.bmc-shortcode-ref__tag:hover {
    background: var(--bg-tertiary);
}
.bmc-shortcode-ref__hint {
    font-size: 11px;
    color: var(--text-tertiary);
    margin: 8px 0 0;
}

/* Test email */
.bmc-test-email {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-top: 4px;
}
</style>
