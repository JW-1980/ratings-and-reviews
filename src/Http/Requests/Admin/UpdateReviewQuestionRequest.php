<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Models\ReviewQuestion;
use RatingsAndReviews\Support\Ability;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Labels, options, flags and order of a question; its scope, category, key and type stay.
 */
class UpdateReviewQuestionRequest extends PackageRequest
{
    private ?ReviewQuestion $question = null;

    protected function ability(): ?string
    {
        return Ability::MANAGE_TOPICS;
    }

    /** The question named by `{question}` (404 otherwise); rr.manage-topics is checked on it. */
    protected function abilitySubject(): mixed
    {
        return $this->question();
    }

    public function question(): ReviewQuestion
    {
        $id = (string) $this->route('question');

        return $this->question ??= (ctype_digit($id) ? ReviewQuestion::query()->find((int) $id) : null) ?? throw new NotFoundHttpException;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return StoreReviewQuestionRequest::textRules(false);
    }
}
