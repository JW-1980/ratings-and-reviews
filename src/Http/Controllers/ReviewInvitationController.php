<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Models\ReviewInvitation;
use RatingsAndReviews\Services\ReviewInvitations;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The member's open review invitations (sent, not completed or dismissed) and dismissing one.
 */
class ReviewInvitationController extends Controller
{
    public function __construct(private readonly ReviewInvitations $invitations) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $open = ReviewInvitation::query()
            ->where('user_id', $this->userId($user))
            ->whereNotNull('sent_at')
            ->whereNull('completed_at')
            ->whereNull('dismissed_at')
            ->with('reviewable')
            ->orderByDesc('sent_at')
            ->limit(50)
            ->get();

        return new JsonResponse(['data' => $open
            ->filter(fn (ReviewInvitation $invitation): bool => $invitation->reviewable instanceof Reviewable && $invitation->reviewable->reviewsVisibleTo($user))
            ->map(function (ReviewInvitation $invitation): array {
                /** @var Model&Reviewable $subject */
                $subject = $invitation->reviewable;

                return [
                    'id' => $invitation->id,
                    'subject' => ['type' => $invitation->reviewable_type, 'id' => $invitation->reviewable_id, 'title' => $subject->reviewSubjectTitle(), 'url' => $subject->reviewSubjectUrl()],
                    'source' => $invitation->source,
                    'due_at' => $invitation->due_at->toIso8601String(),
                    'sent_at' => $invitation->sent_at?->toIso8601String(),
                ];
            })->values()->all()]);
    }

    public function dismiss(Request $request, string $invitation): JsonResponse
    {
        $user = $this->requireUser($request);
        $model = ctype_digit($invitation) ? ReviewInvitation::query()->find((int) $invitation) : null;

        if ($model === null || $model->user_id !== $this->userId($user)) {
            throw new NotFoundHttpException;
        }

        $model = $this->invitations->dismiss($model);

        return new JsonResponse(['data' => ['id' => $model->id, 'dismissed_at' => $model->dismissed_at?->toIso8601String()]]);
    }
}
