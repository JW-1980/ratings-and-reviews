<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import { formatDate } from '../shared';
import RrDialog from './RrDialog.vue';
import RrRichText from './RrRichText.vue';
import type { Revision } from '../types';

const props = defineProps<{ open: boolean; commentId: number | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const rr = useRr();
const revisions = ref<Revision[] | null>(null);
const error = ref('');

watch(() => [props.open, props.commentId], async () => {
    if (!props.open || props.commentId === null) {
        return;
    }

    revisions.value = null;
    error.value = '';

    try {
        revisions.value = (await rr.api.revisions(props.commentId)) as Revision[];
    } catch (failure) {
        error.value = errorMessage(failure, rr.t);
    }
}, { immediate: true });
</script>

<template>
    <RrDialog :open="open" :title="rr.t('discussion.revisions_title')" @update:open="emit('update:open', $event)">
        <p v-if="error" class="rr-alert rr-alert--error" role="alert">{{ error }}</p>
        <p v-else-if="revisions === null" class="rr-help" role="status">{{ rr.t('common.loading') }}</p>
        <p v-else-if="!revisions.length" class="rr-help">{{ rr.t('discussion.revisions_empty') }}</p>
        <ol v-else class="rr-revisions">
            <li v-for="revision in revisions" :key="revision.revision" class="rr-revision">
                <p class="rr-revision__title">{{ rr.t('discussion.revision', { number: revision.revision, date: formatDate(revision.editedAt, rr.locale, { dateStyle: 'medium', timeStyle: 'short' }) }) }}</p>
                <RrRichText :html="revision.bodyHtml" :cards="false" />
            </li>
        </ol>
    </RrDialog>
</template>
