<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Jobs\RefreshRatingAggregates;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewTopicRating;
use RatingsAndReviews\Models\TopicRating;

/**
 * The star-rating topics per scope and category, and the topics a subject is rated on.
 *
 * Inheritance: a subject in category path [a, b] gets the active topics of its scope
 * without a category, then those of `a`, then those of `b`. A category topic with a
 * parent replaces that parent in place; one without a parent is added. The list is capped
 * at `rating_topics.max_active`, in that order.
 *
 * The cap: at most `rating_topics.max_active` topics are active per scope and category.
 * Every write that can raise that count (create, re-activate) runs in a transaction that
 * first reads every topic of the scope with SELECT … FOR UPDATE. Under InnoDB's REPEATABLE
 * READ that locking read takes next-key locks over the scope's index range, so a second
 * admin's concurrent insert waits for the first transaction, or is chosen as a deadlock
 * victim and rolled back; the transaction is retried and the retry sees the committed row.
 */
class RatingTopicCatalog
{
    /**
     * The topics a subject is rated on, in display order.
     *
     * @return Collection<int, RatingTopic>
     */
    public function effectiveFor(Model&Reviewable $subject): Collection
    {
        return $this->effective($subject->ratingScope(), $subject->ratingCategoryPath());
    }

    /**
     * @param  list<string>  $categoryPath
     * @return Collection<int, RatingTopic>
     */
    public function effective(string $scope, array $categoryPath = []): Collection
    {
        $keys = array_values(array_filter($categoryPath, fn (mixed $key): bool => is_string($key) && $key !== ''));

        $topics = RatingTopic::query()
            ->where('scope', $scope)
            ->where('is_active', true)
            ->where(function ($query) use ($keys): void {
                $query->whereNull('category_key');

                if ($keys !== []) {
                    $query->orWhereIn('category_key', $keys);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /** @var array<int, RatingTopic> $slots topic id of the slot => the topic filling it */
        $slots = [];

        foreach ([null, ...$keys] as $key) {
            foreach ($topics->filter(fn (RatingTopic $topic): bool => $topic->category_key === $key) as $topic) {
                if ($topic->parent_id !== null && $this->replaceSlot($slots, $topic)) {
                    continue;
                }

                if ($topic->parent_id === null || $key === null) {
                    $slots[$topic->id] = $topic;
                }
            }
        }

        return new Collection(array_slice(array_values($slots), 0, $this->maxActive()));
    }

    /**
     * @param  array{scope: string, category_key?: string|null, parent_id?: int|null, name: array<string, string>, scale_max?: int, sort_order?: int|null, is_active?: bool}  $data
     *
     * @throws ValidationException when the cap is reached or the parent is invalid
     */
    public function create(array $data): RatingTopic
    {
        return DB::transaction(function () use ($data): RatingTopic {
            $scopeRows = $this->lockScope($data['scope']);
            $categoryKey = $data['category_key'] ?? null;
            $active = (bool) ($data['is_active'] ?? true);

            $this->assertValidParent($scopeRows, $data['parent_id'] ?? null, $categoryKey);

            if ($active) {
                $this->assertRoom($scopeRows, $categoryKey);
            }

            $sameGroup = $scopeRows->filter(fn (RatingTopic $topic): bool => $topic->category_key === $categoryKey);

            return RatingTopic::query()->create([
                'scope' => $data['scope'],
                'category_key' => $categoryKey,
                'parent_id' => $data['parent_id'] ?? null,
                'name' => $data['name'],
                'scale_max' => (int) ($data['scale_max'] ?? 5),
                'sort_order' => $data['sort_order'] ?? ((int) ($sameGroup->max('sort_order') ?? 0) + 1),
                'is_active' => $active,
            ]);
        }, $this->attempts());
    }

    /**
     * Renames, rescales, reorders or (de)activates a topic. Activating counts against the cap.
     *
     * @param  array{name?: array<string, string>, scale_max?: int, sort_order?: int, is_active?: bool, parent_id?: int|null}  $data
     */
    public function update(RatingTopic $topic, array $data): RatingTopic
    {
        return DB::transaction(function () use ($topic, $data): RatingTopic {
            $scopeRows = $this->lockScope($topic->scope);
            $current = $scopeRows->firstWhere('id', $topic->id);

            if (! $current instanceof RatingTopic) {
                throw ValidationException::withMessages(['topic' => __('ratings-and-reviews::messages.topic_missing')]);
            }

            if (array_key_exists('parent_id', $data)) {
                $this->assertValidParent($scopeRows, $data['parent_id'], $current->category_key, $current->id);
            }

            if (($data['is_active'] ?? false) === true && ! $current->is_active) {
                $this->assertRoom($scopeRows, $current->category_key);
            }

            $current->fill(array_intersect_key($data, array_flip(['name', 'scale_max', 'sort_order', 'is_active', 'parent_id'])))->save();

            $this->refreshSubjectsRatedOn($current->id);

            return $current;
        }, $this->attempts());
    }

    /**
     * Deletes a topic with every rating given for it; the aggregates of the affected
     * subjects are recomputed.
     *
     * @return int How many ratings were deleted with it.
     */
    public function delete(RatingTopic $topic): int
    {
        return DB::transaction(function () use ($topic): int {
            // Review-attached and standalone scores (both go with the topic, by cascade).
            $count = $topic->ratings()->count() + TopicRating::query()->withoutGlobalScopes()->where('topic_id', $topic->id)->count();
            $this->refreshSubjectsRatedOn($topic->id);
            RatingTopic::query()->where('parent_id', $topic->id)->update(['parent_id' => null]);
            $topic->delete();

            return $count;
        });
    }

    /**
     * Validates the topic ratings of a review submission: every key must be a topic the
     * subject is rated on and every value a whole number within that topic's scale.
     *
     * @param  array<array-key, mixed>|null  $input
     * @return array<int, array{value: int, scale_max: int}>
     *
     * @throws ValidationException
     */
    public function validatedRatings(Model&Reviewable $subject, ?array $input, string $field = 'topic_ratings'): array
    {
        if ($input === null || $input === []) {
            return [];
        }

        $topics = $this->effectiveFor($subject)->keyBy('id');
        $ratings = [];
        $errors = [];

        foreach ($input as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $id = is_int($key) || (is_string($key) && ctype_digit($key)) ? (int) $key : null;
            $topic = $id !== null ? $topics->get($id) : null;

            if (! $topic instanceof RatingTopic) {
                $errors[$field.'.'.$key] = [__('ratings-and-reviews::messages.topic_unknown')];

                continue;
            }

            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > $topic->scale_max) {
                $errors[$field.'.'.$key] = [__('ratings-and-reviews::messages.topic_value', ['max' => $topic->scale_max])];

                continue;
            }

            $ratings[$topic->id] = ['value' => (int) $value, 'scale_max' => $topic->scale_max];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $ratings;
    }

    public function maxActive(): int
    {
        return max(1, (int) config('ratings-and-reviews.rating_topics.max_active', 4));
    }

    /**
     * @param  array<int, RatingTopic>  $slots
     */
    private function replaceSlot(array &$slots, RatingTopic $topic): bool
    {
        foreach ($slots as $slotId => $current) {
            if ($slotId === $topic->parent_id || $current->id === $topic->parent_id) {
                $slots[$slotId] = $topic;

                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, RatingTopic>
     */
    private function lockScope(string $scope): Collection
    {
        return RatingTopic::query()->where('scope', $scope)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  Collection<int, RatingTopic>  $scopeRows
     */
    private function assertRoom(Collection $scopeRows, ?string $categoryKey): void
    {
        $active = $scopeRows->filter(fn (RatingTopic $topic): bool => $topic->is_active && $topic->category_key === $categoryKey)->count();

        if ($active >= $this->maxActive()) {
            throw ValidationException::withMessages(['is_active' => __('ratings-and-reviews::messages.topic_limit', ['max' => $this->maxActive()])]);
        }
    }

    /**
     * A parent must be a topic of the same scope in a broader group (another category key)
     * and cannot be the topic itself.
     *
     * @param  Collection<int, RatingTopic>  $scopeRows
     */
    private function assertValidParent(Collection $scopeRows, ?int $parentId, ?string $categoryKey, ?int $selfId = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $scopeRows->firstWhere('id', $parentId);

        if (! $parent instanceof RatingTopic || $parent->id === $selfId || $parent->category_key === $categoryKey || $categoryKey === null) {
            throw ValidationException::withMessages(['parent_id' => __('ratings-and-reviews::messages.topic_parent_invalid')]);
        }
    }

    private function refreshSubjectsRatedOn(int $topicId): void
    {
        $subjects = Review::query()->withErasureWithheld()
            ->whereIn('id', ReviewTopicRating::query()->where('topic_id', $topicId)->select('review_id'))
            ->toBase()->distinct()->get(['reviewable_type', 'reviewable_id'])
            ->concat(TopicRating::query()->withoutGlobalScopes()->where('topic_id', $topicId)->toBase()->distinct()->get(['reviewable_type', 'reviewable_id']))
            ->unique(fn (object $row): string => $row->reviewable_type.':'.$row->reviewable_id);

        foreach ($subjects as $row) {
            RefreshRatingAggregates::dispatch((string) $row->reviewable_type, (string) $row->reviewable_id);
        }
    }

    private function attempts(): int
    {
        return max(1, (int) config('ratings-and-reviews.rating_topics.lock_attempts', 3));
    }
}
