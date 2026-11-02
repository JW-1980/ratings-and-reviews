<?php

namespace RatingsAndReviews\Http\Requests;

/**
 * The length limits, the quote, the poll and the trust levels depend on the section and
 * are checked by DiscussionService after sanitizing; this checks the shape.
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
            'quoted_comment_id' => ['nullable', 'integer', 'min:1', 'required_with:quote_text'],
            'quote_text' => ['nullable', 'string', 'required_with:quoted_comment_id'],
            'poll' => ['nullable', 'array'],
            'poll.question' => ['required_with:poll', 'string'],
            'poll.options' => ['required_with:poll', 'array'],
            'poll.options.*' => ['nullable', 'string'],
            'poll.closes_at' => ['nullable', 'date'],
            'confirm_tone' => ['sometimes', 'boolean'],
        ];
    }
}
