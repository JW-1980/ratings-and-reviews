<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Services\SubjectResolver;
use RatingsAndReviews\Support\Ability;

class UpdateDiscussionRequest extends PackageRequest
{
    private ?Model $subject = null;

    protected function ability(): ?string
    {
        return Ability::MODERATE;
    }

    /** The discussion's subject named by `{type}/{id}` (404 otherwise); rr.moderate is checked on it. */
    protected function abilitySubject(): mixed
    {
        return $this->subject();
    }

    /**
     * @return Model&Commentable
     */
    public function subject(): Model
    {
        /** @var Model&Commentable */
        return $this->subject ??= app(SubjectResolver::class)->resolve((string) $this->route('type'), (string) $this->route('id'), Commentable::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slow_mode_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'locked' => ['sometimes', 'boolean'],
        ];
    }
}
