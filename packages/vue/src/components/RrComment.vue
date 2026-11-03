<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRr } from '../context';
import { flatten, useDiscussion } from '../composables/discussion';
import { errorMessage } from '../composables/errors';
import { formatDate, formatRelative } from '../shared';
import RrAuthorName from './RrAuthorName.vue';
import RrComposer from './RrComposer.vue';
import RrEditor from './RrEditor.vue';
import RrPoll from './RrPoll.vue';
import RrRichText from './RrRichText.vue';
import RrVoteButtons from './RrVoteButtons.vue';
import type { Comment } from '../types';

defineOptions({ name: 'RrComment' });

const props = defineProps<{ comment: Comment; truncated?: number }>();
const rr = useRr();
const discussion = useDiscussion();

const expanded = ref(!props.comment.isCollapsed);
const editing = ref(false);
const editHtml = ref('');
const saving = ref(false);
const editError = ref('');

const anchor = computed(() => `rr-comment-${props.comment.id}`);
const bodyHasQuote = computed(() => props.comment.bodyHtml.includes('data-rr-quote'));
const visualDepth = computed(() => Math.min(props.comment.depth, 3));
const name = computed(() => props.comment.author?.name ?? rr.t('reviews.anonymous'));
const shownReplies = computed(() => flatten(props.comment.replies).length);
const thread = computed(() => discussion.threads.value[String(props.comment.id)] ?? null);
const totalReplies = computed(() => Math.max(thread.value?.total || props.truncated || 0, shownReplies.value));

function startEdit() {
    editHtml.value = props.comment.bodyHtml;
    editError.value = '';
    editing.value = true;
}

async function saveEdit() {
    saving.value = true;
    editError.value = '';

    try {
        const result = await rr.api.updateComment(props.comment.id, editHtml.value);
        discussion.replace(result.item as Comment);
        discussion.announce(result.message ?? rr.t('composer.posted'));
        editing.value = false;
    } catch (failure) {
        editError.value = errorMessage(failure, rr.t);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <li class="rr-thread__item" :data-depth="visualDepth">
        <article
            :id="anchor"
            class="rr-comment"
            :class="{ 'is-new': comment.isNew, 'is-top': comment.isTop, 'is-removed': comment.isRemoved, 'is-collapsed': !expanded, 'is-pending': comment.status !== 'approved', 'is-own': comment.isOwn }"
            :aria-label="comment.isRemoved ? comment.removedLabel ?? '' : rr.t('discussion.by', { name })"
            :data-rr-comment="comment.id"
            tabindex="-1"
        >
            <p v-if="comment.isRemoved" class="rr-comment__removed">{{ comment.removedLabel }}</p>

            <template v-else>
                <header class="rr-comment__header">
                    <RrAuthorName :author="comment.author" />
                    <span v-if="comment.inReplyToAuthor" class="rr-comment__reply-to">↳ {{ rr.t('discussion.in_reply_to', { name: comment.inReplyToAuthor.name }) }}</span>
                    <span class="rr-comment__meta">
                        <a :href="`#${anchor}`" class="rr-comment__time">
                            <time v-if="comment.createdAt" :datetime="comment.createdAt" :title="formatDate(comment.createdAt, rr.locale, { dateStyle: 'long', timeStyle: 'short' })">{{ formatRelative(comment.createdAt, rr.locale) }}</time>
                        </a>
                        <span v-if="comment.isTop" class="rr-tag rr-tag--top">{{ rr.t('discussion.top_comment') }}</span>
                        <span v-if="comment.isNew" class="rr-tag rr-tag--new">{{ rr.t('discussion.new') }}</span>
                        <span v-if="comment.status !== 'approved'" class="rr-tag rr-tag--pending">{{ rr.t('common.pending') }}</span>
                        <button v-if="comment.isEdited" type="button" class="rr-tag rr-tag--button" :aria-label="rr.t('discussion.revisions')" @click="discussion.showRevisions(comment)">{{ rr.t('common.edited') }}</button>
                    </span>
                </header>

                <div v-if="!expanded" class="rr-comment__collapsed">
                    <p>{{ rr.t('discussion.collapsed') }}</p>
                    <button type="button" class="rr-button rr-button--link" :aria-controls="`${anchor}-body`" aria-expanded="false" @click="expanded = true">{{ rr.t('discussion.show_collapsed') }}</button>
                </div>

                <div v-show="expanded" :id="`${anchor}-body`" class="rr-comment__content">
                    <blockquote v-if="comment.quote && !bodyHasQuote" class="rr-quote">
                        <p>{{ comment.quote.text }}</p>
                        <footer v-if="comment.quote.available" class="rr-quote__source">
                            <a :href="`#rr-comment-${comment.quote.commentId}`" @click.prevent="discussion.jumpTo(comment.quote.commentId)">{{ comment.quote.author ? rr.t('discussion.quoting_from', { name: comment.quote.author.name }) : rr.t('discussion.quote_source') }}</a>
                        </footer>
                    </blockquote>

                    <div v-if="editing" class="rr-comment__edit">
                        <RrEditor v-model="editHtml" :tools="discussion.settings.value.editorTools" :locked-tools="discussion.lockedTools.value" :label="rr.t('common.edit')" :disabled="saving" @submit="saveEdit" />
                        <p v-if="editError" class="rr-field-error" role="alert">{{ editError }}</p>
                        <div class="rr-actions">
                            <button type="button" class="rr-button rr-button--ghost" @click="editing = false">{{ rr.t('common.cancel') }}</button>
                            <button type="button" class="rr-button rr-button--primary" :disabled="saving" :aria-busy="saving ? 'true' : undefined" @click="saveEdit">{{ rr.t('common.save') }}</button>
                        </div>
                    </div>
                    <RrRichText v-else :html="comment.bodyHtml" class="rr-comment__body" :data-rr-quote-source="comment.id" />

                    <RrPoll v-if="comment.poll" :poll="comment.poll" @message="discussion.announce" />

                    <footer class="rr-comment__footer">
                        <RrVoteButtons
                            v-if="comment.status === 'approved' && discussion.settings.value.voteDimensions.length"
                            target="comment"
                            :target-id="comment.id"
                            :dimensions="discussion.settings.value.voteDimensions"
                            :counts="comment.counts"
                            :viewer-votes="comment.viewerVotes"
                            :is-own="comment.isOwn"
                            :guest-votes="discussion.settings.value.guestVotes"
                            @message="discussion.announce"
                        />
                        <div class="rr-comment__actions">
                            <button v-if="comment.can.reply && discussion.acceptsComments.value && rr.isAuthenticated" type="button" class="rr-button rr-button--small rr-button--ghost" :aria-expanded="discussion.replyTo.value === comment.id ? 'true' : 'false'" @click="discussion.openReply(comment)">{{ rr.t('discussion.reply') }}</button>
                            <button v-if="comment.can.edit && !editing" type="button" class="rr-button rr-button--small rr-button--ghost" @click="startEdit">{{ rr.t('common.edit') }}</button>
                            <button v-if="comment.can.undo && comment.publishAt" type="button" class="rr-button rr-button--small" @click="discussion.undo(comment)">{{ rr.t('undo.action') }}</button>
                            <button v-if="comment.can.delete" type="button" class="rr-button rr-button--small rr-button--ghost" @click="discussion.confirmDelete(comment)">{{ rr.t('common.delete') }}</button>
                            <button v-if="!comment.isOwn && rr.isAuthenticated && comment.status === 'approved'" type="button" class="rr-button rr-button--small rr-button--ghost" @click="discussion.report(comment)">{{ rr.t('reviews.report') }}</button>
                            <button v-if="comment.isCollapsed" type="button" class="rr-button rr-button--small rr-button--link" @click="expanded = false">{{ rr.t('discussion.hide_collapsed') }}</button>
                        </div>
                    </footer>
                </div>
            </template>
        </article>

        <RrComposer
            v-if="discussion.replyTo.value === comment.id"
            :subject="discussion.subject"
            :settings="discussion.settings.value"
            :locked-tools="discussion.lockedTools.value"
            :parent="comment"
            :quote="discussion.quote.value && String(discussion.quote.value.commentId) === String(comment.id) ? discussion.quote.value : null"
            :wait-seconds="discussion.waitSeconds.value"
            :restore-html="discussion.restore.value?.parentId === comment.id ? discussion.restore.value.html : null"
            autofocus
            @posted="discussion.posted($event, comment.id)"
            @update:quote="discussion.quote.value = null"
            @cancel="discussion.closeReply()"
            @wait="discussion.setWait"
        />

        <ol v-if="comment.replies.length" class="rr-thread rr-thread--replies">
            <RrComment v-for="reply in comment.replies" :key="reply.id" :comment="reply" />
        </ol>
        <p v-if="truncated" class="rr-thread__truncated">
            <button type="button" class="rr-button rr-button--small rr-button--ghost" :disabled="thread?.loading" :aria-busy="thread?.loading ? 'true' : undefined" @click="discussion.loadReplies(comment)">
                {{ thread?.loading ? rr.t('discussion.replies_loading') : rr.t('discussion.more_replies') }}
            </button>
            <span class="rr-help">{{ rr.t('discussion.replies_shown', { shown: shownReplies, total: totalReplies }) }}</span>
            <span v-if="thread?.error" class="rr-field-error" role="alert">{{ thread.error }}</span>
        </p>
    </li>
</template>
