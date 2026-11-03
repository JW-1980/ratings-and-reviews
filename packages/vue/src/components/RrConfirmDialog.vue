<script setup lang="ts">
import { useId } from 'vue';
import { useRr } from '../context';
import RrDialog from './RrDialog.vue';

defineProps<{ open: boolean; title: string; message: string; confirmLabel?: string; busy?: boolean }>();
const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [] }>();
const rr = useRr();
const messageId = `rr-confirm-${useId()}`;
</script>

<template>
    <RrDialog :open="open" :title="title" :described-by="messageId" @update:open="emit('update:open', $event)">
        <p :id="messageId" class="rr-help">{{ message }}</p>
        <div class="rr-actions">
            <button type="button" class="rr-button rr-button--ghost" autofocus @click="emit('update:open', false)">{{ rr.t('common.cancel') }}</button>
            <button type="button" class="rr-button rr-button--danger" :disabled="busy" :aria-busy="busy ? 'true' : undefined" @click="emit('confirm')">{{ confirmLabel ?? rr.t('common.confirm') }}</button>
        </div>
    </RrDialog>
</template>
