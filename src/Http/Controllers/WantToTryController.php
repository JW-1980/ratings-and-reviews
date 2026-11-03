<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\WantToTryList;
use RatingsAndReviews\Events\WantToTryRequested;
use RatingsAndReviews\Http\Requests\WantToTryRequest;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\SubjectResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Want to try" from a review: puts the subject on the member's list through the host's
 * WantToTryList (shopping list, wish list), with the review it came from. 404 while the
 * host has not enabled the list. WantToTryRequested fires when the subject was added.
 */
class WantToTryController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly WantToTryList $list,
        private readonly Dispatcher $events,
    ) {}

    public function store(WantToTryRequest $request, string $type, string $id): JsonResponse
    {
        if (! $this->list->isEnabled()) {
            throw new NotFoundHttpException;
        }

        $user = $this->requireUser($request);
        $subject = $this->subjects->resolve($type, $id, Reviewable::class);

        if (! $subject->reviewsVisibleTo($user)) {
            throw new NotFoundHttpException;
        }

        $sourceId = $request->validated('source_review_id');
        $source = null;

        if ($sourceId !== null) {
            $source = Review::query()->forSubject($subject)->approved()->find((int) $sourceId);

            if ($source === null) {
                throw ValidationException::withMessages(['source_review_id' => __('ratings-and-reviews::messages.want_to_try_source')]);
            }
        }

        $added = $this->list->add($user, $subject, $source);

        if ($added) {
            $this->events->dispatch(new WantToTryRequested($user->getAuthIdentifier(), $subject, $source));
        }

        return new JsonResponse([
            'data' => ['added' => $added],
            'message' => __($added ? 'ratings-and-reviews::messages.want_to_try_added' : 'ratings-and-reviews::messages.want_to_try_exists'),
        ], $added ? 201 : 200);
    }
}
