<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\PollVote;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Tests\TestCase;

class PollTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/comments";
    }

    private function postPoll(?int $productId = null, array $poll = ['question' => 'Warm of koud?', 'options' => ['Warm', 'Koud', 'Allebei']]): array
    {
        $productId ??= $this->product()->id;

        return $this->actingAs($this->user())->postJson($this->url($productId), ['body' => 'Hoe drinken jullie het?', 'poll' => $poll])->assertCreated()->json('data');
    }

    public function test_a_poll_is_created_with_its_comment_and_shown_in_the_tree(): void
    {
        $product = $this->product();
        $comment = $this->postPoll($product->id);

        $this->assertSame('Warm of koud?', $comment['poll']['question']);
        $this->assertSame(['Warm', 'Koud', 'Allebei'], array_column($comment['poll']['options'], 'label'));
        $this->assertSame(0, $comment['poll']['total_votes']);
        $this->assertNull($comment['poll']['viewer_option_id']);

        $this->getJson($this->url($product->id))->assertJsonPath('data.0.poll.id', $comment['poll']['id'])->assertJsonPath('data.0.poll.is_closed', false);
    }

    public function test_invalid_poll_definitions_are_refused_with_the_comment(): void
    {
        $product = $this->product();
        $invalid = [
            [['question' => 'Vraag?', 'options' => ['Alleen']], 'poll.options'],
            [['question' => 'Vraag?', 'options' => ['A', 'B', 'C', 'D', 'E']], 'poll.options'],
            [['question' => 'Vraag?', 'options' => ['Ja', ' ja ']], 'poll.options'],
            [['question' => 'Vraag?', 'options' => ['A', str_repeat('b', 121)]], 'poll.options'],
            [['question' => '   ', 'options' => ['A', 'B']], 'poll.question'],
            [['question' => 'Vraag?', 'options' => ['A', 'B'], 'closes_at' => now()->subDay()->toIso8601String()], 'poll.closes_at'],
            [['question' => 'Vraag?', 'options' => ['A', 'B'], 'closes_at' => now()->addDays(400)->toIso8601String()], 'poll.closes_at'],
            [['options' => ['A', 'B']], 'poll.question'],
        ];

        foreach ($invalid as [$poll, $error]) {
            $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => 'Peiling', 'poll' => $poll])->assertUnprocessable()->assertJsonValidationErrors($error);
        }

        config(['ratings-and-reviews.polls.enabled' => false]);
        $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => 'Peiling', 'poll' => ['question' => 'V?', 'options' => ['A', 'B']]])->assertUnprocessable()->assertJsonValidationErrors('poll');

        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(0, Poll::query()->count());
    }

    public function test_one_vote_per_voter_that_can_switch_but_not_double(): void
    {
        $poll = $this->postPoll()['poll'];
        [$warm, $cold] = array_column($poll['options'], 'id');
        $voter = $this->user();

        $this->actingAs($voter)->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $warm])->assertOk()
            ->assertJsonPath('data.total_votes', 1)
            ->assertJsonPath('data.viewer_option_id', $warm)
            ->assertJsonPath('data.options.0.percentage', 100);
        $this->actingAs($voter)->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $warm])->assertOk()->assertJsonPath('meta.changed', false)->assertJsonPath('data.total_votes', 1);
        $this->actingAs($voter)->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $cold])->assertOk()
            ->assertJsonPath('data.total_votes', 1)
            ->assertJsonPath('data.options.0.votes', 0)
            ->assertJsonPath('data.options.1.votes', 1);

        $this->actingAs($this->user())->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $cold])->assertJsonPath('data.total_votes', 2)->assertJsonPath('data.options.1.percentage', 100);
        $this->assertSame(2, PollVote::query()->count());
        $this->actingAs($voter)->getJson("/rr/polls/{$poll['id']}")->assertOk()->assertJsonPath('data.viewer_option_id', $cold);
    }

    public function test_guests_vote_only_where_the_section_allows_guest_votes(): void
    {
        $poll = $this->postPoll()['poll'];
        $option = $poll['options'][0]['id'];
        auth()->forgetGuards();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $option])->assertOk()->assertJsonPath('data.total_votes', 1);
        $this->assertStringStartsWith('g:', PollVote::query()->sole()->voter_key);

        $this->configureSection('product', ['guest_votes' => false]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $option])->assertUnauthorized();
    }

    public function test_closed_foreign_option_and_unpublished_polls_cannot_be_voted_on(): void
    {
        $poll = $this->postPoll()['poll'];
        $other = $this->postPoll()['poll'];

        $this->actingAs($this->user())->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $other['options'][0]['id']])->assertUnprocessable()->assertJsonValidationErrors('option_id');
        $this->actingAs($this->user())->postJson("/rr/polls/{$poll['id']}/votes", [])->assertUnprocessable();

        Poll::query()->whereKey($poll['id'])->update(['closes_at' => now()->subMinute()]);
        $this->actingAs($this->user())->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $poll['options'][0]['id']])
            ->assertUnprocessable()->assertJsonPath('errors.option_id.0', 'This poll is closed.');

        Comment::query()->whereKey(Poll::query()->find($other['id'])->comment_id)->update(['status' => 'rejected']);
        $this->actingAs($this->user())->postJson("/rr/polls/{$other['id']}/votes", ['option_id' => $other['options'][0]['id']])->assertNotFound();
        $this->getJson("/rr/polls/{$other['id']}")->assertNotFound();
        $this->getJson('/rr/polls/999999')->assertNotFound();
        $this->assertSame(0, PollVote::query()->count());
    }

    public function test_an_uncounted_poll_vote_is_stored_but_left_out_of_the_results(): void
    {
        $poll = $this->postPoll()['poll'];
        $this->app->instance(RiskSignals::class, new class implements RiskSignals
        {
            public function forVote(VoteContext $context): RiskVerdict
            {
                return RiskVerdict::counts();
            }

            public function forPollVote(PollVoteContext $context): RiskVerdict
            {
                return $context->poll->votes()->where('counted', true)->exists() ? RiskVerdict::uncounted('device_cap') : RiskVerdict::counts();
            }

            public function forContribution(ContributionContext $context): RiskVerdict
            {
                return RiskVerdict::counts();
            }
        });

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->user())->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $poll['options'][0]['id']])->assertOk();
        }

        $this->assertSame(1, $this->getJson("/rr/polls/{$poll['id']}")->json('data.total_votes'));
        $this->assertSame(['device_cap'], PollVote::query()->where('counted', false)->pluck('uncounted_reason')->all());
    }

    public function test_the_poll_goes_when_its_author_removes_the_comment(): void
    {
        $product = $this->product();
        $author = $this->user();
        $id = $this->actingAs($author)->postJson($this->url($product->id), ['body' => 'Peiling', 'poll' => ['question' => 'V?', 'options' => ['A', 'B']]])->json('data.id');
        Comment::factory()->replyTo(Comment::query()->find($id))->create();

        $this->actingAs($author)->deleteJson("/rr/comments/{$id}")->assertNoContent();

        $this->assertSame(0, Poll::query()->count());
        $this->getJson($this->url($product->id))->assertJsonPath('data.0.poll', null);
    }
}
