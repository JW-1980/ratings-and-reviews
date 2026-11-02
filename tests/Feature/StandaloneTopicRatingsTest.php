<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Events\TopicRatingsSaved;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\TopicRating;
use RatingsAndReviews\Services\TopicRatings;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\TestCase;

class StandaloneTopicRatingsTest extends TestCase
{
    private RatingTopic $taste;

    private RatingTopic $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taste = RatingTopic::factory()->create(['name' => ['nl' => 'Smaak', 'en' => 'Taste'], 'sort_order' => 1]);
        $this->price = RatingTopic::factory()->create(['name' => ['nl' => 'Prijs', 'en' => 'Price'], 'sort_order' => 2, 'scale_max' => 10]);
    }

    private function url(Product $product): string
    {
        return "/rr/subjects/product/{$product->id}/topic-ratings";
    }

    /**
     * @return array{count: int, average: float|null}
     */
    private function topic(Product $product, RatingTopic $topic): array
    {
        $summary = collect($this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->json('data.topics'))->firstWhere('id', $topic->id);

        return ['count' => $summary['count'], 'average' => $summary['average']];
    }

    public function test_standalone_ratings_are_off_unless_the_section_allows_them(): void
    {
        $product = $this->product();

        $this->getJson($this->url($product))->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.own', null);
        $this->actingAs($this->user())->putJson($this->url($product), ['ratings' => [$this->taste->id => 4]])
            ->assertUnprocessable()->assertJsonValidationErrors('ratings');
        $this->assertSame(0, TopicRating::query()->count());
    }

    public function test_members_rate_topics_without_a_review_and_the_averages_include_them(): void
    {
        Event::fake([TopicRatingsSaved::class]);
        $this->configureSection('product', ['standalone_topic_ratings' => true]);
        $product = $this->product();
        $first = $this->user();

        $this->putJson($this->url($product), ['ratings' => [$this->taste->id => 4]])->assertUnauthorized();

        $this->actingAs($first)->putJson($this->url($product), ['ratings' => [$this->taste->id => 4, $this->price->id => 10]])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.accepts_ratings', true)
            ->assertJsonPath('data.own.'.$this->taste->id, 4)
            ->assertJsonPath('data.own.'.$this->price->id, 10);

        $this->actingAs($this->user())->putJson($this->url($product), ['ratings' => [$this->taste->id => 2]])->assertOk();

        $this->assertEquals(['count' => 2, 'average' => 3.0], $this->topic($product, $this->taste));
        $this->assertEquals(['count' => 1, 'average' => 10.0], $this->topic($product, $this->price));
        $this->assertSame(0, RatingAggregate::query()->sole()->review_count, 'Topic scores are not reviews.');

        $this->actingAs($first)->putJson($this->url($product), ['ratings' => [$this->taste->id => 5, $this->price->id => null]])
            ->assertOk()
            ->assertJsonPath('data.own', [$this->taste->id => 5]);

        $this->assertEquals(['count' => 2, 'average' => 3.5], $this->topic($product, $this->taste));
        $this->assertEquals(['count' => 0, 'average' => null], $this->topic($product, $this->price));
        Event::assertDispatched(TopicRatingsSaved::class, fn (TopicRatingsSaved $event): bool => $event->userId === $first->id && $event->removed === [$this->price->id] && $event->ratings === [$this->taste->id => 5]);
    }

    public function test_topics_and_values_are_validated_against_the_subject(): void
    {
        $this->configureSection('product', ['standalone_topic_ratings' => true]);
        $product = $this->product();
        $foreign = RatingTopic::factory()->create(['scope' => 'additive']);
        $user = $this->user();

        $this->actingAs($user)->putJson($this->url($product), ['ratings' => [$foreign->id => 3]])->assertUnprocessable()->assertJsonValidationErrors('ratings.'.$foreign->id);
        $this->actingAs($user)->putJson($this->url($product), ['ratings' => [$this->taste->id => 6]])->assertUnprocessable()->assertJsonValidationErrors('ratings.'.$this->taste->id);
        $this->actingAs($user)->putJson($this->url($product), ['ratings' => []])->assertUnprocessable()->assertJsonValidationErrors('ratings');
        $this->actingAs($user)->putJson($this->url($this->product(['is_open' => false])), ['ratings' => [$this->taste->id => 3]])->assertUnprocessable();
        $this->actingAs($user)->getJson($this->url($this->product(['is_hidden' => true])))->assertNotFound();
        $this->assertSame(0, TopicRating::query()->count());
    }

    public function test_a_members_review_score_wins_over_their_standalone_score_for_the_same_topic(): void
    {
        $this->configureSection('product', ['standalone_topic_ratings' => true]);
        $product = $this->product();
        $user = $this->user();

        $this->actingAs($user)->putJson($this->url($product), ['ratings' => [$this->taste->id => 1, $this->price->id => 2]])->assertOk();
        $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/reviews", [
            'rating' => 5,
            'body' => '<p>Rich and creamy, worth it.</p>',
            'topic_ratings' => [$this->taste->id => 5],
        ])->assertCreated();

        $this->assertEquals(['count' => 1, 'average' => 5.0], $this->topic($product, $this->taste), 'The review score of the topic counts, the standalone one does not.');
        $this->assertEquals(['count' => 1, 'average' => 2.0], $this->topic($product, $this->price), 'A topic the review left out keeps the standalone score.');
    }

    public function test_risk_signals_can_keep_scores_out_of_the_averages(): void
    {
        $this->configureSection('product', ['standalone_topic_ratings' => true]);
        $this->app->instance(RiskSignals::class, new class implements RiskSignals
        {
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
                return $context->kind === TopicRatings::CONTRIBUTION_KIND ? RiskVerdict::held('account_hold') : RiskVerdict::counts();
            }
        });
        $product = $this->product();

        $this->actingAs($this->user())->putJson($this->url($product), ['ratings' => [$this->taste->id => 5]])->assertOk();

        $rating = TopicRating::query()->sole();
        $this->assertFalse($rating->counted);
        $this->assertSame('account_hold', $rating->uncounted_reason);
        $this->assertEquals(['count' => 0, 'average' => null], $this->topic($product, $this->taste));
    }

    public function test_an_importer_stores_scores_idempotently_with_their_dates(): void
    {
        $product = $this->product();
        $service = $this->app->make(TopicRatings::class);
        $at = Carbon::parse('2024-03-01 10:00:00');

        $service->import(501, $product, $this->taste->id, 3, 5, $at);
        $service->import(501, $product, $this->taste->id, 4, 5, $at);
        $service->import(502, $product, $this->taste->id, 2, 5, $at, counted: false);

        $this->assertSame(2, TopicRating::query()->count());
        $rating = TopicRating::query()->where('user_id', 501)->sole();
        $this->assertSame(4, $rating->value);
        $this->assertSame('2024-03-01 10:00:00', $rating->created_at?->format('Y-m-d H:i:s'));
        $this->assertEquals(['count' => 1, 'average' => 4.0], $this->topic($product, $this->taste), 'Imported scores count even where the section does not take new ones.');

        $this->expectException(ValidationException::class);
        $service->import(503, $product, 999999, 3, 5);
    }
}
