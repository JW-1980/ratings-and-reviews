<?php

namespace RatingsAndReviews\Http\Controllers\Admin;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Requests\Admin\ModerateRequest;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Services\DiscussionService;
use RatingsAndReviews\Services\ReviewService;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ModerationScope;

/**
 * One moderation inbox for reviews and comments.
 */
class ModerationController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly DiscussionService $discussions,
        private readonly AuthorResolver $authors,
    ) {}

    /**
     * The inbox of one kind and status. The user needs rr.moderate on the inbox of the kind
     * (ModerationScope::inbox); the list holds only the sections they may moderate
     * (ModerationScope::section per section present), `meta.sections` names them.
     */
    public function index(Request $request): JsonResponse
    {
        $kind = $request->query('kind', 'reviews') === 'comments' ? 'comments' : 'reviews';
        $scopeKind = $kind === 'reviews' ? ModerationScope::REVIEW : ModerationScope::COMMENT;
        $user = $this->requireAbility($request, Ability::MODERATE, ModerationScope::inbox($scopeKind));
        $status = ContentStatus::tryFrom((string) $request->query('status', 'pending')) ?? ContentStatus::Pending;

        $query = $kind === 'reviews' ? Review::query()->with('photos') : Comment::query();
        $query->where('status', $status->value);
        $sections = $this->allowedSections($user, $scopeKind, (clone $query)->distinct()->pluck('section')->all());
        $this->whereSectionIn($query, $sections);

        $page = $query->orderBy('created_at')->orderBy('id')->paginate(25)->withQueryString();
        /** @var Collection<int, Review|Comment> $items */
        $items = collect($page->items());
        $profiles = $this->authors->resolve($items->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return new JsonResponse([
            'data' => $items->map(fn (Review|Comment $item): array => [
                'kind' => $kind === 'reviews' ? 'review' : 'comment',
                'id' => $item->id,
                'type' => $item->getMorphClass(),
                'subject' => $item instanceof Review
                    ? ['type' => $item->reviewable_type, 'id' => $item->reviewable_id]
                    : ['type' => $item->commentable_type, 'id' => $item->commentable_id],
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'author' => ($profiles[$item->user_id] ?? null)?->toArray(),
                'title' => $item instanceof Review ? $item->title : null,
                'rating' => $item instanceof Review ? $item->rating : null,
                'scale_max' => $item instanceof Review ? $item->scale_max : null,
                'body_html' => $item->body_html,
                // A moderator sees every photo, also those of a review that is not public.
                'photos' => $item instanceof Review ? $item->photos->map(fn (Upload $photo): array => ['id' => $photo->id, 'url' => $photo->url(), 'alt' => $photo->alt_text])->values()->all() : [],
                'flags_count' => $item->flags_count,
                'created_at' => $item->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => $this->paginationMeta($page) + ['kind' => $kind, 'status' => $status->value, 'sections' => $sections],
        ]);
    }

    public function update(ModerateRequest $request, string $kind, string $id): JsonResponse
    {
        $moderator = $this->requireUser($request)->getAuthIdentifier();
        $item = $request->item();
        $action = (string) $request->validated('action');
        $note = $request->validated('note');
        $note = is_string($note) ? $note : null;

        if ($action === 'delete') {
            $item instanceof Review ? $this->reviews->delete($item, $moderator) : $this->discussions->deleteByModerator($item, $moderator);

            return new JsonResponse(['data' => ['id' => $item->getKey(), 'deleted' => true]]);
        }

        $status = match ($action) {
            'approve' => ContentStatus::Approved,
            'pending' => ContentStatus::Pending,
            'reject' => ContentStatus::Rejected,
            default => ContentStatus::Spam,
        };

        $item = $item instanceof Review
            ? $this->reviews->moderate($item, $status, $moderator, $note)
            : $this->discussions->moderate($item, $status, $moderator, $note);

        return new JsonResponse(['data' => ['id' => $item->getKey(), 'status' => $item->status->value]]);
    }

    /**
     * The sections among `$present` (null: unknown) the user may moderate this kind in.
     *
     * @param  list<mixed>  $present
     * @return list<string|null>
     */
    private function allowedSections(Authenticatable $user, string $kind, array $present): array
    {
        $resolver = app(AbilityResolver::class);

        return array_values(array_filter(
            array_map(fn (mixed $section): ?string => $section === null ? null : (string) $section, $present),
            fn (?string $section): bool => $resolver->allows($user, Ability::MODERATE, ModerationScope::section($kind, $section)),
        ));
    }

    /**
     * @param  Builder<Review>|Builder<Comment>  $query
     * @param  list<string|null>  $sections
     */
    private function whereSectionIn(Builder $query, array $sections): void
    {
        $named = array_values(array_filter($sections, fn (?string $section): bool => $section !== null));
        $withNull = in_array(null, $sections, true);

        $query->where(function (Builder $where) use ($named, $withNull): void {
            $named === [] ? $where->whereRaw('0 = 1') : $where->whereIn('section', $named);

            if ($withNull) {
                $where->orWhereNull('section');
            }
        });
    }
}
