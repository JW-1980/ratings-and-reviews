<script setup lang="ts">
import { computed, onMounted, reactive, ref, useId } from 'vue';
import { useRr } from '../context';
import { errorMessage, fieldErrors, uploadErrorMessage } from '../composables/errors';
import { useAnnouncer } from '../composables/useAnnouncer';
import { evaluateQuality, toneSuggestionFromError } from '../shared';
import RrEditor from './RrEditor.vue';
import RrQualityMeter from './RrQualityMeter.vue';
import RrScaleInput from './RrScaleInput.vue';
import type { FacetGroup, Option, Photo, ProductRef, QualityResult, QualityRules, Review, ReviewPage, ReviewQuestion, ReviewTopic, SubjectRef } from '../types';

const props = withDefaults(defineProps<{
    subject: SubjectRef;
    /** The section's review scale (5 or 10, `review_scale`); defaults to `meta.reviewScale`. */
    scale?: number;
    /**
     * The reviews meta of this subject (the `loaded` event of <RrReviews>, or api.reviews()).
     * The form then follows the section: extended fields, minimum length, photo and length limits.
     */
    meta?: ReviewPage['meta'] | null;
    topics?: ReviewTopic[];
    /** Context facets (config `reviews.facets`, as the reviews meta `facets` serves them): one choice per group. */
    facetOptions?: FacetGroup[];
    /** Comparison verdicts `[{ value, label }]` (reviews meta `comparisons`); defaults to the ui translations. */
    comparisons?: Option[];
    /** Editor tools for the review text (the review section's `editor_tools`). */
    tools?: string[];
    /** `review_extended_fields`: title, pros and cons (default: `meta.extendedFields`, else true). */
    extendedFields?: boolean;
    /** `review_min_length` (default: `meta.minLength`, else 0). */
    minLength?: number;
    /** `reviews.max_photos` (default: `meta.form.maxPhotos`, else 0: no photos). */
    maxPhotos?: number;
    /** An existing review to edit. */
    review?: Review | null;
    /** Rules for the quality meter; fetched from GET review-quality-rules when absent. */
    qualityRules?: QualityRules | null;
    maxTitle?: number;
    maxPoints?: number;
    /** The subject's review questions (default: `meta.questions`). */
    questions?: ReviewQuestion[];
}>(), {
    scale: undefined, meta: null, topics: () => [], facetOptions: () => [], comparisons: () => [], tools: () => ['bold', 'italic', 'underline', 'bullet_list', 'ordered_list'],
    extendedFields: undefined, minLength: undefined, maxPhotos: undefined, review: null, qualityRules: null, maxTitle: undefined, maxPoints: undefined, questions: undefined,
});

const emit = defineEmits<{ saved: [payload: { item: Review; message: string | null }]; cancel: [] }>();
const rr = useRr();
const { message: live, announce } = useAnnouncer();
const uid = `rr-review-form-${useId()}`;

// Explicit props win; otherwise the section's values from the reviews meta.
const scaleMax = computed(() => props.scale ?? props.meta?.reviewScale ?? props.meta?.scaleMax ?? 5);
const extended = computed(() => props.extendedFields ?? props.meta?.extendedFields ?? true);
const minimumLength = computed(() => props.minLength ?? props.meta?.minLength ?? 0);
const photoLimit = computed(() => props.maxPhotos ?? props.meta?.form.maxPhotos ?? 0);
const titleLimit = computed(() => props.maxTitle ?? props.meta?.form.maxTitleLength ?? 150);
const pointLimit = computed(() => props.maxPoints ?? props.meta?.form.maxProsCons ?? 5);
const pointLength = computed(() => props.meta?.form.maxProConLength ?? 200);
const questionList = computed<ReviewQuestion[]>(() => props.questions ?? props.meta?.questions ?? []);
const attributionOptions = computed(() => props.meta?.attributions ?? []);
const ownAnswers = Object.fromEntries((props.review?.answers ?? []).map((answer) => [answer.key, answer.value]));

const form = reactive({
    rating: props.review?.rating ?? null as number | null,
    title: props.review?.title ?? '',
    body: props.review?.bodyHtml ?? '',
    pros: props.review?.pros.length ? [...props.review.pros] : [''],
    cons: props.review?.cons.length ? [...props.review.cons] : [''],
    topicRatings: Object.fromEntries(props.topics.map((topic) => [String(topic.id), props.review?.topicRatings.find((rating) => rating.topicId === topic.id)?.value ?? null])) as Record<string, number | null>,
    facets: Object.fromEntries(props.review?.facets.map((facet) => [facet.group, facet.value]) ?? []) as Record<string, string | null>,
    comparison: props.review?.comparison ? { subject: props.review.comparison.subject, verdict: props.review.comparison.verdict ?? 'same' } : null as { subject: ProductRef; verdict: string } | null,
    photos: [...(props.review?.photos ?? [])] as Photo[],
    // Question key => a scale number, an option value or (several choices) a list.
    answers: Object.fromEntries(questionList.value.map((question) => [question.key, question.type === 'multi'
        ? (Array.isArray(ownAnswers[question.key]) ? [...(ownAnswers[question.key] as string[])] : [])
        : (ownAnswers[question.key] ?? null)])) as Record<string, number | string | string[] | null>,
    attribution: props.review?.attribution?.key ?? '',
});
const uploadingPhotos = ref(false);
const photoStatus = ref('');

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
    extendedFields: extended.value,
}, rules.value) as QualityResult);

function addPoint(list: string[]) {
    if (list.length < pointLimit.value) {
        list.push('');
    }
}

/** Uploads chosen photos at once (POST uploads); the review keeps their ids in order. */
async function addPhotos(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = [...(input.files ?? [])];
    input.value = '';
    delete errors.value.photos;

    for (const file of files) {
        if (form.photos.length >= photoLimit.value) {
            errors.value = { ...errors.value, photos: rr.tc('form.photos_limit', photoLimit.value) };
            break;
        }

        uploadingPhotos.value = true;
        photoStatus.value = rr.t('form.photo_uploading');

        try {
            const uploaded = await rr.api.uploadImage(file);
            form.photos.push({ id: uploaded.id, url: uploaded.url, alt: uploaded.alt ?? '', width: uploaded.width, height: uploaded.height });
            photoStatus.value = rr.t('form.photo_uploaded');
            announce(rr.t('form.photo_uploaded'));
        } catch (failure) {
            photoStatus.value = '';
            errors.value = { ...errors.value, photos: uploadErrorMessage(failure, rr.t) };
        } finally {
            uploadingPhotos.value = false;
        }
    }
}

function removePhoto(index: number) {
    form.photos.splice(index, 1);
    document.getElementById(`${uid}-photos-add`)?.focus();
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
        title: extended.value ? form.title : '',
        body: form.body,
        pros: extended.value ? form.pros : [],
        cons: extended.value ? form.cons : [],
        photos: photoLimit.value > 0 ? form.photos.map((photo) => Number(photo.id)) : undefined,
        topicRatings: form.topicRatings,
        facets: props.facetOptions.length ? form.facets : undefined,
        comparison: form.comparison ? { type: form.comparison.subject.type, id: form.comparison.subject.id, verdict: form.comparison.verdict } : null,
        answers: questionList.value.length ? form.answers : undefined,
        attribution: attributionOptions.value.length ? (form.attribution || null) : undefined,
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
        <p v-else-if="meta && !meta.reviewsEnabled" class="rr-notice">{{ rr.t('reviews.disabled') }}</p>
        <p v-else-if="meta && meta.canParticipate === false" class="rr-notice">{{ rr.t('reviews.participation_denied') }}</p>

        <form v-else-if="!meta || (meta.reviewsEnabled && meta.canParticipate !== false)" class="rr-form" novalidate @submit.prevent="submit()">
            <p class="rr-visually-hidden" aria-live="polite" role="status">{{ live }}</p>
            <p v-if="error" class="rr-alert rr-alert--error" role="alert">{{ error }}</p>

            <div :id="`${uid}-rating`">
                <RrScaleInput
                    v-model="form.rating"
                    :max="scaleMax"
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

            <fieldset v-if="questionList.length" class="rr-fieldset rr-questions">
                <legend class="rr-label">{{ rr.t('form.questions') }}</legend>
                <fieldset
                    v-for="question in questionList"
                    :key="question.key"
                    class="rr-fieldset rr-fieldset--nested rr-question"
                    :class="`rr-question--${question.type}`"
                    :aria-invalid="errors[`answers.${question.key}`] ? 'true' : undefined"
                    :aria-describedby="errors[`answers.${question.key}`] ? `${uid}-q-${question.key}-error` : undefined"
                >
                    <legend class="rr-label rr-label--minor">
                        {{ question.label }}
                        <span v-if="question.required" class="rr-tag">{{ rr.t('form.question_required') }}</span>
                    </legend>
                    <div v-if="question.type === 'scale'" class="rr-question__scale">
                        <span v-if="question.lowLabel" class="rr-question__anchor" aria-hidden="true">{{ question.lowLabel }}</span>
                        <div class="rr-chips">
                            <label v-if="!question.required" class="rr-chip rr-chip--input">
                                <input v-model="form.answers[question.key]" type="radio" :name="`${uid}-q-${question.key}`" :value="null">
                                <span>{{ rr.t('form.question_none') }}</span>
                            </label>
                            <label v-for="value in question.scaleMax ?? 5" :key="value" class="rr-chip rr-chip--input">
                                <input v-model="form.answers[question.key]" type="radio" :name="`${uid}-q-${question.key}`" :value="value">
                                <span aria-hidden="true">{{ value }}</span>
                                <span class="rr-visually-hidden">{{ rr.t('form.question_scale_option', { value, max: question.scaleMax ?? 5 }) }}<template v-if="value === 1 && question.lowLabel">, {{ question.lowLabel }}</template><template v-else-if="value === (question.scaleMax ?? 5) && question.highLabel">, {{ question.highLabel }}</template></span>
                            </label>
                        </div>
                        <span v-if="question.highLabel" class="rr-question__anchor" aria-hidden="true">{{ question.highLabel }}</span>
                    </div>
                    <div v-else class="rr-chips">
                        <label v-if="question.type === 'choice' && !question.required" class="rr-chip rr-chip--input">
                            <input v-model="form.answers[question.key]" type="radio" :name="`${uid}-q-${question.key}`" :value="null">
                            <span>{{ rr.t('form.question_none') }}</span>
                        </label>
                        <label v-for="option in question.options" :key="option.value" class="rr-chip rr-chip--input">
                            <input v-if="question.type === 'multi'" v-model="form.answers[question.key]" type="checkbox" :value="option.value">
                            <input v-else v-model="form.answers[question.key]" type="radio" :name="`${uid}-q-${question.key}`" :value="option.value">
                            <span>{{ option.label }}</span>
                        </label>
                    </div>
                    <p v-if="errors[`answers.${question.key}`]" :id="`${uid}-q-${question.key}-error`" class="rr-field-error">{{ errors[`answers.${question.key}`] }}</p>
                </fieldset>
            </fieldset>

            <div v-if="extended" class="rr-field">
                <label :for="`${uid}-title-input`" class="rr-label">{{ rr.t('form.title_label') }}</label>
                <input :id="`${uid}-title-input`" v-model="form.title" type="text" class="rr-input" :maxlength="titleLimit" :aria-invalid="errors.title ? 'true' : undefined" :aria-describedby="describedBy('title', `${uid}-title-help`)">
                <p :id="`${uid}-title-help`" class="rr-help">{{ rr.t('form.title_help', { max: titleLimit }) }}</p>
                <p v-if="errors.title" :id="`${uid}-title-error`" class="rr-field-error">{{ errors.title }}</p>
            </div>

            <div class="rr-field">
                <span :id="`${uid}-body-label`" class="rr-label">{{ rr.t('form.body') }}</span>
                <RrEditor v-model="form.body" :tools="tools" :label="rr.t('form.body')" :described-by="describedBy('body', `${uid}-body-help`)" :invalid="!!errors.body" @submit="submit()" />
                <p :id="`${uid}-body-help`" class="rr-help">{{ rr.t('form.body_help') }}<template v-if="minimumLength > 0"> {{ rr.tc('form.min_length', minimumLength) }}</template></p>
                <p v-if="errors.body" :id="`${uid}-body-error`" class="rr-field-error">{{ errors.body }}</p>
            </div>

            <div v-if="extended" class="rr-points-inputs">
                <fieldset v-for="kind in (['pros', 'cons'] as const)" :key="kind" class="rr-fieldset">
                    <legend class="rr-label">{{ rr.t(`form.${kind}`) }}</legend>
                    <div v-for="(_, index) in form[kind]" :key="`${kind}-${index}`" class="rr-input-row">
                        <input v-model="form[kind][index]" type="text" class="rr-input" :maxlength="pointLength" :aria-label="`${rr.t(`form.${kind}`)}: ${rr.t('form.point', { number: index + 1 })}`" :aria-invalid="errors[`${kind}.${index}`] ? 'true' : undefined">
                        <button v-if="form[kind].length > 1 || form[kind][index] !== ''" type="button" class="rr-icon-button" :aria-label="rr.t('form.remove_point', { number: index + 1 })" @click="removePoint(form[kind], index)">×</button>
                    </div>
                    <button v-if="form[kind].length < pointLimit" type="button" class="rr-button rr-button--small rr-button--ghost" @click="addPoint(form[kind])">{{ rr.t(kind === 'pros' ? 'form.add_pro' : 'form.add_con') }}</button>
                </fieldset>
            </div>

            <fieldset v-if="photoLimit > 0" class="rr-fieldset rr-photo-input">
                <legend class="rr-label">{{ rr.t('form.photos') }}</legend>
                <p :id="`${uid}-photos-help`" class="rr-help">{{ rr.tc('form.photos_help', photoLimit) }}<template v-if="meta?.form.photosRequireApproval"> {{ rr.t('form.photos_moderated') }}</template></p>
                <ul v-if="form.photos.length" class="rr-photos rr-photos--input">
                    <li v-for="(photo, index) in form.photos" :key="photo.id ?? photo.url" class="rr-photo-choice">
                        <img :src="photo.url" :alt="photo.alt" loading="lazy">
                        <button type="button" class="rr-button rr-button--small rr-button--ghost" :aria-label="rr.t('form.photo_remove', { number: index + 1 })" @click="removePhoto(index)">×</button>
                    </li>
                </ul>
                <div v-if="form.photos.length < photoLimit" class="rr-field">
                    <label :for="`${uid}-photos-add`" class="rr-label rr-label--minor">{{ rr.t('form.photos_add') }}</label>
                    <input :id="`${uid}-photos-add`" type="file" class="rr-input rr-input--file" :accept="rr.uploadTypes.join(',')" multiple :disabled="uploadingPhotos" :aria-describedby="describedBy('photos', `${uid}-photos-help`)" @change="addPhotos">
                </div>
                <p class="rr-help" role="status">{{ photoStatus }}</p>
                <p v-if="errors.photos" :id="`${uid}-photos-error`" class="rr-field-error" role="alert">{{ errors.photos }}</p>
            </fieldset>

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

            <div v-if="attributionOptions.length" class="rr-field">
                <label :for="`${uid}-attribution`" class="rr-label">{{ rr.t('form.post_as') }}</label>
                <select :id="`${uid}-attribution`" v-model="form.attribution" class="rr-select">
                    <option value="">{{ rr.t('form.post_as_self') }}</option>
                    <option v-for="option in attributionOptions" :key="option.key" :value="option.key">{{ option.label }}</option>
                </select>
                <p v-if="errors.attribution" class="rr-field-error">{{ errors.attribution }}</p>
            </div>

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
