<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Support\Ability;

class UpdateDiscussionRequest extends PackageRequest
{
    protected function ability(): ?string
    {
        return Ability::MODERATE;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slow_mode_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'locked' => ['sometimes', 'boolean'],
        ];
    }
}
