<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Http\Requests\PollVoteRequest;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Services\PollService;
use RatingsAndReviews\Support\VoterKeys;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PollController extends Controller
{
    public function __construct(
        private readonly PollService $polls,
        private readonly VoterIdentity $identity,
    ) {}

    public function show(Request $request, string $poll): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $model = $this->visiblePoll($poll, $viewer);
        $choice = $this->polls->viewerChoices([$model], new VoterKeys($this->identity, $request, $viewer))[$model->id] ?? null;

        return new JsonResponse(['data' => PollService::present($model->load('options'), $choice)]);
    }

    public function vote(PollVoteRequest $request, string $poll): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $model = $this->visiblePoll($poll, $viewer);
        $result = $this->polls->vote($request, $viewer, $model, (int) $request->validated('option_id'));

        return new JsonResponse([
            'data' => PollService::present($model->load('options'), $result['option_id']),
            'meta' => ['counted' => $result['counted'], 'changed' => $result['changed']],
        ]);
    }

    private function visiblePoll(string $id, ?Authenticatable $viewer): Poll
    {
        $poll = ctype_digit($id) ? Poll::query()->find((int) $id) : null;
        $comment = $poll?->comment;
        $subject = $comment?->commentable;

        if ($poll === null || ! $comment instanceof Comment || ! $comment->isPubliclyVisible() || ! $subject instanceof Commentable || ! $subject->commentsVisibleTo($viewer)) {
            throw new NotFoundHttpException;
        }

        return $poll;
    }
}
