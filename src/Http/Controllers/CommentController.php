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
use RatingsAndReviews\Services\DiscussionService;
use RatingsAndReviews\Services\DiscussionTree;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Services\SubjectResolver;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\DiscussionSort;
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
    ) {}

    public function index(Request $request, string $type, string $id): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $subject = $this->visibleSubject($type, $id, $viewer);
        $sort = DiscussionSort::tryFrom((string) $request->query('sort'));
        $page = max(1, (int) $request->query('page', 1));

        $discussion = $this->tree->load($subject, $this->identity->voterKey($request, $viewer), $sort, $page);
        $viewerId = $this->userId($viewer);
        $moderates = $this->moderates($viewer);

        return new JsonResponse([
            'data' => $discussion->roots->map(fn (Comment $comment): array => (new CommentResource($comment))
                ->withContext($discussion->authors, $discussion->viewerVotes, $viewerId, $moderates, $discussion->settings, $discussion->topCommentId)
                ->resolve($request))->values()->all(),
            'meta' => [
                'total' => $discussion->total,
                'page' => $discussion->page,
                'per_page' => $discussion->perPage,
                'has_more' => $discussion->hasMore(),
                'sort' => $discussion->sort->value,
                'sorts' => array_map(fn (DiscussionSort $case): array => ['value' => $case->value, 'label' => $case->label()], DiscussionSort::cases()),
                'top_comment_id' => $discussion->topCommentId,
                'truncated_threads' => (object) $discussion->truncatedThreads,
                'accepts_comments' => $discussion->settings->enabled && $subject->acceptsComments(),
                'settings' => [
                    'max_depth' => $discussion->settings->maxDepth,
                    'editor_tools' => $discussion->settings->toolKeys(),
                    'vote_dimensions' => array_map(fn ($dimension): string => $dimension->value, $discussion->settings->voteDimensions),
                    'guest_votes' => $discussion->settings->guestVotes,
                    'min_length' => $discussion->settings->minLength,
                    'max_length' => $discussion->settings->maxLength,
                    'edit_window_minutes' => $discussion->settings->editWindowMinutes,
                ],
            ],
        ]);
    }

    public function store(StoreCommentRequest $request, string $type, string $id): JsonResponse
    {
        $user = $this->requireUser($request);
        $subject = $this->visibleSubject($type, $id, $user);
        $parentId = $request->validated('parent_id');

        $comment = $this->discussions->post($request, $user, $subject, (string) $request->validated('body'), $parentId === null ? null : (int) $parentId);

        return $this->saved($request, $comment, $user, 201);
    }

    public function update(UpdateCommentRequest $request, string $comment): JsonResponse
    {
        $user = $this->requireUser($request);
        $model = $this->findComment($comment, $user);
        $own = $model->user_id === $this->userId($user);

        if (! $own && ! $this->moderates($user)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        $model = $this->discussions->edit($request, $model, (string) $request->validated('body'), $user, asModerator: ! $own);

        return $this->saved($request, $model, $user, 200);
    }

    public function destroy(Request $request, string $comment): Response
    {
        $user = $this->requireUser($request);
        $model = $this->findComment($comment, $user);

        if ($model->user_id === $this->userId($user)) {
            $this->discussions->deleteByAuthor($model, $user);
        } elseif ($this->moderates($user)) {
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
        $moderates = $this->moderates($viewer);

        if ($model === null || ! $subject instanceof Commentable || (! $model->isPubliclyVisible() && ! $moderates) || (! $subject->commentsVisibleTo($viewer) && ! $moderates)) {
            throw new NotFoundHttpException;
        }

        $revisions = $model->revisions()->orderByDesc('revision')->get();

        return new JsonResponse(['data' => RevisionResource::collection($revisions)->resolve($request)]);
    }

    private function saved(Request $request, Comment $comment, Authenticatable $user, int $status): JsonResponse
    {
        $profiles = $this->authors->resolve([$comment->user_id]);
        $settings = $this->settings->for($comment->section);

        return new JsonResponse([
            'data' => (new CommentResource($comment))
                ->withContext($profiles, [], $this->userId($user), $this->moderates($user), $settings)
                ->resolve($request),
            'message' => $comment->status === ContentStatus::Approved
                ? __('ratings-and-reviews::messages.comment_published')
                : __('ratings-and-reviews::messages.comment_pending'),
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
        $moderates = $this->moderates($user);

        if ($comment === null || ! $subject instanceof Commentable || (! $subject->commentsVisibleTo($user) && ! $moderates)) {
            throw new NotFoundHttpException;
        }

        if ($comment->status !== ContentStatus::Approved && $comment->user_id !== $this->userId($user) && ! $moderates) {
            throw new NotFoundHttpException;
        }

        return $comment;
    }
}
