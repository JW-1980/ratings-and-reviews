<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewAnswer;
use RatingsAndReviews\Models\ReviewQuestion;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

/**
 * The review questionnaire: admin endpoints, inheritance by key, answers with the review and
 * the per-question summary.
 */
class ReviewQuestionsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(array $attributes = []): ReviewQuestion
    {
        return ReviewQuestion::query()->create($attributes + [
            'scope' => 'product',
            'category_key' => null,
            'key' => 'sweet',
            'type' => 'scale',
            'label' => ['nl' => 'Zoet', 'en' => 'Sweet'],
            'low_label' => ['nl' => 'Niet zoet', 'en' => 'Not sweet'],
            'high_label' => ['nl' => 'Heel zoet', 'en' => 'Very sweet'],
        ]);
    }

    private function moments(array $attributes = []): ReviewQuestion
    {
        return $this->question($attributes + [
            'key' => 'moments',
            'type' => 'multi',
            'label' => ['nl' => 'Wanneer?', 'en' => 'When?'],
            'low_label' => null,
            'high_label' => null,
            'options' => [
                ['value' => 'breakfast', 'label' => ['nl' => 'Ontbijt', 'en' => 'Breakfast']],
                ['value' => 'lunch', 'label' => ['nl' => 'Lunch', 'en' => 'Lunch']],
                ['value' => 'dinner', 'label' => ['nl' => 'Avondeten', 'en' => 'Dinner']],
            ],
        ]);
    }

    public function test_question_management_needs_rr_manage_topics_and_valid_definitions(): void
    {
        $payload = [
            'scope' => 'product', 'key' => 'buy_again', 'type' => 'choice',
            'label' => ['nl' => 'Weer kopen?', 'en' => 'Buy again?'],
            'options' => [['value' => 'yes', 'label' => ['nl' => 'Ja', 'en' => 'Yes']], ['value' => 'no', 'label' => ['nl' => 'Nee', 'en' => 'No']]],
        ];

        $this->actingAs($this->user())->postJson('/rr/admin/review-questions', $payload)->assertForbidden();

        $admin = $this->moderator();
        $id = $this->actingAs($admin)->postJson('/rr/admin/review-questions', $payload)->assertCreated()
            ->assertJsonPath('data.key', 'buy_again')->assertJsonPath('data.type', 'choice')->json('data.id');
        $this->actingAs($admin)->postJson('/rr/admin/review-questions', $payload)->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->actingAs($admin)->postJson('/rr/admin/review-questions', ['options' => [$payload['options'][0]], 'key' => 'one'] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->actingAs($admin)->postJson('/rr/admin/review-questions', ['type' => 'slider', 'key' => 'other'] + $payload)->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->actingAs($admin)->patchJson("/rr/admin/review-questions/{$id}", ['label' => ['nl' => 'Opnieuw kopen?', 'en' => 'Buy it again?'], 'is_required' => true])
            ->assertOk()->assertJsonPath('data.label.en', 'Buy it again?')->assertJsonPath('data.is_required', true);
        $this->actingAs($admin)->getJson('/rr/admin/review-questions?scope=product')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.answered_reviews', 0)->assertJsonPath('meta.scale_max', 5);
        $this->actingAs($this->user())->patchJson("/rr/admin/review-questions/{$id}", ['is_active' => false])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/rr/admin/review-questions/{$id}")->assertOk()->assertJsonPath('data.answered_reviews', 0);
        $this->assertSame(0, ReviewQuestion::query()->count());
    }

    public function test_questions_are_inherited_by_key_along_the_category_path(): void
    {
        $this->question();
        $this->moments();
        $this->question(['key' => 'crunchy', 'label' => ['nl' => 'Knapperig', 'en' => 'Crunchy']]);
        $this->question(['category_key' => 'drinks', 'key' => 'sweet', 'label' => ['nl' => 'Zoetheid', 'en' => 'Sweetness']]);
        $this->question(['category_key' => 'drinks', 'key' => 'crunchy', 'is_active' => false]);
        $this->question(['category_key' => 'coffee', 'key' => 'bitter', 'label' => ['nl' => 'Bitter', 'en' => 'Bitter']]);

        $food = $this->product(['category_path' => ['food']]);
        $coffee = $this->product(['category_path' => ['drinks', 'coffee']]);

        $this->assertSame(['sweet', 'moments', 'crunchy'], array_column($this->getJson("/rr/subjects/product/{$food->id}/reviews")->json('meta.review_questions'), 'key'));

        $questions = $this->getJson("/rr/subjects/product/{$coffee->id}/reviews")->json('meta.review_questions');
        $this->assertSame(['sweet', 'moments', 'bitter'], array_column($questions, 'key'));
        $this->assertSame('Sweetness', $questions[0]['label']);
        $this->assertEquals(['type' => 'scale', 'scale_max' => 5, 'low_label' => 'Not sweet'], array_intersect_key($questions[0], array_flip(['type', 'scale_max', 'low_label'])));
        $this->assertSame([['value' => 'breakfast', 'label' => 'Breakfast'], ['value' => 'lunch', 'label' => 'Lunch'], ['value' => 'dinner', 'label' => 'Dinner']], $questions[1]['options']);
    }

    public function test_answers_are_validated_stored_and_shown_with_the_review(): void
    {
        $sweet = $this->question();
        $this->moments();
        $this->question(['key' => 'again', 'type' => 'choice', 'is_required' => true, 'label' => ['nl' => 'Weer?', 'en' => 'Again?'], 'low_label' => null, 'high_label' => null,
            'options' => [['value' => 'yes', 'label' => ['nl' => 'Ja', 'en' => 'Yes']], ['value' => 'no', 'label' => ['nl' => 'Nee', 'en' => 'No']]]]);
        $product = $this->product();
        $author = $this->user();
        $url = "/rr/subjects/product/{$product->id}/reviews";

        $this->actingAs($author)->postJson($url, ['rating' => 4, 'answers' => ['sweet' => 3]])->assertUnprocessable()->assertJsonValidationErrors('answers.again');
        $this->actingAs($author)->postJson($url, ['rating' => 4, 'answers' => ['sweet' => 6, 'again' => 'yes']])->assertUnprocessable()->assertJsonValidationErrors('answers.sweet');
        $this->actingAs($author)->postJson($url, ['rating' => 4, 'answers' => ['moments' => ['brunch'], 'again' => 'yes']])->assertUnprocessable()->assertJsonValidationErrors('answers.moments');
        $this->actingAs($author)->postJson($url, ['rating' => 4, 'answers' => ['moments' => ['lunch', 'lunch'], 'again' => 'yes']])->assertUnprocessable()->assertJsonValidationErrors('answers.moments');
        $this->actingAs($author)->postJson($url, ['rating' => 4, 'answers' => ['salty' => 2, 'again' => 'yes']])->assertUnprocessable()->assertJsonValidationErrors('answers.salty');
        $this->actingAs($author)->postJson($url, ['rating' => 4, 'answers' => ['again' => 'maybe']])->assertUnprocessable()->assertJsonValidationErrors('answers.again');
        $this->assertSame(0, Review::query()->count());

        $id = $this->actingAs($author)->postJson($url, ['rating' => 4, 'answers' => ['sweet' => '4', 'moments' => ['dinner', 'breakfast'], 'again' => 'yes']])
            ->assertCreated()->json('data.id');
        $answers = $this->getJson($url)->json('data.0.answers');
        $this->assertSame([
            ['key' => 'sweet', 'label' => 'Sweet', 'type' => 'scale', 'value' => 4, 'display' => '4', 'scale_max' => 5],
            ['key' => 'moments', 'label' => 'When?', 'type' => 'multi', 'value' => ['breakfast', 'dinner'], 'display' => 'Breakfast, Dinner', 'scale_max' => null],
            ['key' => 'again', 'label' => 'Again?', 'type' => 'choice', 'value' => 'yes', 'display' => 'Yes', 'scale_max' => null],
        ], $answers);

        // An edit without answers keeps them; with answers it replaces them.
        $this->actingAs($author)->patchJson("/rr/reviews/{$id}", ['rating' => 5])->assertOk()->assertJsonCount(3, 'data.answers');
        $this->actingAs($author)->patchJson("/rr/reviews/{$id}", ['rating' => 5, 'answers' => ['again' => 'no']])->assertOk()
            ->assertJsonCount(1, 'data.answers')->assertJsonPath('data.answers.0.value', 'no');
        $this->assertSame(0, ReviewAnswer::query()->where('question_id', $sweet->id)->count());
    }

    public function test_the_summary_counts_answers_of_counted_public_reviews(): void
    {
        $this->configureSection('product', ['min_reviews_for_average' => 2]);
        $sweet = $this->question();
        $moments = $this->moments();
        $product = $this->product();
        $answer = function (Review $review, ReviewQuestion $question, string $option = '', ?int $value = null): void {
            ReviewAnswer::query()->create(['review_id' => $review->id, 'question_id' => $question->id, 'option_key' => $option, 'value' => $value]);
        };

        $first = Review::factory()->forSubject($product)->create();
        $answer($first, $sweet, '', 2);
        $answer($first, $moments, 'breakfast');
        $answer($first, $moments, 'lunch');
        $second = Review::factory()->forSubject($product)->create();
        $answer($second, $sweet, '', 5);
        $answer($second, $moments, 'breakfast');
        $pending = Review::factory()->forSubject($product)->status(ContentStatus::Pending)->create();
        $answer($pending, $sweet, '', 1);
        $uncounted = Review::factory()->forSubject($product)->create(['counted' => false]);
        $answer($uncounted, $sweet, '', 1);

        $this->app->make(RatingAggregator::class)->refresh($product);
        $questions = $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->assertOk()->json('data.questions');

        $this->assertSame('sweet', $questions[0]['key']);
        $this->assertSame(2, $questions[0]['count']);
        $this->assertEquals(3.5, $questions[0]['average']);
        $this->assertSame([['value' => 1, 'count' => 0], ['value' => 2, 'count' => 1], ['value' => 3, 'count' => 0], ['value' => 4, 'count' => 0], ['value' => 5, 'count' => 1]], $questions[0]['distribution']);
        $this->assertSame(2, $questions[1]['count']);
        $this->assertSame([
            ['value' => 'breakfast', 'label' => 'Breakfast', 'count' => 2, 'share' => 1],
            ['value' => 'lunch', 'label' => 'Lunch', 'count' => 1, 'share' => 0.5],
            ['value' => 'dinner', 'label' => 'Dinner', 'count' => 0, 'share' => 0],
        ], array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label'], 'count' => $option['count'], 'share' => $option['share'] + 0], $questions[1]['options']));

        // Below the section's minimum the average stays hidden.
        $this->configureSection('product', ['min_reviews_for_average' => 3]);
        $this->assertNull($this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->json('data.questions.0.average'));
    }

    public function test_removing_an_option_or_the_question_removes_its_answers_and_refreshes_the_summary(): void
    {
        $moments = $this->moments();
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create();
        ReviewAnswer::query()->create(['review_id' => $review->id, 'question_id' => $moments->id, 'option_key' => 'dinner']);
        ReviewAnswer::query()->create(['review_id' => $review->id, 'question_id' => $moments->id, 'option_key' => 'lunch']);
        $admin = $this->moderator();

        $this->actingAs($admin)->patchJson("/rr/admin/review-questions/{$moments->id}", ['options' => [
            ['value' => 'breakfast', 'label' => ['nl' => 'Ontbijt', 'en' => 'Breakfast']],
            ['value' => 'lunch', 'label' => ['nl' => 'Lunch', 'en' => 'Lunch']],
        ]])->assertOk();
        $this->assertSame(['lunch'], ReviewAnswer::query()->pluck('option_key')->all());
        $this->assertSame(1, $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->json('data.questions.0.options.1.count'));

        $this->actingAs($admin)->deleteJson("/rr/admin/review-questions/{$moments->id}")->assertOk()->assertJsonPath('data.answered_reviews', 1);
        $this->assertSame(0, ReviewAnswer::query()->count());
        $this->assertSame([], $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->json('data.questions'));
    }
}
