<script setup lang="ts">
import { computed } from 'vue';
import { useRr } from '../context';
import { formatMonth, formatScore, sparklinePath } from '../shared';
import type { TrendPoint } from '../types';

const props = withDefaults(defineProps<{ points: TrendPoint[]; scaleMax: number; width?: number; height?: number }>(), { width: 120, height: 32 });
const rr = useRr();

const path = computed(() => sparklinePath(props.points, props.scaleMax, props.width, props.height));
const label = computed(() => {
    const usable = props.points.filter((point) => point.average !== null);

    if (usable.length === 0) {
        return '';
    }

    const first = usable[0];
    const last = usable[usable.length - 1];

    return rr.t('summary.trend_label', {
        from: formatMonth(first.period, rr.locale),
        first: formatScore(first.average, rr.locale),
        to: formatMonth(last.period, rr.locale),
        last: formatScore(last.average, rr.locale),
    });
});
</script>

<template>
    <svg v-if="path.line" class="rr-sparkline" :viewBox="`0 0 ${width} ${height}`" :width="width" :height="height" role="img" :aria-label="label">
        <path class="rr-sparkline__line" :d="path.line" fill="none" />
        <circle v-if="path.dots.length" class="rr-sparkline__dot" :cx="path.dots[path.dots.length - 1].x" :cy="path.dots[path.dots.length - 1].y" r="2.5" />
    </svg>
</template>
