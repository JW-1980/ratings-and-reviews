<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Support\Ability;

class ModerateRequest extends PackageRequest
{
    public const ACTIONS = ['approve', 'pending', 'reject', 'spam', 'delete'];

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
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
