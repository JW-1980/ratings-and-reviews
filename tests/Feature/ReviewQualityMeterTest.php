<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\ReviewQualityMeter;
use RatingsAndReviews\Tests\TestCase;

class ReviewQualityMeterTest extends TestCase
{
    private ReviewQualityMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = $this->app->make(ReviewQualityMeter::class);
    }

    public function test_an_empty_review_scores_zero_with_every_hint(): void
    {
        $result = $this->meter->assess(['text' => '', 'topics_available' => 2]);

        $this->assertSame(0, $result->score);
        $this->assertSame('weak', $result->level);
        $this->assertSame(['length', 'pros', 'cons', 'usage', 'topics', 'concrete'], $result->hints);
    }

    public function test_a_complete_review_scores_one_hundred(): void
    {
        $text = str_repeat('Romig en vol van smaak. ', 13).'Ik heb het gebruikt in koffie, 200 ml per dag.';
        $result = $this->meter->assess(['text' => $text, 'pros' => 1, 'cons' => 2, 'topics_rated' => 3, 'topics_available' => 3]);

        $this->assertSame(100, $result->score);
        $this->assertSame('strong', $result->level);
        $this->assertSame([], $result->hints);
    }

    public function test_rules_that_cannot_apply_count_for_neither_side(): void
    {
        // Length 300+ (30) + usage (15) + concrete (10) of applicable 30+15+10 = 55 without topics and extended fields.
        $text = str_repeat('x', 300).' gebruikt 3 keer';
        $result = $this->meter->assess(['text' => $text, 'topics_available' => 0, 'extended_fields' => false]);

        $this->assertSame(100, $result->score);
        $this->assertArrayNotHasKey('topics', $result->rules);
        $this->assertArrayNotHasKey('pros', $result->rules);

        // With topics present but half rated: 15 * 1 / 2 = 7 (rounded down).
        $half = $this->meter->assess(['text' => $text, 'topics_available' => 2, 'topics_rated' => 1, 'extended_fields' => false]);
        $this->assertSame(['earned' => 7, 'available' => 15], $half->rules['topics']);
        $this->assertSame((int) floor((55 + 7) * 100 / 70 + 0.5), $half->score);
    }

    public function test_length_steps_word_boundaries_and_concreteness(): void
    {
        $this->assertSame(['earned' => 10, 'available' => 30], $this->meter->assess(['text' => str_repeat('a', 40)])->rules['length']);
        $this->assertSame(['earned' => 0, 'available' => 30], $this->meter->assess(['text' => str_repeat('a', 39)])->rules['length']);
        $this->assertSame(20, $this->meter->assess(['text' => str_repeat('é', 120)])->rules['length']['earned'], 'Length counts characters, not bytes.');

        $this->assertSame(0, $this->meter->assess(['text' => 'It was unused and misused'])->rules['usage']['earned'], '"used" inside another word does not count.');
        $this->assertSame(15, $this->meter->assess(['text' => 'I USED it daily'])->rules['usage']['earned']);
        $this->assertSame(10, $this->meter->assess(['text' => 'Een halve liter'])->rules['concrete']['earned']);
        $this->assertSame(10, $this->meter->assess(['text' => 'Na 2 dagen op'])->rules['concrete']['earned']);
        $this->assertSame(0, $this->meter->assess(['text' => 'Vrij vaag allemaal'])->rules['concrete']['earned']);
    }

    public function test_the_rules_endpoint_serves_the_data_the_meter_computes_with(): void
    {
        $response = $this->getJson('/rr/review-quality-rules')->assertOk();

        $response->assertJsonPath('data.version', ReviewQualityMeter::VERSION)
            ->assertJsonPath('data.rules.0.key', 'length')
            ->assertJsonPath('data.rules.0.max_points', 30)
            ->assertJsonPath('data.rules.0.hint', 'Tell a bit more: what did you notice?')
            ->assertJsonPath('data.levels.0.level', 'weak');

        $this->assertSame($this->meter->configuredRules(), array_map(
            fn (array $rule): array => array_diff_key($rule, ['max_points' => 1, 'hint' => 1]),
            $response->json('data.rules'),
        ));
        $this->assertSame(100, array_sum(array_column($response->json('data.rules'), 'max_points')));
    }

    public function test_malformed_configured_rules_are_dropped(): void
    {
        config(['ratings-and-reviews.quality.rules' => [['key' => 'x', 'type' => 'unknown', 'points' => 50], 'nonsense', ['type' => 'pros'], ['key' => 'pros', 'type' => 'pros', 'points' => 10]]]);

        $this->assertSame([['key' => 'pros', 'type' => 'pros', 'points' => 10]], $this->meter->configuredRules());
        $this->assertSame(100, $this->meter->assess(['pros' => 1])->score);
    }

    public function test_the_score_is_stored_on_submit_and_recomputed_on_edit(): void
    {
        $product = $this->product();
        RatingTopic::query()->create(['scope' => 'product', 'name' => ['nl' => 'Smaak', 'en' => 'Taste']]);
        $user = $this->user();

        $response = $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'body' => 'Prima.'])
            ->assertCreated()
            ->assertJsonPath('quality.level', 'weak')
            ->assertJsonPath('quality.hints.0.key', 'length')
            ->assertJsonPath('quality.hints.0.message', 'Tell a bit more: what did you notice?');

        $review = Review::query()->sole();
        $this->assertSame(0, $review->quality_score);
        $this->assertSame(0, $response->json('data.quality_score'));

        $topic = RatingTopic::query()->sole();
        $this->actingAs($user)->patchJson("/rr/reviews/{$review->id}", [
            'rating' => 4,
            'body' => str_repeat('Romig, zacht en niet te zoet. ', 11).'Gebruikt in koffie, 1 liter in 3 dagen.',
            'pros' => ['romig'],
            'cons' => ['prijzig'],
            'topic_ratings' => [$topic->id => 4],
        ])->assertOk()->assertJsonPath('quality.score', 100)->assertJsonPath('quality.hints', []);

        $this->assertSame(100, $review->fresh()->quality_score);
    }
}
