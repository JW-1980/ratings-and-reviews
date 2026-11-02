<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Events\CommentDeleted;
use RatingsAndReviews\Events\CommentEdited;
use RatingsAndReviews\Events\CommentModerated;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Exceptions\ToneNudgeRequired;
use RatingsAndReviews\Jobs\PublishScheduledComment;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\CommentRevision;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\SanitizedHtml;
use RatingsAndReviews\Support\SectionSettings;
use RatingsAndReviews\Support\TrustProfile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Writes to every threaded discussion: posting, editing, deleting and moderating comments.
 *
 * Depth: a reply beneath a comment at the section's deepest level is attached to that
 * comment's ancestor one level up, so it lands on the deepest allowed level; the comment
 * it answers is kept in `in_reply_to_id` ("↳ reply to @name").
 *
 * Deleting: the author removes only their own words. When the comment has replies it stays
 * as a placeholder (text gone, `author_removed_at` set) and the replies keep their place; a
 * placeholder left without replies is removed with the last of them. A moderator removes
 * the comment with its whole subtree.
 *
 * Posting also applies, in this order: trust levels (locked tools are stripped, deep replies
 * flattened, polls refused), the quote ("quote and reply": an exact substring of a public
 * comment in the same discussion), the poll definition, the rephrase nudge (422 unless
 * `confirm_tone`), the SpamGuard, RiskSignals, and inside the transaction the lock and slow
 * mode of the discussion. Moderators are exempt from trust levels, slow mode and locks.
 *
 * Undo window: with the section's `undo_window_seconds` above 0 a new comment gets
 * `publish_at` = now + window and no `published_at`; only its author sees it and can undo
 * it. CommentPublisher publishes it when the window ends and only then fires CommentPosted.
 *
 * Counted: the RiskSignals verdict is stored in `counted`. An uncounted comment is shown
 * as usual but is not a contribution (ContributionCounter, digest totals, top comment).
 */
class DiscussionService
{
    public function __construct(
        private readonly SectionSettingsRepository $settings,
        private readonly HtmlSanitizer $sanitizer,
        private readonly SpamGuard $spam,
        private readonly RiskSignals $risk,
        private readonly VoterIdentity $identity,
        private readonly Dispatcher $events,
        private readonly AbilityResolver $abilities,
        private readonly TrustLevels $trust,
        private readonly DiscussionStates $states,
        private readonly ToneNudge $tone,
        private readonly PollService $polls,
        private readonly Uploads $uploads,
    ) {}

    /**
     * @param  array{quoted_comment_id?: int|null, quote_text?: string|null, poll?: array<string, mixed>|null, confirm_tone?: bool}  $options
     *
     * @throws ValidationException
     * @throws ToneNudgeRequired
     */
    public function post(Request $request, Authenticatable $author, Model&Commentable $subject, string $html, ?int $parentId = null, array $options = []): Comment
    {
        $settings = $this->settings->for($subject->commentSectionKey());

        if (! $settings->enabled || ! $subject->acceptsComments()) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.comments_closed')]);
        }

        $authorId = (int) $author->getAuthIdentifier();
        $moderator = $this->abilities->allows($author, Ability::MODERATE, $subject);

        if (! $moderator && $this->states->isLocked($subject)) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.discussion_locked')]);
        }

        $trust = $this->trust->for($author, $settings, $subject);
        $placement = $this->placement($subject, $trust->settings, $parentId);
        $clean = $this->cleanBody($html, $trust->settings, $authorId);
        $quote = $this->quote($subject, $options['quoted_comment_id'] ?? null, $options['quote_text'] ?? null);
        $clean = $this->withQuoteReferences($clean, $quote['id'] ?? null);
        $poll = $this->pollDefinition($options['poll'] ?? null, $trust);

        $this->checkTone([$clean->text, $poll['question'] ?? '', ...($poll['options'] ?? [])], (bool) ($options['confirm_tone'] ?? false));

        $verdict = $this->spam->inspect($request, $clean->text, $authorId);

        if ($verdict->refuse) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.spam_refused')]);
        }

        $risk = $this->risk->forContribution(new ContributionContext(
            kind: 'comment',
            userId: $authorId,
            subject: $subject,
            text: $clean->text,
            request: $request,
            networkHash: $this->identity->networkHash($request),
            deviceHash: $this->identity->deviceHash($request),
        ));

        $status = $verdict->hold || $risk->hold ? ContentStatus::Pending : ContentStatus::Approved;
        $publishAt = $settings->undoWindowSeconds > 0 ? now()->addSeconds($settings->undoWindowSeconds) : null;

        $comment = DB::transaction(function () use ($request, $subject, $settings, $authorId, $moderator, $placement, $clean, $status, $risk, $quote, $poll, $publishAt): Comment {
            if (! $moderator && ($this->states->isLocked($subject) || $this->states->effectiveSlowMode($subject, $settings) > 0)) {
                $this->states->assertMayPost($subject, $settings, $authorId);
            }

            $comment = Comment::query()->create([
                'commentable_type' => $subject->getMorphClass(),
                'commentable_id' => $subject->getKey(),
                'section' => $settings->section,
                'parent_id' => $placement['parent_id'],
                'root_id' => $placement['root_id'],
                'in_reply_to_id' => $placement['in_reply_to_id'],
                'depth' => $placement['depth'],
                'user_id' => $authorId,
                'status' => $status,
                'counted' => $risk->counted,
                'body_html' => $clean->html,
                'body_text' => $clean->text,
                'quoted_comment_id' => $quote['id'] ?? null,
                'quote_text' => $quote['text'] ?? null,
                'publish_at' => $publishAt,
                'published_at' => $status === ContentStatus::Approved && $publishAt === null ? now() : null,
                'ip_hash' => $this->identity->ipHash($request),
                'network_hash' => $this->identity->networkHash($request),
                'device_hash' => $this->identity->deviceHash($request),
            ]);

            $this->uploads->attachInline($comment, $authorId, $clean->uploadPaths);

            if ($poll !== null) {
                $this->polls->create($comment, $poll);
            }

            return $comment;
        });

        // Load the database defaults and the generated helpful_score.
        $comment->refresh();
        $comment->setRelation('commentable', $subject);

        if ($comment->status === ContentStatus::Approved && $comment->published_at !== null) {
            $this->events->dispatch(new CommentPosted($comment));
        } elseif ($comment->publish_at !== null) {
            PublishScheduledComment::dispatch($comment->id)->delay($comment->publish_at);
        }

        return $comment;
    }

    /**
     * The author takes back a comment inside its undo window. It was never public, so it
     * is removed for good (with its poll); its uploads are released for reuse, because the
     * text returns to the composer (unused ones are pruned by rr:prune-uploads).
     *
     * @throws AccessDeniedHttpException
     * @throws ValidationException when the window has passed
     */
    public function undo(Comment $comment, Authenticatable $author): void
    {
        if ((int) $author->getAuthIdentifier() !== $comment->user_id) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        DB::transaction(function () use ($comment): void {
            $locked = Comment::query()->whereKey($comment->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isUndoable()) {
                throw ValidationException::withMessages(['comment' => __('ratings-and-reviews::messages.undo_expired')]);
            }

            $this->uploads->release($locked);

            $locked->forceDelete();
        });
    }

    /**
     * Rewrites a comment, keeping the replaced text as a revision. The author edits within
     * the section's edit window while the discussion is open; their new text is checked for
     * spam again, and text that would be held now sends an approved comment back to the
     * queue. A moderator edits at any time and keeps the status. An edit that changes
     * nothing is not stored.
     *
     * @throws ValidationException
     * @throws AccessDeniedHttpException
     */
    public function edit(Request $request, Comment $comment, string $html, Authenticatable $editor, bool $asModerator = false, bool $confirmTone = false): Comment
    {
        $editorId = (int) $editor->getAuthIdentifier();
        $subject = $comment->commentable;
        $settings = $this->settings->for($comment->section);

        if (! $asModerator) {
            if ($editorId !== $comment->user_id) {
                throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
            }

            if (! $settings->enabled || ! $subject instanceof Commentable || ! $subject->acceptsComments()) {
                throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.comments_closed')]);
            }

            if ($subject instanceof Model && ! $this->abilities->allows($editor, Ability::MODERATE, $comment) && $this->states->isLocked($subject)) {
                throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.discussion_locked')]);
            }

            if (! $this->withinEditWindow($comment, $settings)) {
                throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.edit_window_closed')]);
            }
        }

        if ($comment->isRemovedByAuthor()) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.comment_removed')]);
        }

        $clean = $this->cleanBody($html, $this->trust->for($editor, $settings, $comment)->settings, $comment->user_id, $comment);
        $clean = $this->withQuoteReferences($clean, $comment->quoted_comment_id);

        if ($clean->html === $comment->body_html) {
            return $comment;
        }

        $hold = false;
        $counted = $comment->counted;

        if (! $asModerator) {
            $this->checkTone([$clean->text], $confirmTone);

            $verdict = $this->spam->inspect($request, $clean->text, $comment->user_id, $comment);

            if ($verdict->refuse) {
                throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.spam_refused')]);
            }

            $risk = $subject instanceof Model ? $this->risk->forContribution(new ContributionContext(
                kind: 'comment',
                userId: $comment->user_id,
                subject: $subject,
                text: $clean->text,
                request: $request,
                networkHash: $this->identity->networkHash($request),
                deviceHash: $this->identity->deviceHash($request),
                isEdit: true,
            )) : null;

            $hold = $verdict->hold || ($risk?->hold ?? false);
            $counted = $risk?->counted ?? $counted;
        }

        [$revision, $sentBack] = DB::transaction(function () use ($comment, $clean, $editorId, $hold, $counted): array {
            $locked = Comment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();
            $revision = $this->recordRevision($locked, $editorId);
            $sentBack = $hold && $locked->status === ContentStatus::Approved;

            $comment->fill([
                'body_html' => $clean->html,
                'body_text' => $clean->text,
                'is_edited' => true,
                'edited_at' => now(),
                'status' => $sentBack ? ContentStatus::Pending : $locked->status,
                'counted' => $counted,
            ])->save();

            // The replaced text stays in the revisions, so its images stay attached.
            $this->uploads->attachInline($comment, $comment->user_id, $clean->uploadPaths);

            return [$revision, $sentBack];
        });

        $this->events->dispatch(new CommentEdited($comment, $revision, $editorId, $sentBack));

        return $comment;
    }

    /**
     * The author deletes their comment. Its images are deleted with it (files and rows) once
     * the transaction has committed, unless an open report keeps it as evidence.
     *
     * @throws AccessDeniedHttpException
     */
    public function deleteByAuthor(Comment $comment, Authenticatable $author): void
    {
        if ((int) $author->getAuthIdentifier() !== $comment->user_id) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        [$ids, $placeholder, $evidence] = DB::transaction(function () use ($comment): array {
            Comment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();
            $evidence = $this->uploads->heldAsEvidence($comment);

            if (Comment::query()->where('parent_id', $comment->id)->exists()) {
                if ($evidence) {
                    $this->recordRevision($comment, $comment->user_id);
                } else {
                    CommentRevision::query()->where('comment_id', $comment->id)->delete();
                }

                $comment->forceFill([
                    'body_html' => '',
                    'body_text' => '',
                    'quote_text' => null,
                    'author_removed_at' => now(),
                    'held_as_evidence' => $evidence,
                ])->save();

                Poll::query()->where('comment_id', $comment->id)->delete();

                return [[], true, $evidence];
            }

            $ids = [$comment->id, ...$this->emptiedPlaceholdersAbove($comment)];
            Comment::query()->whereIn('id', $ids)->update(['deleted_at' => now()]);

            return [$ids, false, $evidence];
        });

        if (! $evidence) {
            $this->uploads->purge($comment);
        }

        $comment->refresh();
        $this->events->dispatch(new CommentDeleted($comment, $ids, $placeholder));
    }

    /**
     * A moderator removes the comment with every reply beneath it.
     */
    public function deleteByModerator(Comment $comment, int|string $moderatorId): void
    {
        $ids = DB::transaction(function () use ($comment): array {
            $ids = $this->subtreeIds($comment);
            Comment::query()->whereIn('id', $ids)->update(['deleted_at' => now()]);

            return $ids;
        });

        $comment->refresh();
        $this->events->dispatch(new CommentDeleted($comment, $ids, false, $moderatorId));
    }

    /**
     * A moderator's decision. Hiding (rejected or spam) also hides the replies beneath it,
     * because the tree only shows replies under visible comments. The first approval
     * announces the comment (CommentPosted), once.
     */
    public function moderate(Comment $comment, ContentStatus $status, int|string $moderatorId, ?string $note = null): Comment
    {
        [$previous, $published] = DB::transaction(function () use ($comment, $status): array {
            $locked = Comment::query()->withTrashed()->whereKey($comment->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if ($previous === $status) {
                return [$previous, false];
            }

            // Inside its undo window the publisher publishes it when the window ends.
            $published = $status === ContentStatus::Approved && $locked->published_at === null
                && ($locked->publish_at === null || ! $locked->publish_at->isFuture());
            $comment->forceFill([
                'status' => $status,
                'published_at' => $published ? now() : $locked->published_at,
            ])->save();

            return [$previous, $published];
        });

        if ($previous === $status) {
            return $comment;
        }

        $this->events->dispatch(new CommentModerated($comment, $previous, $status, $moderatorId, $note));

        if ($published) {
            $this->events->dispatch(new CommentPosted($comment));
        }

        return $comment;
    }

    public function withinEditWindow(Comment $comment, SectionSettings $settings): bool
    {
        if ($settings->editWindowMinutes === null) {
            return true;
        }

        return $comment->created_at !== null
            && $comment->created_at->copy()->addMinutes($settings->editWindowMinutes)->isFuture();
    }

    /**
     * Where a new comment goes. Replies deeper than the section allows are attached to
     * the ancestor on the level above the deepest one.
     *
     * @return array{parent_id: int|null, root_id: int|null, in_reply_to_id: int|null, depth: int}
     *
     * @throws ValidationException
     */
    public function placement(Model $subject, SectionSettings $settings, ?int $parentId): array
    {
        if ($parentId === null) {
            return ['parent_id' => null, 'root_id' => null, 'in_reply_to_id' => null, 'depth' => 0];
        }

        $parent = Comment::query()->forSubject($subject)->find($parentId);

        if ($parent === null || ! $parent->isPubliclyVisible()) {
            throw ValidationException::withMessages(['parent_id' => __('ratings-and-reviews::messages.parent_unavailable')]);
        }

        if ($settings->allowsReplyAt($parent->depth)) {
            return ['parent_id' => $parent->id, 'root_id' => $parent->root_id ?? $parent->id, 'in_reply_to_id' => null, 'depth' => $parent->depth + 1];
        }

        $depth = $settings->maxDepth;

        if ($depth === 0) {
            return ['parent_id' => null, 'root_id' => null, 'in_reply_to_id' => $parent->id, 'depth' => 0];
        }

        $ancestor = $parent;

        while ($ancestor !== null && $ancestor->depth > $depth - 1) {
            $ancestor = $ancestor->parent_id !== null ? Comment::query()->withTrashed()->find($ancestor->parent_id) : null;
        }

        if ($ancestor === null) {
            throw ValidationException::withMessages(['parent_id' => __('ratings-and-reviews::messages.parent_unavailable')]);
        }

        return [
            'parent_id' => $ancestor->id,
            'root_id' => $ancestor->root_id ?? $ancestor->id,
            'in_reply_to_id' => $parent->id,
            'depth' => $ancestor->depth + 1,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function cleanBody(string $html, SectionSettings $settings, int $authorId, ?Comment $target = null): SanitizedHtml
    {
        if ($this->sanitizer->exceedsInputLimit($html)) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_large')]);
        }

        $clean = $this->sanitizer->clean($html, $settings, $authorId, $target);
        $length = mb_strlen($clean->text);

        if ($length < $settings->minLength && $clean->imageCount === 0) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_short', ['min' => $settings->minLength])]);
        }

        if ($length > $settings->maxLength) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_long', ['max' => $settings->maxLength])]);
        }

        return $clean;
    }

    /**
     * Validates "quote and reply": the quoted comment must be public in the same discussion
     * and the quote an exact substring of its plain text (whitespace collapsed).
     *
     * @return array{id: int, text: string}|null
     *
     * @throws ValidationException
     */
    public function quote(Model $subject, mixed $quotedId, mixed $quoteText): ?array
    {
        $text = is_string($quoteText) ? trim((string) preg_replace('/\s+/u', ' ', $quoteText)) : '';
        $id = filter_var($quotedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false && $text === '' && ($quotedId === null || $quotedId === '')) {
            return null;
        }

        if ($id === false) {
            throw ValidationException::withMessages(['quoted_comment_id' => __('ratings-and-reviews::messages.quote_unavailable')]);
        }

        if ($text === '') {
            throw ValidationException::withMessages(['quote_text' => __('ratings-and-reviews::messages.quote_required')]);
        }

        $max = max(1, (int) config('ratings-and-reviews.discussions.max_quote_length', 500));

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages(['quote_text' => __('ratings-and-reviews::messages.quote_too_long', ['max' => $max])]);
        }

        $quoted = Comment::query()->forSubject($subject)->find($id);

        if ($quoted === null || ! $quoted->isPubliclyVisible()) {
            throw ValidationException::withMessages(['quoted_comment_id' => __('ratings-and-reviews::messages.quote_unavailable')]);
        }

        if (! str_contains($quoted->body_text, $text)) {
            throw ValidationException::withMessages(['quote_text' => __('ratings-and-reviews::messages.quote_mismatch')]);
        }

        return ['id' => $quoted->id, 'text' => $text];
    }

    /**
     * Keeps `data-rr-quote` only when it names the validated quoted comment, so a body
     * cannot claim to quote a comment it did not.
     */
    private function withQuoteReferences(SanitizedHtml $clean, ?int $quotedId): SanitizedHtml
    {
        if (! str_contains($clean->html, 'data-rr-quote')) {
            return $clean;
        }

        $html = (string) preg_replace_callback(
            '/\sdata-rr-quote="([^"]*)"/',
            fn (array $match): string => $quotedId !== null && $match[1] === (string) $quotedId ? $match[0] : '',
            $clean->html,
        );

        return new SanitizedHtml($html, $clean->text, $clean->imageCount, $clean->droppedImages, $clean->uploadPaths);
    }

    /**
     * @return array{question: string, options: list<string>, closes_at: Carbon|null}|null
     *
     * @throws ValidationException
     */
    private function pollDefinition(mixed $input, TrustProfile $trust): ?array
    {
        if ($input === null || $input === []) {
            return null;
        }

        if (! $trust->allows(TrustProfile::POLL)) {
            throw ValidationException::withMessages(['poll' => trans_choice('ratings-and-reviews::messages.trust_locked', $trust->locked[TrustProfile::POLL], ['count' => $trust->locked[TrustProfile::POLL]])]);
        }

        return $this->polls->definition($input);
    }

    /**
     * @param  list<string>  $texts
     *
     * @throws ToneNudgeRequired
     */
    private function checkTone(array $texts, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }

        $suggestion = $this->tone->check(implode("\n", $texts));

        if ($suggestion !== null) {
            throw new ToneNudgeRequired($suggestion);
        }
    }

    private function recordRevision(Comment $comment, int $editorId): CommentRevision
    {
        $number = (int) CommentRevision::query()->where('comment_id', $comment->id)->max('revision') + 1;

        return CommentRevision::query()->create([
            'comment_id' => $comment->id,
            'revision' => $number,
            'body_html' => $comment->body_html,
            'body_text' => $comment->body_text,
            'edited_by' => $editorId,
            'edited_at' => now(),
        ]);
    }

    /**
     * Placeholders above the comment that keep no reply once it is gone, nearest first.
     *
     * @return list<int>
     */
    private function emptiedPlaceholdersAbove(Comment $comment): array
    {
        $ids = [];
        $leaving = $comment->id;
        $parent = $comment->parent_id !== null ? Comment::query()->find($comment->parent_id) : null;

        while ($parent !== null && $parent->isRemovedByAuthor()
            && ! Comment::query()->where('parent_id', $parent->id)->whereKeyNot($leaving)->exists()) {
            $ids[] = $parent->id;
            $leaving = $parent->id;
            $parent = $parent->parent_id !== null ? Comment::query()->find($parent->parent_id) : null;
        }

        return $ids;
    }

    /**
     * Every id in the subtree, one query per level.
     *
     * @return list<int>
     */
    private function subtreeIds(Comment $comment): array
    {
        $ids = [$comment->id];
        $frontier = [$comment->id];

        while ($frontier !== []) {
            $frontier = Comment::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
