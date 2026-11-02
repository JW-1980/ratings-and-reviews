<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\UpdateDiscussionRequest;
use RatingsAndReviews\Models\DiscussionState;
use RatingsAndReviews\Services\DiscussionStates;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Services\SubjectResolver;
use RatingsAndReviews\Support\Ability;

/**
 * A moderator's per-discussion controls: slow mode (overriding the section default; null
 * returns to it) and locking.
 */
class DiscussionController extends Controller
{
    public function __construct(
        private readonly SubjectResolver $subjects,
        private readonly DiscussionStates $states,
        private readonly SectionSettingsRepository $settings,
    ) {}

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        $this->requireAbility($request, Ability::MODERATE);
        $subject = $this->subjects->resolve($type, $id, Commentable::class);

        return new JsonResponse(['data' => $this->present($subject, $this->states->find($subject))]);
    }

    public function update(UpdateDiscussionRequest $request, string $type, string $id): JsonResponse
    {
        $moderator = $this->requireUser($request);
        $subject = $this->subjects->resolve($type, $id, Commentable::class);

        /** @var array{slow_mode_seconds?: int|null, locked?: bool} $changes */
        $changes = $request->validated();
        $state = $this->states->update($subject, $changes, $moderator->getAuthIdentifier());

        return new JsonResponse(['data' => $this->present($subject, $state), 'message' => __('ratings-and-reviews::messages.discussion_saved')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Model&Commentable $subject, ?DiscussionState $state): array
    {
        $settings = $this->settings->for($subject->commentSection());

        return [
            'subject' => ['type' => $subject->getMorphClass(), 'id' => $subject->getKey()],
            'slow_mode_seconds' => $state?->slow_mode_seconds,
            'section_slow_mode_seconds' => $settings->slowModeSeconds,
            'effective_slow_mode_seconds' => $this->states->effectiveSlowMode($subject, $settings, $state),
            'locked' => $state?->locked_at !== null,
            'locked_at' => $state?->locked_at?->toIso8601String(),
            'locked_by' => $state?->locked_by,
        ];
    }
}
