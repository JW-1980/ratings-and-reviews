<?php

namespace RatingsAndReviews\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\AuthorProfile;

/**
 * @property Review $resource
 */
class ReviewResource extends JsonResource
{
    private ?AuthorProfile $author = null;

    /** @var array<string, int> */
    private array $viewerVotes = [];

    private ?int $viewerId = null;

    /**
     * @param  array<string, int>  $viewerVotes
     */
    public function withContext(?AuthorProfile $author, array $viewerVotes, ?int $viewerId): static
    {
        $this->author = $author;
        $this->viewerVotes = $viewerVotes;
        $this->viewerId = $viewerId;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $review = $this->resource;
        $own = $this->viewerId !== null && $this->viewerId === $review->user_id;

        return [
            'id' => $review->id,
            'subject' => ['type' => $review->reviewable_type, 'id' => $review->reviewable_id],
            'author' => $this->author?->toArray(),
            'status' => $review->status->value,
            'status_label' => $review->status->label(),
            'rating' => $review->rating,
            'scale_max' => $review->scale_max,
            'title' => $review->title,
            'body_html' => $review->body_html,
            'pros' => $review->pros ?? [],
            'cons' => $review->cons ?? [],
            'topic_ratings' => $review->relationLoaded('topicRatings')
                ? $review->topicRatings->map(fn ($rating): array => ['topic_id' => $rating->topic_id, 'value' => $rating->value, 'scale_max' => $rating->scale_max])->values()->all()
                : [],
            'price' => $review->price_cents === null ? null : ['cents' => $review->price_cents, 'currency' => $review->price_currency],
            'helpful_count' => $review->helpful_count,
            'viewer_votes' => (object) $this->viewerVotes,
            'is_own' => $own,
            'can' => ['edit' => $own, 'delete' => $own],
            'created_at' => $review->created_at?->toIso8601String(),
            'edited_at' => $review->edited_at?->toIso8601String(),
        ];
    }
}
