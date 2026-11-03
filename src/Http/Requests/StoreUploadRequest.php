<?php

namespace RatingsAndReviews\Http\Requests;

use RatingsAndReviews\Services\Uploads;

/**
 * An image upload (multipart `image`, optional `alt`). Size, type and pixel limits come from
 * `ratings-and-reviews.uploads`; the type is checked on the file's content, not its name.
 */
class StoreUploadRequest extends PackageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::imageRules() + [
            'alt' => ['nullable', 'string', 'max:'.max(1, (int) config('ratings-and-reviews.uploads.max_alt_length', 255))],
        ];
    }

    /**
     * The rules of one image field, shared with the review form's no-JavaScript photo upload.
     *
     * @return array<string, list<string>>
     */
    public static function imageRules(string $field = 'image', bool $required = true): array
    {
        $config = (array) config('ratings-and-reviews.uploads', []);
        $dimensions = 'dimensions:'.implode(',', [
            'min_width='.max(1, (int) ($config['min_width'] ?? 1)),
            'min_height='.max(1, (int) ($config['min_height'] ?? 1)),
            'max_width='.max(1, (int) ($config['max_width'] ?? 6000)),
            'max_height='.max(1, (int) ($config['max_height'] ?? 6000)),
        ]);

        return [
            $field => [
                $required ? 'required' : 'nullable',
                'file',
                'mimetypes:'.implode(',', Uploads::allowedMimeTypes()),
                'max:'.max(1, (int) ($config['max_kilobytes'] ?? 5120)),
                $dimensions,
            ],
        ];
    }
}
