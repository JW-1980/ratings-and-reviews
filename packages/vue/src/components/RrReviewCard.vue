<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import { formatDate, formatMoney, formatRelative } from '../shared';
import RrAttributionBadge from './RrAttributionBadge.vue';
import RrAuthorName from './RrAuthorName.vue';
import RrDiscussion from './RrDiscussion.vue';
import RrFollowUp from './RrFollowUp.vue';
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

const emit = defineEmits<{ report: [review: Review]; edit: [review: Review]; delete: [review: Review]; message: [text: string]; updated: [review: Review] }>();
const rr = useRr();

const added = ref(false);
const showReplies = ref(false);
const repliesId = computed(() => `rr-review-${props.review.id}-replies`);
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
    <article class="rr-review" :class="{ 'is-own': review.isOwn, 'is-pending': review.status !== 'approved', 'is-blocked': review.isBlocked }" :aria-labelledby="headingId" :data-rr-review="review.id">
        <header class="rr-review__header">
            <RrAuthorName :author="review.author" />
            <RrAttributionBadge :attribution="review.attribution" />
            <span class="rr-review__meta">
                <time v-if="review.createdAt" :datetime="review.createdAt" :title="formatDate(review.createdAt, rr.locale, { dateStyle: 'long' })">{{ formatRelative(review.createdAt, rr.locale) }}</time>
                <span v-if="review.isEdited" class="rr-tag" :title="formatDate(review.editedAt, rr.locale, { dateStyle: 'long', timeStyle: 'short' })">{{ rr.t('common.edited') }}</span>
                <span v-if="review.uncounted?.isPublic" class="rr-tag rr-tag--uncounted" :data-reason="review.uncounted.reason ?? undefined" :title="rr.t('reviews.uncounted_public')">{{ review.uncounted.label }}<span class="rr-visually-hidden"> · {{ rr.t('reviews.uncounted_public') }}</span></span>
            </span>
        </header>

        <p v-if="review.isOwn && review.status === 'pending'" class="rr-notice rr-notice--pending" role="status">{{ rr.t('reviews.own_pending') }}</p>
        <p v-else-if="review.isOwn && (review.status === 'rejected' || review.status === 'spam')" class="rr-notice rr-notice--warning">{{ rr.t('reviews.own_rejected') }}</p>
        <p v-if="review.uncounted && !review.uncounted.isPublic" class="rr-notice rr-notice--muted" data-rr-uncounted>{{ rr.t(review.isOwn ? 'reviews.own_uncounted' : 'reviews.uncounted_moderator', { reason: review.uncounted.label }) }}</p>

        <div class="rr-review__score">
            <RrScoreBadge :value="review.rating" :max="review.scaleMax" size="md" />
        </div>
        <h3 :id="headingId" :class="['rr-review__title', { 'rr-visually-hidden': !review.title }]">{{ review.title || rr.t('reviews.rating', { rating: review.rating, max: review.scaleMax }) }}</h3>

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

        <dl v-if="review.answers.length" class="rr-review__answers" :aria-label="rr.t('reviews.answers')">
            <div v-for="answer in review.answers" :key="answer.key" class="rr-review__answer" :class="`rr-review__answer--${answer.type}`">
                <dt>{{ answer.label }}</dt>
                <dd>{{ answer.type === 'scale' ? rr.t('form.question_scale_option', { value: String(answer.value), max: answer.scaleMax ?? 5 }) : answer.display }}</dd>
            </div>
        </dl>

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
            <li v-for="(photo, index) in review.photos" :key="photo.id ?? photo.url">
                <a :href="photo.url" class="rr-photo"><img :src="photo.url" :alt="photo.alt || rr.t('reviews.photo_alt', { number: index + 1, name: review.author?.name ?? '' })" :width="photo.width ?? undefined" :height="photo.height ?? undefined" loading="lazy" decoding="async"></a>
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

        <RrFollowUp
            :review="review"
            @saved="emit('updated', $event)"
            @removed="emit('updated', { ...review, followUp: null, can: { ...review.can, followUp: true } })"
            @message="emit('message', $event)"
        />

        <p v-if="review.isBlocked" class="rr-notice rr-notice--muted">{{ rr.t('reviews.blocked') }}</p>

        <slot name="brand-reply" :review="review">
            <aside v-if="review.officialReply" class="rr-brand-reply rr-official-reply" :aria-label="rr.t('reviews.official_reply', { name: review.officialReply.author.name })">
                <p class="rr-brand-reply__title">
                    <a v-if="review.officialReply.author.url" :href="review.officialReply.author.url">{{ rr.t('reviews.official_reply', { name: review.officialReply.author.name }) }}</a>
                    <template v-else>{{ rr.t('reviews.official_reply', { name: review.officialReply.author.name }) }}</template>
                    <span v-if="review.officialReply.label" class="rr-tag">{{ review.officialReply.label }}</span>
                    <time v-if="review.officialReply.repliedAt" :datetime="review.officialReply.repliedAt" class="rr-review__meta">{{ formatRelative(review.officialReply.repliedAt, rr.locale) }}</time>
                </p>
                <RrRichText :html="review.officialReply.bodyHtml" />
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
                :blocked="review.isBlocked"
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
                <button
                    v-if="review.repliesCount !== null && review.status === 'approved' && (review.repliesCount > 0 || review.can.reply)"
                    type="button"
                    class="rr-button rr-button--small rr-button--ghost"
                    :aria-expanded="showReplies ? 'true' : 'false'"
                    :aria-controls="showReplies ? repliesId : undefined"
                    @click="showReplies = !showReplies"
                >
                    {{ showReplies ? rr.t('reviews.hide_replies') : (review.repliesCount > 0 ? rr.t('reviews.show_replies', { count: review.repliesCount }) : rr.tc('reviews.replies', 0)) }}
                </button>
            </div>
        </footer>

        <div v-if="showReplies" :id="repliesId" class="rr-review__replies" role="region" :aria-label="rr.t('reviews.replies_title')">
            <RrDiscussion :subject="rr.api.reviewRepliesSubject(review.id)" :searchable="false" />
        </div>
    </article>
</template>
