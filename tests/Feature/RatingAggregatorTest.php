<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewTopicRating;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

class RatingAggregatorTest extends TestCase
{
    private RatingAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregator = $this->app->make(RatingAggregator::class);
    }

    private function useScale(int $scale): void
    {
        config(['ratings-and-reviews.section_defaults.review_scale' => $scale]);
    }

    public function test_the_extremes_of_every_scale_map_onto_the_extremes_of_the_display_scale(): void
    {
        $this->useScale(10);
        $product = $this->product();
        Review::factory()->forSubject($product)->rated(5, 5)->create();
        Review::factory()->forSubject($product)->rated(10, 10)->create();
        $this->aggregator->refresh($product);

        $this->assertSame(10.0, $this->aggregator->summary($product)['average']);

        $low = $this->product();
        Review::factory()->forSubject($low)->rated(1, 5)->create();
        Review::factory()->forSubject($low)->rated(1, 10)->create();
        $this->aggregator->refresh($low);

        $this->assertSame(1.0, $this->aggregator->summary($low)['average']);
    }

    public function test_mixed_scales_are_averaged_after_normalisation(): void
    {
        $this->useScale(5);
        $product = $this->product();
        // 4/5 -> 0.75 and 6/10 -> 5/9; mean 0.65278 -> 1 + 0.65278 * 4 = 3.61 on a 1-5 scale.
        Review::factory()->forSubject($product)->rated(4, 5)->create();
        Review::factory()->forSubject($product)->rated(6, 10)->create();
        $this->aggregator->refresh($product);

        $summary = $this->aggregator->summary($product);
        $this->assertSame(2, $summary['count']);
        $this->assertSame(3.61, $summary['average']);

        // The same reviews shown on a 1-10 scale: 1 + 0.65278 * 9 = 6.88.
        $this->useScale(10);
        $this->app->forgetInstance(SectionSettingsRepository::class);
        $this->app['cache']->store()->flush();
        $this->assertSame(6.88, $this->app->make(RatingAggregator::class)->summary($product)['average']);
    }

    public function test_the_histogram_buckets_ratings_of_other_scales_on_the_display_scale(): void
    {
        $this->useScale(5);
        $product = $this->product();
        Review::factory()->forSubject($product)->rated(5, 5)->create();
        Review::factory()->forSubject($product)->rated(10, 10)->create(); // -> 5
        Review::factory()->forSubject($product)->rated(1, 10)->create();  // -> 1
        Review::factory()->forSubject($product)->rated(6, 10)->create();  // 1 + 5/9*4 = 3.22 -> 3
        $this->aggregator->refresh($product);

        $this->assertSame([5 => 2, 4 => 0, 3 => 1, 2 => 0, 1 => 1], $this->aggregator->summary($product)['histogram']);
    }

    public function test_only_approved_counted_and_undeleted_reviews_count(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->rated(5)->create();
        Review::factory()->forSubject($product)->rated(1)->status(ContentStatus::Pending)->create();
        Review::factory()->forSubject($product)->rated(1)->status(ContentStatus::Rejected)->create();
        Review::factory()->forSubject($product)->rated(1)->status(ContentStatus::Spam)->create();
        Review::factory()->forSubject($product)->rated(1)->create(['counted' => false]);
        Review::factory()->forSubject($product)->rated(1)->create()->delete();

        $aggregate = $this->aggregator->refresh($product);

        $this->assertSame(1, $aggregate->review_count);
        $this->assertSame(5.0, $this->aggregator->summary($product)['average']);
    }

    public function test_the_bayesian_average_shrinks_towards_the_scope_mean_by_the_median_count(): void
    {
        $this->useScale(5);
        // Three other subjects in the scope with 1, 3 and 5 reviews; all rated 3/5 (0.5).
        foreach ([1, 3, 5] as $count) {
            $other = $this->product();
            Review::factory()->count($count)->forSubject($other)->rated(3)->create();
            $this->aggregator->refresh($other);
        }

        $product = $this->product();
        Review::factory()->count(2)->forSubject($product)->rated(5)->create();
        $this->aggregator->refresh($product);

        // Priors over the scope (including this subject): counts 1,2,3,5 -> median 2.5;
        // mean = (9 * 0.5 + 2 * 1.0) / 11 = 0.590909.
        $priors = $this->aggregator->priors('product');
        $this->assertSame(2.5, $priors['weight']);
        $this->assertEqualsWithDelta(6.5 / 11, $priors['mean'], 1e-9);

        $expected = round(1 + ((2 / 4.5) * 1.0 + (2.5 / 4.5) * (6.5 / 11)) * 4, 2);
        $summary = $this->aggregator->summary($product);
        $this->assertSame(5.0, $summary['average']);
        $this->assertSame($expected, $summary['bayesian_average']);
        $this->assertLessThan($summary['average'], $summary['bayesian_average']);
    }

    public function test_priors_are_cached_per_scope(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->rated(5)->create();
        $this->aggregator->refresh($product);
        $first = $this->aggregator->priors('product');

        $other = $this->product();
        Review::factory()->count(9)->forSubject($other)->rated(1)->create();
        $this->aggregator->refresh($other);

        $this->assertSame($first, $this->aggregator->priors('product'));
        $this->aggregator->forgetPriors('product');
        $this->assertNotSame($first, $this->aggregator->priors('product'));
    }

    public function test_topic_averages_use_each_topics_own_scale(): void
    {
        $taste = RatingTopic::factory()->create(['scope' => 'product', 'scale_max' => 5, 'sort_order' => 1, 'name' => ['nl' => 'Smaak', 'en' => 'Taste']]);
        $price = RatingTopic::factory()->create(['scope' => 'product', 'scale_max' => 10, 'sort_order' => 2, 'name' => ['nl' => 'Prijs', 'en' => 'Price']]);
        $product = $this->product();

        foreach ([[5, 10], [3, 4]] as [$tasteValue, $priceValue]) {
            $review = Review::factory()->forSubject($product)->create();
            ReviewTopicRating::query()->create(['review_id' => $review->id, 'topic_id' => $taste->id, 'value' => $tasteValue, 'scale_max' => 5]);
            ReviewTopicRating::query()->create(['review_id' => $review->id, 'topic_id' => $price->id, 'value' => $priceValue, 'scale_max' => 10]);
        }

        $hidden = Review::factory()->forSubject($product)->status(ContentStatus::Rejected)->create();
        ReviewTopicRating::query()->create(['review_id' => $hidden->id, 'topic_id' => $taste->id, 'value' => 1, 'scale_max' => 5]);

        $this->aggregator->refresh($product);
        $topics = $this->aggregator->summary($product)['topics'];

        $this->assertSame(['Taste', 'Price'], array_column($topics, 'name'));
        $this->assertSame(4.0, $topics[0]['average']);
        $this->assertSame(2, $topics[0]['count']);
        $this->assertSame(7.0, $topics[1]['average']);
    }

    public function test_the_summary_endpoint_returns_the_summary_and_hides_hidden_subjects(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->rated(4)->create();
        $this->aggregator->refresh($product);

        $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.average', 4)
            ->assertJsonPath('data.scale_max', 5);

        $this->getJson('/rr/subjects/product/'.$this->product(['is_hidden' => true])->id.'/rating-summary')->assertNotFound();
    }

    public function test_a_subject_without_reviews_has_no_scores(): void
    {
        $reviewed = $this->product();
        Review::factory()->forSubject($reviewed)->rated(5)->create();
        $this->aggregator->refresh($reviewed);

        $summary = $this->aggregator->summary($this->product());

        $this->assertSame(0, $summary['count']);
        $this->assertNull($summary['average']);
        $this->assertNull($summary['bayesian_average']);
        $this->assertSame([5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], $summary['histogram']);
    }

    public function test_a_summary_without_a_stored_aggregate_computes_one(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->rated(2)->create();
        RatingAggregate::query()->delete();

        $this->assertSame(2.0, $this->aggregator->summary($product)['average']);
        $this->assertSame(1, RatingAggregate::query()->count());
    }
}
