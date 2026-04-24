<template>
    <div class="relative min-h-[120px]">
        <CoffeeLoader :loading="fetching" />

        <!-- Notification rows -->
        <div class="divide-y divide-neutral-100">
            <div
                v-for="notif in notifications"
                :key="notif.id"
                class="flex items-center gap-4 py-4"
            >
                <!-- Icon -->
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                    :class="notif.id === 'donor' ? 'bg-blue-50' : 'bg-amber-50'"
                >
                    <component
                        :is="notif.id === 'donor' ? Heart : ShieldCheck"
                        :size="17"
                        :class="notif.id === 'donor' ? 'text-blue-500' : 'text-amber-500'"
                    />
                </div>

                <!-- Label + trigger -->
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-[var(--text-primary)]">{{ notif.label }}</p>
                    <p class="text-xs text-[var(--text-tertiary)] mt-0.5">
                        {{ notif.id === 'donor' ? 'Sent to the donor on successful payment' : 'Sent to site admin on successful payment' }}
                    </p>
                </div>

                <!-- Enabled toggle -->
                <el-switch
                    v-model="notif.enabled"
                    @change="quickSave(notif)"
                />

                <!-- Edit button -->
                <button
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-neutral-200 bg-white text-[var(--text-secondary)] hover:bg-neutral-50 hover:text-[var(--text-primary)] transition-colors cursor-pointer"
                    @click="openEdit(notif)"
                >
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
            <div v-if="editTarget" class="space-y-4">
                <!-- Enabled -->
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-[var(--text-primary)]">Enable this notification</span>
                    <el-switch v-model="editTarget.enabled" />
                </div>

                <div class="h-px bg-neutral-100" />

                <!-- Subject -->
                <div>
                    <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1.5">Subject</label>
                    <el-input v-model="editTarget.subject" placeholder="Email subject..." />
                </div>

                <!-- Body -->
                <div>
                    <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1.5">Message Body</label>
                    <el-input
                        v-model="editTarget.body"
                        type="textarea"
                        :rows="8"
                        placeholder="Email body..."
                    />
                </div>

                <!-- Shortcode reference -->
                <div class="bg-neutral-50 rounded-lg border border-neutral-200 p-3">
                    <p class="text-xs font-medium text-[var(--text-secondary)] mb-2">Available shortcodes:</p>
                    <div class="flex flex-wrap gap-1.5">
                        <code
                            v-for="sc in shortcodes"
                            :key="sc"
                            class="px-2 py-0.5 text-xs bg-white border border-neutral-200 rounded text-[var(--text-secondary)] cursor-pointer hover:bg-neutral-100 transition-colors"
                            @click="insertShortcode(sc)"
                            :title="'Click to copy ' + sc"
                        >{{ sc }}</code>
                    </div>
                    <p class="text-xs text-[var(--text-tertiary)] mt-2">Click a shortcode to copy it to your clipboard.</p>
                </div>

                <!-- Test email -->
                <div class="flex items-center gap-2 pt-1">
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
import { Heart, ShieldCheck, Pencil, Send } from 'lucide-vue-next';
import CoffeeLoader from '../UI/CoffeeLoader.vue';

export default {
    name: 'Emails',
    components: { Heart, ShieldCheck, Pencil, Send, CoffeeLoader },

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
            Heart,
            ShieldCheck,
        };
    },

    methods: {
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
                // Update the local list
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
