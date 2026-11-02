<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Concerns\HasComments;
use RatingsAndReviews\Concerns\HasReviews;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

/**
 * The traits only add rr-prefixed relations, so they never clash with a host's own
 * reviews() or comments().
 */
class HostRelationsTest extends TestCase
{
    public function test_the_traits_define_only_prefixed_relations(): void
    {
        $methods = [...get_class_methods(new class
        {
            use HasComments;
            use HasReviews;
        })];

        sort($methods);

        $this->assertSame(['rrApprovedComments', 'rrApprovedReviews', 'rrComments', 'rrRatingAggregate', 'rrReviews'], $methods);
    }

    public function test_the_prefixed_relations_read_the_package_tables(): void
    {
        $product = $this->product();
        $other = $this->product();
        Review::factory()->forSubject($product)->create();
        Review::factory()->forSubject($product)->status(ContentStatus::Pending)->create();
        Review::factory()->forSubject($other)->create();
        Comment::factory()->forSubject($product)->create();
        Comment::factory()->forSubject($product)->create(['status' => ContentStatus::Rejected]);
        Comment::factory()->forSubject($product)->create(['published_at' => null, 'publish_at' => now()->addMinute()]);
        $this->app->make(RatingAggregator::class)->refresh($product);

        $this->assertSame(2, $product->rrReviews()->count());
        $this->assertSame(1, $product->rrApprovedReviews()->count());
        $this->assertSame(3, $product->rrComments()->count());
        $this->assertSame(1, $product->rrApprovedComments()->count());
        $this->assertInstanceOf(RatingAggregate::class, $product->rrRatingAggregate);
        $this->assertSame(1, $product->rrRatingAggregate->review_count);
    }
}
