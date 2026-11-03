<?php

namespace RatingsAndReviews\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RatingsAndReviews\Contracts\Votable;
use RatingsAndReviews\Events\UserErasureProcessed;
use RatingsAndReviews\Jobs\RefreshRatingAggregates;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\CommentRevision;
use RatingsAndReviews\Models\DiscussionState;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\PollVote;
use RatingsAndReviews\Models\ReadMarker;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewInvitation;
use RatingsAndReviews\Models\SectionSetting;
use RatingsAndReviews\Models\TopicRating;
use RatingsAndReviews\Models\Vote;

/**
 * Erasure of one user's data in the package tables, for a host's account deletion or
 * erasure request (GDPR art. 17). The host calls it; the package never decides on its own.
 *
 * - withhold(): while a request is pending, the user's reviews, comments and topic scores
 *   are withheld (`erasure_withheld_at`): reviews and topic scores vanish from every query,
 *   comments from public reads, counts, digests, search and the moderation inbox (a comment
 *   with replies is shown as a placeholder so the replies keep their place). Aggregates of
 *   the affected subjects are recomputed. Votes are not touched until purge().
 * - restore(): undoes withhold() (the request was withdrawn).
 * - purge(): removes the user's data for good:
 *   - deletes their votes and poll votes (and recounts the items and polls), read markers,
 *     review invitations and standalone topic scores;
 *   - deletes their reviews with everything attached (topic scores, votes, photos, the
 *     replies to the review); a review an open report holds as evidence is kept, withheld,
 *     soft-deleted and stripped of its hashes, so a moderator can still judge it (purge
 *     again after the report is handled); the same happens when a reply to the review is held
 *     as evidence, so that reply stays reachable;
 *   - deletes their comments (with votes, poll, revisions and images); a comment that still
 *     holds replies becomes an anonymous placeholder (no text, no author, `user_id` null);
 *     one held as evidence (`held_as_evidence` or an open report) is kept like a review;
 *     comments of others that quoted them lose the quoted text;
 *   - unlinks them where they acted on others: reports they filed (`reporter_id` null; the
 *     report and its snapshot stay), reports they handled, revisions they made, discussions
 *     they locked and section settings they saved;
 *   - deletes their uploads (files and rows) except those of content kept as evidence;
 *   - recomputes the aggregates of the affected subjects.
 *
 * Each method returns counts per kind and dispatches UserErasureProcessed. Database changes
 * run in one transaction; files are deleted after it commits.
 */
class Erasure
{
    public function __construct(
        private readonly VoteService $votes,
        private readonly PollService $polls,
        private readonly Uploads $uploads,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return array{reviews: int, comments: int, topic_ratings: int}
     */
    public function withhold(int $userId): array
    {
        return $this->setWithheld($userId, true);
    }

    /**
     * @return array{reviews: int, comments: int, topic_ratings: int}
     */
    public function restore(int $userId): array
    {
        return $this->setWithheld($userId, false);
    }

    /**
     * @return array<string, int>
     */
    public function purge(int $userId): array
    {
        $subjects = [];
        $keep = [];
        $removedContent = [];

        $counts = DB::transaction(function () use ($userId, &$subjects, &$keep, &$removedContent): array {
            $counts = [];
            $counts['votes'] = $this->purgeVotes($userId);
            $counts['poll_votes'] = $this->purgePollVotes($userId);
            $counts['read_markers'] = ReadMarker::query()->where('user_id', $userId)->delete();
            $counts['review_invitations'] = ReviewInvitation::query()->where('user_id', $userId)->delete();

            $ratings = TopicRating::query()->withoutGlobalScopes()->where('user_id', $userId);
            $this->collectSubjects($subjects, (clone $ratings)->get(['reviewable_type', 'reviewable_id']));
            $counts['topic_ratings'] = $ratings->delete();

            [$counts['reviews_deleted'], $counts['reviews_kept_as_evidence']] = $this->purgeReviews($userId, $subjects, $keep, $removedContent);
            [$counts['comments_deleted'], $counts['comments_anonymised'], $counts['comments_kept_as_evidence']] = $this->purgeComments($userId, $keep, $removedContent);

            $counts['reports_unlinked'] = Report::query()->where('reporter_id', $userId)->update(['reporter_id' => null]);
            Report::query()->where('handled_by', $userId)->update(['handled_by' => null]);
            CommentRevision::query()->where('edited_by', $userId)->update(['edited_by' => null]);
            DiscussionState::query()->where('locked_by', $userId)->update(['locked_by' => null]);
            SectionSetting::query()->where('updated_by', $userId)->update(['updated_by' => null]);

            return $counts;
        }, 3);

        // Files after the commit: the images of removed content (also other members' images in
        // the replies to a removed review), then whatever the user uploaded besides evidence.
        $counts['uploads_deleted'] = array_sum(array_map(fn (Model $content): int => $this->uploads->purge($content), $removedContent))
            + $this->uploads->purgeForUser($userId, $keep);
        $this->refresh($subjects);
        $this->events->dispatch(new UserErasureProcessed($userId, UserErasureProcessed::PURGE, $counts));

        return $counts;
    }

    /**
     * @return array{reviews: int, comments: int, topic_ratings: int}
     */
    private function setWithheld(int $userId, bool $withheld): array
    {
        $subjects = [];

        $counts = DB::transaction(function () use ($userId, $withheld, &$subjects): array {
            $value = $withheld ? now() : null;
            $reviews = Review::query()->withoutGlobalScopes()->where('user_id', $userId)
                ->when($withheld, fn ($query) => $query->whereNull('erasure_withheld_at'), fn ($query) => $query->whereNotNull('erasure_withheld_at'));
            $ratings = TopicRating::query()->withoutGlobalScopes()->where('user_id', $userId)
                ->when($withheld, fn ($query) => $query->whereNull('erasure_withheld_at'), fn ($query) => $query->whereNotNull('erasure_withheld_at'));
            $comments = Comment::query()->withTrashed()->where('user_id', $userId)
                ->when($withheld, fn ($query) => $query->whereNull('erasure_withheld_at'), fn ($query) => $query->whereNotNull('erasure_withheld_at'));

            $this->collectSubjects($subjects, (clone $reviews)->get(['reviewable_type', 'reviewable_id']));
            $this->collectSubjects($subjects, (clone $ratings)->get(['reviewable_type', 'reviewable_id']));

            return [
                'reviews' => $reviews->update(['erasure_withheld_at' => $value]),
                'comments' => $comments->update(['erasure_withheld_at' => $value]),
                'topic_ratings' => $ratings->update(['erasure_withheld_at' => $value]),
            ];
        }, 3);

        $this->refresh($subjects);
        $this->events->dispatch(new UserErasureProcessed($userId, $withheld ? UserErasureProcessed::WITHHOLD : UserErasureProcessed::RESTORE, $counts));

        return $counts;
    }

    private function purgeVotes(int $userId): int
    {
        $items = Vote::query()->where('user_id', $userId)->toBase()->distinct()->get(['votable_type', 'votable_id']);
        $deleted = Vote::query()->where('user_id', $userId)->delete();

        foreach ($items as $item) {
            $this->recountVotable((string) $item->votable_type, (int) $item->votable_id);
        }

        return $deleted;
    }

    private function purgePollVotes(int $userId): int
    {
        $pollIds = PollVote::query()->where('user_id', $userId)->distinct()->pluck('poll_id')->all();
        $deleted = PollVote::query()->where('user_id', $userId)->delete();

        foreach (Poll::query()->whereKey($pollIds)->get() as $poll) {
            $this->polls->recount($poll);
        }

        return $deleted;
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $subjects
     * @param  list<array{0: string, 1: int}>  $keep
     * @param  list<Model>  $removedContent
     * @return array{0: int, 1: int} deleted, kept as evidence
     */
    private function purgeReviews(int $userId, array &$subjects, array &$keep, array &$removedContent): array
    {
        $deleted = 0;
        $kept = 0;

        foreach (Review::query()->withoutGlobalScopes()->withTrashed()->where('user_id', $userId)->get() as $review) {
            $this->collectSubjects($subjects, [$review]);

            if ($this->uploads->heldAsEvidence($review)) {
                $review->forceFill([
                    'erasure_withheld_at' => $review->erasure_withheld_at ?? now(),
                    'deleted_at' => $review->deleted_at ?? now(),
                    'ip_hash' => null,
                    'network_hash' => null,
                    'device_hash' => null,
                ])->save();
                $keep[] = [$review->getMorphClass(), (int) $review->id];
                $kept++;

                continue;
            }

            // The replies to the review have no subject once it is gone, but a reply held as
            // evidence (an open report or `held_as_evidence`) must stay judgeable: keep the
            // review as the evidence container and withhold it.
            $replies = Comment::query()->withTrashed()->forSubject($review)->orderByDesc('depth')->get();
            $replyHeld = $replies->contains(fn (Comment $reply): bool => $reply->held_as_evidence || $this->uploads->heldAsEvidence($reply));

            if ($replyHeld) {
                $review->forceFill([
                    'erasure_withheld_at' => $review->erasure_withheld_at ?? now(),
                    'deleted_at' => $review->deleted_at ?? now(),
                    'ip_hash' => null,
                    'network_hash' => null,
                    'device_hash' => null,
                ])->save();
                $keep[] = [$review->getMorphClass(), (int) $review->id];

                foreach ($replies as $reply) {
                    $keep[] = [$reply->getMorphClass(), (int) $reply->id];
                }

                $kept++;

                continue;
            }

            foreach ($replies as $reply) {
                $this->deleteComment($reply);
                $removedContent[] = $reply;
            }

            Vote::query()->where('votable_type', $review->getMorphClass())->where('votable_id', $review->id)->delete();
            $review->forceDelete();
            $removedContent[] = $review;
            $deleted++;
        }

        return [$deleted, $kept];
    }

    /**
     * Deepest first, so a comment whose only replies are the user's own goes as well.
     *
     * @param  list<array{0: string, 1: int}>  $keep
     * @param  list<Model>  $removedContent
     * @return array{0: int, 1: int, 2: int} deleted, anonymised, kept as evidence
     */
    private function purgeComments(int $userId, array &$keep, array &$removedContent): array
    {
        $deleted = 0;
        $anonymised = 0;
        $kept = 0;

        $comments = Comment::query()->withTrashed()->where('user_id', $userId)->orderByDesc('depth')->orderByDesc('id')->get();

        foreach ($comments as $comment) {
            Comment::query()->withTrashed()->where('quoted_comment_id', $comment->id)->update(['quote_text' => null]);

            if ($comment->held_as_evidence || $this->uploads->heldAsEvidence($comment)) {
                $comment->forceFill([
                    'erasure_withheld_at' => $comment->erasure_withheld_at ?? now(),
                    'ip_hash' => null,
                    'network_hash' => null,
                    'device_hash' => null,
                ])->save();
                $keep[] = [$comment->getMorphClass(), (int) $comment->id];
                $kept++;

                continue;
            }

            if (Comment::query()->withTrashed()->where('parent_id', $comment->id)->exists()) {
                $this->anonymise($comment);
                $removedContent[] = $comment;
                $anonymised++;

                continue;
            }

            $this->deleteComment($comment);
            $removedContent[] = $comment;
            $deleted++;
        }

        return [$deleted, $anonymised, $kept];
    }

    /** A placeholder without text, author or anything that pointed to the user. */
    private function anonymise(Comment $comment): void
    {
        CommentRevision::query()->where('comment_id', $comment->id)->delete();
        Poll::query()->where('comment_id', $comment->id)->delete();
        Vote::query()->where('votable_type', $comment->getMorphClass())->where('votable_id', $comment->id)->delete();

        $comment->forceFill([
            'user_id' => null,
            'body_html' => '',
            'body_text' => '',
            'quote_text' => null,
            'author_removed_at' => $comment->author_removed_at ?? now(),
            'erasure_withheld_at' => null,
            'ip_hash' => null,
            'network_hash' => null,
            'device_hash' => null,
        ])->save();

        $this->votes->recount($comment);
    }

    /** Deletes a comment with its votes (its poll and revisions go by cascade; images after the commit). */
    private function deleteComment(Comment $comment): void
    {
        Vote::query()->where('votable_type', $comment->getMorphClass())->where('votable_id', $comment->id)->delete();
        $comment->forceDelete();
    }

    private function recountVotable(string $type, int $id): void
    {
        $model = match ($type) {
            (new Review)->getMorphClass() => Review::query()->withoutGlobalScopes()->withTrashed()->find($id),
            (new Comment)->getMorphClass() => Comment::query()->withTrashed()->find($id),
            default => null,
        };

        if ($model instanceof Model && $model instanceof Votable) {
            $this->votes->recount($model);
        }
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $subjects
     * @param  iterable<object>  $rows  with reviewable_type and reviewable_id
     */
    private function collectSubjects(array &$subjects, iterable $rows): void
    {
        foreach ($rows as $row) {
            $subjects[$row->reviewable_type.':'.$row->reviewable_id] = [(string) $row->reviewable_type, (int) $row->reviewable_id];
        }
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $subjects
     */
    private function refresh(array $subjects): void
    {
        foreach ($subjects as [$type, $id]) {
            RefreshRatingAggregates::dispatch($type, (string) $id);
        }
    }
}
