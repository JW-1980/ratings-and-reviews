<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Models\ReviewQuestion;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\QuestionType;

class StoreReviewQuestionRequest extends PackageRequest
{
    protected function ability(): ?string
    {
        return Ability::MANAGE_TOPICS;
    }

    /**
     * An unsaved question with the submitted scope and category, so a host can allow managing
     * questions per scope (as topics). Without a valid scope the ability is asked in general.
     */
    protected function abilitySubject(): mixed
    {
        $scope = $this->input('scope');
        $category = $this->input('category_key');

        return is_string($scope) && $scope !== ''
            ? new ReviewQuestion(['scope' => $scope, 'category_key' => is_string($category) && $category !== '' ? $category : null])
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_\-]+$/'],
            'category_key' => ['nullable', 'string', 'max:64'],
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'type' => ['required', 'string', Rule::in(QuestionType::values())],
            ...self::textRules(true),
        ];
    }

    /**
     * The rules shared with an update: labels, options and flags.
     *
     * @return array<string, mixed>
     */
    public static function textRules(bool $creating): array
    {
        $max = max(1, (int) config('ratings-and-reviews.review_questions.max_label_length', 120));
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'label' => [$presence, 'array'],
            'label.nl' => ['required_with:label', 'string', 'max:'.$max],
            'label.en' => ['required_with:label', 'string', 'max:'.$max],
            'low_label' => ['sometimes', 'nullable', 'array'],
            'low_label.nl' => ['required_with:low_label', 'string', 'max:'.$max],
            'low_label.en' => ['required_with:low_label', 'string', 'max:'.$max],
            'high_label' => ['sometimes', 'nullable', 'array'],
            'high_label.nl' => ['required_with:high_label', 'string', 'max:'.$max],
            'high_label.en' => ['required_with:high_label', 'string', 'max:'.$max],
            'options' => ['sometimes', 'nullable', 'array', 'max:'.max(2, (int) config('ratings-and-reviews.review_questions.max_options', 10))],
            'options.*.value' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_\-]*$/'],
            'options.*.label' => ['required', 'array'],
            'options.*.label.nl' => ['required', 'string', 'max:'.$max],
            'options.*.label.en' => ['required', 'string', 'max:'.$max],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
