<template>
  <button
    type="button"
    class="bmc-media-btn"
    @click="openMediaFrame"
  >
    <ImageUp :size="14" />
    Upload Image
  </button>
</template>

<script setup>
import { onMounted } from 'vue';
import { ImageUp } from 'lucide-vue-next';

let mediaFrame = null;

const emit = defineEmits(['onMediaSelected']);

const openMediaFrame = () => {
  if (mediaFrame == null) return;
  mediaFrame.open();
};

onMounted(() => {
  if (!typeof window.wp.media === 'function') return;

  mediaFrame = window.wp.media({
    title: 'Select or Upload Media',
    button: { text: 'Use this image' },
    multiple: false,
  });

  mediaFrame.on('select', () => {
    const attachments = mediaFrame.state().get('selection').toJSON();
    emit('onMediaSelected', attachments);
  });
});
</script>

<style scoped>
.bmc-media-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  font-size: 13px;
  font-weight: 500;
  font-family: var(--font-sans);
  color: var(--text-secondary);
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  border-radius: 7px;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
  white-space: nowrap;
}

.bmc-media-btn:hover {
  background: var(--bg-hover);
  color: var(--text-primary);
  border-color: var(--border-secondary);
}
</style>
