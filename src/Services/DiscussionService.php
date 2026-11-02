<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Events\CommentDeleted;
use RatingsAndReviews\Events\CommentEdited;
use RatingsAndReviews\Events\CommentModerated;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\CommentRevision;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\SanitizedHtml;
use RatingsAndReviews\Support\SectionSettings;
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
    ) {}

    /**
     * @throws ValidationException
     */
    public function post(Request $request, Authenticatable $author, Model&Commentable $subject, string $html, ?int $parentId = null): Comment
    {
        $settings = $this->settings->for($subject->commentSection());

        if (! $settings->enabled || ! $subject->acceptsComments()) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.comments_closed')]);
        }

        $authorId = (int) $author->getAuthIdentifier();
        $placement = $this->placement($subject, $settings, $parentId);
        $clean = $this->cleanBody($html, $settings, $authorId);

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

        $comment = DB::transaction(function () use ($request, $subject, $settings, $authorId, $placement, $clean, $status): Comment {
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
                'body_html' => $clean->html,
                'body_text' => $clean->text,
                'published_at' => $status === ContentStatus::Approved ? now() : null,
                'ip_hash' => $this->identity->ipHash($request),
                'network_hash' => $this->identity->networkHash($request),
                'device_hash' => $this->identity->deviceHash($request),
            ]);

            $this->attachUploads($comment, $clean);

            return $comment;
        });

        // Load the database defaults and the generated helpful_score.
        $comment->refresh();
        $comment->setRelation('commentable', $subject);

        if ($status === ContentStatus::Approved) {
            $this->events->dispatch(new CommentPosted($comment));
        }

        return $comment;
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
    public function edit(Request $request, Comment $comment, string $html, Authenticatable $editor, bool $asModerator = false): Comment
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

            if (! $this->withinEditWindow($comment, $settings)) {
                throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.edit_window_closed')]);
            }
        }

        if ($comment->isRemovedByAuthor()) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.comment_removed')]);
        }

        $clean = $this->cleanBody($html, $settings, $comment->user_id);

        if ($clean->html === $comment->body_html) {
            return $comment;
        }

        $hold = false;

        if (! $asModerator) {
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
        }

        [$revision, $sentBack] = DB::transaction(function () use ($comment, $clean, $editorId, $hold): array {
            $locked = Comment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();
            $revision = $this->recordRevision($locked, $editorId);
            $sentBack = $hold && $locked->status === ContentStatus::Approved;

            $comment->fill([
                'body_html' => $clean->html,
                'body_text' => $clean->text,
                'is_edited' => true,
                'edited_at' => now(),
                'status' => $sentBack ? ContentStatus::Pending : $locked->status,
            ])->save();

            $this->attachUploads($comment, $clean);

            return [$revision, $sentBack];
        });

        $this->events->dispatch(new CommentEdited($comment, $revision, $editorId, $sentBack));

        return $comment;
    }

    /**
     * The author deletes their comment.
     *
     * @throws AccessDeniedHttpException
     */
    public function deleteByAuthor(Comment $comment, Authenticatable $author): void
    {
        if ((int) $author->getAuthIdentifier() !== $comment->user_id) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        [$ids, $placeholder] = DB::transaction(function () use ($comment): array {
            Comment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();

            if (Comment::query()->where('parent_id', $comment->id)->exists()) {
                $evidence = Report::query()->open()
                    ->where('reportable_type', $comment->getMorphClass())
                    ->where('reportable_id', $comment->id)
                    ->exists();

                if ($evidence) {
                    $this->recordRevision($comment, $comment->user_id);
                } else {
                    CommentRevision::query()->where('comment_id', $comment->id)->delete();
                }

                $comment->forceFill([
                    'body_html' => '',
                    'body_text' => '',
                    'author_removed_at' => now(),
                    'held_as_evidence' => $evidence,
                ])->save();

                return [[], true];
            }

            $ids = [$comment->id, ...$this->emptiedPlaceholdersAbove($comment)];
            Comment::query()->whereIn('id', $ids)->update(['deleted_at' => now()]);

            return [$ids, false];
        });

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

            $published = $status === ContentStatus::Approved && $locked->published_at === null;
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
    private function cleanBody(string $html, SectionSettings $settings, int $authorId): SanitizedHtml
    {
        if ($this->sanitizer->exceedsInputLimit($html)) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_large')]);
        }

        $clean = $this->sanitizer->clean($html, $settings, $authorId);
        $length = mb_strlen($clean->text);

        if ($length < $settings->minLength && $clean->imageCount === 0) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_short', ['min' => $settings->minLength])]);
        }

        if ($length > $settings->maxLength) {
            throw ValidationException::withMessages(['body' => __('ratings-and-reviews::messages.too_long', ['max' => $settings->maxLength])]);
        }

        return $clean;
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

    private function attachUploads(Comment $comment, SanitizedHtml $clean): void
    {
        if ($clean->uploadPaths === []) {
            return;
        }

        Upload::query()
            ->where('user_id', $comment->user_id)
            ->whereIn('path', $clean->uploadPaths)
            ->whereNull('attached_id')
            ->update(['attached_type' => $comment->getMorphClass(), 'attached_id' => $comment->id, 'attached_at' => now()]);
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
