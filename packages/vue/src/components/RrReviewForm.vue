<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRr } from '../context';
import { errorMessage, fieldErrors } from '../composables/errors';
import { useAnnouncer } from '../composables/useAnnouncer';
import { evaluateQuality, toneSuggestionFromError } from '../shared';
import RrEditor from './RrEditor.vue';
import RrQualityMeter from './RrQualityMeter.vue';
import RrScaleInput from './RrScaleInput.vue';
import type { FacetGroup, Option, ProductRef, QualityResult, QualityRules, Review, ReviewTopic, SubjectRef } from '../types';

const props = withDefaults(defineProps<{
    subject: SubjectRef;
    /** The section's review scale (5 or 10, `review_scale`). */
    scale: number;
    topics?: ReviewTopic[];
    /** Context facets (config `reviews.facets`, as the reviews meta `facets` serves them): one choice per group. */
    facetOptions?: FacetGroup[];
    /** Comparison verdicts `[{ value, label }]` (reviews meta `comparisons`); defaults to the ui translations. */
    comparisons?: Option[];
    /** Editor tools for the review text (the review section's `editor_tools`). */
    tools?: string[];
    /** `review_extended_fields`: pros and cons. */
    extendedFields?: boolean;
    /** An existing review to edit. */
    review?: Review | null;
    /** Rules for the quality meter; fetched from GET review-quality-rules when absent. */
    qualityRules?: QualityRules | null;
    maxTitle?: number;
    maxPoints?: number;
}>(), { topics: () => [], facetOptions: () => [], comparisons: () => [], tools: () => ['bold', 'italic', 'underline', 'bullet_list', 'ordered_list'], extendedFields: true, review: null, qualityRules: null, maxTitle: 150, maxPoints: 5 });

const emit = defineEmits<{ saved: [payload: { item: Review; message: string | null }]; cancel: [] }>();
const rr = useRr();
const { message: live, announce } = useAnnouncer();
const uid = `rr-review-form-${Math.random().toString(36).slice(2)}`;

const form = reactive({
    rating: props.review?.rating ?? null as number | null,
    title: props.review?.title ?? '',
    body: props.review?.bodyHtml ?? '',
    pros: props.review?.pros.length ? [...props.review.pros] : [''],
    cons: props.review?.cons.length ? [...props.review.cons] : [''],
    topicRatings: Object.fromEntries(props.topics.map((topic) => [String(topic.id), props.review?.topicRatings.find((rating) => rating.topicId === topic.id)?.value ?? null])) as Record<string, number | null>,
    facets: Object.fromEntries(props.review?.facets.map((facet) => [facet.group, facet.value]) ?? []) as Record<string, string | null>,
    comparison: props.review?.comparison ? { subject: props.review.comparison.subject, verdict: props.review.comparison.verdict ?? 'same' } : null as { subject: ProductRef; verdict: string } | null,
});

const rules = ref<QualityRules | null>(props.qualityRules);
const saving = ref(false);
const error = ref('');
const errors = ref<Record<string, string>>({});
const tone = ref<string | null>(null);
const verdicts = computed<Option[]>(() => (props.comparisons.length ? props.comparisons : ['better', 'same', 'worse'].map((value) => ({ value, label: rr.t(`comparison.${value}`) }))));
const startedAt = Math.floor(Date.now() / 1000);

onMounted(async () => {
    if (!rules.value) {
        try {
            rules.value = (await rr.api.qualityRules()) as QualityRules | null;
        } catch {
            // No rules endpoint (yet): the meter stays hidden; the form works without it.
            rules.value = null;
        }
    }
});

const quality = computed<QualityResult>(() => evaluateQuality({
    body: form.body,
    pros: form.pros,
    cons: form.cons,
    topicRatings: form.topicRatings,
    topicCount: props.topics.length,
    extendedFields: props.extendedFields,
}, rules.value) as QualityResult);

function addPoint(list: string[]) {
    if (list.length < props.maxPoints) {
        list.push('');
    }
}

function removePoint(list: string[], index: number) {
    list.splice(index, 1);

    if (list.length === 0) {
        list.push('');
    }
}

function setComparison(subject: ProductRef | null) {
    form.comparison = subject ? { subject, verdict: form.comparison?.verdict ?? 'better' } : null;
}

async function submit(confirmTone = false) {
    errors.value = {};
    error.value = '';
    tone.value = null;

    if (!form.rating) {
        errors.value = { rating: rr.t('form.rating_required') };
        document.getElementById(`${uid}-rating`)?.querySelector('input')?.focus();

        return;
    }

    saving.value = true;

    const payload = {
        rating: form.rating,
        title: form.title,
        body: form.body,
        pros: props.extendedFields ? form.pros : [],
        cons: props.extendedFields ? form.cons : [],
        topicRatings: form.topicRatings,
        facets: props.facetOptions.length ? form.facets : undefined,
        comparison: form.comparison ? { type: form.comparison.subject.type, id: form.comparison.subject.id, verdict: form.comparison.verdict } : null,
        confirmTone,
        formStartedAt: props.review ? undefined : startedAt,
    };

    try {
        const result = props.review
            ? await rr.api.updateReview(props.review.id, payload)
            : await rr.api.createReview(props.subject, payload);

        announce(result.message ?? '');
        emit('saved', result as { item: Review; message: string | null });
    } catch (failure) {
        const suggestion = toneSuggestionFromError(failure);

        if (suggestion) {
            tone.value = suggestion;

            return;
        }

        errors.value = fieldErrors(failure);
        error.value = Object.keys(errors.value).length ? rr.t('form.errors') : errorMessage(failure, rr.t);
    } finally {
        saving.value = false;
    }
}

const describedBy = (field: string, help?: string) => [help, errors.value[field] ? `${uid}-${field}-error` : null].filter(Boolean).join(' ') || undefined;
</script>

<template>
    <section class="rr rr-review-form" :aria-labelledby="`${uid}-title`">
        <h2 :id="`${uid}-title`" class="rr-section-header__title">{{ review ? rr.t('form.edit_title') : rr.t('form.title') }}</h2>

        <p v-if="!rr.isAuthenticated" class="rr-notice">
            <a v-if="rr.loginUrl" :href="rr.loginUrl">{{ rr.t('form.login_required') }}</a>
            <template v-else>{{ rr.t('form.login_required') }}</template>
        </p>

        <form v-else class="rr-form" novalidate @submit.prevent="submit()">
            <p class="rr-visually-hidden" aria-live="polite" role="status">{{ live }}</p>
            <p v-if="error" class="rr-alert rr-alert--error" role="alert">{{ error }}</p>

            <div :id="`${uid}-rating`">
                <RrScaleInput
                    v-model="form.rating"
                    :max="scale"
                    :name="`${uid}-rating`"
                    :legend="rr.t('form.rating')"
                    required
                    :invalid="!!errors.rating"
                    :described-by="describedBy('rating')"
                />
                <p v-if="errors.rating" :id="`${uid}-rating-error`" class="rr-field-error">{{ errors.rating }}</p>
            </div>

            <fieldset v-if="topics.length" class="rr-fieldset">
                <legend class="rr-label">{{ rr.t('form.topics') }}</legend>
                <div class="rr-topic-inputs">
                    <RrScaleInput
                        v-for="topic in topics"
                        :key="topic.id"
                        v-model="form.topicRatings[String(topic.id)]"
                        :max="topic.scaleMax"
                        :name="`${uid}-topic-${topic.id}`"
                        :legend="topic.name"
                        clearable
                        :clear-label="rr.t('form.topic_clear', { name: topic.name })"
                        :invalid="!!errors[`topic_ratings.${topic.id}`]"
                    />
                </div>
            </fieldset>

            <div class="rr-field">
                <label :for="`${uid}-title-input`" class="rr-label">{{ rr.t('form.title_label') }}</label>
                <input :id="`${uid}-title-input`" v-model="form.title" type="text" class="rr-input" :maxlength="maxTitle" :aria-invalid="errors.title ? 'true' : undefined" :aria-describedby="describedBy('title', `${uid}-title-help`)">
                <p :id="`${uid}-title-help`" class="rr-help">{{ rr.t('form.title_help', { max: maxTitle }) }}</p>
                <p v-if="errors.title" :id="`${uid}-title-error`" class="rr-field-error">{{ errors.title }}</p>
            </div>

            <div class="rr-field">
                <span :id="`${uid}-body-label`" class="rr-label">{{ rr.t('form.body') }}</span>
                <RrEditor v-model="form.body" :tools="tools" :label="rr.t('form.body')" :described-by="describedBy('body', `${uid}-body-help`)" :invalid="!!errors.body" @submit="submit()" />
                <p :id="`${uid}-body-help`" class="rr-help">{{ rr.t('form.body_help') }}</p>
                <p v-if="errors.body" :id="`${uid}-body-error`" class="rr-field-error">{{ errors.body }}</p>
            </div>

            <div v-if="extendedFields" class="rr-points-inputs">
                <fieldset v-for="kind in (['pros', 'cons'] as const)" :key="kind" class="rr-fieldset">
                    <legend class="rr-label">{{ rr.t(`form.${kind}`) }}</legend>
                    <div v-for="(_, index) in form[kind]" :key="`${kind}-${index}`" class="rr-input-row">
                        <input v-model="form[kind][index]" type="text" class="rr-input" maxlength="200" :aria-label="`${rr.t(`form.${kind}`)}: ${rr.t('form.point', { number: index + 1 })}`" :aria-invalid="errors[`${kind}.${index}`] ? 'true' : undefined">
                        <button v-if="form[kind].length > 1 || form[kind][index] !== ''" type="button" class="rr-icon-button" :aria-label="rr.t('form.remove_point', { number: index + 1 })" @click="removePoint(form[kind], index)">×</button>
                    </div>
                    <button v-if="form[kind].length < maxPoints" type="button" class="rr-button rr-button--small rr-button--ghost" @click="addPoint(form[kind])">{{ rr.t(kind === 'pros' ? 'form.add_pro' : 'form.add_con') }}</button>
                </fieldset>
            </div>

            <fieldset v-if="facetOptions.length" class="rr-fieldset">
                <legend class="rr-label">{{ rr.t('form.facets') }}</legend>
                <fieldset v-for="group in facetOptions" :key="group.group" class="rr-fieldset rr-fieldset--nested">
                    <legend class="rr-label rr-label--minor">{{ group.label }}</legend>
                    <div class="rr-chips">
                        <label v-for="option in group.values" :key="option.value" class="rr-chip rr-chip--input">
                            <input v-model="form.facets[group.group]" type="radio" :name="`${uid}-facet-${group.group}`" :value="option.value">
                            <span>{{ option.label }}</span>
                        </label>
                        <label class="rr-chip rr-chip--input">
                            <input v-model="form.facets[group.group]" type="radio" :name="`${uid}-facet-${group.group}`" :value="null">
                            <span>{{ rr.t('form.facet_none') }}</span>
                        </label>
                    </div>
                    <p v-if="errors[`facets.${group.group}`]" class="rr-field-error">{{ errors[`facets.${group.group}`] }}</p>
                </fieldset>
            </fieldset>

            <fieldset v-if="$slots['comparison-picker'] || form.comparison" class="rr-fieldset rr-comparison-input">
                <legend class="rr-label">{{ rr.t('form.comparison') }}</legend>
                <p class="rr-help">{{ rr.t('form.comparison_help') }}</p>
                <slot name="comparison-picker" :comparison="form.comparison?.subject ?? null" :select="setComparison" />
                <div v-if="form.comparison" class="rr-comparison-input__chosen">
                    <span class="rr-tag">{{ form.comparison.subject.title }}</span>
                    <label class="rr-inline-field">
                        <span class="rr-label">{{ rr.t('form.comparison_verdict') }}</span>
                        <select v-model="form.comparison.verdict" class="rr-select">
                            <option v-for="option in verdicts" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </label>
                    <button type="button" class="rr-button rr-button--small rr-button--ghost" @click="setComparison(null)">{{ rr.t('form.comparison_remove') }}</button>
                </div>
                <p v-if="errors.compared_id || errors.compared_type || errors.comparison" class="rr-field-error">{{ errors.compared_id || errors.compared_type || errors.comparison }}</p>
            </fieldset>

            <div v-if="tone" class="rr-tone" role="alertdialog" :aria-labelledby="`${uid}-tone-title`" :aria-describedby="`${uid}-tone-body`">
                <p :id="`${uid}-tone-title`" class="rr-tone__title">{{ rr.t('tone.title') }}</p>
                <p :id="`${uid}-tone-body`" class="rr-tone__body">{{ tone }}</p>
                <div class="rr-actions rr-actions--start">
                    <button type="button" class="rr-button rr-button--small rr-button--primary" autofocus @click="tone = null">{{ rr.t('tone.rephrase') }}</button>
                    <button type="button" class="rr-button rr-button--small rr-button--ghost" @click="submit(true)">{{ rr.t('tone.post_anyway') }}</button>
                </div>
            </div>

            <RrQualityMeter :result="quality" />

            <div class="rr-actions">
                <button v-if="review" type="button" class="rr-button rr-button--ghost" @click="emit('cancel')">{{ rr.t('common.cancel') }}</button>
                <button type="submit" class="rr-button rr-button--primary" :disabled="saving" :aria-busy="saving ? 'true' : undefined">
                    {{ saving ? rr.t('form.submitting') : (review ? rr.t('form.update') : rr.t('form.submit')) }}
                </button>
            </div>
        </form>
    </section>
</template>
