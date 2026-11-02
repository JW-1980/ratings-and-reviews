<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller as BaseController;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Http\Concerns\ResolvesCurrentUser;
use RatingsAndReviews\Support\Ability;

abstract class Controller extends BaseController
{
    use ResolvesCurrentUser;

    /**
     * Whether the user may moderate this item or subject (a Review, a Comment, a Commentable
     * or Reviewable subject, an Upload without content): rr.moderate with that subject.
     */
    protected function moderates(?Authenticatable $user, mixed $subject): bool
    {
        return $user !== null && app(AbilityResolver::class)->allows($user, Ability::MODERATE, $subject);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{total: int, page: int, per_page: int, last_page: int}
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
