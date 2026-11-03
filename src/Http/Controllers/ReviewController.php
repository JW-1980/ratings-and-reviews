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
use RatingsAndReviews\Contracts\CardResolver;
use RatingsAndReviews\Contracts\OfficialReplyResolver;
use RatingsAndReviews\Contracts\PriceAtReviewResolver;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Contracts\WantToTryList;
use RatingsAndReviews\Http\Requests\StoreFollowUpRequest;
use RatingsAndReviews\Http\Requests\StoreReviewRequest;
use RatingsAndReviews\Http\Requests\UpdateReviewRequest;
use RatingsAndReviews\Http\Resources\ReviewResource;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\Annotations;
use RatingsAndReviews\Services\Participation;
use RatingsAndReviews\Services\RatingTopicCatalog;
use RatingsAndReviews\Services\ReviewFacets;
use RatingsAndReviews\Services\ReviewFollowUps;
use RatingsAndReviews\Services\ReviewQualityMeter;
use RatingsAndReviews\Services\ReviewQuestions;
use RatingsAndReviews\Services\ReviewService;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Services\SubjectResolver;
use RatingsAndReviews\Services\ToneNudge;
use RatingsAndReviews\Services\TrustLevels;
use RatingsAndReviews\Services\VoteService;
use RatingsAndReviews\Support\Comparison;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\OfficialReply;
use RatingsAndReviews\Support\ParticipationKind;
use RatingsAndReviews\Support\ReviewSort;
use RatingsAndReviews\Support\SqlDialect;
use RatingsAndReviews\Support\VoterKeys;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ReviewController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly ReviewService $reviews,
        private readonly VoteService $votes,
        private readonly AuthorResolver $authors,
        private readonly VoterIdentity $identity,
        private readonly SectionSettingsRepository $settings,
        private readonly ReviewFacets $facets,
        private readonly ReviewQualityMeter $quality,
        private readonly RatingTopicCatalog $topics,
        private readonly TrustLevels $trust,
        private readonly ToneNudge $tone,
        private readonly Participation $participation,
        private readonly Annotations $annotations,
        private readonly ReviewQuestions $questions,
        private readonly ReviewFollowUps $followUps,
    ) {}

    /** The relations a review card needs. */
    private const WITH = ['topicRatings', 'compared', 'photos', 'answers.question', 'followUp'];

    /**
     * The reviews of a subject. Filters: `rating` (on the section's scale),
     * `facet[group]=value` (several groups combine with AND; unknown groups or values are
     * ignored) and `with_photos=1` (only reviews with at least one photo). Where the subject
     * hides blocked members (HidesBlockedMembers), reviews by members in a block relation with
     * the viewer are left out. With the section's `reviews_enabled` off the list is empty.
     */
    public function index(Request $request, string $type, string $id): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $subject = $this->visibleSubject($type, $id, $viewer);
        $settings = $this->settings->for($subject->reviewSectionKey());
        $scale = $settings->reviewScale;
        $sort = ReviewSort::tryFrom((string) $request->query('sort')) ?? ReviewSort::Helpful;
        $ratingFilter = filter_var($request->query('rating'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $scale]]);

        $viewerId = $this->userId($viewer);
        $query = Review::query()->forSubject($subject)->approved()->with(self::WITH);
        $this->withReplyCount($query);

        if (! $settings->reviewsEnabled) {
            $query->whereRaw('0 = 1');
        }

        $hidden = $this->participation->hiddenAuthorIds($subject, $viewerId);

        if ($hidden !== []) {
            $query->whereNotIn('user_id', $hidden);
        }

        if ($ratingFilter !== false && $ratingFilter !== null) {
            // The rating on the section's scale, as the histogram buckets it.
            $query->whereRaw('ROUND(1 + (rating - 1) * 1.0 / '.SqlDialect::greatest('scale_max - 1', '1').' * ?) = ?', [$scale - 1, $ratingFilter]);
        }

        $withPhotos = $request->boolean('with_photos');

        if ($withPhotos) {
            $query->whereHas('photos');
        }

        $facetFilter = $this->facetFilter($request->query('facet'));

        foreach ($facetFilter as $group => $value) {
            $query->where('facets->'.$group, $value);
        }

        $this->sort($query, $sort);

        $page = $query->paginate(max(1, (int) config('ratings-and-reviews.reviews.per_page', 10)))->withQueryString();
        /** @var Collection<int, Review> $items */
        $items = collect($page->items());

        $own = $viewerId === null ? null : $this->withReplyCount(Review::query()->forSubject($subject)->where('user_id', $viewerId)->with(self::WITH))->first();
        $items->concat(array_filter([$own]))->each(fn (Review $review) => $review->setRelation('reviewable', $subject));
        $profiles = $this->authors->resolve($items->pluck('user_id')->push($own?->user_id)->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $choices = $this->votes->viewerChoices($items->concat(array_filter([$own]))->unique(fn (Review $review): int => $review->id)->values(), new VoterKeys($this->identity, $request, $viewer));

        $replies = $this->officialReplies($items->concat(array_filter([$own]))->all());
        $blocked = $this->participation->blockedWith($viewerId);
        $participates = $viewer === null ? null : $this->participation->canParticipate($viewer, $subject, ParticipationKind::REVIEW);

        $marks = $this->annotations->forItems($items->concat(array_filter([$own])), $viewer);

        $present = fn (Review $review): array => $this->resource($review, $replies)
            ->withAnnotations($marks[Annotations::keyOf($review)] ?? [])
            ->withContext($profiles[$review->user_id] ?? null, $choices[$review->id] ?? [], $viewerId)
            ->withModeratorCheck(fn (): bool => $this->moderates($viewer, $review))
            ->withParticipation($blocked, $review->user_id === $viewerId ? $this->followUps->reasonToRefuse($viewer, $review, $review->followUp !== null) === null : false)
            ->resolve($request);

        return new JsonResponse([
            'data' => $items->map($present)->values()->all(),
            'meta' => $this->paginationMeta($page) + [
                'sort' => $sort->value,
                'sorts' => array_map(fn (ReviewSort $case): array => ['value' => $case->value, 'label' => $case->label()], ReviewSort::cases()),
                'scale_max' => $scale,
                'review_scale' => $scale,
                'review_extended_fields' => $settings->reviewExtendedFields,
                'review_min_length' => $settings->reviewMinLength,
                'review_form' => [
                    'max_body_length' => max(1, (int) config('ratings-and-reviews.reviews.max_body_length', 10000)),
                    'max_title_length' => (int) config('ratings-and-reviews.reviews.max_title_length', 150),
                    'max_pros_cons' => (int) config('ratings-and-reviews.reviews.max_pros_cons', 5),
                    'max_pro_con_length' => (int) config('ratings-and-reviews.reviews.max_pro_con_length', 200),
                    'max_photos' => max(0, (int) config('ratings-and-reviews.reviews.max_photos', 4)),
                    'photos_require_approval' => (bool) config('ratings-and-reviews.reviews.photos_require_approval', false),
                    'requires_approval' => $settings->reviewRequiresApproval,
                ],
                'own_review' => $own === null ? null : $present($own),
                'filters' => ['rating' => $ratingFilter === false ? null : $ratingFilter, 'facets' => (object) $facetFilter, 'with_photos' => $withPhotos],
                'facets' => $this->facets->options(),
                'comparisons' => array_map(fn ($case): array => ['value' => $case->value, 'label' => $case->label()], Comparison::cases()),
                'editor' => $this->trust->for($viewer, $settings, $subject)->toArray(),
                'features' => [
                    'price_at_review' => app(PriceAtReviewResolver::class)->isEnabled(),
                    'want_to_try' => app(WantToTryList::class)->isEnabled(),
                    'product_cards' => app(CardResolver::class)->isEnabled(),
                    'tone_check' => $this->tone->isEnabled(),
                    'review_replies' => Review::repliesEnabled(),
                    'official_replies' => app(OfficialReplyResolver::class)->isEnabled(),
                    'follow_ups' => $settings->reviewFollowUpAfterDays > 0,
                    'attributions' => $viewer !== null && $this->participation->attributionOptions($viewer, $subject) !== [],
                ],
                'reviews_enabled' => $settings->reviewsEnabled,
                // Null for a guest; false: the subject's ParticipationRules keep this member out.
                'can_participate' => $participates,
                'attributions' => $viewer !== null && $participates !== false ? $this->participation->attributionOptions($viewer, $subject) : [],
                'review_questions' => $settings->reviewsEnabled ? $this->questions->presentFor($subject) : [],
                'review_follow_up' => $settings->reviewFollowUpAfterDays > 0 ? [
                    'after_days' => $settings->reviewFollowUpAfterDays,
                    'window_days' => $settings->reviewFollowUpWindowDays,
                    'max_body_length' => max(1, (int) config('ratings-and-reviews.reviews.follow_ups.max_body_length', 2000)),
                ] : null,
                'review_replies_section' => Review::repliesEnabled() ? (new Review)->commentSectionKey() : null,
                'accepts_reviews' => $settings->enabled && $settings->reviewsEnabled && $subject->acceptsReviews() && $participates !== false,
            ],
        ]);
    }

    /**
     * @return array<string, string> validated group => value
     */
    private function facetFilter(mixed $input): array
    {
        $groups = $this->facets->groups();
        $filter = [];

        foreach (is_array($input) ? $input : [] as $group => $value) {
            if (is_string($group) && is_string($value) && in_array($value, $groups[$group] ?? [], true)) {
                $filter[$group] = $value;
            }
        }

        ksort($filter);

        return $filter;
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
        } elseif ($this->moderates($user, $model)) {
            $this->reviews->delete($model, $user->getAuthIdentifier());
        } else {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.not_yours'));
        }

        return new Response('', 204);
    }

    /**
     * The author adds a follow-up to their review ("after a month": `rating`, optional `body`).
     */
    public function storeFollowUp(StoreFollowUpRequest $request, string $review): JsonResponse
    {
        $user = $this->requireUser($request);
        $model = $this->findReview($review, $user);

        if ($model->user_id !== $this->userId($user)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.follow_up_not_author'));
        }

        $body = $request->validated('body');
        $this->followUps->add($request, $user, $model, $request->validated('rating'), is_string($body) ? $body : null, $request->boolean('confirm_tone'));

        $model->refresh();

        return $this->saved($request, $model, 201, $model->status === ContentStatus::Approved
            ? __('ratings-and-reviews::messages.follow_up_saved')
            : __('ratings-and-reviews::messages.follow_up_pending'));
    }

    /** The author (or a moderator) removes the follow-up. */
    public function destroyFollowUp(Request $request, string $review): Response
    {
        $user = $this->requireUser($request);
        $this->followUps->delete($this->findReview($review, $user, allowHidden: true), $user);

        return new Response('', 204);
    }

    private function saved(Request $request, Review $review, int $status, ?string $message = null): JsonResponse
    {
        $review->load(self::WITH);

        if (Review::repliesEnabled()) {
            $review->loadCount(['rrComments as replies_count' => fn (Builder $replies) => $replies->published()->withContent()]);
        }

        $viewerId = $review->user_id;
        $profile = $this->authors->resolve([$viewerId])[$viewerId] ?? null;
        $subject = $review->reviewable;

        $quality = $this->quality->assess([
            'text' => $review->body_text,
            'pros' => count($review->pros ?? []),
            'cons' => count($review->cons ?? []),
            'topics_rated' => $review->topicRatings->count(),
            'topics_available' => $subject instanceof Reviewable ? $this->topics->effectiveFor($subject)->count() : 0,
            'extended_fields' => $subject instanceof Reviewable && $this->settings->for($subject->reviewSectionKey())->reviewExtendedFields,
        ]);

        return new JsonResponse([
            'data' => $this->resource($review, $this->officialReplies([$review]))->withContext($profile, [], $viewerId)
                ->withAnnotations($this->annotations->forItems([$review], $this->currentUser($request))[Annotations::keyOf($review)] ?? [])
                ->withParticipation([], $this->followUps->reasonToRefuse($this->currentUser($request), $review, $review->followUp !== null) === null)
                ->resolve($request),
            'quality' => $quality->toArray(),
            'message' => $message ?? ($review->status === ContentStatus::Approved
                ? __('ratings-and-reviews::messages.review_published')
                : __('ratings-and-reviews::messages.review_pending')),
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

        if (! $subject instanceof Reviewable || (! $subject->reviewsVisibleTo($user) && ! $this->moderates($user, $review))) {
            throw new NotFoundHttpException;
        }

        if ($review->status !== ContentStatus::Approved && ! $own && ! ($allowHidden && $this->moderates($user, $review))) {
            throw new NotFoundHttpException;
        }

        return $review;
    }

    /**
     * The official replies of these reviews, or null when the host has none (switched off).
     * A failing resolver is reported and shows no replies rather than breaking the list.
     *
     * @param  list<Review>  $reviews
     * @return array<int, OfficialReply|null>|null
     */
    private function officialReplies(array $reviews): ?array
    {
        $resolver = app(OfficialReplyResolver::class);

        if (! $resolver->isEnabled()) {
            return null;
        }

        try {
            return array_filter($resolver->repliesFor(array_values($reviews)), fn (mixed $reply): bool => $reply instanceof OfficialReply);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param  array<int, OfficialReply|null>|null  $replies
     */
    private function resource(Review $review, ?array $replies): ReviewResource
    {
        $resource = new ReviewResource($review);

        return $replies === null ? $resource : $resource->withOfficialReply($replies[$review->id] ?? null);
    }

    /**
     * Adds `replies_count` (the public replies) when replies to reviews are on.
     *
     * @param  Builder<Review>  $query
     * @return Builder<Review>
     */
    private function withReplyCount(Builder $query): Builder
    {
        return $query->when(Review::repliesEnabled(), fn (Builder $query) => $query->withCount(['rrComments as replies_count' => fn (Builder $replies) => $replies->published()->withContent()]));
    }

    /**
     * @param  Builder<Review>  $query
     */
    private function sort(Builder $query, ReviewSort $sort): void
    {
        match ($sort) {
            ReviewSort::Newest => $query->orderByDesc('created_at')->orderByDesc('id'),
            ReviewSort::Highest => $query->orderByRaw('(rating - 1) * 1.0 / '.SqlDialect::greatest('scale_max - 1', '1').' DESC')->orderByDesc('created_at')->orderByDesc('id'),
            ReviewSort::Lowest => $query->orderByRaw('(rating - 1) * 1.0 / '.SqlDialect::greatest('scale_max - 1', '1').' ASC')->orderByDesc('created_at')->orderByDesc('id'),
            ReviewSort::Helpful => $query->orderByDesc('helpful_count')->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
