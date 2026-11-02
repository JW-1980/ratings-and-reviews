<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Support\Ability;

/**
 * Renaming, (de)activating or reordering a sticker. The image itself is not replaced: posts
 * that already show it keep their `src`; add a new sticker instead.
 */
class UpdateStickerRequest extends PackageRequest
{
    protected function ability(): ?string
    {
        return Ability::MANAGE_SETTINGS;
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
