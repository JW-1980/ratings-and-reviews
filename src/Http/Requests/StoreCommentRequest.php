<?php

namespace RatingsAndReviews\Http\Requests;

/**
 * The length limits depend on the section and are checked by DiscussionService after
 * sanitizing; this checks the shape.
 */
class StoreCommentRequest extends PackageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
