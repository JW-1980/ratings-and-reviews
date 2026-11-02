<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Tests\TestCase;

class ControversialSortTest extends TestCase
{
    public function test_votes_through_the_api_keep_the_controversy_score_and_the_sort_in_step(): void
    {
        $product = $this->product();
        $popular = $this->commentOn($product, ['created_at' => now()->subMinutes(3)]);
        $divisive = $this->commentOn($product, ['created_at' => now()->subMinutes(2)]);
        $quiet = $this->commentOn($product, ['created_at' => now()->subMinute()]);

        foreach (range(1, 4) as $i) {
            $this->actingAs($this->user())->postJson("/rr/comments/{$popular->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertOk();
        }
        foreach ([1, -1, 1, -1] as $value) {
            $this->actingAs($this->user())->postJson("/rr/comments/{$divisive->id}/votes", ['dimension' => 'agreement', 'value' => $value])->assertOk();
        }

        $this->assertEqualsWithDelta(4.0, $divisive->fresh()->controversy_score, 1e-6, '(2 + 2) ^ (2 / 2)');
        $this->assertEqualsWithDelta(1.0, $popular->fresh()->controversy_score, 1e-6, '(4 + 0) ^ 0');
        $this->assertSame(0.0, $quiet->fresh()->controversy_score);

        $this->getJson("/rr/subjects/product/{$product->id}/comments?sort=controversial")->assertJsonPath('data.*.id', [$divisive->id, $popular->id, $quiet->id]);

        // Withdrawing a disagree vote lowers the score again.
        $voter = $this->user();
        $this->actingAs($voter)->postJson("/rr/comments/{$quiet->id}/votes", ['dimension' => 'agreement', 'value' => -1]);
        $this->actingAs($voter)->postJson("/rr/comments/{$quiet->id}/votes", ['dimension' => 'agreement', 'value' => -1]);
        $this->assertSame(0.0, Comment::query()->find($quiet->id)->controversy_score);
    }

    public function test_reviews_have_no_controversial_sort_because_their_votes_are_one_sided(): void
    {
        $this->getJson("/rr/subjects/product/{$this->product()->id}/reviews?sort=controversial")
            ->assertOk()
            ->assertJsonPath('meta.sort', 'helpful');
    }
}
