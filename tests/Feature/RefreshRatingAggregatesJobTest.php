<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RatingsAndReviews\Jobs\RefreshRatingAggregates;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Tests\TestCase;

class RefreshRatingAggregatesJobTest extends TestCase
{
    public function test_one_job_is_queued_per_subject_until_it_runs(): void
    {
        Queue::fake();
        $product = $this->product();
        $other = $this->product();

        foreach ([$this->user(), $this->user()] as $user) {
            $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 3, 'body' => 'Prima drank om mee te koken.'.$user->id])->assertCreated();
        }
        $this->actingAs($this->user())->postJson("/rr/subjects/product/{$other->id}/reviews", ['rating' => 3, 'body' => 'Ook prima.'])->assertCreated();

        Queue::assertPushed(RefreshRatingAggregates::class, 2);
        Queue::assertPushed(RefreshRatingAggregates::class, fn (RefreshRatingAggregates $job): bool => $job->uniqueId() === 'product:'.$product->id && $job->afterCommit === true);
    }

    public function test_the_job_waits_for_the_transaction_and_reads_committed_data(): void
    {
        $product = $this->product();

        DB::transaction(function () use ($product): void {
            Review::factory()->forSubject($product)->rated(5)->create();
            RefreshRatingAggregates::dispatch('product', (string) $product->id);

            $this->assertSame(0, RatingAggregate::query()->count(), 'Not run inside the transaction.');
        });

        $this->assertSame(1, RatingAggregate::query()->sole()->review_count);
    }

    public function test_the_job_removes_the_aggregate_of_a_subject_that_no_longer_exists(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create();
        (new RefreshRatingAggregates('product', (string) $product->id))->handle($this->app->make(RatingAggregator::class));
        $this->assertSame(1, RatingAggregate::query()->count());

        $product->delete();
        (new RefreshRatingAggregates('product', (string) $product->id))->handle($this->app->make(RatingAggregator::class));

        $this->assertSame(0, RatingAggregate::query()->count());
    }
}
