<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\PollVote;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Services\VoteService;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Tests\Fixtures\User;
use RatingsAndReviews\Tests\TestCase;

/**
 * VoteService::recountForUsers(): the host's fraud picture changes (an account cluster forms
 * or breaks up), the members' votes and poll votes are asked again and the counters follow.
 */
class RecountVotesForUsersTest extends TestCase
{
    /** A RiskSignals that refuses to count the votes of a cluster, recording what it was asked. */
    private object $risk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->risk = new class implements RiskSignals
        {
            /** @var list<int> */
            public array $cluster = [];

            /** @var list<array{user: int|null, reevaluation: bool, earlier: int}> */
            public array $asked = [];

            /** @var array<string, mixed>|null */
            public ?array $meta = null;

            public function forVote(VoteContext $context): RiskVerdict
            {
                $this->asked[] = ['user' => $context->userId, 'reevaluation' => $context->isReevaluation(), 'earlier' => (clone $context->existingVotes)->count()];

                return $this->verdict($context->userId);
            }

            public function forPollVote(PollVoteContext $context): RiskVerdict
            {
                $this->asked[] = ['user' => $context->userId, 'reevaluation' => $context->isReevaluation(), 'earlier' => 0];

                return $this->verdict($context->userId);
            }

            public function forContribution(ContributionContext $context): RiskVerdict
            {
                return RiskVerdict::counts();
            }

            private function verdict(?int $userId): RiskVerdict
            {
                $verdict = in_array($userId, $this->cluster, true) ? RiskVerdict::uncounted('account_cluster') : RiskVerdict::counts();

                return $this->meta === null ? $verdict : $verdict->withMeta($this->meta);
            }
        };
        $this->app->instance(RiskSignals::class, $this->risk);
    }

    /**
     * @return array{comment: Comment, review: Review, poll: array<string, mixed>, voters: list<User>}
     */
    private function votedOn(): array
    {
        $product = $this->product();
        $comment = Comment::factory()->forSubject($product)->create(['user_id' => $this->user()->id]);
        $review = Review::factory()->forSubject($product)->create(['user_id' => $this->user()->id]);
        $poll = $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => 'Hoe drinken jullie het?', 'poll' => ['question' => 'Warm of koud?', 'options' => ['Warm', 'Koud']]])
            ->assertCreated()->json('data.poll');
        $voters = [$this->user(), $this->user(), $this->user()];

        foreach ($voters as $voter) {
            $this->actingAs($voter)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertOk();
            $this->actingAs($voter)->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertOk();
            $this->actingAs($voter)->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $poll['options'][0]['id']])->assertOk();
        }

        $this->risk->asked = [];

        return ['comment' => $comment, 'review' => $review, 'poll' => $poll, 'voters' => $voters];
    }

    private function service(): VoteService
    {
        return $this->app->make(VoteService::class);
    }

    public function test_a_forming_cluster_stops_counting_and_a_breaking_one_counts_again(): void
    {
        ['comment' => $comment, 'review' => $review, 'poll' => $poll, 'voters' => [$first, $second, $third]] = $this->votedOn();
        $this->assertSame(3, $comment->fresh()->helpful_count);

        $this->risk->cluster = [$first->id, $second->id];
        $totals = $this->service()->recountForUsers([$first->id, $second->id, $second->id]);

        $this->assertSame(['votes' => 4, 'votes_changed' => 4, 'items' => 2, 'poll_votes' => 2, 'poll_votes_changed' => 2, 'polls' => 1], $totals);
        $this->assertSame(1, $comment->fresh()->helpful_count);
        $this->assertSame(1, $review->fresh()->helpful_count);
        $this->assertGreaterThan(0.0, (float) $comment->fresh()->helpful_score);
        $this->getJson("/rr/polls/{$poll['id']}")->assertJsonPath('data.total_votes', 1);
        $this->assertSame(['account_cluster'], Vote::query()->where('user_id', $first->id)->distinct()->pluck('uncounted_reason')->all());
        $this->assertSame(['account_cluster'], PollVote::query()->where('user_id', $second->id)->pluck('uncounted_reason')->all());
        $this->assertTrue(Vote::query()->where('user_id', $third->id)->get()->every(fn (Vote $vote): bool => $vote->counted), 'Members outside the set are untouched.');

        // Only the members in the set were asked, each as a re-evaluation; the first vote on an
        // item sees no earlier votes, the second sees the one cast before it.
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_values(array_unique(array_column($this->risk->asked, 'user'))));
        $this->assertTrue(collect($this->risk->asked)->every(fn (array $call): bool => $call['reevaluation']));
        $this->assertSame([0, 1], collect($this->risk->asked)->where('user', '!==', null)->take(2)->pluck('earlier')->all());

        // Asked again with the same picture: nothing changes.
        $this->assertSame(0, $this->service()->recountForUsers([$first->id, $second->id])['votes_changed']);

        $this->risk->cluster = [];
        $this->service()->recountForUsers([$first->id, $second->id]);
        $this->assertSame(3, $comment->fresh()->helpful_count);
        $this->assertSame(3, $review->fresh()->helpful_count);
        $this->getJson("/rr/polls/{$poll['id']}")->assertJsonPath('data.total_votes', 3);
        $this->assertSame(0, Vote::query()->where('counted', false)->count());
        $this->assertNull(Vote::query()->where('user_id', $first->id)->value('uncounted_reason'));
    }

    public function test_the_counters_are_recounted_even_when_no_verdict_changes(): void
    {
        ['comment' => $comment, 'voters' => [$first]] = $this->votedOn();
        Comment::query()->whereKey($comment->id)->update(['helpful_count' => 99]);

        $totals = $this->service()->recountForUsers([$first->id]);

        $this->assertSame(0, $totals['votes_changed']);
        $this->assertSame(3, $comment->fresh()->helpful_count);
    }

    public function test_new_meta_from_the_verdict_is_stored_with_the_vote(): void
    {
        ['voters' => [$first]] = $this->votedOn();
        $this->risk->meta = ['cluster_id' => 'c-17'];

        $this->service()->recountForUsers([$first->id]);

        $this->assertSame([['cluster_id' => 'c-17']], Vote::query()->where('user_id', $first->id)->get()->pluck('meta')->unique()->values()->all());
        $this->assertSame(['cluster_id' => 'c-17'], PollVote::query()->where('user_id', $first->id)->sole()->meta);
    }

    public function test_an_empty_or_unknown_set_changes_nothing(): void
    {
        $zero = ['votes' => 0, 'votes_changed' => 0, 'items' => 0, 'poll_votes' => 0, 'poll_votes_changed' => 0, 'polls' => 0];

        $this->assertSame($zero, $this->service()->recountForUsers([]));
        $this->assertSame($zero, $this->service()->recountForUsers([0, -3, 'x']));
        $this->assertSame($zero, $this->service()->recountForUsers([987654]));
        $this->assertSame([], $this->risk->asked);
    }
}
