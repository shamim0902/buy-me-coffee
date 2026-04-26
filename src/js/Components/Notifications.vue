<template>
    <div>
        <PageTitle title="Notifications" subtitle="Manage email templates and webhook integrations for your supporters." />

        <div class="bmc-card">
            <el-tabs :tab-position="'top'">
                <el-tab-pane label="Emails">
                    <Emails />
                </el-tab-pane>
                <el-tab-pane label="Webhooks">
                    <Webhook />
                </el-tab-pane>
                <el-tab-pane label="Templates">
                    <div class="bmc-empty-tab">
                        <p class="bmc-empty-tab__text">Email template customization coming soon.</p>
                    </div>
                </el-tab-pane>
            </el-tabs>
        </div>
    </div>
</template>

<script>
import PageTitle from './UI/PageTitle.vue';
import Emails from './Email/Emails.vue';
import Webhook from './Webhook.vue';

export default {
    name: 'Notifications',
    components: {
        PageTitle,
        Emails,
        Webhook,
    },
};
</script>

<style scoped>
.bmc-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-secondary);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.bmc-empty-tab {
    padding: 40px 0;
    text-align: center;
}
.bmc-empty-tab__text {
    font-size: 14px;
    color: var(--text-tertiary);
    margin: 0;
}
</style>
