<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import { formatDate, formatMoney, formatRelative } from '../shared';
import RrAuthorName from './RrAuthorName.vue';
import RrRichText from './RrRichText.vue';
import RrScoreBadge from './RrScoreBadge.vue';
import RrVoteButtons from './RrVoteButtons.vue';
import type { Review, SubjectRef } from '../types';

const props = withDefaults(defineProps<{
    review: Review;
    subject: SubjectRef;
    topicNames?: Record<string, string>;
    wantToTry?: boolean;
}>(), { topicNames: () => ({}), wantToTry: false });

const emit = defineEmits<{ report: [review: Review]; edit: [review: Review]; delete: [review: Review]; message: [text: string] }>();
const rr = useRr();

const added = ref(false);
const adding = ref(false);
const headingId = computed(() => `rr-review-${props.review.id}-title`);
const comparisonVerdict = computed(() => props.review.comparison?.verdictLabel ?? (props.review.comparison?.verdict ? rr.t(`comparison.${props.review.comparison.verdict}`) : ''));

async function addToList() {
    if (adding.value || added.value) {
        return;
    }

    adding.value = true;

    try {
        const result = await rr.api.wantToTry(props.subject, props.review.id);
        added.value = result.added;
        emit('message', result.message ?? rr.t('reviews.want_to_try_added'));
    } catch (failure) {
        emit('message', errorMessage(failure, rr.t));
    } finally {
        adding.value = false;
    }
}
</script>

<template>
    <article class="rr-review" :class="{ 'is-own': review.isOwn, 'is-pending': review.status !== 'approved' }" :aria-labelledby="headingId" :data-rr-review="review.id">
        <header class="rr-review__header">
            <RrAuthorName :author="review.author" />
            <span class="rr-review__meta">
                <time v-if="review.createdAt" :datetime="review.createdAt" :title="formatDate(review.createdAt, rr.locale, { dateStyle: 'long' })">{{ formatRelative(review.createdAt, rr.locale) }}</time>
                <span v-if="review.isEdited" class="rr-tag" :title="formatDate(review.editedAt, rr.locale, { dateStyle: 'long', timeStyle: 'short' })">{{ rr.t('common.edited') }}</span>
            </span>
        </header>

        <p v-if="review.isOwn && review.status === 'pending'" class="rr-notice rr-notice--pending" role="status">{{ rr.t('reviews.own_pending') }}</p>
        <p v-else-if="review.isOwn && (review.status === 'rejected' || review.status === 'spam')" class="rr-notice rr-notice--warning">{{ rr.t('reviews.own_rejected') }}</p>

        <div class="rr-review__score">
            <RrScoreBadge :value="review.rating" :max="review.scaleMax" size="md" />
        </div>
        <h3 :id="headingId" class="rr-review__title">{{ review.title || rr.t('reviews.rating', { rating: review.rating, max: review.scaleMax }) }}</h3>

        <ul v-if="review.topicRatings.length" class="rr-review__topics" :aria-label="rr.t('reviews.topic_scores')">
            <li v-for="rating in review.topicRatings" :key="rating.topicId" class="rr-review__topic">
                <span class="rr-review__topic-name">{{ topicNames[String(rating.topicId)] ?? '' }}</span>
                <RrScoreBadge :value="rating.value" :max="rating.scaleMax" />
            </li>
        </ul>

        <ul v-if="review.facets.length" class="rr-chips rr-chips--static">
            <li v-for="facet in review.facets" :key="facet.group" class="rr-chip">{{ facet.label }}</li>
        </ul>

        <RrRichText v-if="review.bodyHtml" :html="review.bodyHtml" class="rr-review__body" />

        <div v-if="review.pros.length || review.cons.length" class="rr-review__points">
            <div v-if="review.pros.length" class="rr-points rr-points--pros">
                <h4 class="rr-points__title">{{ rr.t('reviews.pros') }}</h4>
                <ul><li v-for="(pro, index) in review.pros" :key="`p${index}`">{{ pro }}</li></ul>
            </div>
            <div v-if="review.cons.length" class="rr-points rr-points--cons">
                <h4 class="rr-points__title">{{ rr.t('reviews.cons') }}</h4>
                <ul><li v-for="(con, index) in review.cons" :key="`c${index}`">{{ con }}</li></ul>
            </div>
        </div>

        <ul v-if="review.photos.length" class="rr-photos" :aria-label="rr.t('reviews.photos')">
            <li v-for="(photo, index) in review.photos" :key="photo.url">
                <a :href="photo.url" class="rr-photo"><img :src="photo.url" :alt="photo.alt || rr.t('reviews.photo_alt', { number: index + 1, name: review.author?.name ?? '' })" loading="lazy"></a>
            </li>
        </ul>

        <p v-if="review.price || review.comparison" class="rr-review__context">
            <span v-if="review.price" class="rr-tag">{{ rr.t('reviews.price_at_review', { price: formatMoney(review.price, rr.locale) }) }}</span>
            <span v-if="review.comparison" class="rr-tag rr-tag--comparison" :data-verdict="review.comparison.verdict">
                <template v-if="review.comparison.subject.url">
                    <a :href="review.comparison.subject.url">{{ rr.t('comparison.compared_with', { title: review.comparison.subject.title, verdict: comparisonVerdict }) }}</a>
                </template>
                <template v-else>{{ rr.t('comparison.compared_with', { title: review.comparison.subject.title, verdict: comparisonVerdict }) }}</template>
            </span>
        </p>

        <slot name="brand-reply" :review="review">
            <aside v-if="review.brandReply" class="rr-brand-reply" :aria-label="rr.t('reviews.brand_reply', { name: review.brandReply.author?.name ?? '' })">
                <p class="rr-brand-reply__title">{{ rr.t('reviews.brand_reply', { name: review.brandReply.author?.name ?? '' }) }}</p>
                <RrRichText :html="review.brandReply.bodyHtml" />
            </aside>
        </slot>

        <footer class="rr-review__footer">
            <RrVoteButtons
                v-if="review.status === 'approved'"
                target="review"
                :target-id="review.id"
                :dimensions="['helpfulness']"
                :counts="{ helpfulness: { up: review.helpfulCount, down: 0 } }"
                :viewer-votes="review.viewerVotes"
                :is-own="review.isOwn"
                positive-only
                @message="emit('message', $event)"
            />
            <div class="rr-review__actions">
                <button v-if="wantToTry && !review.isOwn" type="button" class="rr-button rr-button--small" :aria-pressed="added ? 'true' : 'false'" :disabled="adding" @click="addToList">
                    {{ added ? rr.t('reviews.want_to_try_added') : rr.t('reviews.want_to_try') }}
                </button>
                <button v-if="review.can.edit" type="button" class="rr-button rr-button--small rr-button--ghost" @click="emit('edit', review)">{{ rr.t('common.edit') }}</button>
                <button v-if="review.can.delete" type="button" class="rr-button rr-button--small rr-button--ghost" @click="emit('delete', review)">{{ rr.t('common.delete') }}</button>
                <button v-if="!review.isOwn && rr.isAuthenticated && review.status === 'approved'" type="button" class="rr-button rr-button--small rr-button--ghost" @click="emit('report', review)">{{ rr.t('reviews.report') }}</button>
            </div>
        </footer>
    </article>
</template>
