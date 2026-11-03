<script setup lang="ts">
import { computed, onMounted, ref, useId, watch } from 'vue';
import { useRr } from '../context';
import { useAnnouncer } from '../composables/useAnnouncer';
import { errorMessage } from '../composables/errors';
import RrConfirmDialog from './RrConfirmDialog.vue';
import RrReportDialog from './RrReportDialog.vue';
import RrReviewCard from './RrReviewCard.vue';
import type { Review, ReviewPage, ReviewTopic, SubjectRef } from '../types';

const props = withDefaults(defineProps<{
    subject: SubjectRef;
    rating?: number | null;
    /** group => value (v-model:facets), shared with <RrRatingSummary>. */
    facets?: Record<string, string>;
    topics?: ReviewTopic[];
    initialSort?: string;
}>(), { rating: null, facets: () => ({}), topics: () => [], initialSort: '' });

const emit = defineEmits<{ 'update:rating': [value: number | null]; 'update:facets': [value: Record<string, string>]; edit: [review: Review]; deleted: [review: Review]; loaded: [page: ReviewPage] }>();
const rr = useRr();
const { message: live, announce } = useAnnouncer();

const items = ref<Review[]>([]);
const meta = ref<ReviewPage['meta'] | null>(null);
const sort = ref(props.initialSort);
const withPhotos = ref(false);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const reportTarget = ref<Review | null>(null);
const deleteTarget = ref<Review | null>(null);
const deleting = ref(false);
const uid = `rr-reviews-${useId()}`;
let controller: AbortController | null = null;

const topicNames = computed(() => Object.fromEntries(props.topics.map((topic) => [String(topic.id), topic.name])));
const own = computed(() => meta.value?.ownReview ?? null);
const list = computed(() => items.value.filter((review) => review.id !== own.value?.id));
const hasMore = computed(() => meta.value !== null && meta.value.page < meta.value.lastPage);
const filtered = computed(() => props.rating !== null || Object.keys(props.facets).length > 0 || withPhotos.value);
const ratingOptions = computed(() => Array.from({ length: meta.value?.scaleMax ?? 5 }, (_, index) => (meta.value?.scaleMax ?? 5) - index));

async function load(page = 1) {
    controller?.abort();
    const current = (controller = new AbortController());
    page === 1 ? (loading.value = true) : (loadingMore.value = true);
    error.value = '';

    try {
        const result = (await rr.api.reviews(props.subject, { sort: sort.value || undefined, rating: props.rating, facets: props.facets, withPhotos: withPhotos.value, page }, current.signal)) as ReviewPage;
        items.value = page === 1 ? result.items : [...items.value, ...result.items.filter((item) => !items.value.some((existing) => existing.id === item.id))];
        meta.value = result.meta;
        sort.value = result.meta.sort;
        emit('loaded', result);
        announce(rr.t('reviews.updated', { count: result.meta.total }));
    } catch (failure) {
        if ((failure as Error)?.name !== 'AbortError') {
            error.value = errorMessage(failure, rr.t);
        }
    } finally {
        // A newer request (sort/filter change) owns the loading flags now.
        if (controller === current) {
            loading.value = false;
            loadingMore.value = false;
        }
    }
}

onMounted(() => load());
watch(() => [props.rating, JSON.stringify(props.facets), withPhotos.value, props.subject.type, props.subject.id], () => load());

function changeSort(value: string) {
    sort.value = value;
    load();
}

function setFacet(group: string, value: string) {
    const next = { ...props.facets };

    if (value === '') {
        delete next[group];
    } else {
        next[group] = value;
    }

    emit('update:facets', next);
}

async function confirmDelete() {
    if (!deleteTarget.value) {
        return;
    }

    deleting.value = true;

    try {
        await rr.api.deleteReview(deleteTarget.value.id);
        const removed = deleteTarget.value;
        items.value = items.value.filter((review) => review.id !== removed.id);

        if (meta.value?.ownReview?.id === removed.id) {
            meta.value = { ...meta.value, ownReview: null };
        }

        if (meta.value !== null) {
            const total = Math.max(0, meta.value.total - 1);
            meta.value = { ...meta.value, total, lastPage: Math.max(1, Math.ceil(total / Math.max(1, meta.value.perPage))) };
        }

        emit('deleted', removed);
        announce(rr.t('reviews.deleted'));
        deleteTarget.value = null;
    } catch (failure) {
        announce(errorMessage(failure, rr.t));
    } finally {
        deleting.value = false;
    }
}

defineExpose({ reload: () => load() });
</script>

<template>
    <section class="rr rr-reviews" :aria-labelledby="`${uid}-title`" :aria-busy="loading ? 'true' : 'false'">
        <header class="rr-section-header">
            <h2 :id="`${uid}-title`" class="rr-section-header__title">
                {{ rr.t('reviews.title') }}
                <span v-if="meta" class="rr-section-header__count">{{ rr.tc('reviews.count', meta.total) }}</span>
            </h2>
            <div class="rr-toolbar">
                <label class="rr-inline-field">
                    <span class="rr-label">{{ rr.t('reviews.sort') }}</span>
                    <select class="rr-select" :value="sort" @change="changeSort(($event.target as HTMLSelectElement).value)">
                        <option v-for="option in meta?.sorts ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label class="rr-inline-field">
                    <span class="rr-label">{{ rr.t('reviews.filter_rating') }}</span>
                    <select class="rr-select" :value="rating ?? ''" @change="emit('update:rating', ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value))">
                        <option value="">{{ rr.t('reviews.filter_any_rating') }}</option>
                        <option v-for="bucket in ratingOptions" :key="bucket" :value="bucket">{{ rr.t('reviews.filter_rating_option', { bucket, max: meta?.scaleMax ?? 5 }) }}</option>
                    </select>
                </label>
                <label v-for="group in meta?.facets ?? []" :key="group.group" class="rr-inline-field">
                    <span class="rr-label">{{ group.label }}</span>
                    <select class="rr-select" :value="facets[group.group] ?? ''" @change="setFacet(group.group, ($event.target as HTMLSelectElement).value)">
                        <option value="">{{ rr.t('reviews.filter_any_facet') }}</option>
                        <option v-for="option in group.values" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label v-if="(meta?.form.maxPhotos ?? 0) > 0 || withPhotos" class="rr-inline-field rr-inline-field--check">
                    <input v-model="withPhotos" type="checkbox" class="rr-checkbox">
                    <span class="rr-label">{{ rr.t('reviews.filter_photos') }}</span>
                </label>
            </div>
        </header>

        <p class="rr-visually-hidden" aria-live="polite" role="status">{{ live }}</p>

        <p v-if="error" class="rr-alert rr-alert--error" role="alert">
            {{ error }}
            <button type="button" class="rr-button rr-button--link" @click="load()">{{ rr.t('common.retry') }}</button>
        </p>

        <div v-if="own" class="rr-reviews__own">
            <p class="rr-reviews__own-label">{{ rr.t('reviews.your_review') }}</p>
            <RrReviewCard :review="own" :subject="subject" :topic-names="topicNames" @edit="emit('edit', $event)" @delete="deleteTarget = $event" @message="announce">
                <template #brand-reply="slotProps"><slot name="brand-reply" v-bind="slotProps" /></template>
            </RrReviewCard>
        </div>

        <div v-if="loading && !items.length" class="rr-skeleton-list" aria-hidden="true">
            <div v-for="n in 3" :key="n" class="rr-skeleton rr-skeleton--card" />
        </div>
        <p v-else-if="!loading && !list.length && !own && !error" class="rr-empty">{{ filtered ? rr.t('reviews.empty_filtered') : rr.t('reviews.empty') }}</p>

        <ol class="rr-review-list">
            <li v-for="review in list" :key="review.id">
                <RrReviewCard
                    :review="review"
                    :subject="subject"
                    :topic-names="topicNames"
                    :want-to-try="meta?.features.wantToTry ?? false"
                    @report="reportTarget = $event"
                    @edit="emit('edit', $event)"
                    @delete="deleteTarget = $event"
                    @message="announce"
                >
                    <template #brand-reply="slotProps"><slot name="brand-reply" v-bind="slotProps" /></template>
                </RrReviewCard>
            </li>
        </ol>

        <div v-if="hasMore" class="rr-more">
            <button type="button" class="rr-button" :disabled="loadingMore" :aria-busy="loadingMore ? 'true' : undefined" @click="load((meta?.page ?? 1) + 1)">
                {{ loadingMore ? rr.t('reviews.loading') : rr.t('reviews.load_more') }}
            </button>
        </div>

        <RrReportDialog
            :open="reportTarget !== null"
            reportable-type="rr_review"
            :reportable-id="reportTarget?.id ?? null"
            @update:open="!$event && (reportTarget = null)"
            @reported="announce"
        />
        <RrConfirmDialog
            :open="deleteTarget !== null"
            :title="rr.t('common.delete')"
            :message="rr.t('reviews.delete_confirm')"
            :confirm-label="rr.t('common.delete')"
            :busy="deleting"
            @update:open="!$event && (deleteTarget = null)"
            @confirm="confirmDelete"
        />
    </section>
</template>
