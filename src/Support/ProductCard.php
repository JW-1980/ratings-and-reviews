<?php

namespace RatingsAndReviews\Support;

/**
 * A product card rendered in the editor for `type:id`, provided by the host's CardResolver.
 */
final readonly class ProductCard
{
    public function __construct(
        public string $type,
        public int|string $id,
        public string $title,
        public ?string $url = null,
        public ?string $imageUrl = null,
        public ?Money $price = null,
        public ?float $score = null,
        public ?int $scaleMax = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'image_url' => $this->imageUrl,
            'price' => $this->price === null ? null : ['cents' => $this->price->cents, 'currency' => $this->price->currency],
            'score' => $this->score,
            'scale_max' => $this->scaleMax,
        ];
    }
}
