<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import { ApiError, formatDate, formatRelative } from '../shared';
import RrScaleInput from './RrScaleInput.vue';
import RrScoreBadge from './RrScoreBadge.vue';
import type { Review } from '../types';

/**
 * The author's follow-up on a review ("after a month"): shown on the card, and for the author
 * a form to add one while the window is open (`can.followUp`) or the date it opens.
 */
const props = defineProps<{ review: Review }>();
const emit = defineEmits<{ saved: [review: Review]; removed: []; message: [text: string] }>();
const rr = useRr();

const open = ref(false);
const rating = ref<number | null>(null);
const body = ref('');
const busy = ref(false);
const errors = ref<Record<string, string>>({});
const formError = ref('');
const formId = computed(() => `rr-review-${props.review.id}-follow-up`);
const followUp = computed(() => props.review.followUp);
const changed = computed(() => followUp.value !== null && (followUp.value.rating !== followUp.value.initialRating || followUp.value.scaleMax !== followUp.value.initialScaleMax));
const opensLater = computed(() => {
    const opensAt = props.review.followUpWindow?.opensAt;

    return props.review.isOwn && !props.review.can.followUp && followUp.value === null && opensAt && new Date(opensAt).getTime() > Date.now() ? opensAt : null;
});

async function submit() {
    errors.value = {};
    formError.value = '';

    if (!rating.value) {
        errors.value = { rating: rr.t('form.rating_required') };

        return;
    }

    busy.value = true;

    try {
        const result = await rr.api.createFollowUp(props.review.id, { rating: rating.value, body: body.value });
        open.value = false;
        emit('saved', result.item as Review);
        emit('message', result.message ?? rr.t('reviews.follow_up_saved'));
    } catch (failure) {
        if (failure instanceof ApiError && failure.isValidation) {
            errors.value = Object.fromEntries(Object.entries(failure.errors).map(([field, messages]) => [field, messages[0]]));
        }

        formError.value = errorMessage(failure, rr.t);
    } finally {
        busy.value = false;
    }
}

async function remove() {
    if (!window.confirm(rr.t('reviews.follow_up_delete_confirm'))) {
        return;
    }

    try {
        await rr.api.deleteFollowUp(props.review.id);
        emit('removed');
        emit('message', rr.t('common.deleted'));
    } catch (failure) {
        emit('message', errorMessage(failure, rr.t));
    }
}
</script>

<template>
    <aside v-if="followUp" class="rr-follow-up" :aria-label="rr.tc('reviews.follow_up_title', followUp.daysAfter ?? 0, { count: followUp.daysAfter ?? 0 })" :data-rr-follow-up="review.id">
        <p class="rr-follow-up__title">
            <span>{{ rr.tc('reviews.follow_up_title', followUp.daysAfter ?? 0, { count: followUp.daysAfter ?? 0 }) }}</span>
            <RrScoreBadge :value="followUp.rating" :max="followUp.scaleMax" />
            <time v-if="followUp.createdAt" class="rr-review__meta" :datetime="followUp.createdAt" :title="formatDate(followUp.createdAt, rr.locale, { dateStyle: 'long' })">{{ formatRelative(followUp.createdAt, rr.locale) }}</time>
        </p>
        <p v-if="changed" class="rr-help">
            {{ rr.t('reviews.follow_up_change', {
                from: rr.t('reviews.rating', { rating: followUp.initialRating, max: followUp.initialScaleMax }),
                to: rr.t('reviews.rating', { rating: followUp.rating, max: followUp.scaleMax }),
            }) }}
        </p>
        <p v-if="followUp.body" class="rr-follow-up__body">{{ followUp.body }}</p>
        <button v-if="review.isOwn" type="button" class="rr-button rr-button--small rr-button--ghost" @click="remove">{{ rr.t('reviews.follow_up_delete') }}</button>
    </aside>
    <div v-else-if="review.isOwn && review.can.followUp" class="rr-follow-up rr-follow-up--form">
        <button type="button" class="rr-button rr-button--small" :aria-expanded="open ? 'true' : 'false'" :aria-controls="open ? formId : undefined" @click="open = !open">{{ rr.t('reviews.follow_up_add') }}</button>
        <form v-if="open" :id="formId" class="rr-form" novalidate @submit.prevent="submit">
            <p class="rr-help">{{ rr.t('reviews.follow_up_intro') }}</p>
            <p v-if="formError" class="rr-alert rr-alert--error" role="alert">{{ formError }}</p>
            <RrScaleInput v-model="rating" :max="review.scaleMax" :name="`${formId}-rating`" :legend="rr.t('reviews.follow_up_rating')" required :invalid="Boolean(errors.rating)" :described-by="errors.rating ? `${formId}-rating-error` : undefined" />
            <p v-if="errors.rating" :id="`${formId}-rating-error`" class="rr-field-error" role="alert">{{ errors.rating }}</p>
            <div class="rr-field">
                <label :for="`${formId}-body`" class="rr-label">{{ rr.t('reviews.follow_up_body') }}</label>
                <textarea :id="`${formId}-body`" v-model="body" class="rr-textarea" rows="3" :aria-invalid="errors.body ? 'true' : undefined" />
                <p v-if="errors.body" class="rr-field-error" role="alert">{{ errors.body }}</p>
            </div>
            <div class="rr-actions">
                <button type="submit" class="rr-button rr-button--primary rr-button--small" :disabled="busy">{{ busy ? rr.t('form.submitting') : rr.t('reviews.follow_up_submit') }}</button>
            </div>
        </form>
    </div>
    <p v-else-if="opensLater" class="rr-help rr-follow-up__opens">{{ rr.t('reviews.follow_up_opens', { date: formatDate(opensLater, rr.locale, { dateStyle: 'long' }) }) }}</p>
</template>
