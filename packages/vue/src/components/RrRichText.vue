<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRr } from '../context';
import { productCardCache } from '../composables/cache';
import { hydrateCards } from '../shared';

/**
 * Renders stored user HTML after DOMPurify (never raw), then turns product-card
 * references into cards.
 */
const props = withDefaults(defineProps<{ html: string; tag?: string; extraTags?: string[]; cards?: boolean }>(), { tag: 'div', cards: true });
const rr = useRr();
const root = ref<HTMLElement | null>(null);
const safe = computed(() => rr.sanitize(props.html, props.extraTags));

async function hydrate() {
    await nextTick();

    if (props.cards && root.value) {
        await hydrateCards(root.value, rr.api, { locale: rr.locale, t: rr.t, cache: productCardCache as Map<string, Promise<unknown>> });
    }
}

onMounted(hydrate);
watch(safe, hydrate);
</script>

<template>
    <component :is="tag" ref="root" class="rr-prose" v-html="safe" />
</template>
