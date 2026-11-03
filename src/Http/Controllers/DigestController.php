<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Services\DiscussionDigest;
use RatingsAndReviews\Services\SubjectResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DigestController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly DiscussionDigest $digest,
    ) {}

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        $subject = $this->subjects->resolve($type, $id, Commentable::class);

        if (! $subject->commentsVisibleTo($this->currentUser($request))) {
            throw new NotFoundHttpException;
        }

        return new JsonResponse(['data' => $this->digest->for($subject)]);
    }
}
