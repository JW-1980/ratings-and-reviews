<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Http\Requests\VoteRequest;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\VoteService;
use RatingsAndReviews\Support\VoteResult;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReviewVoteController extends Controller
{
    public function __construct(private readonly VoteService $votes) {}

    public function store(VoteRequest $request, string $review): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $model = ctype_digit($review) ? Review::query()->approved()->find((int) $review) : null;
        $subject = $model?->reviewable;

        if ($model === null || ! $subject instanceof Reviewable || ! $subject->reviewsVisibleTo($viewer)) {
            throw new NotFoundHttpException;
        }

        return self::result($this->votes->toggle($request, $viewer, $model, $request->dimension(), (int) $request->validated('value')));
    }

    public static function result(VoteResult $result): JsonResponse
    {
        return new JsonResponse(['data' => [
            'dimension' => $result->dimension->value,
            'value' => $result->value,
            'counted' => $result->counted,
            'counts' => $result->counts,
        ]]);
    }
}
