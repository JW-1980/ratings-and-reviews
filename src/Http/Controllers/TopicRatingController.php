<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Http\Requests\SaveTopicRatingsRequest;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Services\SubjectResolver;
use RatingsAndReviews\Services\TopicRatings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Topic ratings without a review: the subject's topics with their averages and the member's
 * own scores (GET), and saving the member's scores (PUT, section `standalone_topic_ratings`).
 */
class TopicRatingController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly TopicRatings $ratings,
        private readonly RatingAggregator $aggregator,
    ) {}

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $subject = $this->visibleSubject($type, $id, $viewer);

        return new JsonResponse(['data' => $this->present($subject, $viewer)]);
    }

    public function update(SaveTopicRatingsRequest $request, string $type, string $id): JsonResponse
    {
        $user = $this->requireUser($request);
        $subject = $this->visibleSubject($type, $id, $user);

        $this->ratings->rate($request, $user, $subject, $request->ratings());

        return new JsonResponse([
            'data' => $this->present($subject, $user),
            'message' => __('ratings-and-reviews::messages.topic_ratings_saved'),
        ]);
    }

    /**
     * @return array{enabled: bool, accepts_ratings: bool, topics: list<array<string, mixed>>, own: object|null}
     */
    private function present(Model&Reviewable $subject, ?Authenticatable $viewer): array
    {
        $enabled = $this->ratings->isEnabledFor($subject);
        $userId = $this->userId($viewer);

        return [
            'enabled' => $enabled,
            'accepts_ratings' => $enabled && $subject->acceptsReviews(),
            'topics' => $this->aggregator->summary($subject)['topics'],
            'own' => $userId === null ? null : (object) $this->ratings->forUser($subject, $userId),
        ];
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
}
