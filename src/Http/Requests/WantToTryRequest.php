<?php

namespace RatingsAndReviews\Http\Requests;

class WantToTryRequest extends PackageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['source_review_id' => ['nullable', 'integer', 'min:1']];
    }
}
