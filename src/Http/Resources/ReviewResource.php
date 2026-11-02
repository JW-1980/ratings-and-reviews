<?php

namespace RatingsAndReviews\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Services\ReviewFacets;
use RatingsAndReviews\Support\AuthorProfile;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\OfficialReply;

/**
 * @property Review $resource
 */
class ReviewResource extends JsonResource
{
    private ?AuthorProfile $author = null;

    /** @var array<string, int> */
    private array $viewerVotes = [];

    private ?int $viewerId = null;

    /** Whether the host resolves official replies (the key is only present then). */
    private bool $officialReplies = false;

    private ?OfficialReply $officialReply = null;

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

    /** The official reply (OfficialReplyResolver enabled); null: none for this review. */
    public function withOfficialReply(?OfficialReply $reply): static
    {
        $this->officialReplies = true;
        $this->officialReply = $reply;

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
            'price' => $review->price_cents === null ? null : ['cents' => $review->price_cents, 'currency' => $review->price_currency, 'at' => $review->created_at?->toIso8601String()],
            'facets' => $this->facets(),
            'compared' => $this->compared(),
            'quality_score' => $review->quality_score,
            'photos' => $this->photos($own),
            'helpful_count' => $review->helpful_count,
            // Replies to the review (reviews.replies): null when switched off.
            'replies_count' => Review::repliesEnabled() ? (int) ($review->getAttribute('replies_count') ?? 0) : null,
            'viewer_votes' => (object) $this->viewerVotes,
            'is_own' => $own,
            'can' => ['edit' => $own, 'delete' => $own, 'reply' => Review::repliesEnabled() && $review->acceptsComments()],
            'created_at' => $review->created_at?->toIso8601String(),
            'edited_at' => $review->edited_at?->toIso8601String(),
        ] + ($this->officialReplies ? ['official_reply' => $this->officialReply?->toArray()] : []);
    }

    /**
     * The review's photos (relation `photos` loaded). Photos of a review that is not public
     * are only shown to its author. `alt` is the uploader's text or, without one, a
     * description from the review's title.
     *
     * @return list<array{id: int, url: string, alt: string, width: int|null, height: int|null}>
     */
    private function photos(bool $own): array
    {
        $review = $this->resource;

        if (! $review->relationLoaded('photos') || (! $own && $review->status !== ContentStatus::Approved)) {
            return [];
        }

        $title = trim((string) $review->title);

        return $review->photos->values()->map(fn (Upload $photo, int $index): array => [
            'id' => $photo->id,
            'url' => $photo->url(),
            'alt' => $photo->alt_text ?? (string) ($title !== ''
                ? __('ratings-and-reviews::messages.photo_alt', ['number' => $index + 1, 'title' => $title])
                : __('ratings-and-reviews::messages.photo_alt_untitled', ['number' => $index + 1])),
            'width' => $photo->width,
            'height' => $photo->height,
        ])->all();
    }

    /**
     * @return list<array{group: string, value: string, label: string}>
     */
    private function facets(): array
    {
        $facets = app(ReviewFacets::class);
        $list = [];

        foreach ((array) ($this->resource->facets ?? []) as $group => $value) {
            if (is_string($group) && is_string($value)) {
                $list[] = ['group' => $group, 'value' => $value, 'label' => $facets->label($group, $value)];
            }
        }

        return $list;
    }

    /**
     * "Compared with…", when the alternative still exists and is public.
     *
     * @return array{type: string, id: int, title: string, url: string|null, comparison: string, comparison_label: string}|null
     */
    private function compared(): ?array
    {
        $review = $this->resource;

        if ($review->compared_type === null || $review->compared_id === null || $review->comparison === null) {
            return null;
        }

        $compared = $review->relationLoaded('compared') ? $review->compared : null;

        if (! $compared instanceof Reviewable || ! $compared->reviewsVisibleTo(null)) {
            return null;
        }

        return [
            'type' => $review->compared_type,
            'id' => $review->compared_id,
            'title' => $compared->reviewSubjectTitle(),
            'url' => $compared->reviewSubjectUrl(),
            'comparison' => $review->comparison->value,
            'comparison_label' => $review->comparison->label(),
        ];
    }
}
