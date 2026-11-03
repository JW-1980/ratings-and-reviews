<?php

namespace RatingsAndReviews\Http\Requests;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Support\VoteDimension;

/**
 * Guests may vote where the section allows it; VoteService decides.
 */
class VoteRequest extends PackageRequest
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
            'dimension' => ['required', 'string', Rule::in(VoteDimension::values())],
            'value' => ['required', 'integer', Rule::in([1, -1])],
        ];
    }

    public function dimension(): VoteDimension
    {
        return VoteDimension::from((string) $this->validated('dimension'));
    }
}
