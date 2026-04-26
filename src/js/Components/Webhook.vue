<template>
    <div class="bmc-webhook">
        <div class="bmc-webhook-card">
            <div class="bmc-webhook-card__header">
                <div>
                    <h4 class="bmc-webhook-card__title">Webhook Configuration</h4>
                    <p class="bmc-webhook-card__desc">Send real-time event data to external services.</p>
                </div>
                <button class="bmc-webhook-card__add" disabled>
                    <Plus :size="14" />
                    Add Webhook
                </button>
            </div>

            <div class="bmc-webhook-notice">
                <AlertTriangle :size="16" class="bmc-webhook-notice__icon" />
                <p class="bmc-webhook-notice__text">
                    Webhook support is coming soon. You can use the email notifications in the meantime.
                </p>
            </div>
        </div>
    </div>
</template>

<script>
import { AlertTriangle, Plus } from 'lucide-vue-next';
export default {
    name: 'Webhook',
    components: { AlertTriangle, Plus },
};
</script>

<style scoped>
.bmc-webhook {
    padding-top: 8px;
}
.bmc-webhook-card {
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
}
.bmc-webhook-card__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: var(--color-neutral-800);
    color: #fff;
}
.bmc-webhook-card__title {
    font-size: 14px;
    font-weight: 600;
    margin: 0;
    color: #fff;
}
.bmc-webhook-card__desc {
    font-size: 12px;
    color: var(--color-neutral-400);
    margin: 2px 0 0;
}
.bmc-webhook-card__add {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 500;
    border-radius: var(--radius-sm);
    border: 0;
    background: var(--color-primary-500);
    color: #fff;
    cursor: not-allowed;
    opacity: 0.6;
}

.bmc-webhook-notice {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 20px;
    background: var(--color-warning-50);
    border-top: 1px solid var(--border-secondary);
}
.bmc-webhook-notice__icon {
    flex-shrink: 0;
    color: var(--color-warning-500);
    margin-top: 1px;
}
.bmc-webhook-notice__text {
    font-size: 13px;
    color: var(--color-warning-700);
    margin: 0;
    line-height: 1.5;
}
</style>
