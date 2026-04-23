<template>
    <div class="bmc-code">
        <span v-if="label" class="bmc-code__label">{{ label }}</span>
        <div class="bmc-code__block">
            <code class="bmc-code__text">{{ code }}</code>
            <button class="bmc-code__copy" @click="handleCopy" :title="'Copy'">
                <Check v-if="copied" :size="14" />
                <Copy v-else :size="14" />
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { Copy, Check } from 'lucide-vue-next';
import { useClipboard } from '../../composables/useClipboard';

const props = defineProps({
    code: { type: String, required: true },
    label: { type: String, default: '' }
});

const { copy } = useClipboard();
const copied = ref(false);

async function handleCopy() {
    await copy(props.code);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}
</script>

<style scoped>
.bmc-code__label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 6px;
}
.bmc-code__block {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-primary);
    border-radius: 8px;
    padding: 10px 12px;
}
.bmc-code__text {
    flex: 1;
    font-family: var(--font-mono);
    font-size: 13px;
    color: var(--text-primary);
    word-break: break-all;
}
.bmc-code__copy {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 6px;
    background: var(--bg-primary);
    color: #64748b;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.15s ease;
}
.bmc-code__copy:hover {
    color: var(--text-primary);
    background: var(--bg-hover);
}
</style>
