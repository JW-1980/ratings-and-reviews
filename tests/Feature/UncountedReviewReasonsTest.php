<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\ReviewService;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\TestCase;

/**
 * `rr_reviews.uncounted_reason`: stored from the RiskSignals verdict or the host
 * (ReviewService::setCounted()), shown to the author and moderators, and to everyone for a
 * reason marked public in config `reviews.uncounted_reasons`.
 */
class UncountedReviewReasonsTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/reviews";
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + ['rating' => 4, 'title' => 'Romig', 'body' => 'Lekker romig en niet te zoet, prima in koffie.'];
    }

    private function risk(RiskVerdict $verdict): void
    {
        $this->app->instance(RiskSignals::class, new class($verdict) implements RiskSignals
        {
            public function __construct(public RiskVerdict $verdict) {}

            public function forVote(VoteContext $context): RiskVerdict
            {
                return RiskVerdict::counts();
            }

            public function forPollVote(PollVoteContext $context): RiskVerdict
            {
                return RiskVerdict::counts();
            }

            public function forContribution(ContributionContext $context): RiskVerdict
            {
                return $this->verdict;
            }
        });
    }

    private function aggregateCount(Product $product): int
    {
        return (int) RatingAggregate::query()->where('reviewable_type', 'product')->where('reviewable_id', $product->id)->value('review_count');
    }

    public function test_a_risk_verdict_reason_is_stored_and_shown_only_to_the_author_and_moderators(): void
    {
        $this->risk(RiskVerdict::uncounted('device_cluster'));
        $product = $this->product();
        $author = $this->user();

        $this->actingAs($author)->postJson($this->url($product->id), $this->valid())
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.uncounted_reason', 'device_cluster')
            ->assertJsonPath('data.uncounted_label', 'No reason given')
            ->assertJsonPath('data.uncounted_public', false);

        $review = Review::query()->sole();
        $this->assertFalse($review->counted);
        $this->assertSame('device_cluster', $review->uncounted_reason);
        $this->assertSame(0, $this->aggregateCount($product), 'An uncounted review stays out of the averages.');

        $this->actingAs($this->user())->getJson($this->url($product->id))
            ->assertJsonPath('data.0.id', $review->id)
            ->assertJsonPath('data.0.uncounted_reason', null)
            ->assertJsonPath('data.0.uncounted_label', null);
        $this->getJson($this->url($product->id))->assertJsonPath('data.0.uncounted_label', null);

        $this->actingAs($this->moderator())->getJson($this->url($product->id))
            ->assertJsonPath('data.0.uncounted_reason', 'device_cluster')
            ->assertJsonPath('data.0.uncounted_label', 'No reason given');

        $this->actingAs($this->moderator())->getJson('/rr/admin/moderation?status=approved')
            ->assertJsonPath('data.0.counted', false)
            ->assertJsonPath('data.0.uncounted_reason', 'device_cluster');
    }

    public function test_a_counting_review_has_no_reason(): void
    {
        $product = $this->product();
        $author = $this->user();

        $this->actingAs($author)->postJson($this->url($product->id), $this->valid())
            ->assertCreated()
            ->assertJsonPath('data.uncounted_reason', null)
            ->assertJsonPath('data.uncounted_label', null);

        $this->assertNull(Review::query()->sole()->uncounted_reason);
        $this->assertSame(1, $this->aggregateCount($product));
    }

    public function test_the_host_flags_a_sample_review_publicly_and_the_flag_survives_an_edit(): void
    {
        $product = $this->product();
        $author = $this->user();
        $id = $this->actingAs($author)->postJson($this->url($product->id), $this->valid())->assertCreated()->json('data.id');
        $this->assertSame(1, $this->aggregateCount($product));

        $review = app(ReviewService::class)->setCounted(Review::query()->findOrFail($id), false, 'sample_campaign');
        $this->assertFalse($review->fresh()->counted);
        $this->assertSame(0, $this->aggregateCount($product), 'setCounted() refreshes the aggregates.');

        $this->getJson($this->url($product->id))
            ->assertJsonPath('data.0.uncounted_reason', 'sample_campaign')
            ->assertJsonPath('data.0.uncounted_label', 'Received from the brand')
            ->assertJsonPath('data.0.uncounted_public', true);

        app()->setLocale('nl');
        $this->getJson($this->url($product->id))->assertJsonPath('data.0.uncounted_label', 'Gekregen van het merk');
        app()->setLocale('en');

        // keep_on_edit: RiskSignals says "counts" on the edit, the host's decision stays.
        $this->actingAs($author)->patchJson("/rr/reviews/{$id}", $this->valid(['body' => 'Nog steeds lekker romig, ook na een maand in de koffie.']))
            ->assertOk()
            ->assertJsonPath('data.uncounted_reason', 'sample_campaign');
        $this->assertFalse(Review::query()->findOrFail($id)->counted);

        app(ReviewService::class)->setCounted(Review::query()->findOrFail($id), true);
        $fresh = Review::query()->findOrFail($id);
        $this->assertTrue($fresh->counted);
        $this->assertNull($fresh->uncounted_reason);
        $this->assertSame(1, $this->aggregateCount($product));
    }

    public function test_a_check_reason_is_replaced_by_the_verdict_of_the_next_edit(): void
    {
        $this->risk(RiskVerdict::uncounted('new_device'));
        $product = $this->product();
        $author = $this->user();
        $id = $this->actingAs($author)->postJson($this->url($product->id), $this->valid())->assertCreated()->json('data.id');
        $this->assertSame('new_device', Review::query()->findOrFail($id)->uncounted_reason);

        $this->risk(RiskVerdict::counts());
        $this->actingAs($author)->patchJson("/rr/reviews/{$id}", $this->valid(['body' => 'Nog steeds lekker romig, ook na een maand in de koffie.']))
            ->assertOk()
            ->assertJsonPath('data.uncounted_reason', null);

        $review = Review::query()->findOrFail($id);
        $this->assertTrue($review->counted);
        $this->assertNull($review->uncounted_reason);
        $this->assertSame(1, $this->aggregateCount($product));
    }

    public function test_uncounting_needs_a_reason(): void
    {
        $review = Review::factory()->forSubject($this->product())->create();

        $this->expectException(InvalidArgumentException::class);
        app(ReviewService::class)->setCounted($review, false, '  ');
    }

    public function test_the_blade_card_shows_a_public_reason_to_everyone_and_a_private_one_to_the_author(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['counted' => false, 'uncounted_reason' => 'sample_campaign', 'title' => 'Proefpakket']);
        $author = $this->user();
        Review::factory()->forSubject($product)->create(['user_id' => $author->id, 'counted' => false, 'uncounted_reason' => 'device_cluster', 'title' => 'Eigen']);
        Route::middleware('web')->get('/page', fn () => Blade::render('<x-rr::reviews :subject="$p" />', ['p' => $product]));

        $guest = $this->get('/page')->assertOk()->getContent();
        $this->assertStringContainsString('data-reason="sample_campaign"', $guest);
        $this->assertStringContainsString('Received from the brand', $guest);
        $this->assertStringNotContainsString('data-rr-uncounted', $guest);
        $this->assertStringNotContainsString('device_cluster', $guest);

        $own = $this->actingAs($author)->get('/page')->assertOk()->getContent();
        $this->assertStringContainsString('Your review is not counted in the score: No reason given.', $own);
        $this->assertStringContainsString('data-reason="sample_campaign"', $own);
    }
}
