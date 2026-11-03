<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\PollVote;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Tests\TestCase;

/**
 * Host data from the RiskSignals verdict is stored with the vote, and the voter key is
 * asked per voted item, for writes and for showing the viewer's own votes.
 */
class VoteMetaAndVoterKeysTest extends TestCase
{
    private function bindMetaRiskSignals(): void
    {
        $this->app->instance(RiskSignals::class, new class implements RiskSignals
        {
            public function forVote(VoteContext $context): RiskVerdict
            {
                return RiskVerdict::uncounted('duplicate_device')->withMeta(['hardware' => 'h-'.$context->votable->getKey(), 'dimension' => $context->dimension->value]);
            }

            public function forPollVote(PollVoteContext $context): RiskVerdict
            {
                return RiskVerdict::counts()->withMeta(['poll' => $context->poll->id]);
            }

            public function forContribution(ContributionContext $context): RiskVerdict
            {
                return RiskVerdict::counts()->withMeta(['ignored' => true]);
            }
        });
    }

    /**
     * A guest whose voice is per item: `g:<type>-<id>`, recording every item it was asked for.
     *
     * @param  list<string>  $asked
     */
    private function bindPerItemIdentity(array &$asked): void
    {
        $this->app->instance(VoterIdentity::class, new class($asked) implements VoterIdentity
        {
            /** @param  list<string>  $asked */
            public function __construct(private array &$asked) {}

            public function voterKey(Request $request, ?Authenticatable $user, ?Model $votable = null): ?string
            {
                $this->asked[] = $votable === null ? 'none' : $votable->getMorphClass().':'.$votable->getKey();

                return $votable === null ? null : 'g:'.$votable->getMorphClass().'-'.$votable->getKey();
            }

            public function networkHash(Request $request): ?string
            {
                return 'net';
            }

            public function deviceHash(Request $request): ?string
            {
                return null;
            }

            public function ipHash(Request $request): ?string
            {
                return null;
            }
        });
    }

    public function test_the_verdict_meta_is_stored_with_a_new_vote_and_kept_when_switching(): void
    {
        $this->bindMetaRiskSignals();
        $comment = Comment::factory()->forSubject($this->product())->create(['user_id' => $this->user()->id]);
        $voter = $this->user();
        $url = "/rr/comments/{$comment->id}/votes";

        $this->actingAs($voter)->postJson($url, ['dimension' => 'agreement', 'value' => 1])->assertOk()->assertJsonPath('data.counted', false);
        $vote = Vote::query()->sole();
        $this->assertSame(['hardware' => 'h-'.$comment->id, 'dimension' => 'agreement'], $vote->meta);
        $this->assertSame('duplicate_device', $vote->uncounted_reason);

        $this->actingAs($voter)->postJson($url, ['dimension' => 'agreement', 'value' => -1])->assertOk();
        $this->assertSame(['hardware' => 'h-'.$comment->id, 'dimension' => 'agreement'], $vote->refresh()->meta);
        $this->assertSame(-1, $vote->value);
    }

    public function test_a_verdict_without_meta_stores_null(): void
    {
        $review = Review::factory()->forSubject($this->product())->create(['user_id' => $this->user()->id]);

        $this->actingAs($this->user())->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertOk();

        $this->assertNull(Vote::query()->sole()->meta);
    }

    public function test_the_verdict_meta_is_stored_with_a_poll_vote(): void
    {
        $this->bindMetaRiskSignals();
        $product = $this->product();
        $poll = $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => 'Warm of koud?', 'poll' => ['question' => 'Hoe?', 'options' => ['Warm', 'Koud']]])
            ->assertCreated()->json('data.poll');

        $this->actingAs($this->user())->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $poll['options'][0]['id']])->assertOk();

        $this->assertSame(['poll' => $poll['id']], PollVote::query()->sole()->meta);
    }

    public function test_the_voter_key_is_asked_per_item_for_votes_and_for_the_viewers_own_votes(): void
    {
        $asked = [];
        $this->bindPerItemIdentity($asked);
        $product = $this->product();
        $first = Comment::factory()->forSubject($product)->create(['user_id' => $this->user()->id]);
        $second = Comment::factory()->forSubject($product)->create(['user_id' => $this->user()->id]);

        $this->postJson("/rr/comments/{$first->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertOk();
        $this->postJson("/rr/comments/{$second->id}/votes", ['dimension' => 'agreement', 'value' => -1])->assertOk();

        $this->assertSame(['g:rr_comment-'.$first->id, 'g:rr_comment-'.$second->id], Vote::query()->orderBy('id')->pluck('voter_key')->all());
        $this->assertSame(['rr_comment:'.$first->id, 'rr_comment:'.$second->id], $asked);

        $asked = [];
        $tree = $this->getJson("/rr/subjects/product/{$product->id}/comments?sort=oldest")->assertOk();
        $this->assertSame(1, $tree->json('data.0.viewer_votes.agreement'));
        $this->assertSame(-1, $tree->json('data.1.viewer_votes.agreement'));
        $this->assertEqualsCanonicalizing(['rr_comment:'.$first->id, 'rr_comment:'.$second->id], $asked, 'Asked once per item, never without one.');
    }

    public function test_the_review_list_shows_the_viewers_votes_with_the_key_per_review(): void
    {
        $asked = [];
        $this->bindPerItemIdentity($asked);
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create(['user_id' => $this->user()->id]);
        Review::factory()->forSubject($product)->create(['user_id' => $this->user()->id]);

        $this->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertOk();

        $list = $this->getJson("/rr/subjects/product/{$product->id}/reviews?sort=helpful")->assertOk();
        $this->assertSame($review->id, $list->json('data.0.id'));
        $this->assertSame(1, $list->json('data.0.viewer_votes.helpfulness'));
        $this->assertSame([], (array) $list->json('data.1.viewer_votes'));
        $this->assertNotContains('none', $asked);
    }
}
