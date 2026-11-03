<script setup lang="ts">
import { computed, useId } from 'vue';
import { useRr } from '../context';
import type { QualityResult } from '../types';

const props = defineProps<{ result: QualityResult }>();
const rr = useRr();
const uid = `rr-quality-${useId()}`;
const levelLabel = computed(() => props.result.levelLabel ?? (props.result.level ? rr.t(`quality.level.${props.result.level}`) : ''));
const hintText = (hint: QualityResult['hints'][number]) => hint.label ?? rr.t(`quality.hint.${hint.hint}`);
</script>

<template>
    <div v-if="result.available" class="rr-quality" :data-level="result.level ?? undefined">
        <div class="rr-quality__header">
            <span :id="`${uid}-label`" class="rr-label">{{ rr.t('quality.title') }}</span>
            <span class="rr-quality__level">{{ levelLabel }}</span>
        </div>
        <div
            class="rr-meter"
            role="meter"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="result.score"
            :aria-valuetext="`${rr.t('quality.score', { score: result.score })}, ${levelLabel}`"
            :aria-labelledby="`${uid}-label`"
        >
            <span class="rr-meter__fill" :style="{ inlineSize: `${result.score}%` }" />
        </div>
        <ul v-if="result.hints.length" class="rr-quality__hints" :aria-label="rr.t('quality.hints')">
            <li v-for="hint in result.hints" :key="hint.key">{{ hintText(hint) }}</li>
        </ul>
        <p v-else class="rr-quality__done">{{ rr.t('quality.complete') }}</p>
    </div>
</template>
