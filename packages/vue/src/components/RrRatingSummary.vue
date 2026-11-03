<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import { formatScore } from '../shared';
import RrSparkline from './RrSparkline.vue';
import type { RatingSummary, SubjectRef } from '../types';

const props = defineProps<{
    subject: SubjectRef;
    /** Preloaded summary (SSR or host props); fetched when absent. */
    summary?: RatingSummary | null;
    /** The active rating filter (v-model:rating), shared with <RrReviews>. */
    rating?: number | null;
    /** The active facet filters, group => value (v-model:facets). */
    facets?: Record<string, string>;
}>();

const emit = defineEmits<{ 'update:rating': [value: number | null]; 'update:facets': [value: Record<string, string>] }>();
const rr = useRr();

const data = ref<RatingSummary | null>(props.summary ?? null);
const loading = ref(false);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';

    try {
        data.value = await rr.api.ratingSummary(props.subject);
    } catch (failure) {
        error.value = errorMessage(failure, rr.t);
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    if (!data.value) {
        load();
    }
});

watch(() => props.summary, (value) => {
    if (value) {
        data.value = value;
    }
});

watch(() => [props.subject.type, props.subject.id], load);

const total = computed(() => data.value?.histogram.reduce((sum, bucket) => sum + bucket.count, 0) ?? 0);
const percent = (count: number) => (total.value === 0 ? 0 : Math.round((count / total.value) * 100));
const fmt = (value: number | null, digits = 1) => formatScore(value, rr.locale, digits);

function toggleRating(bucket: number) {
    emit('update:rating', props.rating === bucket ? null : bucket);
}

function toggleFacet(group: string, value: string) {
    const next = { ...(props.facets ?? {}) };

    if (next[group] === value) {
        delete next[group];
    } else {
        next[group] = value;
    }

    emit('update:facets', next);
}

const facetGroups = computed(() => (data.value?.facets ?? []).filter((group) => group.values.some((value) => value.count > 0)));
const questions = computed(() => (data.value?.questions ?? []).filter((question) => question.count > 0));
const followUps = computed(() => (data.value?.followUps?.enabled && data.value.followUps.count > 0 ? data.value.followUps : null));
const sharePercent = (share: number | null) => (share === null ? 0 : Math.round(share * 100));
const verdictCount = (count: number) => rr.tc('summary.switch_count', count);

defineExpose({ reload: load });
</script>

<template>
    <section class="rr rr-summary" :aria-labelledby="`rr-summary-title-${subject.type}-${subject.id}`" :aria-busy="loading ? 'true' : 'false'">
        <h2 :id="`rr-summary-title-${subject.type}-${subject.id}`" class="rr-summary__title">{{ rr.t('summary.title') }}</h2>

        <p v-if="error" class="rr-alert rr-alert--error" role="alert">
            {{ error }}
            <button type="button" class="rr-button rr-button--link" @click="load">{{ rr.t('common.retry') }}</button>
        </p>
        <div v-else-if="!data" class="rr-skeleton rr-summary__skeleton" aria-hidden="true" />

        <template v-else>
            <div class="rr-summary__headline">
                <p class="rr-summary__score">
                    <span class="rr-summary__value">{{ fmt(data.average) }}</span>
                    <span class="rr-summary__max">/{{ data.scaleMax }}</span>
                    <span v-if="data.uncertainty?.margin !== null && data.uncertainty?.margin !== undefined && data.count > 0" class="rr-summary__band" :title="rr.t('summary.uncertainty_help', { low: fmt(data.uncertainty.low), high: fmt(data.uncertainty.high) })">
                        {{ rr.t('summary.uncertainty', { margin: fmt(data.uncertainty.margin) }) }}
                    </span>
                    <span class="rr-visually-hidden">{{ rr.t('summary.score_out_of', { score: fmt(data.average), max: data.scaleMax }) }}</span>
                </p>
                <p class="rr-summary__count">{{ rr.tc('summary.based_on', data.count) }}</p>
                <p v-if="data.uncertainty && data.count > 0" class="rr-summary__band-help">
                    {{ data.uncertainty.fewReviews ? rr.t('summary.few_reviews') : rr.t('summary.uncertainty_help', { low: fmt(data.uncertainty.low), high: fmt(data.uncertainty.high) }) }}
                </p>
                <p v-if="data.bayesianAverage !== null && data.count > 0" class="rr-summary__weighted" :title="rr.t('summary.weighted_help')">
                    {{ rr.t('summary.weighted', { score: fmt(data.bayesianAverage) }) }}
                </p>
                <div v-if="data.trend.length > 1" class="rr-summary__trend">
                    <span class="rr-summary__label">{{ rr.t('summary.trend') }}</span>
                    <RrSparkline :points="data.trend" :scale-max="data.scaleMax" />
                </div>
            </div>

            <p v-if="data.count === 0" class="rr-empty">{{ rr.t('summary.no_reviews') }}</p>

            <div v-else class="rr-summary__histogram" role="group" :aria-label="rr.t('summary.histogram')">
                <button
                    v-for="bucket in data.histogram"
                    :key="bucket.bucket"
                    type="button"
                    class="rr-histogram__row"
                    :class="{ 'is-active': rating === bucket.bucket }"
                    :aria-pressed="rating === bucket.bucket ? 'true' : 'false'"
                    :aria-label="`${rr.t('summary.histogram_row', { bucket: bucket.bucket, max: data.scaleMax, count: bucket.count, percent: percent(bucket.count) })}. ${rr.t('summary.histogram_filter', { bucket: bucket.bucket, max: data.scaleMax })}`"
                    :disabled="bucket.count === 0 && rating !== bucket.bucket"
                    @click="toggleRating(bucket.bucket)"
                >
                    <span class="rr-histogram__bucket" aria-hidden="true">{{ bucket.bucket }}</span>
                    <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" :style="{ inlineSize: `${percent(bucket.count)}%` }" /></span>
                    <span class="rr-histogram__count" aria-hidden="true">{{ bucket.count }}</span>
                </button>
            </div>

            <p v-if="rating" class="rr-summary__filter" role="status">
                {{ rr.t('summary.filter_active', { bucket: rating, max: data.scaleMax }) }}
                <button type="button" class="rr-button rr-button--link" @click="emit('update:rating', null)">{{ rr.t('summary.clear_filter') }}</button>
            </p>

            <div v-if="data.topics.length" class="rr-summary__topics">
                <h3 class="rr-summary__subtitle">{{ rr.t('summary.topics') }}</h3>
                <ul class="rr-topics">
                    <li v-for="topic in data.topics" :key="topic.id" class="rr-topic">
                        <span class="rr-topic__name">{{ topic.name }}</span>
                        <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" :style="{ inlineSize: topic.average === null ? '0%' : `${((topic.average - 1) / Math.max(1, topic.scaleMax - 1)) * 100}%` }" /></span>
                        <span class="rr-topic__score">
                            <template v-if="topic.average !== null">
                                <span aria-hidden="true">{{ fmt(topic.average) }}</span>
                                <span class="rr-visually-hidden">{{ rr.t('summary.topic_score', { name: topic.name, score: fmt(topic.average), max: topic.scaleMax }) }}</span>
                            </template>
                            <template v-else>{{ rr.t('summary.topic_none') }}</template>
                        </span>
                    </li>
                </ul>
            </div>

            <div v-if="questions.length" class="rr-summary__questions">
                <h3 class="rr-summary__subtitle">{{ rr.t('summary.questions') }}</h3>
                <ul class="rr-question-summaries">
                    <li v-for="question in questions" :key="question.key" class="rr-question-summary" :class="`rr-question-summary--${question.type}`">
                        <span class="rr-topic__name">{{ question.label }}</span>
                        <span class="rr-help">{{ rr.tc('summary.question_answers', question.count) }}</span>
                        <template v-if="question.type === 'scale'">
                            <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" :style="{ inlineSize: question.average === null ? '0%' : `${((question.average - 1) / Math.max(1, (question.scaleMax ?? 5) - 1)) * 100}%` }" /></span>
                            <span class="rr-topic__score">{{ question.average === null ? rr.t('summary.topic_none') : rr.t('summary.question_average', { average: fmt(question.average), max: question.scaleMax ?? 5 }) }}</span>
                            <span v-if="question.lowLabel || question.highLabel" class="rr-question-summary__anchors rr-help"><span>{{ question.lowLabel }}</span><span>{{ question.highLabel }}</span></span>
                        </template>
                        <ul v-else class="rr-question-summary__options">
                            <li v-for="option in question.options" :key="option.value" class="rr-question-summary__option">
                                <span class="rr-bar" aria-hidden="true"><span class="rr-bar__fill" :style="{ inlineSize: `${sharePercent(option.share)}%` }" /></span>
                                <span>{{ rr.t('summary.question_option', { label: option.label, percent: sharePercent(option.share), count: option.count }) }}</span>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>

            <div v-if="followUps" class="rr-summary__follow-ups">
                <h3 class="rr-summary__subtitle">{{ rr.t('summary.follow_ups') }}</h3>
                <template v-if="followUps.averageChange !== null">
                    <p class="rr-summary__follow-up-change" :data-direction="followUps.averageChange > 0 ? 'up' : followUps.averageChange < 0 ? 'down' : 'same'">
                        {{ rr.t('summary.follow_up_change', { change: `${followUps.averageChange > 0 ? '+' : ''}${fmt(followUps.averageChange)}`, before: fmt(followUps.averageBefore), after: fmt(followUps.averageAfter) }) }}
                    </p>
                    <p class="rr-help">{{ rr.tc('summary.follow_up_count', followUps.count) }}</p>
                </template>
                <p v-else class="rr-help">{{ rr.t('summary.follow_up_few') }}</p>
            </div>

            <div v-if="facetGroups.length" class="rr-summary__facets">
                <h3 class="rr-summary__subtitle">{{ rr.t('summary.facets') }}</h3>
                <div v-for="group in facetGroups" :key="group.group" class="rr-chips" role="group" :aria-label="group.label">
                    <span class="rr-chips__label" aria-hidden="true">{{ group.label }}</span>
                    <button
                        v-for="item in group.values"
                        :key="item.value"
                        type="button"
                        class="rr-chip"
                        :aria-pressed="facets?.[group.group] === item.value ? 'true' : 'false'"
                        :disabled="item.count === 0 && facets?.[group.group] !== item.value"
                        @click="toggleFacet(group.group, item.value)"
                    >{{ rr.t('summary.facet_count', { label: item.label, count: item.count }) }}</button>
                </div>
            </div>

            <div v-if="data.switchTo || data.alternatives.length" class="rr-summary__switches">
                <p v-if="data.switchTo" class="rr-switch rr-switch--highlight">
                    <span class="rr-switch__lead">{{ rr.t('summary.switches') }}</span>
                    <a v-if="data.switchTo.subject.url" :href="data.switchTo.subject.url" class="rr-switch__title">{{ data.switchTo.subject.title }}</a>
                    <span v-else class="rr-switch__title">{{ data.switchTo.subject.title }}</span>
                    <span class="rr-switch__count">({{ verdictCount(data.switchTo.count) }})</span>
                </p>
                <template v-if="data.alternatives.length">
                    <h3 class="rr-summary__subtitle">{{ rr.t('summary.alternatives') }}</h3>
                    <ul class="rr-switches">
                        <li v-for="entry in data.alternatives" :key="`${entry.subject.type}:${entry.subject.id}`" class="rr-switch">
                            <a v-if="entry.subject.url" :href="entry.subject.url" class="rr-switch__title">{{ entry.subject.title }}</a>
                            <span v-else class="rr-switch__title">{{ entry.subject.title }}</span>
                            <span class="rr-switch__count">{{ rr.t('summary.alternative_counts', { better: entry.better, same: entry.same, worse: entry.worse }) }}</span>
                        </li>
                    </ul>
                </template>
            </div>
        </template>
    </section>
</template>
