<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\DiscussionSort;
use RatingsAndReviews\Support\EditorTool;
use RatingsAndReviews\Support\SectionScope;
use RatingsAndReviews\Support\VoteDimension;

class UpdateSectionRequest extends PackageRequest
{
    protected function ability(): ?string
    {
        return Ability::MANAGE_SETTINGS;
    }

    protected function abilitySubject(): mixed
    {
        return new SectionScope((string) $this->route('section'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $depthLimit = (int) config('ratings-and-reviews.max_depth_limit', 10);

        return [
            'enabled' => ['sometimes', 'boolean'],
            'max_depth' => ['sometimes', 'integer', 'min:0', 'max:'.$depthLimit],
            'editor_tools' => ['sometimes', 'array'],
            'editor_tools.*' => ['string', 'distinct', Rule::in(EditorTool::values())],
            'vote_dimensions' => ['sometimes', 'array'],
            'vote_dimensions.*' => ['string', 'distinct', Rule::in(VoteDimension::values())],
            'guest_votes' => ['sometimes', 'boolean'],
            'default_sort' => ['sometimes', 'string', Rule::in(DiscussionSort::values())],
            'collapse_low_quality' => ['sometimes', 'boolean'],
            'collapse_threshold' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'highlight_top_comment' => ['sometimes', 'boolean'],
            'top_comment_min_votes' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'min_length' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_length' => ['sometimes', 'integer', 'min:1', 'max:100000', 'gte:min_length'],
            'edit_window_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:525600'],
            'trust_levels' => ['sometimes', 'array'],
            'trust_levels.*' => ['integer', 'min:0', 'max:100000'],
            'slow_mode_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'digest_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'undo_window_seconds' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'review_scale' => ['sometimes', 'integer', Rule::in((array) config('ratings-and-reviews.reviews.allowed_scales', [5, 10]))],
            'review_extended_fields' => ['sometimes', 'boolean'],
            'review_min_length' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'review_requires_approval' => ['sometimes', 'boolean'],
            'standalone_topic_ratings' => ['sometimes', 'boolean'],
        ];
    }
}
