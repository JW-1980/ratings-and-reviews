<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Services\SubjectResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RatingSummaryController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly RatingAggregator $aggregator,
    ) {}

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        $subject = $this->subjects->resolve($type, $id, Reviewable::class);

        if (! $subject->reviewsVisibleTo($this->currentUser($request))) {
            throw new NotFoundHttpException;
        }

        return new JsonResponse(['data' => $this->aggregator->summary($subject)]);
    }
}
