<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Support\Ability;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateTopicRequest extends PackageRequest
{
    private ?RatingTopic $topic = null;

    protected function ability(): ?string
    {
        return Ability::MANAGE_TOPICS;
    }

    /** The topic named by `{topic}` (404 otherwise); rr.manage-topics is checked on it. */
    protected function abilitySubject(): mixed
    {
        return $this->topic();
    }

    public function topic(): RatingTopic
    {
        $id = (string) $this->route('topic');

        return $this->topic ??= (ctype_digit($id) ? RatingTopic::query()->find((int) $id) : null) ?? throw new NotFoundHttpException;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'array'],
            'name.nl' => ['required_with:name', 'string', 'max:80'],
            'name.en' => ['required_with:name', 'string', 'max:80'],
            'scale_max' => ['sometimes', 'integer', Rule::in((array) config('ratings-and-reviews.reviews.allowed_scales', [5, 10]))],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
