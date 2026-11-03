<?php

namespace RatingsAndReviews\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use RatingsAndReviews\Http\Requests\PackageRequest;
use RatingsAndReviews\Support\Ability;

/**
 * A new sticker: an uploaded `image` (stored on `stickers.disk`) or the `path` of a file the
 * host already serves under `stickers.url_prefix`, with a name in both languages.
 */
class StoreStickerRequest extends PackageRequest
{
    public const PATH_PATTERN = '#^(?!.*\.\.)[A-Za-z0-9_\-][A-Za-z0-9_\-./]*$#';

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
            'image' => ['required_without:path', 'prohibits:path', ...self::imageRules()],
            'path' => ['required_without:image', 'nullable', 'string', 'max:255', 'regex:'.self::PATH_PATTERN, Rule::unique('rr_stickers', 'path')],
            'name' => ['required', 'array'],
            'name.nl' => ['required', 'string', 'max:80'],
            'name.en' => ['required', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function imageRules(): array
    {
        $config = (array) config('ratings-and-reviews.stickers', []);

        return [
            'nullable',
            'file',
            'mimetypes:'.implode(',', (array) ($config['mime_types'] ?? ['image/png', 'image/webp', 'image/gif', 'image/jpeg'])),
            'max:'.max(1, (int) ($config['max_kilobytes'] ?? 512)),
            'dimensions:max_width='.max(1, (int) ($config['max_width'] ?? 1024)).',max_height='.max(1, (int) ($config['max_height'] ?? 1024)),
        ];
    }
}
