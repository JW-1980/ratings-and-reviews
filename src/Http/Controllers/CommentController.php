<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Http\Requests\StoreCommentRequest;
use RatingsAndReviews\Http\Requests\UpdateCommentRequest;
use RatingsAndReviews\Http\Resources\CommentResource;
use RatingsAndReviews\Http\Resources\RevisionResource;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Services\Annotations;
use RatingsAndReviews\Services\DiscussionService;
use RatingsAndReviews\Services\DiscussionStates;
use RatingsAndReviews\Services\DiscussionTree;
use RatingsAndReviews\Services\Participation;
use RatingsAndReviews\Services\ReadMarkers;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Services\SubjectResolver;
use RatingsAndReviews\Services\ToneNudge;
use RatingsAndReviews\Services\TrustLevels;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\DiscussionSort;
use RatingsAndReviews\Support\ParticipationKind;
use RatingsAndReviews\Support\VoterKeys;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CommentController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly DiscussionService $discussions,
        private readonly DiscussionTree $tree,
        private readonly AuthorResolver $authors,
        private readonly VoterIdentity $identity,
        private readonly SectionSettingsRepository $settings,
        private readonly ReadMarkers $markers,
        private readonly DiscussionStates $states,
        private readonly TrustLevels $trust,
        private readonly ToneNudge $tone,
        private readonly Participation $participation,
        private readonly Annotations $annotations,
    ) {}

    /**
     * The discussion tree. For a member it also reads and moves their read marker ("new
     * since your last visit"; `mark_read=0` reads without moving it).
     */
    public function index(Request $request, string $type, string $id): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $subject = $this->visibleSubject($type, $id, $viewer);
        $sort = DiscussionSort::tryFrom((string) $request->query('sort'));
        $page = max(1, (int) $request->query('page', 1));
        $viewerId = $this->userId($viewer);
        $moderates = $this->moderates($viewer, $subject);

        $lastVisit = $viewerId === null ? null : $this->markers->lastReadAt($viewerId, $subject);
        $discussion = $this->tree->load($subject, new VoterKeys($this->identity, $request, $viewer), $sort, $page, $viewerId, $lastVisit);
        $newSince = $viewerId === null ? null : $this->markers->newSince($subject, $lastVisit, $viewerId);

        if ($viewerId !== null && $request->query('mark_read', '1') !== '0') {
            $this->markers->touch($viewerId, $subject);
        }

        $state = $this->states->find($subject);
        $locked = $state?->locked_at !== null;
        $trust = $this->trust->for($viewer, $discussion->settings, $subject);
        $blocked = $this->participation->blockedWith($viewerId);
        $ownerId = $this->participation->ownerId($subject);
        $pins = $viewerId !== null && ($moderates || $ownerId === $viewerId);
        $participates = $viewer === null ? null : $this->participation->canParticipate($viewer, $subject, ParticipationKind::COMMENT);
        $pinned = $discussion->roots->first(fn (Comment $comment): bool => $comment->pinned_at !== null && $comment->isPubliclyVisible());

        $marks = $this->annotations->forItems(Annotations::flattenComments($discussion->roots), $viewer);

        return new JsonResponse([
            'data' => $discussion->roots->map(fn (Comment $comment): array => (new CommentResource($comment))
                ->withAnnotations($marks)
                ->withContext($discussion->authors, $discussion->viewerVotes, $viewerId, $moderates, $discussion->settings, $discussion->topCommentId)
                ->withExtras($discussion->quotes, $discussion->pollChoices, $discussion->newSince)
                ->withParticipation($blocked, $pins)
                ->resolve($request))->values()->all(),
            'meta' => [
                'total' => $discussion->total,
                'page' => $discussion->page,
                'per_page' => $discussion->perPage,
                'has_more' => $discussion->hasMore(),
                'sort' => $discussion->sort->value,
                'sorts' => array_map(fn (DiscussionSort $case): array => ['value' => $case->value, 'label' => $case->label()], DiscussionSort::cases()),
                'top_comment_id' => $discussion->topCommentId,
                'pinned_comment_id' => $pinned?->id,
                'truncated_threads' => (object) $discussion->truncatedThreads,
                'accepts_comments' => $discussion->settings->enabled && $subject->acceptsComments() && (! $locked || $moderates) && $participates !== false,
                // Null for a guest; false: the subject's ParticipationRules keep this member out.
                'can_participate' => $participates,
                'can_pin' => $pins,
                'attributions' => $viewer !== null && $participates !== false ? $this->participation->attributionOptions($viewer, $subject) : [],
                'subject' => [
                    'type' => $subject->getMorphClass(),
                    'id' => $subject->getKey(),
                    'title' => $subject->discussionTitle(),
                    'url' => $subject->discussionUrl(),
                    'context_url' => $this->participation->contextUrl($subject),
                ],
                'settings' => [
                    'max_depth' => $discussion->settings->maxDepth,
                    'editor_tools' => $trust->settings->toolKeys(),
                    'vote_dimensions' => array_map(fn ($dimension): string => $dimension->value, $discussion->settings->voteDimensions),
                    'guest_votes' => $discussion->settings->guestVotes,
                    'min_length' => $discussion->settings->minLength,
                    'max_length' => $discussion->settings->maxLength,
                    'edit_window_minutes' => $discussion->settings->editWindowMinutes,
                    'undo_window_seconds' => $discussion->settings->undoWindowSeconds,
                    'max_quote_length' => max(1, (int) config('ratings-and-reviews.discussions.max_quote_length', 500)),
                    'tone_check' => $this->tone->isEnabled(),
                    'polls' => [
                        'enabled' => (bool) config('ratings-and-reviews.polls.enabled', true),
                        'min_options' => max(2, (int) config('ratings-and-reviews.polls.min_options', 2)),
                        'max_options' => max(2, (int) config('ratings-and-reviews.polls.max_options', 4)),
                    ],
                ],
                'trust' => $trust->toArray(),
                'discussion' => [
                    'locked' => $locked,
                    'slow_mode_seconds' => $this->states->effectiveSlowMode($subject, $discussion->settings, $state),
                    'seconds_until_next_post' => $viewerId === null || $moderates ? 0 : $this->states->secondsUntilNextPost($subject, $discussion->settings, $viewerId, $state),
                ],
                'new_since' => [
                    'last_visit_at' => $lastVisit?->toIso8601String(),
                    'count' => $newSince['count'] ?? 0,
                    'first_new_id' => $newSince['first_id'] ?? null,
                    'first_new_root_id' => $newSince['first_root_id'] ?? null,
                ],
            ],
        ]);
    }

    public function store(StoreCommentRequest $request, string $type, string $id): JsonResponse
    {
        $user = $this->requireUser($request);
        $subject = $this->visibleSubject($type, $id, $user);
        $parentId = $request->validated('parent_id');

        $comment = $this->discussions->post($request, $user, $subject, (string) $request->validated('body'), $parentId === null ? null : (int) $parentId, [
            'quoted_comment_id' => $request->validated('quoted_comment_id'),
            'quote_text' => $request->validated('quote_text'),
            'poll' => $request->validated('poll'),
            'confirm_tone' => $request->boolean('confirm_tone'),
            'attribution' => $request->validated('attribution'),
        ]);

        return $this->saved($request, $comment, $user, 201);
    }

    /** Pins a top-level comment to the top of its discussion (moderator or discussion owner). */
    public function pin(Request $request, string $comment): JsonResponse
    {
        return $this->setPinned($request, $comment, true);
    }

    public function unpin(Request $request, string $comment): JsonResponse
    {
        return $this->setPinned($request, $comment, false);
    }

    private function setPinned(Request $request, string $comment, bool $pinned): JsonResponse
    {
        $user = $this->requireUser($request);
        $model = $this->discussions->pin($this->findComment($comment, $user), $user, $pinned);

        return new JsonResponse([
            'data' => ['id' => $model->id, 'is_pinned' => $model->pinned_at !== null, 'pinned_at' => $model->pinned_at?->toIso8601String()],
            'message' => $pinned ? __('ratings-and-reviews::messages.comment_pinned') : __('ratings-and-reviews::messages.comment_unpinned'),
        ]);
    }

    /** The author takes back a comment inside its undo window. */
    public function undo(Request $request, string $comment): Response
    {
        $user = $this->requireUser($request);
        $model = ctype_digit($comment) ? Comment::query()->find((int) $comment) : null;

        if ($model === null || $model->user_id !== $this->userId($user)) {
            throw new NotFoundHttpException;
        }

        $this->discussions->undo($model, $user);

        return new Response('', 204);
    }

    public function update(UpdateCommentRequest $request, string $comment): JsonResponse
    {
        $user = $this->requireUser($request);
        $model = $this->findComment($comment, $user);
        $own = $model->user_id === $this->userId($user);

        if (! $own && ! $this->moderates($user, $model)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        $model = $this->discussions->edit($request, $model, (string) $request->validated('body'), $user, asModerator: ! $own, confirmTone: $request->boolean('confirm_tone'));

        return $this->saved($request, $model, $user, 200);
    }

    public function destroy(Request $request, string $comment): Response
    {
        $user = $this->requireUser($request);
        $model = $this->findComment($comment, $user);

        if ($model->user_id === $this->userId($user)) {
            $this->discussions->deleteByAuthor($model, $user);
        } elseif ($this->moderates($user, $model)) {
            $this->discussions->deleteByModerator($model, $user->getAuthIdentifier());
        } else {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        return new Response('', 204);
    }

    /**
     * The earlier versions of a visible comment. A comment that is not public (pending,
     * hidden, a placeholder kept as evidence) shows its history to moderators only.
     */
    public function revisions(Request $request, string $comment): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $model = ctype_digit($comment) ? Comment::query()->find((int) $comment) : null;
        $subject = $model?->commentable;
        $moderates = $model !== null && $this->moderates($viewer, $model);

        if ($model === null || ! $subject instanceof Commentable || (! $model->isPubliclyVisible() && ! $moderates) || (! $subject->commentsVisibleTo($viewer) && ! $moderates)) {
            throw new NotFoundHttpException;
        }

        $revisions = $model->revisions()->orderByDesc('revision')->get();

        return new JsonResponse(['data' => RevisionResource::collection($revisions)->resolve($request)]);
    }

    /**
     * The replies of a thread that the tree cut at `discussions.max_replies_per_page`
     * (`meta.truncated_threads`): flat, in tree order (shallowest first, then oldest), with
     * `parent_id` to place them, `cursor` / `next_cursor` to page, or `after` (the id of the last
     * reply already shown) for the first request. `{comment}` is the root of the thread or any
     * reply in it. Does not move the viewer's read marker.
     */
    public function replies(Request $request, string $comment): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $viewerId = $this->userId($viewer);
        $model = ctype_digit($comment) ? Comment::query()->find((int) $comment) : null;
        $root = $model === null ? null : ($model->root_id === null ? $model : Comment::query()->find($model->root_id));
        $subject = $root?->commentable;
        $moderates = $subject !== null && $this->moderates($viewer, $subject);

        if ($root === null || ! $subject instanceof Commentable || ! $subject->commentsVisibleTo($viewer)
            || ($root->user_id !== null && in_array($root->user_id, $this->participation->hiddenAuthorIds($subject, $viewerId), true))) {
            throw new NotFoundHttpException;
        }

        $visible = ($root->status === ContentStatus::Approved && $root->published_at !== null) || ($viewerId !== null && $root->user_id === $viewerId);

        if (! $visible) {
            throw new NotFoundHttpException;
        }

        $cursor = $request->query('cursor');
        $limit = max(1, min(
            (int) config('ratings-and-reviews.discussions.max_replies_per_page', 400),
            (int) ($request->query('limit') ?: config('ratings-and-reviews.discussions.replies_per_request', 100)),
        ));
        $lastVisit = $viewerId === null ? null : $this->markers->lastReadAt($viewerId, $subject);
        $afterId = filter_var($request->query('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = $this->tree->replies($root, new VoterKeys($this->identity, $request, $viewer), $viewerId, is_string($cursor) ? $cursor : null, $limit, $lastVisit, $afterId === false ? null : $afterId);

        $blocked = $this->participation->blockedWith($viewerId);

        $marks = $this->annotations->forItems(Annotations::flattenComments($page->replies), $viewer);

        return new JsonResponse([
            'data' => $page->replies->map(fn (Comment $reply): array => (new CommentResource($reply))
                ->withAnnotations($marks)
                ->withContext($page->authors, $page->viewerVotes, $viewerId, $moderates, $page->settings)
                ->withExtras($page->quotes, $page->pollChoices, $page->newSince)
                ->withParticipation($blocked, false)
                ->resolve($request))->values()->all(),
            'meta' => [
                'root_id' => $root->id,
                'total' => $page->total,
                'next_cursor' => $page->nextCursor,
                'has_more' => $page->nextCursor !== null,
            ],
        ]);
    }

    private function saved(Request $request, Comment $comment, Authenticatable $user, int $status): JsonResponse
    {
        $comment->load('poll.options');
        $quoted = $comment->quoted_comment_id === null ? null : Comment::query()->find($comment->quoted_comment_id);
        $profiles = $this->authors->resolve(array_values(array_filter([$comment->user_id, $quoted?->user_id])));
        $settings = $this->settings->for($comment->section);
        $quotes = $quoted === null ? [] : [$quoted->id => ['user_id' => $quoted->user_id, 'root_id' => $quoted->root_id ?? $quoted->id, 'available' => $quoted->isPubliclyVisible()]];

        return new JsonResponse([
            'data' => (new CommentResource($comment))
                ->withAnnotations($this->annotations->forItems([$comment], $user))
                ->withContext($profiles, [], $this->userId($user), $this->moderates($user, $comment), $settings)
                ->withExtras($quotes, [], null)
                ->withParticipation([], $this->discussions->mayPin($user, $comment))
                ->resolve($request),
            'message' => match ($comment->visibility()) {
                'public' => __('ratings-and-reviews::messages.comment_published'),
                'scheduled' => trans_choice('ratings-and-reviews::messages.comment_scheduled', $settings->undoWindowSeconds, ['seconds' => $settings->undoWindowSeconds]),
                default => __('ratings-and-reviews::messages.comment_pending'),
            },
        ], $status);
    }

    /**
     * @return Model&Commentable
     */
    private function visibleSubject(string $type, string $id, ?Authenticatable $viewer): Model
    {
        $subject = $this->subjects->resolve($type, $id, Commentable::class);

        if (! $subject->commentsVisibleTo($viewer)) {
            throw new NotFoundHttpException;
        }

        return $subject;
    }

    /**
     * A comment the user may act on: in a discussion they can see, and either public or theirs (moderators see all).
     */
    private function findComment(string $id, Authenticatable $user): Comment
    {
        $comment = ctype_digit($id) ? Comment::query()->find((int) $id) : null;
        $subject = $comment?->commentable;
        $moderates = $comment !== null && $this->moderates($user, $comment);

        if ($comment === null || ! $subject instanceof Commentable || (! $subject->commentsVisibleTo($user) && ! $moderates)) {
            throw new NotFoundHttpException;
        }

        if (($comment->status !== ContentStatus::Approved || $comment->published_at === null) && $comment->user_id !== $this->userId($user) && ! $moderates) {
            throw new NotFoundHttpException;
        }

        return $comment;
    }
}
