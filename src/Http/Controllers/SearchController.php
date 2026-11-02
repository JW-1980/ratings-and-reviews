<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Http\Requests\SearchRequest;
use RatingsAndReviews\Services\SearchService;
use RatingsAndReviews\Services\SubjectResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Searches the reviews and/or the discussion of one subject, each only when the subject
 * has it and the viewer may see it (`in`: all, reviews or comments).
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly SearchService $search,
    ) {}

    public function index(SearchRequest $request, string $type, string $id): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $subject = $this->subjects->resolveAny($type, $id, [Reviewable::class, Commentable::class]);
        $in = (string) ($request->validated('in') ?? 'all');

        $reviews = $subject instanceof Reviewable && $subject->reviewsVisibleTo($viewer) && $in !== 'comments';
        $comments = $subject instanceof Commentable && $subject->commentsVisibleTo($viewer) && $in !== 'reviews';

        if (! ($subject instanceof Reviewable && $subject->reviewsVisibleTo($viewer)) && ! ($subject instanceof Commentable && $subject->commentsVisibleTo($viewer))) {
            throw new NotFoundHttpException;
        }

        $result = $this->search->search($subject, (string) $request->validated('q'), $reviews, $comments);

        return new JsonResponse(['data' => ['reviews' => $result['reviews'], 'comments' => $result['comments']], 'meta' => $result['meta']]);
    }
}
