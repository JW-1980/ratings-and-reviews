<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Http\Requests\VoteRequest;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Services\VoteService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CommentVoteController extends Controller
{
    public function __construct(private readonly VoteService $votes) {}

    public function store(VoteRequest $request, string $comment): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $model = ctype_digit($comment) ? Comment::query()->approved()->find((int) $comment) : null;
        $subject = $model?->commentable;

        if ($model === null || ! $subject instanceof Commentable || ! $subject->commentsVisibleTo($viewer)) {
            throw new NotFoundHttpException;
        }

        return ReviewVoteController::result($this->votes->toggle($request, $viewer, $model, $request->dimension(), (int) $request->validated('value')));
    }
}
