<?php

namespace RatingsAndReviews\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RatingsAndReviews\Models\RatingTopic;

/**
 * @extends Factory<RatingTopic>
 */
class RatingTopicFactory extends Factory
{
    protected $model = RatingTopic::class;

    public function definition(): array
    {
        $word = $this->faker->unique()->word();

        return [
            'scope' => 'product',
            'category_key' => null,
            'parent_id' => null,
            'name' => ['nl' => ucfirst($word), 'en' => ucfirst($word)],
            'scale_max' => 5,
            'sort_order' => $this->faker->numberBetween(1, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
