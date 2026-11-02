<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\Ability;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ModerateRequest extends PackageRequest
{
    public const ACTIONS = ['approve', 'pending', 'reject', 'spam', 'delete'];

    private Review|Comment|null $item = null;

    protected function ability(): ?string
    {
        return Ability::MODERATE;
    }

    /** The review or comment named by `{kind}/{id}` (404 otherwise); rr.moderate is checked on it. */
    protected function abilitySubject(): mixed
    {
        return $this->item();
    }

    public function item(): Review|Comment
    {
        if ($this->item !== null) {
            return $this->item;
        }

        $id = (string) $this->route('id');
        $query = match ((string) $this->route('kind')) {
            'reviews' => Review::query(),
            'comments' => Comment::query(),
            default => throw new NotFoundHttpException,
        };
        $item = ctype_digit($id) ? $query->find((int) $id) : null;

        return $this->item = $item instanceof Review || $item instanceof Comment ? $item : throw new NotFoundHttpException;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
