<?php

namespace RatingsAndReviews\Defaults;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Support\AuthorProfile;

/**
 * Reads the display name from the host's user model (`authors.model`, or the model of the
 * `users` auth provider) in one query. Without such a model every author is shown with a
 * neutral "Member" label; avatars and profile URLs need a host implementation.
 */
class DefaultAuthorResolver implements AuthorResolver
{
    public function resolve(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($ids === []) {
            return [];
        }

        $names = $this->names($ids);
        $profiles = [];

        foreach ($ids as $id) {
            $profiles[$id] = new AuthorProfile(
                id: $id,
                displayName: $names[$id] ?? (string) __('ratings-and-reviews::messages.member_fallback'),
            );
        }

        return $profiles;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $model = config('ratings-and-reviews.authors.model') ?? config('auth.providers.users.model');
        $attribute = (string) config('ratings-and-reviews.authors.name_attribute', 'name');

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return [];
        }

        /** @var Model $instance */
        $instance = new $model;

        return $model::query()
            ->whereIn($instance->getKeyName(), $ids)
            ->get([$instance->getKeyName(), $attribute])
            ->mapWithKeys(fn (Model $user): array => [(int) $user->getKey() => (string) $user->getAttribute($attribute)])
            ->filter(fn (string $name): bool => $name !== '')
            ->all();
    }
}
