<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Events\VoteCast;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Tests\TestCase;

class VoteTest extends TestCase
{
    private function comment(array $attributes = []): Comment
    {
        return Comment::factory()->forSubject($this->product())->create($attributes + ['user_id' => $this->user()->id]);
    }

    private function section(array $settings): void
    {
        config(['ratings-and-reviews.sections.product' => $settings]);
        $this->app->make(SectionSettingsRepository::class)->forget('product');
    }

    public function test_a_vote_is_cast_withdrawn_and_switched(): void
    {
        Event::fake([VoteCast::class]);
        $comment = $this->comment();
        $voter = $this->user();
        $url = "/rr/comments/{$comment->id}/votes";

        $this->actingAs($voter)->postJson($url, ['dimension' => 'agreement', 'value' => 1])
            ->assertOk()
            ->assertJsonPath('data.value', 1)
            ->assertJsonPath('data.counted', true)
            ->assertJsonPath('data.counts.agree_count', 1);

        $this->actingAs($voter)->postJson($url, ['dimension' => 'agreement', 'value' => -1])
            ->assertJsonPath('data.counts.agree_count', 0)
            ->assertJsonPath('data.counts.disagree_count', 1);

        $this->actingAs($voter)->postJson($url, ['dimension' => 'helpfulness', 'value' => 1])
            ->assertJsonPath('data.counts.helpful_count', 1)
            ->assertJsonPath('data.counts.disagree_count', 1);

        $this->actingAs($voter)->postJson($url, ['dimension' => 'agreement', 'value' => -1])
            ->assertJsonPath('data.value', null)
            ->assertJsonPath('data.counts.disagree_count', 0);

        $comment->refresh();
        $this->assertSame([0, 0, 1], [$comment->agree_count, $comment->disagree_count, $comment->helpful_count]);
        $this->assertGreaterThan(0, $comment->helpful_score);
        $this->assertSame(1, Vote::query()->count());
        $this->assertSame('u:'.$voter->id, Vote::query()->sole()->voter_key);

        Event::assertDispatched(VoteCast::class, fn (VoteCast $event): bool => $event->value === null && $event->previousValue === -1);
    }

    public function test_nobody_votes_on_their_own_content(): void
    {
        $author = $this->user();
        $comment = $this->comment(['user_id' => $author->id]);
        $review = Review::factory()->forSubject($this->product())->create(['user_id' => $author->id]);

        $this->actingAs($author)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot vote on your own contribution.');
        $this->actingAs($author)->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertForbidden();

        $this->assertSame(0, Vote::query()->count());
        $this->assertSame(0, $comment->fresh()->helpful_count);
    }

    public function test_a_guest_on_the_authors_network_cannot_vote_on_it(): void
    {
        $identity = $this->app->make(VoterIdentity::class);
        $network = $identity->networkHash(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));
        $comment = $this->comment(['network_hash' => $network]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'agreement', 'value' => 1])
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'agreement', 'value' => 1])
            ->assertOk();

        $this->assertStringStartsWith('g:', Vote::query()->sole()->voter_key);
        $this->assertNull(Vote::query()->sole()->user_id);
    }

    public function test_guests_vote_only_where_the_section_allows_it(): void
    {
        $this->section(['guest_votes' => false]);
        $comment = $this->comment();

        $this->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'agreement', 'value' => 1])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Please sign in to vote here.');
        $this->assertSame(0, Vote::query()->count());
    }

    public function test_dimensions_and_values_must_be_available(): void
    {
        $comment = $this->comment();
        $review = Review::factory()->forSubject($this->product())->create();
        $voter = $this->user();

        // needs_source is not in the default section dimensions.
        $this->actingAs($voter)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'needs_source', 'value' => 1])->assertUnprocessable();

        $this->section(['vote_dimensions' => ['agreement', 'helpfulness', 'needs_source']]);
        $this->actingAs($voter)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'needs_source', 'value' => -1])->assertUnprocessable();
        $this->actingAs($voter)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'needs_source', 'value' => 1])->assertOk()->assertJsonPath('data.counts.needs_source_count', 1);

        // Reviews only take "helpful".
        $this->actingAs($voter)->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => -1])->assertUnprocessable();
        $this->actingAs($voter)->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertUnprocessable();
        $this->actingAs($voter)->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertOk()->assertJsonPath('data.counts.helpful_count', 1);
        $this->assertSame(1, $review->fresh()->helpful_count);

        $this->actingAs($voter)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'mood', 'value' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dimension', 'value']);
    }

    public function test_hidden_content_cannot_be_voted_on(): void
    {
        $pending = $this->comment(['status' => ContentStatus::Pending]);
        $rejected = Review::factory()->forSubject($this->product())->status(ContentStatus::Rejected)->create();
        $hiddenSubject = Comment::factory()->forSubject($this->product(['is_hidden' => true]))->create();

        $this->actingAs($this->user())->postJson("/rr/comments/{$pending->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertNotFound();
        $this->actingAs($this->user())->postJson("/rr/reviews/{$rejected->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertNotFound();
        $this->actingAs($this->user())->postJson("/rr/comments/{$hiddenSubject->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertNotFound();
    }

    public function test_an_untrusted_vote_is_stored_but_not_counted(): void
    {
        $this->app->instance(RiskSignals::class, new class implements RiskSignals
        {
            public function forVote(VoteContext $context): RiskVerdict
            {
                return (clone $context->existingVotes)->where('counted', true)->exists()
                    ? RiskVerdict::uncounted('network_cap')
                    : RiskVerdict::counts();
            }

            public function forContribution(ContributionContext $context): RiskVerdict
            {
                return RiskVerdict::counts();
            }
        });

        $comment = $this->comment();
        $first = $this->user();
        $second = $this->user();

        $this->actingAs($first)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertJsonPath('data.counted', true);
        $this->actingAs($second)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])
            ->assertOk()
            ->assertJsonPath('data.counted', false)
            ->assertJsonPath('data.value', 1)
            ->assertJsonPath('data.counts.helpful_count', 1);

        $uncounted = Vote::query()->where('user_id', $second->id)->sole();
        $this->assertFalse($uncounted->counted);
        $this->assertSame('network_cap', $uncounted->uncounted_reason);
        $this->assertSame(1, $comment->fresh()->helpful_count);
    }

    public function test_counters_are_recounted_from_the_rows(): void
    {
        $comment = $this->comment();
        Comment::query()->whereKey($comment->id)->update(['agree_count' => 50, 'helpful_count' => 50]);

        $this->actingAs($this->user())->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertOk();

        $comment->refresh();
        $this->assertSame(1, $comment->agree_count);
        $this->assertSame(0, $comment->helpful_count);
        $this->assertEqualsWithDelta(Comment::controversy(1, 0), $comment->controversy_score, 1e-6);
    }

    public function test_the_tree_shows_the_viewers_own_votes(): void
    {
        $comment = $this->comment();
        $voter = $this->user();
        $this->actingAs($voter)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'agreement', 'value' => -1]);

        $this->actingAs($voter)->getJson("/rr/subjects/product/{$comment->commentable_id}/comments")
            ->assertJsonPath('data.0.viewer_votes.agreement', -1)
            ->assertJsonPath('data.0.counts.agreement.down', 1);
    }
}
