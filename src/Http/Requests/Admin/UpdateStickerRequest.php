<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Models\Sticker;
use RatingsAndReviews\Support\Ability;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renaming, (de)activating or reordering a sticker. The image itself is not replaced: posts
 * that already show it keep their `src`; add a new sticker instead.
 */
class UpdateStickerRequest extends PackageRequest
{
    private ?Sticker $sticker = null;

    protected function ability(): ?string
    {
        return Ability::MANAGE_SETTINGS;
    }

    /** The sticker named by `{sticker}` (404 otherwise); rr.manage-settings is checked on it. */
    protected function abilitySubject(): mixed
    {
        return $this->sticker();
    }

    public function sticker(): Sticker
    {
        $id = (string) $this->route('sticker');

        return $this->sticker ??= (ctype_digit($id) ? Sticker::query()->find((int) $id) : null) ?? throw new NotFoundHttpException;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'array'],
            'name.nl' => ['required_with:name', 'string', 'max:80'],
            'name.en' => ['required_with:name', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
