<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Http\Requests\StoreReviewRequest;
use RatingsAndReviews\Http\Requests\UpdateReviewRequest;
use RatingsAndReviews\Http\Resources\ReviewResource;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\ReviewService;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Services\SubjectResolver;
use RatingsAndReviews\Services\VoteService;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ReviewSort;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReviewController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly ReviewService $reviews,
        private readonly VoteService $votes,
        private readonly AuthorResolver $authors,
        private readonly VoterIdentity $identity,
        private readonly SectionSettingsRepository $settings,
    ) {}

    public function index(Request $request, string $type, string $id): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $subject = $this->visibleSubject($type, $id, $viewer);
        $scale = $this->settings->for($subject->reviewSection())->reviewScale;
        $sort = ReviewSort::tryFrom((string) $request->query('sort')) ?? ReviewSort::Helpful;
        $ratingFilter = filter_var($request->query('rating'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $scale]]);

        $query = Review::query()->forSubject($subject)->approved()->with('topicRatings');

        if ($ratingFilter !== false && $ratingFilter !== null) {
            // The rating on the section's scale, as the histogram buckets it.
            $query->whereRaw('ROUND(1 + (rating - 1) / GREATEST(scale_max - 1, 1) * ?) = ?', [$scale - 1, $ratingFilter]);
        }

        $this->sort($query, $sort);

        $page = $query->paginate(max(1, (int) config('ratings-and-reviews.reviews.per_page', 10)))->withQueryString();
        /** @var Collection<int, Review> $items */
        $items = collect($page->items());

        $viewerId = $this->userId($viewer);
        $own = $viewerId === null ? null : Review::query()->forSubject($subject)->where('user_id', $viewerId)->with('topicRatings')->first();
        $profiles = $this->authors->resolve($items->pluck('user_id')->push($own?->user_id)->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $choices = $this->votes->choicesFor((new Review)->getMorphClass(), $items->pluck('id')->push($own?->id)->filter()->map(fn ($id): int => (int) $id)->all(), $this->identity->voterKey($request, $viewer));

        $present = fn (Review $review): array => (new ReviewResource($review))
            ->withContext($profiles[$review->user_id] ?? null, $choices[$review->id] ?? [], $viewerId)
            ->resolve($request);

        return new JsonResponse([
            'data' => $items->map($present)->values()->all(),
            'meta' => $this->paginationMeta($page) + [
                'sort' => $sort->value,
                'sorts' => array_map(fn (ReviewSort $case): array => ['value' => $case->value, 'label' => $case->label()], ReviewSort::cases()),
                'scale_max' => $scale,
                'own_review' => $own === null ? null : $present($own),
            ],
        ]);
    }

    public function store(StoreReviewRequest $request, string $type, string $id): JsonResponse
    {
        $user = $this->requireUser($request);
        $subject = $this->visibleSubject($type, $id, $user);

        $review = $this->reviews->submit($request, $user, $subject, $request->reviewData());

        return $this->saved($request, $review, 201);
    }

    public function update(UpdateReviewRequest $request, string $review): JsonResponse
    {
        $user = $this->requireUser($request);
        $model = $this->findReview($review, $user);

        if ($model->user_id !== $this->userId($user)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        $model = $this->reviews->update($request, $user, $model, $request->reviewData());

        return $this->saved($request, $model, 200);
    }

    public function destroy(Request $request, string $review): Response
    {
        $user = $this->requireUser($request);
        $model = $this->findReview($review, $user, allowHidden: true);

        if ($model->user_id === $this->userId($user)) {
            $this->reviews->delete($model);
        } elseif ($this->moderates($user)) {
            $this->reviews->delete($model, $user->getAuthIdentifier());
        } else {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        return new Response('', 204);
    }

    private function saved(Request $request, Review $review, int $status): JsonResponse
    {
        $review->load('topicRatings');
        $viewerId = $review->user_id;
        $profile = $this->authors->resolve([$viewerId])[$viewerId] ?? null;

        return new JsonResponse([
            'data' => (new ReviewResource($review))->withContext($profile, [], $viewerId)->resolve($request),
            'message' => $review->status === ContentStatus::Approved
                ? __('ratings-and-reviews::messages.review_published')
                : __('ratings-and-reviews::messages.review_pending'),
        ], $status);
    }

    /**
     * @return Model&Reviewable
     */
    private function visibleSubject(string $type, string $id, ?Authenticatable $viewer): Model
    {
        $subject = $this->subjects->resolve($type, $id, Reviewable::class);

        if (! $subject->reviewsVisibleTo($viewer)) {
            throw new NotFoundHttpException;
        }

        return $subject;
    }

    /**
     * A review the user may act on: its subject must be visible to them; a review that is
     * not approved is only found by its author (or a moderator, with `$allowHidden`).
     */
    private function findReview(string $id, Authenticatable $user, bool $allowHidden = false): Review
    {
        $review = ctype_digit($id) ? Review::query()->find((int) $id) : null;

        if ($review === null) {
            throw new NotFoundHttpException;
        }

        $subject = $review->reviewable;
        $own = $review->user_id === $this->userId($user);

        if (! $subject instanceof Reviewable || (! $subject->reviewsVisibleTo($user) && ! $this->moderates($user))) {
            throw new NotFoundHttpException;
        }

        if ($review->status !== ContentStatus::Approved && ! $own && ! ($allowHidden && $this->moderates($user))) {
            throw new NotFoundHttpException;
        }

        return $review;
    }

    /**
     * @param  Builder<Review>  $query
     */
    private function sort(Builder $query, ReviewSort $sort): void
    {
        match ($sort) {
            ReviewSort::Newest => $query->orderByDesc('created_at')->orderByDesc('id'),
            ReviewSort::Highest => $query->orderByRaw('(rating - 1) / GREATEST(scale_max - 1, 1) DESC')->orderByDesc('created_at')->orderByDesc('id'),
            ReviewSort::Lowest => $query->orderByRaw('(rating - 1) / GREATEST(scale_max - 1, 1) ASC')->orderByDesc('created_at')->orderByDesc('id'),
            ReviewSort::Helpful => $query->orderByDesc('helpful_count')->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
