<?php

namespace RatingsAndReviews\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ContentStatus;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        $text = $this->faker->sentence(12);

        return [
            'user_id' => $this->faker->unique()->numberBetween(100000, 999999),
            'status' => ContentStatus::Approved,
            'rating' => $this->faker->numberBetween(1, 5),
            'scale_max' => 5,
            'title' => $this->faker->sentence(4),
            'body_html' => '<p>'.$text.'</p>',
            'body_text' => $text,
            'counted' => true,
            'approved_at' => now(),
        ];
    }

    public function forSubject(Model $subject): static
    {
        return $this->state(['reviewable_type' => $subject->getMorphClass(), 'reviewable_id' => $subject->getKey()]);
    }

    public function status(ContentStatus $status): static
    {
        return $this->state(['status' => $status, 'approved_at' => $status === ContentStatus::Approved ? now() : null]);
    }

    public function rated(int $rating, int $scaleMax = 5): static
    {
        return $this->state(['rating' => $rating, 'scale_max' => $scaleMax]);
    }
}
