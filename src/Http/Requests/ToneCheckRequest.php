<?php

namespace RatingsAndReviews\Http\Requests;

class ToneCheckRequest extends PackageRequest
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
        return ['text' => ['required', 'string', 'max:'.max(1, (int) config('ratings-and-reviews.sanitizer.max_input_bytes', 50000))]];
    }
}
