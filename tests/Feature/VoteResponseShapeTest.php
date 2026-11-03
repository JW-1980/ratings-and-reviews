<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Tests\TestCase;

/**
 * The documented body of `POST comments/{id}/votes` and `POST reviews/{id}/votes`
 * (VoteResult::toArray()). The JavaScript client (normalize.voteResult, the vote-response
 * spec) reads exactly these keys; it used to read `counts.up`, which was never sent.
 */
class VoteResponseShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ratings-and-reviews.sections.product' => ['vote_dimensions' => ['agreement', 'helpfulness', 'needs_source']]]);
    }

    public function test_a_comment_vote_answers_with_the_voted_dimension_every_dimension_and_the_raw_counters(): void
    {
        $comment = Comment::factory()->forSubject($this->product())->create(['user_id' => $this->user()->id]);
        $url = "/rr/comments/{$comment->id}/votes";

        foreach ([[1, 'agreement', 1], [2, 'agreement', -1], [3, 'agreement', -1], [4, 'helpfulness', 1], [5, 'needs_source', 1]] as [, $dimension, $value]) {
            $this->actingAs($this->user())->postJson($url, ['dimension' => $dimension, 'value' => $value])->assertOk();
        }

        $response = $this->actingAs($this->user())->postJson($url, ['dimension' => 'agreement', 'value' => -1])->assertOk();

        $this->assertSame([
            'dimension' => 'agreement',
            'value' => -1,
            'counted' => true,
            'count' => ['up' => 1, 'down' => 3],
            'counts_by_dimension' => [
                'agreement' => ['up' => 1, 'down' => 3],
                'helpfulness' => ['up' => 1, 'down' => 0],
                'needs_source' => ['up' => 1, 'down' => 0],
            ],
            'counts' => ['agree_count' => 1, 'disagree_count' => 3, 'helpful_count' => 1, 'unhelpful_count' => 0, 'needs_source_count' => 1],
        ], $response->json('data'));
    }

    public function test_the_needs_source_dimension_reports_its_own_count(): void
    {
        $comment = Comment::factory()->forSubject($this->product())->create(['user_id' => $this->user()->id]);

        $this->actingAs($this->user())->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'needs_source', 'value' => 1])
            ->assertOk()
            ->assertJsonPath('data.count', ['up' => 1, 'down' => 0])
            ->assertJsonPath('data.counts_by_dimension.agreement', ['up' => 0, 'down' => 0]);
    }

    public function test_counts_by_dimension_holds_only_the_sections_dimensions(): void
    {
        config(['ratings-and-reviews.sections.product' => ['vote_dimensions' => ['helpfulness']]]);
        $comment = Comment::factory()->forSubject($this->product())->create(['user_id' => $this->user()->id]);

        $response = $this->actingAs($this->user())->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'helpfulness', 'value' => -1])->assertOk();

        $this->assertSame(['helpfulness' => ['up' => 0, 'down' => 1]], $response->json('data.counts_by_dimension'));
        $this->assertSame(['up' => 0, 'down' => 1], $response->json('data.count'));
    }

    public function test_a_review_helpful_vote_and_its_withdrawal_answer_with_the_count(): void
    {
        $review = Review::factory()->forSubject($this->product())->create(['user_id' => $this->user()->id]);
        $voter = $this->user();
        $url = "/rr/reviews/{$review->id}/votes";

        $this->actingAs($this->user())->postJson($url, ['dimension' => 'helpfulness', 'value' => 1])->assertOk();

        $this->assertSame([
            'dimension' => 'helpfulness',
            'value' => 1,
            'counted' => true,
            'count' => ['up' => 2, 'down' => 0],
            'counts_by_dimension' => ['helpfulness' => ['up' => 2, 'down' => 0]],
            'counts' => ['helpful_count' => 2],
        ], $this->actingAs($voter)->postJson($url, ['dimension' => 'helpfulness', 'value' => 1])->assertOk()->json('data'));

        $this->actingAs($voter)->postJson($url, ['dimension' => 'helpfulness', 'value' => 1])
            ->assertOk()
            ->assertJsonPath('data.value', null)
            ->assertJsonPath('data.counted', false)
            ->assertJsonPath('data.count', ['up' => 1, 'down' => 0]);

        $this->assertSame(1, $review->fresh()->helpful_count);
    }
}
