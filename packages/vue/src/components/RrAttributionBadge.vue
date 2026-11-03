<script setup lang="ts">
import { useRr } from '../context';
import type { Attribution } from '../types';

/** The badge of a contribution posted "as" a brand or shop (AttributionResolver). */
defineProps<{ attribution: Attribution | null }>();
const rr = useRr();
</script>

<template>
    <span v-if="attribution && attribution.label" class="rr-tag rr-tag--attribution" :data-rr-attribution="attribution.key">
        <span class="rr-visually-hidden">{{ rr.t('discussion.posted_as', { label: attribution.label }) }}</span>
        <span aria-hidden="true">
            <a v-if="attribution.url" :href="attribution.url">{{ attribution.label }}</a>
            <template v-else>{{ attribution.label }}</template>
        </span>
    </span>
</template>
