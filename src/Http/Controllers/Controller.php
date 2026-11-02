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

    protected function moderates(?Authenticatable $user): bool
    {
        return $user !== null && app(AbilityResolver::class)->allows($user, Ability::MODERATE);
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
