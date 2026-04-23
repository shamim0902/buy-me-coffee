<template>
    <div>
        <PageTitle title="Notifications" subtitle="Configure email and webhook notifications" />

        <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6">
            <el-tabs :tab-position="'top'">
                <el-tab-pane label="Emails">
                    <Emails />
                </el-tab-pane>
                <el-tab-pane label="Webhook">
                    <Webhook />
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
