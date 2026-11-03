<?php

namespace RatingsAndReviews\Http\Requests;

/**
 * `ratings`: topic id => value (null removes the score). TopicRatings validates the topics
 * and values against the subject.
 */
class SaveTopicRatingsRequest extends PackageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ratings' => ['required', 'array', 'max:50'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function ratings(): array
    {
        return (array) $this->validated('ratings');
    }
}
