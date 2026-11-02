<?php

namespace RatingsAndReviews\Http\Requests;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Support\Comparison;

/**
 * The shape of a review. The rating's range, the body length and the topic keys depend on
 * the subject's section and are checked by ReviewService.
 */
class StoreReviewRequest extends PackageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxPoints = (int) config('ratings-and-reviews.reviews.max_pros_cons', 5);
        $maxPointLength = (int) config('ratings-and-reviews.reviews.max_pro_con_length', 200);

        return [
            'rating' => ['required', 'integer', 'min:1', 'max:'.max((array) config('ratings-and-reviews.reviews.allowed_scales', [5, 10]))],
            'title' => ['nullable', 'string', 'max:'.(int) config('ratings-and-reviews.reviews.max_title_length', 150)],
            'body' => ['nullable', 'string'],
            'pros' => ['nullable', 'array', 'max:'.$maxPoints],
            'pros.*' => ['nullable', 'string', 'max:'.$maxPointLength],
            'cons' => ['nullable', 'array', 'max:'.$maxPoints],
            'cons.*' => ['nullable', 'string', 'max:'.$maxPointLength],
            'topic_ratings' => ['nullable', 'array'],
            'topic_ratings.*' => ['nullable', 'integer', 'min:1', 'max:10'],
            'facets' => ['nullable', 'array'],
            'facets.*' => ['nullable', 'string', 'max:32'],
            'compared_type' => ['nullable', 'string', 'max:64', 'required_with:compared_id,comparison'],
            'compared_id' => ['nullable', 'integer', 'min:1', 'required_with:compared_type,comparison'],
            'comparison' => ['nullable', 'string', Rule::in(Comparison::values()), 'required_with:compared_type,compared_id'],
            'confirm_tone' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewData(): array
    {
        return $this->safe()->only(['rating', 'title', 'body', 'pros', 'cons', 'topic_ratings', 'facets', 'compared_type', 'compared_id', 'comparison'])
            + ['confirm_tone' => $this->boolean('confirm_tone')];
    }
}
