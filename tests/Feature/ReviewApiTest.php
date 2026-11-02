<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Events\ReviewModerated;
use RatingsAndReviews\Events\ReviewPublished;
use RatingsAndReviews\Events\ReviewSubmitted;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\ReviewService;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

class ReviewApiTest extends TestCase
{
    private function url(int|string $productId, string $type = 'product'): string
    {
        return "/rr/subjects/{$type}/{$productId}/reviews";
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + ['rating' => 4, 'title' => 'Romig', 'body' => 'Lekker romig en niet te zoet, prima in koffie.', 'pros' => ['romig'], 'cons' => ['duur']];
    }

    public function test_a_member_submits_a_review_that_is_published_and_aggregated(): void
    {
        Event::fake([ReviewSubmitted::class, ReviewPublished::class]);
        $user = $this->user();
        $product = $this->product();

        $response = $this->actingAs($user)->postJson($this->url($product->id), $this->valid())->assertCreated();

        $response->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.scale_max', 5)
            ->assertJsonPath('data.pros', ['romig'])
            ->assertJsonPath('data.author.name', $user->name);

        $review = Review::query()->sole();
        $this->assertSame($user->id, $review->user_id);
        $this->assertNotNull($review->approved_at);
        $this->assertSame('Lekker romig en niet te zoet, prima in koffie.', $review->body_text);

        $aggregate = RatingAggregate::query()->where('reviewable_type', 'product')->where('reviewable_id', $product->id)->sole();
        $this->assertSame(1, $aggregate->review_count);
        $this->assertEqualsWithDelta(0.75, $aggregate->normalized_sum, 1e-9);

        Event::assertDispatched(ReviewSubmitted::class, fn (ReviewSubmitted $event): bool => $event->review->is($review) && ! $event->isResubmission);
        Event::assertDispatchedTimes(ReviewPublished::class, 1);
    }

    public function test_a_guest_cannot_submit_a_review(): void
    {
        $this->postJson($this->url($this->product()->id), $this->valid())->assertUnauthorized();
        $this->assertSame(0, Review::query()->count());
    }

    public function test_unknown_or_unsuitable_subject_types_are_not_found(): void
    {
        $user = $this->user();
        $product = $this->product();

        $this->actingAs($user)->postJson($this->url($product->id, 'nope'), $this->valid())->assertNotFound();
        $this->actingAs($user)->postJson($this->url($product->id, 'article'), $this->valid())->assertNotFound();
        $this->actingAs($user)->postJson($this->url($product->id, 'rr_review'), $this->valid())->assertNotFound();
        $this->actingAs($user)->postJson($this->url(999999), $this->valid())->assertNotFound();
        $this->getJson($this->url($product->id, 'RatingsAndReviews%5CTests%5CFixtures%5CProduct'))->assertNotFound();
    }

    public function test_hidden_subjects_are_not_found_and_closed_subjects_refuse(): void
    {
        $user = $this->user();

        $this->actingAs($user)->getJson($this->url($this->product(['is_hidden' => true])->id))->assertNotFound();
        $this->actingAs($user)->postJson($this->url($this->product(['is_open' => false])->id), $this->valid())
            ->assertUnprocessable()
            ->assertJsonPath('errors.review.0', 'Reviews cannot be written here at the moment.');
    }

    public function test_the_rating_must_fit_the_section_scale(): void
    {
        $user = $this->user();
        $product = $this->product();

        $this->actingAs($user)->postJson($this->url($product->id), $this->valid(['rating' => 6]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.rating.0', 'Choose a score from 1 to 5.');

        $this->actingAs($user)->postJson($this->url($product->id), $this->valid(['rating' => null]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.rating.0', 'The score field is required.');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_validation_messages_are_translated_to_dutch(): void
    {
        $this->app->setLocale('nl');

        $this->actingAs($this->user())->postJson($this->url($this->product()->id), ['rating' => 'veel', 'pros' => 'geen lijst'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.rating.0', 'Score moet een geheel getal zijn.')
            ->assertJsonPath('errors.pros.0', 'Pluspunten moet een lijst zijn.');
    }

    public function test_one_review_per_member_and_subject(): void
    {
        $user = $this->user();
        $product = $this->product();

        $this->actingAs($user)->postJson($this->url($product->id), $this->valid())->assertCreated();
        $this->actingAs($user)->postJson($this->url($product->id), $this->valid(['rating' => 1]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.review.0', 'You have already reviewed this. Edit your existing review instead.');

        $this->assertSame(4, Review::query()->sole()->rating);
    }

    public function test_a_rejected_review_never_returns_to_approved_on_edit_or_resubmission(): void
    {
        Event::fake([ReviewPublished::class, ReviewModerated::class]);
        $user = $this->user();
        $moderator = $this->moderator();
        $product = $this->product();

        $id = $this->actingAs($user)->postJson($this->url($product->id), $this->valid())->assertCreated()->json('data.id');

        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/reviews/{$id}", ['action' => 'reject'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAs($user)->patchJson("/rr/reviews/{$id}", $this->valid(['body' => 'Aangepaste tekst, nog steeds lekker romig.']))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        $this->assertSame(ContentStatus::Pending, Review::query()->find($id)->status);

        $this->actingAs($user)->deleteJson("/rr/reviews/{$id}")->assertNoContent();
        $this->assertSoftDeleted('rr_reviews', ['id' => $id]);

        // Rejected again, deleted, then resubmitted: still pending, and the same row is reused.
        Review::query()->withTrashed()->whereKey($id)->update(['status' => 'spam']);
        $this->actingAs($user)->postJson($this->url($product->id), $this->valid(['body' => 'Een heel nieuwe review over deze drank.']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.id', $id);

        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/reviews/{$id}", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

        // Published once (at the first submission); the later approval is not announced again.
        Event::assertDispatchedTimes(ReviewPublished::class, 1);
        Event::assertDispatched(ReviewModerated::class, fn (ReviewModerated $event): bool => $event->to === ContentStatus::Approved && $event->from === ContentStatus::Pending);
    }

    public function test_an_approved_review_stays_approved_after_a_clean_edit(): void
    {
        $user = $this->user();
        $product = $this->product();
        $id = $this->actingAs($user)->postJson($this->url($product->id), $this->valid())->json('data.id');

        $this->actingAs($user)->patchJson("/rr/reviews/{$id}", $this->valid(['rating' => 2, 'body' => 'Bij nader inzien te zoet voor mij.']))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.rating', 2);

        $this->assertNotNull(Review::query()->find($id)->edited_at);
        $this->assertEqualsWithDelta(0.25, RatingAggregate::query()->sole()->normalized_sum, 1e-9);
    }

    public function test_sections_that_require_approval_hold_reviews(): void
    {
        config(['ratings-and-reviews.section_defaults.review_requires_approval' => true]);

        $this->actingAs($this->user())->postJson($this->url($this->product()->id), $this->valid())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('message', 'Thank you, your review will appear after a moderator has checked it.');

        $this->assertSame(0, RatingAggregate::query()->sole()->review_count, 'A pending review does not count.');
    }

    public function test_spam_signals_refuse_or_hold(): void
    {
        $product = $this->product();

        $this->actingAs($this->user())->postJson($this->url($product->id), $this->valid(['website' => 'http://bot.test']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'This was not saved because our spam filter stopped it.');
        $this->assertSame(0, Review::query()->count());

        $this->actingAs($this->user())->postJson($this->url($product->id), $this->valid(['body' => 'Kijk https://a.test https://b.test https://c.test https://d.test']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_only_the_author_edits_and_only_author_or_moderator_deletes(): void
    {
        $author = $this->user();
        $other = $this->user();
        $id = $this->actingAs($author)->postJson($this->url($this->product()->id), $this->valid())->json('data.id');

        $this->actingAs($other)->patchJson("/rr/reviews/{$id}", $this->valid())->assertForbidden();
        $this->actingAs($other)->deleteJson("/rr/reviews/{$id}")->assertForbidden();
        $this->assertNotSoftDeleted('rr_reviews', ['id' => $id]);

        $this->actingAs($this->moderator())->deleteJson("/rr/reviews/{$id}")->assertNoContent();
        $this->assertSoftDeleted('rr_reviews', ['id' => $id]);
        $this->assertSame(0, RatingAggregate::query()->sole()->review_count);
    }

    public function test_topic_ratings_are_validated_against_the_subjects_topics(): void
    {
        $taste = RatingTopic::factory()->create(['scope' => 'product', 'scale_max' => 5]);
        $other = RatingTopic::factory()->create(['scope' => 'additive']);
        $user = $this->user();
        $product = $this->product();

        $errors = $this->actingAs($user)->postJson($this->url($product->id), $this->valid(['topic_ratings' => [$other->id => 3]]))
            ->assertUnprocessable()
            ->json('errors');
        $this->assertSame(['This rating topic is not available for this item.'], $errors['topic_ratings.'.$other->id]);

        $errors = $this->actingAs($user)->postJson($this->url($product->id), $this->valid(['topic_ratings' => [$taste->id => 6]]))
            ->assertUnprocessable()
            ->json('errors');
        $this->assertSame(['Choose a score from 1 to 5.'], $errors['topic_ratings.'.$taste->id]);
        $this->assertSame(0, Review::query()->count());

        $this->actingAs($user)->postJson($this->url($product->id), $this->valid(['topic_ratings' => [$taste->id => 5]]))
            ->assertCreated()
            ->assertJsonPath('data.topic_ratings.0', ['topic_id' => $taste->id, 'value' => 5, 'scale_max' => 5]);
    }

    public function test_the_list_shows_approved_reviews_sorted_and_filtered_with_the_viewers_own(): void
    {
        $product = $this->product();
        $viewer = $this->user();
        Review::factory()->forSubject($product)->rated(5)->create(['helpful_count' => 1, 'created_at' => now()->subDays(3)]);
        Review::factory()->forSubject($product)->rated(2)->create(['helpful_count' => 9, 'created_at' => now()->subDays(2)]);
        Review::factory()->forSubject($product)->rated(3)->create(['helpful_count' => 0, 'created_at' => now()->subDay()]);
        Review::factory()->forSubject($product)->rated(1)->status(ContentStatus::Pending)->create();
        Review::factory()->forSubject($product)->rated(4)->status(ContentStatus::Pending)->create(['user_id' => $viewer->id]);

        $this->getJson($this->url($product->id))->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.*.rating', [2, 5, 3])
            ->assertJsonPath('meta.own_review', null);
        $this->getJson($this->url($product->id).'?sort=newest')->assertJsonPath('data.*.rating', [3, 2, 5]);
        $this->getJson($this->url($product->id).'?sort=highest')->assertJsonPath('data.*.rating', [5, 3, 2]);
        $this->getJson($this->url($product->id).'?sort=lowest')->assertJsonPath('data.*.rating', [2, 3, 5]);
        $this->getJson($this->url($product->id).'?rating=5')->assertJsonPath('data.*.rating', [5]);

        $this->actingAs($viewer)->getJson($this->url($product->id))
            ->assertJsonPath('meta.own_review.status', 'pending')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_the_service_refuses_a_moderation_to_the_same_status_silently(): void
    {
        Event::fake([ReviewModerated::class]);
        $review = Review::factory()->forSubject($this->product())->create();

        $this->app->make(ReviewService::class)->moderate($review, ContentStatus::Approved, 1);

        Event::assertNotDispatched(ReviewModerated::class);
    }
}
