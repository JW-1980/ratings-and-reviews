<script setup lang="ts">
import { computed } from 'vue';
import { useRr } from '../context';
import type { Annotation, Author } from '../types';

/**
 * The badge row of a card header: the author's badges (AuthorResolver `extra.badges`:
 * reliability, flair) followed by the ContentAnnotator's annotations of the item (a health
 * claim warning). Tone is shown by an icon and a visually hidden word as well as colour.
 */
const props = withDefaults(defineProps<{ annotations?: Annotation[]; author?: Author | null }>(), { annotations: () => [], author: null });
const rr = useRr();

const ICONS = { info: 'ℹ', warning: '⚠', success: '✓' } as const;

const marks = computed(() => [
    ...(props.author?.cardBadges ?? []).map((mark) => ({ ...mark, source: 'author' })),
    ...props.annotations.map((mark) => ({ ...mark, source: 'content' })),
]);
</script>

<template>
    <ul v-if="marks.length" class="rr-annotations" :aria-label="rr.t('annotations.label')">
        <li v-for="(mark, index) in marks" :key="`${mark.source}-${mark.key}-${index}`">
            <span class="rr-tag rr-tag--annotation" :data-rr-annotation="mark.key" :data-rr-annotation-source="mark.source" :data-tone="mark.tone">
                <span class="rr-tag__icon" aria-hidden="true">{{ ICONS[mark.tone] }}</span>
                <span class="rr-visually-hidden">{{ rr.t(`annotations.tone_${mark.tone}`) }}: </span>
                <a v-if="mark.url" :href="mark.url">{{ mark.label }}</a>
                <template v-else>{{ mark.label }}</template>
            </span>
        </li>
    </ul>
</template>
