<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Support\Ability;

class StoreTopicRequest extends PackageRequest
{
    protected function ability(): ?string
    {
        return Ability::MANAGE_TOPICS;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_\-]+$/'],
            'category_key' => ['nullable', 'string', 'max:64'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'array'],
            'name.nl' => ['required', 'string', 'max:80'],
            'name.en' => ['required', 'string', 'max:80'],
            'scale_max' => ['sometimes', 'integer', Rule::in((array) config('ratings-and-reviews.reviews.allowed_scales', [5, 10]))],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
