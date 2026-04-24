<template>
    <div class="py-2">
        <div class="flex items-start gap-3 p-4 bg-amber-50 rounded-lg border border-amber-200 mb-4">
            <AlertTriangle :size="16" class="text-amber-500 flex-shrink-0 mt-0.5" />
            <p class="text-sm text-amber-700">
                Webhook support is coming soon. You can use the email notifications in the meantime.
            </p>
        </div>
    </div>
</template>

<script>
import { AlertTriangle } from 'lucide-vue-next';
export default {
    name: 'Webhook',
    components: { AlertTriangle },
};
</script>
