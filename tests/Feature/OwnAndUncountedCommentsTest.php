<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Contracts\ContributionCounter;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Tests\TestCase;

/**
 * An author sees their own pending comments (marked), and an uncounted RiskSignals verdict
 * on a comment is stored and respected.
 */
class OwnAndUncountedCommentsTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/comments";
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

    public function test_an_author_sees_their_own_pending_comments_and_replies_marked_others_do_not(): void
    {
        $product = $this->product();
        $author = $this->user();
        $root = $this->commentOn($product, ['created_at' => now()->subHour()]);
        $pendingRoot = $this->commentOn($product, ['user_id' => $author->id, 'status' => ContentStatus::Pending, 'published_at' => null]);
        $pendingReply = Comment::factory()->replyTo($root)->create(['user_id' => $author->id, 'status' => ContentStatus::Pending, 'published_at' => null]);
        $rejected = $this->commentOn($product, ['user_id' => $author->id, 'status' => ContentStatus::Rejected, 'published_at' => null]);
        $othersPending = $this->commentOn($product, ['status' => ContentStatus::Pending, 'published_at' => null]);

        $mine = $this->actingAs($author)->getJson($this->url($product->id))->assertOk();
        $mine->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $pendingRoot->id)
            ->assertJsonPath('data.0.visibility', 'pending')
            ->assertJsonPath('data.0.can.reply', false)
            ->assertJsonPath('data.1.id', $root->id)
            ->assertJsonPath('data.1.replies.0.id', $pendingReply->id)
            ->assertJsonPath('data.1.replies.0.visibility', 'pending');
        $ids = collect($mine->json('data'))->pluck('id');
        $this->assertNotContains($rejected->id, $ids);
        $this->assertNotContains($othersPending->id, $ids);

        $theirs = $this->actingAs($this->user())->getJson($this->url($product->id));
        $theirs->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $root->id)->assertJsonCount(0, 'data.0.replies');

        // On page 2 the own pending roots are not repeated.
        config(['ratings-and-reviews.discussions.roots_per_page' => 1]);
        $this->actingAs($author)->getJson($this->url($product->id).'?page=2')->assertJsonCount(0, 'data');
    }

    public function test_a_held_comment_posted_through_the_api_shows_up_for_its_author(): void
    {
        $this->risk(RiskVerdict::held('new_device'));
        $product = $this->product();
        $author = $this->user();

        $id = $this->actingAs($author)->postJson($this->url($product->id), ['body' => 'Even wachten'])->assertCreated()
            ->assertJsonPath('data.visibility', 'pending')
            ->assertJsonPath('message', 'Your comment will appear after a moderator has checked it.')
            ->json('data.id');

        $this->actingAs($author)->getJson($this->url($product->id))->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_own', true);
        $this->actingAs($author)->patchJson("/rr/comments/{$id}", ['body' => 'Nog even wachten'])->assertOk();
    }

    public function test_an_uncounted_comment_is_shown_but_not_counted_as_a_contribution_or_top_comment(): void
    {
        $this->risk(RiskVerdict::uncounted('device_cap'));
        $product = $this->product();
        $author = $this->user();

        $id = $this->actingAs($author)->postJson($this->url($product->id), ['body' => 'Telt niet mee'])->assertCreated()->assertJsonPath('data.status', 'approved')->json('data.id');
        $comment = Comment::query()->find($id);

        $this->assertFalse($comment->counted);
        $this->assertSame(0, $this->app->make(ContributionCounter::class)->approvedContributions($author->id));
        $this->getJson($this->url($product->id))->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.counted');

        $comment->update(['helpful_count' => 50]);
        $this->commentOn($product, ['helpful_count' => 4]);
        $this->getJson($this->url($product->id))->assertJsonPath('meta.top_comment_id', Comment::query()->where('helpful_count', 4)->value('id'));
    }

    public function test_an_edit_re_evaluates_whether_the_comment_counts(): void
    {
        $product = $this->product();
        $author = $this->user();
        $id = $this->actingAs($author)->postJson($this->url($product->id), ['body' => 'Eerst telt het'])->json('data.id');
        $this->assertTrue(Comment::query()->find($id)->counted);

        $this->risk(RiskVerdict::uncounted('device_cap'));
        $this->actingAs($author)->patchJson("/rr/comments/{$id}", ['body' => 'Nu niet meer'])->assertOk();

        $this->assertFalse(Comment::query()->find($id)->counted);
    }
}
