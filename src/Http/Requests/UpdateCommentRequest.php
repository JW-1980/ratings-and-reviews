<?php

namespace RatingsAndReviews\Http\Requests;

class UpdateCommentRequest extends PackageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string'], 'confirm_tone' => ['sometimes', 'boolean']];
    }
}
