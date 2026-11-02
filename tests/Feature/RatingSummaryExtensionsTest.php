<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Carbon;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Summary\UncertaintyBand;
use RatingsAndReviews\Tests\TestCase;

class RatingSummaryExtensionsTest extends TestCase
{
    private function summary(int $productId): array
    {
        return $this->getJson("/rr/subjects/product/{$productId}/rating-summary")->assertOk()->json('data');
    }

    public function test_the_uncertainty_band_is_the_t_interval_of_the_mean_on_the_display_scale(): void
    {
        config(['ratings-and-reviews.reviews.few_reviews_below' => 3]);
        $product = $this->product();

        // Ratings 3, 4, 5 of 5 -> normalised 0.5, 0.75, 1.0: mean 0.75, s = 0.25, SE = 0.25/sqrt(3).
        foreach ([3, 4, 5] as $rating) {
            Review::factory()->forSubject($product)->rated($rating)->create();
        }
        $this->app->make(RatingAggregator::class)->refresh($product);

        $band = $this->summary($product->id)['uncertainty'];
        $se = 0.25 / sqrt(3);
        $margin = 4.303 * $se;

        $this->assertSame(0.95, $band['confidence']);
        $this->assertSame(round($se * 4, 2), $band['standard_error']);
        $this->assertSame(round($margin * 4, 2), $band['margin']);
        $this->assertSame(round(1 + (0.75 - $margin) * 4, 2), $band['lower']);
        $this->assertEquals(5, $band['upper'], 'Clamped to the top of the scale.');
        $this->assertFalse($band['few_reviews']);
    }

    public function test_a_single_review_has_no_margin_and_is_flagged_as_few(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->rated(4)->create();
        $this->app->make(RatingAggregator::class)->refresh($product);

        $band = $this->summary($product->id)['uncertainty'];

        $this->assertNull($band['margin']);
        $this->assertNull($band['lower']);
        $this->assertTrue($band['few_reviews']);
        $this->assertSame(5, $band['few_reviews_below']);

        $empty = $this->product();
        $this->assertTrue($this->summary($empty->id)['uncertainty']['few_reviews']);
        $this->assertNull($this->summary($empty->id)['uncertainty']['margin']);
    }

    public function test_identical_ratings_give_a_zero_margin_and_many_reviews_narrow_the_band(): void
    {
        $same = $this->product();
        Review::factory()->count(4)->forSubject($same)->rated(4)->create();
        $this->app->make(RatingAggregator::class)->refresh($same);
        $this->assertEquals(0, $this->summary($same->id)['uncertainty']['margin']);

        $few = $this->product();
        $many = $this->product();
        foreach ([2, 5] as $rating) {
            Review::factory()->count(2)->forSubject($few)->rated($rating)->create();
            Review::factory()->count(40)->forSubject($many)->rated($rating)->create();
        }
        $this->app->make(RatingAggregator::class)->refresh($few);
        $this->app->make(RatingAggregator::class)->refresh($many);

        $this->assertLessThan($this->summary($few->id)['uncertainty']['margin'], $this->summary($many->id)['uncertainty']['margin']);
        $this->assertSame(1.96, UncertaintyBand::tCritical(500));
        $this->assertSame(12.706, UncertaintyBand::tCritical(1));
    }

    public function test_the_trend_gives_the_normalised_average_per_month_with_empty_months(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        config(['ratings-and-reviews.reviews.trend_months' => 4]);
        $product = $this->product();

        Review::factory()->forSubject($product)->rated(5)->create(['created_at' => '2026-08-03 10:00:00']);
        Review::factory()->forSubject($product)->rated(1)->create(['created_at' => '2026-08-20 10:00:00']);
        Review::factory()->forSubject($product)->rated(10, 10)->create(['created_at' => '2026-10-01 00:00:00']);
        Review::factory()->forSubject($product)->rated(1)->create(['created_at' => '2026-10-02 00:00:00', 'counted' => false]);
        Review::factory()->forSubject($product)->rated(1)->create(['created_at' => '2026-05-01 00:00:00']);
        $this->app->make(RatingAggregator::class)->refresh($product);

        $trend = $this->summary($product->id)['trend'];

        $this->assertSame(4, $trend['months']);
        $this->assertEquals([
            ['month' => '2026-08', 'count' => 2, 'average' => 3.0],
            ['month' => '2026-09', 'count' => 0, 'average' => null],
            ['month' => '2026-10', 'count' => 1, 'average' => 5.0],
            ['month' => '2026-11', 'count' => 0, 'average' => null],
        ], $trend['points']);
        $this->assertNull($trend['points'][1]['average'], 'An empty month has no average, not 0.');
        $this->assertNull($trend['points'][3]['average']);

        $this->assertSame([], $this->summary($this->product()->id)['trend']['points']);
        Carbon::setTestNow();
    }

    public function test_an_aggregate_from_before_the_feature_columns_reads_as_no_data(): void
    {
        $product = $this->product();
        Review::factory()->count(2)->forSubject($product)->rated(4)->create();
        $this->app->make(RatingAggregator::class)->refresh($product);
        RatingAggregate::query()->update(['facet_counts' => null, 'monthly' => null, 'comparisons' => null]);

        $summary = $this->summary($product->id);

        $this->assertSame([], $summary['trend']['points']);
        $this->assertSame(['alternatives' => [], 'often_switch_to' => null], $summary['comparisons']);
        $this->assertSame(0, $summary['facets'][0]['values'][0]['count']);
    }
}
