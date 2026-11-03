<?php

namespace RatingsAndReviews\Http\Requests;

/**
 * A follow-up on the author's own review: the rating now (on the review's scale, checked by
 * ReviewFollowUps) and an optional plain-text note.
 */
class StoreFollowUpRequest extends PackageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:'.max((array) config('ratings-and-reviews.reviews.allowed_scales', [5, 10]))],
            'body' => ['nullable', 'string', 'max:'.max(1, (int) config('ratings-and-reviews.reviews.follow_ups.max_body_length', 2000))],
            'confirm_tone' => ['sometimes', 'boolean'],
        ];
    }
}
