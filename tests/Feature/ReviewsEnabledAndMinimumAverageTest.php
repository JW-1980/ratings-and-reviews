<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewTopicRating;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Tests\TestCase;

/**
 * Section settings `reviews_enabled` (standalone topic ratings without reviews) and
 * `min_reviews_for_average`.
 */
class ReviewsEnabledAndMinimumAverageTest extends TestCase
{
    public function test_a_subject_without_reviews_still_takes_topic_ratings(): void
    {
        $this->configureSection('product', ['reviews_enabled' => false, 'standalone_topic_ratings' => true]);
        $product = $this->product();
        $topic = RatingTopic::factory()->create(['scope' => 'product']);
        Review::factory()->forSubject($product)->create();
        $member = $this->user();

        $this->actingAs($member)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'body' => 'Toch een review'])
            ->assertUnprocessable()->assertJsonValidationErrors(['review' => __('ratings-and-reviews::messages.reviews_disabled')]);
        $this->assertSame(1, Review::query()->count());

        $this->actingAs($member)->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.reviews_enabled', false)
            ->assertJsonPath('meta.accepts_reviews', false);
        $this->getJson("/rr/subjects/product/{$product->id}/search?q=review")->assertOk()->assertJsonCount(0, 'data.reviews');

        $this->actingAs($member)->putJson("/rr/subjects/product/{$product->id}/topic-ratings", ['ratings' => [$topic->id => 4]])->assertOk();
        $this->getJson("/rr/subjects/product/{$product->id}/topic-ratings")->assertJsonPath('data.topics.0.count', 1);
    }

    public function test_an_existing_review_cannot_be_edited_once_reviews_are_off(): void
    {
        $product = $this->product();
        $author = $this->user();
        $review = Review::factory()->forSubject($product)->create(['user_id' => $author->id]);
        $this->configureSection('product', ['reviews_enabled' => false]);

        $this->actingAs($author)->patchJson("/rr/reviews/{$review->id}", ['rating' => 2])->assertUnprocessable()->assertJsonValidationErrors('review');
    }

    public function test_the_section_setting_is_managed_through_the_admin_api(): void
    {
        $this->actingAs($this->moderator())->patchJson('/rr/admin/sections/product', ['reviews_enabled' => false, 'min_reviews_for_average' => 3])
            ->assertOk()->assertJsonPath('data.settings.reviews_enabled', false)->assertJsonPath('data.settings.min_reviews_for_average', 3);
        $this->actingAs($this->moderator())->patchJson('/rr/admin/sections/product', ['min_reviews_for_average' => -1])->assertUnprocessable();
    }

    public function test_averages_appear_from_the_minimum_number_of_ratings(): void
    {
        $this->configureSection('product', ['min_reviews_for_average' => 3]);
        $product = $this->product();
        $topic = RatingTopic::factory()->create(['scope' => 'product']);

        foreach ([5, 4] as $rating) {
            $review = Review::factory()->forSubject($product)->rated($rating)->create();
            ReviewTopicRating::query()->create(['review_id' => $review->id, 'topic_id' => $topic->id, 'value' => $rating, 'scale_max' => 5]);
        }

        $this->refreshAggregates($product);
        $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.average', null)
            ->assertJsonPath('data.bayesian_average', null)
            ->assertJsonPath('data.topics.0.count', 2)
            ->assertJsonPath('data.topics.0.average', null)
            ->assertJsonPath('data.min_reviews_for_average', 3);

        $review = Review::factory()->forSubject($product)->rated(3)->create();
        ReviewTopicRating::query()->create(['review_id' => $review->id, 'topic_id' => $topic->id, 'value' => 3, 'scale_max' => 5]);
        $this->refreshAggregates($product);

        $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")
            ->assertJsonPath('data.average', 4)
            ->assertJsonPath('data.topics.0.average', 4);
    }

    private function refreshAggregates($product): void
    {
        $this->app->make(RatingAggregator::class)->refresh($product);
    }
}
