<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Events\ReviewFollowUpDue;
use RatingsAndReviews\Events\ReviewFollowUpPosted;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewFollowUp;
use RatingsAndReviews\Services\Erasure;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

/**
 * Follow-ups on a review ("after a month"), their invitation, display and summary.
 */
class ReviewFollowUpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSection('product', ['review_follow_up_after_days' => 30, 'review_follow_up_window_days' => 60]);
    }

    public function test_the_author_adds_one_follow_up_inside_the_window(): void
    {
        Event::fake([ReviewFollowUpPosted::class]);
        $product = $this->product();
        $author = $this->user();
        $review = Review::factory()->forSubject($product)->rated(4)->create(['user_id' => $author->id]);
        $url = "/rr/reviews/{$review->id}/follow-up";

        $this->actingAs($author)->getJson("/rr/subjects/product/{$product->id}/reviews")
            ->assertJsonPath('data.0.can.follow_up', false)
            ->assertJsonPath('data.0.follow_up_window.opens_at', $review->approved_at->copy()->addDays(30)->toIso8601String())
            ->assertJsonPath('meta.features.follow_ups', true)
            ->assertJsonPath('meta.review_follow_up.after_days', 30);
        $this->actingAs($author)->postJson($url, ['rating' => 2])->assertUnprocessable()->assertJsonValidationErrors('follow_up');

        $this->travel(31)->days();

        $this->actingAs($this->user())->postJson($url, ['rating' => 2])->assertForbidden();
        $this->actingAs($author)->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonPath('data.0.can.follow_up', true);
        $this->actingAs($author)->postJson($url, ['rating' => 6])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->actingAs($author)->postJson($url, ['rating' => 2, 'body' => "Na een maand <b>minder</b> lekker.\nTe zoet."])->assertCreated()
            ->assertJsonPath('data.follow_up.rating', 2)
            ->assertJsonPath('data.follow_up.initial_rating', 4)
            ->assertJsonPath('data.follow_up.body', "Na een maand minder lekker.\nTe zoet.")
            ->assertJsonPath('data.follow_up.days_after', 31)
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('message', __('ratings-and-reviews::messages.follow_up_saved'));
        Event::assertDispatched(ReviewFollowUpPosted::class, fn (ReviewFollowUpPosted $event): bool => ! $event->sentBackToModeration);

        $this->actingAs($author)->postJson($url, ['rating' => 3])->assertUnprocessable()->assertJsonValidationErrors('follow_up');
        $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonPath('data.0.follow_up.rating', 2)->assertJsonPath('data.0.can.follow_up', false);

        // The original review is untouched; editing it later keeps the follow-up's snapshot.
        $this->assertSame(4, $review->fresh()->rating);
        $this->actingAs($author)->patchJson("/rr/reviews/{$review->id}", ['rating' => 1])->assertOk()->assertJsonPath('data.follow_up.initial_rating', 4);

        $this->actingAs($author)->deleteJson($url)->assertNoContent();
        $this->assertSame(0, ReviewFollowUp::query()->count());
    }

    public function test_the_window_closes_and_follow_ups_can_be_switched_off(): void
    {
        $author = $this->user();
        $review = Review::factory()->forSubject($this->product())->create(['user_id' => $author->id]);

        $this->travel(91)->days();
        $this->actingAs($author)->postJson("/rr/reviews/{$review->id}/follow-up", ['rating' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors(['follow_up' => __('ratings-and-reviews::messages.follow_up_too_late')]);

        $this->configureSection('product', ['review_follow_up_after_days' => 0]);
        $this->travel(-50)->days();
        $this->actingAs($author)->postJson("/rr/reviews/{$review->id}/follow-up", ['rating' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors(['follow_up' => __('ratings-and-reviews::messages.follow_up_unavailable')]);
    }

    public function test_a_held_note_sends_the_review_back_to_moderation(): void
    {
        config(['ratings-and-reviews.spam.hold_phrases' => ['bestel hier']]);
        $author = $this->user();
        $review = Review::factory()->forSubject($this->product())->create(['user_id' => $author->id]);
        $this->travel(31)->days();

        $this->actingAs($author)->postJson("/rr/reviews/{$review->id}/follow-up", ['rating' => 5, 'body' => 'Bestel hier met korting.'])->assertCreated()
            ->assertJsonPath('message', __('ratings-and-reviews::messages.follow_up_pending'));

        $this->assertSame(ContentStatus::Pending, $review->fresh()->status);
        $this->actingAs($this->moderator())->getJson('/rr/admin/moderation?kind=reviews')->assertOk()
            ->assertJsonPath('data.0.id', $review->id)->assertJsonPath('data.0.follow_up.body', 'Bestel hier met korting.');
    }

    public function test_the_invitation_fires_once_when_the_window_opens(): void
    {
        Event::fake([ReviewFollowUpDue::class]);
        $due = Review::factory()->forSubject($this->product())->create(['approved_at' => now()->subDays(31)]);
        Review::factory()->forSubject($this->product())->create(['approved_at' => now()->subDays(5)]);
        Review::factory()->forSubject($this->product())->create(['approved_at' => now()->subDays(200)]);
        Review::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create();

        $this->artisan('rr:send-review-invitations')->assertSuccessful()->expectsOutputToContain('Follow-up invitations sent: 1.');
        $this->artisan('rr:send-review-invitations')->assertSuccessful()->expectsOutputToContain('Follow-up invitations sent: 0.');

        Event::assertDispatchedTimes(ReviewFollowUpDue::class, 1);
        Event::assertDispatched(ReviewFollowUpDue::class, fn (ReviewFollowUpDue $event): bool => $event->review->is($due));
        $this->assertNotNull($due->fresh()->follow_up_invited_at);
    }

    public function test_the_summary_reports_the_average_change_from_three_follow_ups(): void
    {
        $product = $this->product();

        foreach ([[4, 2], [5, 4]] as [$before, $after]) {
            $review = Review::factory()->forSubject($product)->rated($before)->create();
            ReviewFollowUp::query()->create(['review_id' => $review->id, 'user_id' => $review->user_id, 'rating' => $after, 'scale_max' => 5, 'initial_rating' => $before, 'initial_scale_max' => 5]);
        }

        $this->app->make(RatingAggregator::class)->refresh($product);
        $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")
            ->assertJsonPath('data.follow_ups.count', 2)
            ->assertJsonPath('data.follow_ups.average_change', null)
            ->assertJsonPath('data.follow_ups.enabled', true);

        $review = Review::factory()->forSubject($product)->rated(3)->create();
        ReviewFollowUp::query()->create(['review_id' => $review->id, 'user_id' => $review->user_id, 'rating' => 3, 'scale_max' => 5, 'initial_rating' => 3, 'initial_scale_max' => 5]);
        $this->app->make(RatingAggregator::class)->refresh($product);

        $summary = $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->json('data.follow_ups');
        $this->assertSame(3, $summary['count']);
        $this->assertEquals(4.0, $summary['average_before']);
        $this->assertEquals(3.0, $summary['average_after']);
        $this->assertEquals(-1.0, $summary['average_change']);
    }

    public function test_resubmitting_a_deleted_review_or_erasing_the_author_removes_the_follow_up(): void
    {
        $author = $this->user();
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create(['user_id' => $author->id]);
        ReviewFollowUp::query()->create(['review_id' => $review->id, 'user_id' => $author->id, 'rating' => 2, 'scale_max' => 5, 'initial_rating' => 4, 'initial_scale_max' => 5]);

        $this->actingAs($author)->deleteJson("/rr/reviews/{$review->id}")->assertNoContent();
        $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 3, 'body' => 'Opnieuw geschreven'])->assertCreated()
            ->assertJsonPath('data.follow_up', null);
        $this->assertSame(0, ReviewFollowUp::query()->count());

        ReviewFollowUp::query()->create(['review_id' => $review->id, 'user_id' => $author->id, 'rating' => 2, 'scale_max' => 5, 'initial_rating' => 3, 'initial_scale_max' => 5]);
        $this->app->make(Erasure::class)->purge($author->id);
        $this->assertSame(0, ReviewFollowUp::query()->count());
    }
}
