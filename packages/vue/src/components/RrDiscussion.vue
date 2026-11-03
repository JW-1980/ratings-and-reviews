<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, useId } from 'vue';
import { preloadCardExtensions, useRr } from '../context';
import { useAnnouncer } from '../composables/useAnnouncer';
import { errorMessage } from '../composables/errors';
import { RR_DISCUSSION, flatten, insertInTree, lastShownReply, mergeReplies, removeFromTree, replaceInTree, type ThreadState } from '../composables/discussion';
import { quotableSelection, secondsUntil } from '../shared';
import RrComment from './RrComment.vue';
import RrComposer from './RrComposer.vue';
import RrConfirmDialog from './RrConfirmDialog.vue';
import RrDigest from './RrDigest.vue';
import RrReportDialog from './RrReportDialog.vue';
import RrRevisionsDialog from './RrRevisionsDialog.vue';
import RrSearch from './RrSearch.vue';
import RrUndoToast from './RrUndoToast.vue';
import type { Comment, CommentPage, Digest, DiscussionSettings, SubjectRef } from '../types';

const props = withDefaults(defineProps<{
    subject: SubjectRef;
    initialSort?: string;
    /** Show the search box (reviews and comments of this subject). */
    searchable?: boolean;
}>(), { initialSort: '', searchable: true });

/**
 * Host slots `comment-meta` and `comment-footer` (scoped `{ comment, item }`) on every comment
 * and reply; they replace the `cardExtensions` at `comment.meta`/`comment.footer`.
 */
defineSlots<{
    'comment-meta'?: (props: { comment: Comment; item: Comment }) => unknown;
    'comment-footer'?: (props: { comment: Comment; item: Comment }) => unknown;
}>();

const rr = useRr();
const { message: live, announce } = useAnnouncer();
const uid = `rr-discussion-${useId()}`;
const COMMENT_POSITIONS = ['comment.meta', 'comment.footer'] as const;

/** The card extension preloads, once per loaded batch of comments (placeholders left out). */
function preload(batch: Comment[]) {
    preloadCardExtensions(rr, COMMENT_POSITIONS, batch.filter((comment) => !comment.isRemoved));
}

const items = ref<Comment[]>([]);
const meta = ref<CommentPage['meta'] | null>(null);
const sort = ref(props.initialSort);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const digest = ref<Digest | null>(null);
const replyTo = ref<number | null>(null);
const quote = ref<{ commentId: number | string; text: string; authorName?: string } | null>(null);
const quoteOffer = ref<{ commentId: string; text: string; top: number; left: number } | null>(null);
const pendingUndo = ref<{ comment: Comment; seconds: number; parentId: number | null; html: string } | null>(null);
const restore = ref<{ parentId: number | null; html: string } | null>(null);
const reportTarget = ref<Comment | null>(null);
const revisionsTarget = ref<Comment | null>(null);
const deleteTarget = ref<Comment | null>(null);
const deleting = ref(false);
const waitSeconds = ref(0);
const thread = ref<HTMLElement | null>(null);
const threads = ref<Record<string, ThreadState>>({});
let controller: AbortController | null = null;

const fallbackSettings: DiscussionSettings = { maxDepth: 5, editorTools: [], voteDimensions: [], guestVotes: false, minLength: 1, maxLength: 10000, editWindowMinutes: null, undoWindowSeconds: 0, maxQuoteLength: 500, toneCheck: false, polls: { enabled: false, minOptions: 2, maxOptions: 4 } };
const settings = computed(() => meta.value?.settings ?? fallbackSettings);
const lockedTools = computed(() => meta.value?.trust.locked ?? {});
// accepts_comments already accounts for the lock (moderators may still post in a locked discussion).
const acceptsComments = computed(() => Boolean(meta.value?.acceptsComments));
const attributions = computed(() => meta.value?.attributions ?? []);
const newComments = computed(() => flatten(items.value).filter((comment) => comment.isNew));
const newCount = computed(() => Math.max(newComments.value.length, meta.value?.newSince.count ?? 0));
const slowInterval = computed(() => {
    const seconds = meta.value?.slowModeSeconds ?? 0;

    if (seconds >= 3600 && seconds % 3600 === 0) {
        return rr.tc('interval.hours', seconds / 3600);
    }

    if (seconds >= 60 && seconds % 60 === 0) {
        return rr.tc('interval.minutes', seconds / 60);
    }

    return rr.tc('interval.seconds', seconds);
});

async function loadDigest() {
    try {
        digest.value = (await rr.api.digest(props.subject)) as Digest | null;
    } catch {
        // The digest is optional: no digest when it is unavailable.
        digest.value = null;
    }
}

async function load(page = 1) {
    controller?.abort();
    controller = new AbortController();
    page === 1 ? (loading.value = true) : (loadingMore.value = true);
    error.value = '';

    try {
        const result = (await rr.api.comments(props.subject, { sort: sort.value || undefined, page }, controller.signal)) as CommentPage;
        const added = page === 1 ? result.items : result.items.filter((item) => !items.value.some((existing) => existing.id === item.id));
        items.value = page === 1 ? result.items : [...items.value, ...added];
        preload(flatten(added));
        meta.value = result.meta;
        sort.value = result.meta.sort;
        waitSeconds.value = result.meta.secondsUntilNextPost;

        if (page === 1) {
            loadDigest();
        } else {
            announce(rr.t('discussion.updated'));
        }
    } catch (failure) {
        if ((failure as Error)?.name !== 'AbortError') {
            error.value = errorMessage(failure, rr.t);
        }
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
}

function changeSort(value: string) {
    sort.value = value;
    load().then(() => announce(rr.t('discussion.updated')));
}

async function jumpTo(commentId: number | string) {
    await nextTick();
    const element = document.getElementById(`rr-comment-${commentId}`);

    if (element) {
        const reduce = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        element.scrollIntoView?.({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        element.focus({ preventScroll: true });
    }
}

function jumpToNew() {
    const first = newComments.value[0]?.id ?? meta.value?.newSince.firstNewId;

    if (first) {
        jumpTo(first);
    }
}

function posted(payload: { item: Comment; message: string | null; bodyHtml: string }, parentId: number | null) {
    items.value = insertInTree(items.value, payload.item, parentId);
    preload([payload.item]);
    replyTo.value = null;
    restore.value = null;

    if (meta.value) {
        meta.value = { ...meta.value, total: meta.value.total + (parentId === null ? 1 : 0) };
    }

    const seconds = payload.item.publishAt ? secondsUntil(payload.item.publishAt) : 0;

    if (seconds > 0) {
        pendingUndo.value = { comment: payload.item, seconds, parentId, html: payload.bodyHtml };
    } else {
        announce(payload.message ?? (payload.item.status === 'approved' ? rr.t('composer.posted') : rr.t('composer.pending')));
    }

    if (meta.value?.slowModeSeconds) {
        waitSeconds.value = meta.value.slowModeSeconds;
    }
}

async function undo() {
    const pending = pendingUndo.value;

    if (!pending) {
        return;
    }

    pendingUndo.value = null;

    try {
        await rr.api.undoComment(pending.comment.id);
        items.value = removeFromTree(items.value, pending.comment.id, '');
        restore.value = { parentId: pending.parentId, html: pending.html };
        replyTo.value = pending.parentId;
        waitSeconds.value = 0;
        announce(rr.t('undo.undone'));
    } catch {
        announce(rr.t('undo.failed'));
    }
}

function undoExpired() {
    pendingUndo.value = null;
    announce(rr.t('undo.published'));
}

async function confirmDelete() {
    const target = deleteTarget.value;

    if (!target) {
        return;
    }

    deleting.value = true;

    try {
        await rr.api.deleteComment(target.id);
        items.value = removeFromTree(items.value, target.id, rr.t('common.deleted'));
        announce(rr.t('common.deleted'));
        deleteTarget.value = null;
    } catch (failure) {
        announce(errorMessage(failure, rr.t));
    } finally {
        deleting.value = false;
    }
}

/**
 * "Show more replies": the next page of a thread cut at the reply cap. The first request
 * continues after the last reply shown, later ones follow `nextCursor`; when nothing is left
 * the thread is no longer marked as cut.
 */
async function loadReplies(root: Comment) {
    const key = String(root.id);
    const state = threads.value[key] ?? { loading: false, error: '', cursor: null, total: meta.value?.truncatedThreads[key] ?? 0 };

    if (state.loading) {
        return;
    }

    threads.value = { ...threads.value, [key]: { ...state, loading: true, error: '' } };

    try {
        const page = await rr.api.replies(root.id, state.cursor ? { cursor: state.cursor } : { after: lastShownReply(root)?.id ?? null });
        const current = items.value.find((item) => item.id === root.id) ?? root;
        const before = flatten(current.replies).length;
        const merged = mergeReplies(current, page.items);
        const known = new Set(flatten(current.replies).map((reply) => reply.id));
        preload(page.items.filter((reply) => !known.has(reply.id)));
        items.value = items.value.map((item) => (item.id === root.id ? merged : item));
        threads.value = { ...threads.value, [key]: { loading: false, error: '', cursor: page.nextCursor, total: page.total } };

        if (!page.hasMore && meta.value) {
            const { [key]: _done, ...rest } = meta.value.truncatedThreads;
            meta.value = { ...meta.value, truncatedThreads: rest };
        }

        announce(rr.tc('discussion.replies_loaded', flatten(merged.replies).length - before));
    } catch (failure) {
        threads.value = { ...threads.value, [key]: { ...state, loading: false, error: errorMessage(failure, rr.t) || rr.t('discussion.replies_failed') } };
    }
}

function onSelection() {
    if (!acceptsComments.value || !rr.isAuthenticated) {
        return;
    }

    const selected = quotableSelection(window.getSelection(), settings.value.maxQuoteLength);

    if (!selected || !thread.value) {
        quoteOffer.value = null;

        return;
    }

    const box = thread.value.getBoundingClientRect();
    const rect = selected.rect;
    quoteOffer.value = {
        commentId: selected.commentId,
        text: selected.text,
        top: rect ? rect.bottom - box.top + 6 : 0,
        left: rect ? Math.max(0, rect.left - box.left) : 0,
    };
}

function takeQuote() {
    const offer = quoteOffer.value;

    if (!offer) {
        return;
    }

    const source = flatten(items.value).find((comment) => String(comment.id) === offer.commentId);
    quote.value = { commentId: offer.commentId, text: offer.text, authorName: source?.author?.name };
    replyTo.value = source && source.can.reply ? source.id : null;
    quoteOffer.value = null;
    window.getSelection()?.removeAllRanges();
}

function onDocumentKey(event: KeyboardEvent) {
    if (event.key === 'Escape' && quoteOffer.value) {
        quoteOffer.value = null;
    }
}

provide(RR_DISCUSSION, {
    subject: props.subject,
    settings,
    lockedTools,
    acceptsComments,
    attributions,
    replyTo,
    quote,
    waitSeconds,
    restore,
    openReply: (comment: Comment) => {
        if (replyTo.value !== comment.id) {
            quote.value = null;
        }

        replyTo.value = replyTo.value === comment.id ? null : comment.id;
    },
    closeReply: () => {
        replyTo.value = null;
        quote.value = null;
    },
    report: (comment: Comment) => (reportTarget.value = comment),
    showRevisions: (comment: Comment) => (revisionsTarget.value = comment),
    confirmDelete: (comment: Comment) => (deleteTarget.value = comment),
    replace: (comment: Comment) => (items.value = replaceInTree(items.value, comment)),
    pin: async (comment: Comment) => {
        try {
            await rr.api.pinComment(comment.id, !comment.isPinned);
            announce(rr.t(comment.isPinned ? 'discussion.unpinned_done' : 'discussion.pinned_done'));
            // The pinned comment leads the first page: load it again.
            await load();
        } catch (failure) {
            announce(errorMessage(failure, rr.t));
        }
    },
    undo: async (comment: Comment) => {
        try {
            await rr.api.undoComment(comment.id);
            items.value = removeFromTree(items.value, comment.id, '');
            restore.value = { parentId: comment.parentId, html: comment.bodyHtml };
            replyTo.value = comment.parentId;
            announce(rr.t('undo.undone'));
        } catch {
            announce(rr.t('undo.failed'));
            load();
        }
    },
    posted,
    announce,
    setWait: (seconds: number) => (waitSeconds.value = seconds),
    jumpTo,
    loadReplies,
    threads,
});

onMounted(() => {
    load();
    document.addEventListener('keydown', onDocumentKey);
});
onBeforeUnmount(() => {
    controller?.abort();
    document.removeEventListener('keydown', onDocumentKey);
});

defineExpose({ reload: () => load(), jumpTo });
</script>

<template>
    <section class="rr rr-discussion" :aria-labelledby="`${uid}-title`" :aria-busy="loading ? 'true' : 'false'">
        <header class="rr-section-header">
            <h2 :id="`${uid}-title`" class="rr-section-header__title">
                {{ rr.t('discussion.title') }}
                <span v-if="meta" class="rr-section-header__count">{{ rr.tc('discussion.count', meta.total) }}</span>
            </h2>
            <label v-if="meta?.sorts.length" class="rr-inline-field">
                <span class="rr-label">{{ rr.t('discussion.sort') }}</span>
                <select class="rr-select" :value="sort" @change="changeSort(($event.target as HTMLSelectElement).value)">
                    <option v-for="option in meta.sorts" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </label>
        </header>

        <p class="rr-visually-hidden" aria-live="polite" role="status">{{ live }}</p>

        <RrSearch v-if="searchable" :subject="subject" @open="$event.type === 'comment' && jumpTo($event.id)" />

        <RrDigest v-if="digest" :digest="digest" @jump="jumpTo" />

        <p v-if="error" class="rr-alert rr-alert--error" role="alert">
            {{ error }}
            <button type="button" class="rr-button rr-button--link" @click="load()">{{ rr.t('common.retry') }}</button>
        </p>

        <div v-if="newCount > 0" class="rr-new-since" role="region" :aria-label="rr.t('discussion.new_since')">
            <span class="rr-new-since__label">{{ rr.t('discussion.new_since') }}</span>
            <button type="button" class="rr-button rr-button--small" :disabled="!newComments.length && !meta?.newSince.firstNewId" @click="jumpToNew">{{ rr.tc('discussion.jump_new', newCount) }}</button>
        </div>

        <p v-if="meta?.isLocked" class="rr-notice">{{ rr.t('discussion.locked') }}</p>
        <p v-else-if="meta && meta.canParticipate === false" class="rr-notice">{{ rr.t('discussion.participation_denied') }}</p>
        <p v-else-if="meta && !meta.acceptsComments" class="rr-notice">{{ rr.t('discussion.closed') }}</p>
        <p v-if="meta?.slowModeSeconds" class="rr-notice rr-notice--slow">{{ rr.t('discussion.slow_mode', { interval: slowInterval }) }}</p>

        <template v-if="acceptsComments">
            <RrComposer
                v-if="rr.isAuthenticated"
                :quote="replyTo === null ? quote : null"
                :subject="subject"
                :settings="settings"
                :locked-tools="lockedTools"
                :wait-seconds="waitSeconds"
                :restore-html="restore?.parentId === null ? restore.html : null"
                :attributions="attributions"
                @posted="posted($event, null)"
                @update:quote="quote = null"
                @wait="waitSeconds = $event"
            />
            <p v-else class="rr-notice">
                <a v-if="rr.loginUrl" :href="rr.loginUrl">{{ rr.t('discussion.login_to_reply') }}</a>
                <template v-else>{{ rr.t('discussion.login_to_reply') }}</template>
            </p>
        </template>

        <div v-if="loading && !items.length" class="rr-skeleton-list" aria-hidden="true">
            <div v-for="n in 3" :key="n" class="rr-skeleton rr-skeleton--comment" />
        </div>
        <p v-else-if="!loading && meta && !items.length && !error" class="rr-empty">{{ rr.t('discussion.empty') }}</p>

        <div ref="thread" class="rr-discussion__thread" @mouseup="onSelection" @keyup="onSelection">
            <ol class="rr-thread" :aria-label="rr.t('discussion.thread')">
                <RrComment v-for="comment in items" :key="comment.id" :comment="comment" :truncated="meta?.truncatedThreads[String(comment.id)]">
                    <template v-if="$slots['comment-meta']" #meta="slotProps"><slot name="comment-meta" v-bind="slotProps" /></template>
                    <template v-if="$slots['comment-footer']" #footer="slotProps"><slot name="comment-footer" v-bind="slotProps" /></template>
                </RrComment>
            </ol>
            <button
                v-if="quoteOffer"
                type="button"
                class="rr-button rr-button--small rr-button--primary rr-quote-offer"
                :style="{ insetBlockStart: `${quoteOffer.top}px`, insetInlineStart: `${quoteOffer.left}px` }"
                @mousedown.prevent
                @click="takeQuote"
            >{{ rr.t('discussion.quote_reply') }}</button>
        </div>

        <div v-if="meta?.hasMore" class="rr-more">
            <button type="button" class="rr-button" :disabled="loadingMore" :aria-busy="loadingMore ? 'true' : undefined" @click="load((meta?.page ?? 1) + 1)">
                {{ loadingMore ? rr.t('discussion.loading') : rr.t('discussion.load_more') }}
            </button>
        </div>

        <div class="rr-toast-region">
            <RrUndoToast v-if="pendingUndo" :key="pendingUndo.comment.id" :seconds="pendingUndo.seconds" @undo="undo" @expire="undoExpired" />
        </div>

        <RrReportDialog
            :open="reportTarget !== null"
            reportable-type="rr_comment"
            :reportable-id="reportTarget?.id ?? null"
            @update:open="!$event && (reportTarget = null)"
            @reported="announce"
        />
        <RrRevisionsDialog :open="revisionsTarget !== null" :comment-id="revisionsTarget?.id ?? null" @update:open="!$event && (revisionsTarget = null)" />
        <RrConfirmDialog
            :open="deleteTarget !== null"
            :title="rr.t('common.delete')"
            :message="rr.t('discussion.delete_confirm')"
            :confirm-label="rr.t('common.delete')"
            :busy="deleting"
            @update:open="!$event && (deleteTarget = null)"
            @confirm="confirmDelete"
        />
    </section>
</template>
