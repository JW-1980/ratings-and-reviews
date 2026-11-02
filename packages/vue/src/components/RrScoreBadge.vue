<script setup lang="ts">
import { computed } from 'vue';
import { useRr } from '../context';
import { formatScore } from '../shared';

/** A score as "4/5" or "8/10" with filled marks for 5-point scales. */
const props = withDefaults(defineProps<{ value: number | null; max: number; digits?: number; size?: 'sm' | 'md' }>(), { digits: 0, size: 'sm' });
const rr = useRr();
const label = computed(() => rr.t('reviews.rating', { rating: formatScore(props.value, rr.locale, props.digits), max: props.max }));
const fill = computed(() => (props.value === null ? 0 : Math.max(0, Math.min(1, (props.value - 1) / Math.max(1, props.max - 1)))));
</script>

<template>
    <span class="rr-score" :class="`rr-score--${size}`" role="img" :aria-label="label" :style="{ '--rr-fill': fill }">
        <span v-if="max <= 5" class="rr-score__stars" aria-hidden="true">
            <span v-for="n in max" :key="n" class="rr-score__star" :class="{ 'is-on': value !== null && n <= Math.round(value) }">★</span>
        </span>
        <span class="rr-score__value" aria-hidden="true">{{ formatScore(value, rr.locale, digits) }}<span class="rr-score__max">/{{ max }}</span></span>
    </span>
</template>
