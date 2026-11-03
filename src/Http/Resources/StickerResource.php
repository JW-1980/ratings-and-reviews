<?php

namespace RatingsAndReviews\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RatingsAndReviews\Models\Sticker;

/**
 * A sticker for the admin: both names, state and whether the package manages the file.
 *
 * @property Sticker $resource
 */
class StickerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sticker = $this->resource;

        return [
            'id' => $sticker->id,
            'name' => (object) $sticker->name,
            'label' => $sticker->localizedName(),
            'path' => $sticker->path,
            'url' => $sticker->url(),
            'managed_file' => $sticker->disk !== null,
            'is_active' => $sticker->is_active,
            'sort_order' => $sticker->sort_order,
            'created_at' => $sticker->created_at?->toIso8601String(),
            'updated_at' => $sticker->updated_at?->toIso8601String(),
        ];
    }
}
