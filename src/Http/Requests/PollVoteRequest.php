<?php

namespace RatingsAndReviews\Http\Requests;

/**
 * Guests may vote where the section allows guest votes; PollService decides.
 */
class PollVoteRequest extends PackageRequest
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
        return ['option_id' => ['required', 'integer', 'min:1']];
    }
}
