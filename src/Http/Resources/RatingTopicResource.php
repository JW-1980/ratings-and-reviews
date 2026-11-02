<?php

namespace RatingsAndReviews\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RatingsAndReviews\Models\RatingTopic;

/**
 * @property RatingTopic $resource
 */
class RatingTopicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $topic = $this->resource;

        return [
            'id' => $topic->id,
            'scope' => $topic->scope,
            'category_key' => $topic->category_key,
            'parent_id' => $topic->parent_id,
            'name' => $topic->name,
            'label' => $topic->localizedName(),
            'scale_max' => $topic->scale_max,
            'sort_order' => $topic->sort_order,
            'is_active' => $topic->is_active,
            'ratings_count' => $this->when($topic->getAttribute('ratings_count') !== null, fn () => (int) $topic->getAttribute('ratings_count')),
        ];
    }
}
