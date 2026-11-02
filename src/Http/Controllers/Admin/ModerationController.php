<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\ModerateRequest;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\DiscussionService;
use RatingsAndReviews\Services\ReviewService;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\ContentStatus;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One moderation inbox for reviews and comments.
 */
class ModerationController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly DiscussionService $discussions,
        private readonly AuthorResolver $authors,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAbility($request, Ability::MODERATE);
        $kind = $request->query('kind', 'reviews') === 'comments' ? 'comments' : 'reviews';
        $status = ContentStatus::tryFrom((string) $request->query('status', 'pending')) ?? ContentStatus::Pending;

        $query = $kind === 'reviews' ? Review::query() : Comment::query();
        $page = $query->where('status', $status->value)->orderBy('created_at')->orderBy('id')->paginate(25)->withQueryString();
        /** @var Collection<int, Review|Comment> $items */
        $items = collect($page->items());
        $profiles = $this->authors->resolve($items->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return new JsonResponse([
            'data' => $items->map(fn (Review|Comment $item): array => [
                'kind' => $kind === 'reviews' ? 'review' : 'comment',
                'id' => $item->id,
                'type' => $item->getMorphClass(),
                'subject' => $item instanceof Review
                    ? ['type' => $item->reviewable_type, 'id' => $item->reviewable_id]
                    : ['type' => $item->commentable_type, 'id' => $item->commentable_id],
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'author' => ($profiles[$item->user_id] ?? null)?->toArray(),
                'title' => $item instanceof Review ? $item->title : null,
                'rating' => $item instanceof Review ? $item->rating : null,
                'scale_max' => $item instanceof Review ? $item->scale_max : null,
                'body_html' => $item->body_html,
                'flags_count' => $item->flags_count,
                'created_at' => $item->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => $this->paginationMeta($page) + ['kind' => $kind, 'status' => $status->value],
        ]);
    }

    public function update(ModerateRequest $request, string $kind, string $id): JsonResponse
    {
        $moderator = $this->requireUser($request)->getAuthIdentifier();
        $item = $this->find($kind, $id);
        $action = (string) $request->validated('action');
        $note = $request->validated('note');
        $note = is_string($note) ? $note : null;

        if ($action === 'delete') {
            $item instanceof Review ? $this->reviews->delete($item, $moderator) : $this->discussions->deleteByModerator($item, $moderator);

            return new JsonResponse(['data' => ['id' => $item->getKey(), 'deleted' => true]]);
        }

        $status = match ($action) {
            'approve' => ContentStatus::Approved,
            'pending' => ContentStatus::Pending,
            'reject' => ContentStatus::Rejected,
            default => ContentStatus::Spam,
        };

        $item = $item instanceof Review
            ? $this->reviews->moderate($item, $status, $moderator, $note)
            : $this->discussions->moderate($item, $status, $moderator, $note);

        return new JsonResponse(['data' => ['id' => $item->getKey(), 'status' => $item->status->value]]);
    }

    private function find(string $kind, string $id): Review|Comment
    {
        $model = match ($kind) {
            'reviews' => Review::query(),
            'comments' => Comment::query(),
            default => throw new NotFoundHttpException,
        };

        $item = ctype_digit($id) ? $model->find((int) $id) : null;

        return $item instanceof Model ? $item : throw new NotFoundHttpException;
    }
}
