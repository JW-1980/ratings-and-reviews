<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRr } from '../context';
import { createUndoCountdown } from '../shared';

/**
 * "Undo" toast with a countdown. Emits `undo` when the reader withdraws in time and
 * `expire` when the window closes. Announced politely; the button is reachable by keyboard
 * and focused when the toast appears.
 */
const props = defineProps<{ seconds: number }>();
const emit = defineEmits<{ undo: []; expire: [] }>();
const rr = useRr();
const remaining = ref(props.seconds);
const button = ref<HTMLButtonElement | null>(null);

const countdown = createUndoCountdown({
    seconds: props.seconds,
    onTick: (left: number) => (remaining.value = left),
    onExpire: () => emit('expire'),
});

function undo() {
    if (countdown.cancel()) {
        emit('undo');
    }
}

onMounted(() => button.value?.focus());
onBeforeUnmount(() => countdown.dispose());
defineExpose({ focus: () => button.value?.focus() });
</script>

<template>
    <div class="rr-toast" role="status" aria-live="polite" aria-atomic="true">
        <span class="rr-toast__text">{{ rr.tc('undo.message', remaining) }}</span>
        <span class="rr-toast__progress" aria-hidden="true" :style="{ '--rr-undo-duration': `${seconds}s` }" />
        <button ref="button" type="button" class="rr-button rr-button--small rr-button--inverse" @click="undo">{{ rr.t('undo.action') }}</button>
    </div>
</template>
