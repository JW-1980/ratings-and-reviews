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

    /**
     * The vote response, see VoteResult::toArray(): `count` ({up, down} of the voted
     * dimension), `counts_by_dimension` (every dimension of the item) and `counts` (the raw
     * counter columns, kept for existing clients).
     */
    public static function result(VoteResult $result): JsonResponse
    {
        return new JsonResponse(['data' => $result->toArray()]);
    }
}
