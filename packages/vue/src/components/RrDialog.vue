<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRr } from '../context';

/**
 * Modal dialog on the native <dialog> element (focus trap, Escape and inert background by
 * the browser). Focus returns to the element that was focused before opening.
 */
const props = defineProps<{ open: boolean; title: string; describedBy?: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean]; close: [] }>();
const rr = useRr();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = `rr-dialog-${Math.random().toString(36).slice(2)}`;
let returnFocus: HTMLElement | null = null;

function show() {
    const element = dialog.value;

    if (!element || element.open) {
        return;
    }

    returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    if (typeof element.showModal === 'function') {
        element.showModal();
    } else {
        element.setAttribute('open', '');
    }

    nextTick(() => {
        const first = element.querySelector<HTMLElement>('[autofocus], input, select, textarea, button:not(.rr-dialog__close)');
        first?.focus();
    });
}

function hide() {
    const element = dialog.value;

    if (element?.open) {
        if (typeof element.close === 'function') {
            element.close();
        } else {
            element.removeAttribute('open');
        }
    }

    returnFocus?.focus?.();
    returnFocus = null;
}

function requestClose() {
    emit('update:open', false);
    emit('close');
}

watch(() => props.open, (open) => (open ? nextTick(show) : hide()), { immediate: true });
onBeforeUnmount(hide);
</script>

<template>
    <dialog ref="dialog" class="rr rr-dialog" :aria-labelledby="titleId" :aria-describedby="describedBy" @cancel.prevent="requestClose" @close="open && requestClose()">
        <div class="rr-dialog__panel">
            <header class="rr-dialog__header">
                <h2 :id="titleId" class="rr-dialog__title">{{ title }}</h2>
                <button type="button" class="rr-icon-button rr-dialog__close" :aria-label="rr.t('common.close')" @click="requestClose">×</button>
            </header>
            <slot />
        </div>
    </dialog>
</template>
