<?php

namespace RatingsAndReviews\Http\Requests;

use Illuminate\Validation\Rule;

class SearchRequest extends PackageRequest
{
    protected function requiresUser(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:'.max(1, (int) config('ratings-and-reviews.search.max_query_length', 200) * 4)],
            'in' => ['nullable', 'string', Rule::in(['all', 'reviews', 'comments'])],
        ];
    }
}
