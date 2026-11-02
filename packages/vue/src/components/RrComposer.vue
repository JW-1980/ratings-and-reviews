<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRr } from '../context';
import { errorMessage } from '../composables/errors';
import { ApiError, codePointLength, createDraftStore, createToneChecker, toneSuggestionFromError, validatePoll } from '../shared';
import RrEditor from './RrEditor.vue';
import RrPollBuilder from './RrPollBuilder.vue';
import type { Comment, DiscussionSettings, SubjectRef } from '../types';

const props = withDefaults(defineProps<{
    subject: SubjectRef;
    settings: DiscussionSettings;
    lockedTools?: Record<string, number>;
    parent?: Comment | null;
    quote?: { commentId: number | string; text: string; authorName?: string } | null;
    /** Seconds the viewer still has to wait (slow mode). */
    waitSeconds?: number;
    /** HTML to put back after an undo. */
    restoreHtml?: string | null;
    autofocus?: boolean;
}>(), { lockedTools: () => ({}), parent: null, quote: null, waitSeconds: 0, restoreHtml: null, autofocus: false });

const emit = defineEmits<{ posted: [payload: { item: Comment; message: string | null; bodyHtml: string }]; cancel: []; 'update:quote': [value: null]; wait: [seconds: number] }>();
const rr = useRr();
const uid = `rr-composer-${Math.random().toString(36).slice(2)}`;

const editorRef = ref<InstanceType<typeof RrEditor> | null>(null);
const body = ref('');
const text = ref('');
const poll = ref<{ question: string; options: string[] } | null>(null);
const showPollErrors = ref(false);
const sending = ref(false);
const error = ref('');
const tone = ref<string | null>(null);
const draftState = ref<'saved' | 'failed' | 'restored' | null>(null);
const wait = ref(props.waitSeconds);
/** A quote that went into the text as a QuoteReply block (still sent as quoted_comment_id). */
const insertedQuote = ref<{ commentId: number | string; text: string } | null>(null);
const startedAt = Math.floor(Date.now() / 1000);
let waitTimer: ReturnType<typeof setInterval> | null = null;

const draft = createDraftStore(`${props.subject.type}:${props.subject.id}:${props.parent?.id ?? 'root'}`, {
    onResult: (saved: boolean) => (draftState.value = saved ? 'saved' : 'failed'),
});
const checker = createToneChecker(rr.api);

const length = computed(() => codePointLength(text.value.trim()));
const tooLong = computed(() => length.value > props.settings.maxLength);
const label = computed(() => (props.parent ? rr.t('composer.reply_label', { name: props.parent.author?.name ?? rr.t('reviews.anonymous') }) : rr.t('composer.label')));
const canPoll = computed(() => props.settings.polls.enabled && !props.parent);
const pollLimits = computed(() => ({ minOptions: props.settings.polls.minOptions, maxOptions: props.settings.polls.maxOptions }));

function startWait(seconds: number) {
    wait.value = Math.max(0, Math.ceil(seconds));

    if (waitTimer) {
        clearInterval(waitTimer);
    }

    if (wait.value > 0) {
        waitTimer = setInterval(() => {
            wait.value = Math.max(0, wait.value - 1);

            if (wait.value === 0 && waitTimer) {
                clearInterval(waitTimer);
            }
        }, 1000);
    }
}

watch(() => props.waitSeconds, (seconds) => startWait(seconds));
watch(() => props.restoreHtml, (html) => {
    if (html) {
        body.value = html;
    }
});

onMounted(() => {
    startWait(props.waitSeconds);

    if (props.restoreHtml) {
        body.value = props.restoreHtml;
    } else {
        const saved = draft.load();

        if (saved && typeof saved.value === 'string' && saved.value !== '') {
            body.value = saved.value;
            draftState.value = 'restored';
        }
    }

    if (props.quote) {
        insertQuote(props.quote);
    }

    if (props.autofocus) {
        setTimeout(() => editorRef.value?.focus(), 0);
    }
});

onBeforeUnmount(() => {
    draft.dispose();
    checker.reset();

    if (waitTimer) {
        clearInterval(waitTimer);
    }
});

watch(body, (html) => {
    tone.value = null;
    draft.save(html);
});

function insertQuote(quote: { commentId: number | string; text: string }) {
    setTimeout(() => {
        const inserted = editorRef.value?.insertQuote(quote);

        if (inserted) {
            insertedQuote.value = { commentId: quote.commentId, text: quote.text };
            emit('update:quote', null);
        }
    }, 0);
}

watch(() => props.quote, (quote) => quote && insertQuote(quote));

function discardDraft() {
    draft.clear();
    body.value = '';
    editorRef.value?.clear();
    draftState.value = null;
}

async function submit(confirmTone = false) {
    error.value = '';

    if (sending.value || wait.value > 0) {
        return;
    }

    if (length.value < props.settings.minLength) {
        error.value = rr.t('composer.too_short', { min: props.settings.minLength });
        editorRef.value?.focus();

        return;
    }

    if (tooLong.value) {
        error.value = rr.t('composer.too_long', { max: props.settings.maxLength });

        return;
    }

    if (poll.value && !validatePoll(poll.value, pollLimits.value).valid) {
        showPollErrors.value = true;

        return;
    }

    sending.value = true;

    try {
        if (!confirmTone && props.settings.toneCheck) {
            const result = await checker.check(body.value);

            if (result.suggestion) {
                tone.value = result.suggestion;

                return;
            }
        }

        const result = await rr.api.createComment(props.subject, {
            body: body.value,
            parentId: props.parent?.id ?? null,
            quote: props.quote ? { commentId: props.quote.commentId, text: props.quote.text } : insertedQuote.value,
            poll: poll.value,
            confirmTone,
            formStartedAt: startedAt,
        });

        const posted = body.value;
        draft.clear();
        body.value = '';
        editorRef.value?.clear();
        poll.value = null;
        insertedQuote.value = null;
        showPollErrors.value = false;
        tone.value = null;
        draftState.value = null;
        emit('posted', { item: result.item as Comment, message: result.message, bodyHtml: posted });
    } catch (failure) {
        const suggestion = toneSuggestionFromError(failure);

        if (suggestion) {
            tone.value = suggestion;
        } else if (failure instanceof ApiError && failure.isThrottled && failure.retryAfter) {
            // Throttled by the rate limiter: wait as long as Retry-After says.
            startWait(failure.retryAfter);
            emit('wait', failure.retryAfter);
        } else {
            error.value = errorMessage(failure, rr.t);
        }
    } finally {
        sending.value = false;
    }
}

function rephrase() {
    tone.value = null;
    editorRef.value?.focus();
}

defineExpose({ focus: () => editorRef.value?.focus(), insertQuote });
</script>

<template>
    <form class="rr-composer" :class="{ 'rr-composer--reply': parent }" novalidate :aria-labelledby="`${uid}-label`" @submit.prevent="submit()">
        <p :id="`${uid}-label`" class="rr-label">{{ label }}</p>
        <p v-if="parent" class="rr-composer__context">{{ rr.t('discussion.replying_to', { name: parent.author?.name ?? rr.t('reviews.anonymous') }) }}</p>

        <p v-if="quote" class="rr-composer__quote">
            <span class="rr-quote rr-quote--chip">{{ quote.authorName ? rr.t('discussion.quoting', { name: quote.authorName }) : '' }} “{{ quote.text }}”</span>
            <button type="button" class="rr-icon-button" :aria-label="rr.t('discussion.remove_quote')" @click="emit('update:quote', null)">×</button>
        </p>

        <RrEditor
            ref="editorRef"
            v-model="body"
            :tools="settings.editorTools"
            :locked-tools="lockedTools"
            :label="label"
            :placeholder="rr.t('composer.placeholder')"
            :described-by="`${uid}-meta${error ? ` ${uid}-error` : ''}`"
            :invalid="!!error"
            :disabled="sending"
            @text="text = $event"
            @submit="submit()"
        />

        <RrPollBuilder v-if="poll" v-model="poll" :show-errors="showPollErrors" :min-options="settings.polls.minOptions" :max-options="settings.polls.maxOptions" @remove="poll = null" />

        <div v-if="tone" class="rr-tone" role="alertdialog" :aria-labelledby="`${uid}-tone-title`" :aria-describedby="`${uid}-tone-body`">
            <p :id="`${uid}-tone-title`" class="rr-tone__title">{{ rr.t('tone.title') }}</p>
            <p :id="`${uid}-tone-body`" class="rr-tone__body">{{ tone || rr.t('tone.body') }}</p>
            <div class="rr-actions rr-actions--start">
                <button type="button" class="rr-button rr-button--small rr-button--primary" autofocus @click="rephrase">{{ rr.t('tone.rephrase') }}</button>
                <button type="button" class="rr-button rr-button--small rr-button--ghost" @click="submit(true)">{{ rr.t('tone.post_anyway') }}</button>
            </div>
        </div>

        <p v-if="error" :id="`${uid}-error`" class="rr-field-error" role="alert">{{ error }}</p>
        <p v-if="wait > 0" class="rr-notice rr-notice--slow" role="status">{{ rr.t('discussion.slow_mode_wait', { seconds: wait }) }}</p>

        <div class="rr-composer__footer">
            <p :id="`${uid}-meta`" class="rr-composer__meta">
                <span :class="{ 'is-over': tooLong }">{{ rr.t('composer.length', { count: length, max: settings.maxLength }) }}</span>
                <span v-if="draftState === 'saved'" class="rr-composer__draft">{{ rr.t('composer.draft_saved') }}</span>
                <span v-else-if="draftState === 'failed'" class="rr-composer__draft">{{ rr.t('composer.draft_failed') }}</span>
                <span v-else-if="draftState === 'restored'" class="rr-composer__draft" role="status">{{ rr.t('composer.draft_restored') }}</span>
                <span class="rr-composer__shortcut">{{ rr.t('composer.shortcut') }}</span>
            </p>
            <div class="rr-actions">
                <button v-if="draftState === 'restored'" type="button" class="rr-button rr-button--small rr-button--ghost" @click="discardDraft">{{ rr.t('composer.discard_draft') }}</button>
                <button v-if="canPoll && !poll" type="button" class="rr-button rr-button--small rr-button--ghost" @click="poll = { question: '', options: Array.from({ length: settings.polls.minOptions }, () => '') }">{{ rr.t('composer.add_poll') }}</button>
                <button v-if="parent" type="button" class="rr-button rr-button--ghost" @click="emit('cancel')">{{ rr.t('discussion.cancel_reply') }}</button>
                <button type="submit" class="rr-button rr-button--primary" :disabled="sending || wait > 0" :aria-busy="sending ? 'true' : undefined">
                    {{ sending ? rr.t('composer.submitting') : (parent ? rr.t('composer.reply_submit') : rr.t('composer.submit')) }}
                </button>
            </div>
        </div>
    </form>
</template>
