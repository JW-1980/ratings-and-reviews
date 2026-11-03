<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Jobs\PublishScheduledComment;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Services\CommentPublisher;
use RatingsAndReviews\Support\ContributionContext;
use RatingsAndReviews\Support\PollVoteContext;
use RatingsAndReviews\Support\RiskVerdict;
use RatingsAndReviews\Support\VoteContext;
use RatingsAndReviews\Tests\TestCase;

class UndoWindowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSection('product', ['undo_window_seconds' => 10]);
    }

    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/comments";
    }

    public function test_a_new_comment_stays_private_to_its_author_until_the_window_ends(): void
    {
        Event::fake([CommentPosted::class]);
        $product = $this->product();
        $author = $this->user();

        $comment = $this->actingAs($author)->postJson($this->url($product->id), ['body' => 'Net geplaatst'])->assertCreated()
            ->assertJsonPath('data.visibility', 'scheduled')
            ->assertJsonPath('data.can.undo', true)
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('message', 'Your comment will be posted in 10 seconds. You can still undo it.')
            ->json('data');
        $this->assertNotNull($comment['publish_at']);

        $this->actingAs($author)->getJson($this->url($product->id))->assertJsonPath('data.0.id', $comment['id'])->assertJsonPath('data.0.visibility', 'scheduled')->assertJsonPath('meta.total', 0);
        $this->actingAs($this->user())->getJson($this->url($product->id))->assertJsonCount(0, 'data');
        auth()->forgetGuards();
        $this->getJson($this->url($product->id))->assertJsonCount(0, 'data');
        Event::assertNotDispatched(CommentPosted::class);

        // Not public yet: no votes, replies, reports or hovercard count.
        $other = $this->user();
        $this->actingAs($other)->postJson("/rr/comments/{$comment['id']}/votes", ['dimension' => 'agreement', 'value' => 1])->assertNotFound();
        $this->actingAs($other)->postJson($this->url($product->id), ['body' => 'Reactie', 'parent_id' => $comment['id']])->assertUnprocessable();
        $this->actingAs($other)->postJson('/rr/reports', ['reportable_type' => 'rr_comment', 'reportable_id' => $comment['id'], 'reason' => 'spam'])->assertUnprocessable();
        $this->getJson("/rr/authors/{$author->id}/card")->assertNotFound();
    }

    public function test_the_publisher_publishes_due_comments_and_announces_them_once(): void
    {
        Event::fake([CommentPosted::class]);
        $product = $this->product();
        $id = $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => 'Straks zichtbaar'])->json('data.id');

        $this->artisan('rr:publish-due')->assertSuccessful()->expectsOutputToContain('Published 0 comment(s).');
        Event::assertNotDispatched(CommentPosted::class);

        $this->travel(11)->seconds();
        $this->artisan('rr:publish-due')->assertSuccessful()->expectsOutputToContain('Published 1 comment(s).');
        $this->artisan('rr:publish-due')->assertSuccessful()->expectsOutputToContain('Published 0 comment(s).');

        Event::assertDispatchedTimes(CommentPosted::class, 1);
        Event::assertDispatched(CommentPosted::class, fn (CommentPosted $event): bool => $event->comment->id === $id && $event->comment->published_at !== null);
        $this->actingAs($this->user())->getJson($this->url($product->id))->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.visibility', 'public');
    }

    public function test_the_delayed_job_publishes_only_once_the_window_has_ended(): void
    {
        Event::fake([CommentPosted::class]);
        $id = $this->actingAs($this->user())->postJson($this->url($this->product()->id), ['body' => 'Via de job'])->json('data.id');

        // The sync queue already ran the job at posting time: too early, nothing happened.
        $this->assertNull(Comment::query()->find($id)->published_at);

        $this->travel(10)->seconds();
        PublishScheduledComment::dispatchSync($id);

        $this->assertNotNull(Comment::query()->find($id)->published_at);
        Event::assertDispatchedTimes(CommentPosted::class, 1);
    }

    public function test_the_author_undoes_within_the_window_and_the_comment_is_gone_for_good(): void
    {
        Event::fake([CommentPosted::class]);
        $product = $this->product();
        $author = $this->user();
        Upload::query()->create(['user_id' => $author->id, 'disk' => 'public', 'path' => 'u/a.png']);
        $this->configureSection('product', ['editor_tools' => ['image']]);
        $id = $this->actingAs($author)->postJson($this->url($product->id), ['body' => '<p>Oeps<img src="/storage/u/a.png"></p>', 'poll' => ['question' => 'V?', 'options' => ['A', 'B']]])->json('data.id');
        $this->assertSame($id, Upload::query()->sole()->attached_id);

        $this->actingAs($this->user())->postJson("/rr/comments/{$id}/undo")->assertNotFound();
        $this->actingAs($author)->postJson("/rr/comments/{$id}/undo")->assertNoContent();

        $this->assertNull(Comment::query()->withTrashed()->find($id));
        $this->assertSame(0, Poll::query()->count());
        $this->assertNull(Upload::query()->sole()->attached_id, 'The upload can be used again.');

        $this->travel(11)->seconds();
        $this->artisan('rr:publish-due')->expectsOutputToContain('Published 0 comment(s).');
        Event::assertNotDispatched(CommentPosted::class);
        $this->actingAs($author)->postJson("/rr/comments/{$id}/undo")->assertNotFound();
    }

    public function test_after_the_window_or_after_publication_undo_is_refused(): void
    {
        $product = $this->product();
        $author = $this->user();
        $late = $this->actingAs($author)->postJson($this->url($product->id), ['body' => 'Te laat'])->json('data.id');

        $this->travel(10)->seconds();
        $this->actingAs($author)->postJson("/rr/comments/{$late}/undo")->assertUnprocessable()->assertJsonPath('errors.comment.0', 'This comment can no longer be undone.');

        $this->app->make(CommentPublisher::class)->publishDue();
        $this->actingAs($author)->postJson("/rr/comments/{$late}/undo")->assertUnprocessable();
        $this->assertNotNull(Comment::query()->find($late)->published_at);

        $this->configureSection('product', ['undo_window_seconds' => 0]);
        $instant = $this->actingAs($author)->postJson($this->url($product->id), ['body' => 'Meteen'])->assertJsonPath('data.can.undo', false)->json('data.id');
        $this->actingAs($author)->postJson("/rr/comments/{$instant}/undo")->assertUnprocessable();
    }

    public function test_a_held_comment_is_not_published_by_the_publisher_and_an_approval_inside_the_window_waits_for_it(): void
    {
        Event::fake([CommentPosted::class]);
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
                return RiskVerdict::held('new_device');
            }
        });
        $product = $this->product();
        $id = $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => 'Wacht op moderator'])->assertJsonPath('data.visibility', 'pending')->json('data.id');

        $this->actingAs($this->moderator())->patchJson("/rr/admin/moderation/comments/{$id}", ['action' => 'approve'])->assertOk();
        $this->assertNull(Comment::query()->find($id)->published_at, 'Approved inside the window: the publisher publishes it.');
        Event::assertNotDispatched(CommentPosted::class);

        $this->travel(11)->seconds();
        $this->artisan('rr:publish-due')->expectsOutputToContain('Published 1 comment(s).');
        Event::assertDispatchedTimes(CommentPosted::class, 1);

        $other = $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => 'Nog een'])->json('data.id');
        $this->travel(11)->seconds();
        $this->artisan('rr:publish-due')->expectsOutputToContain('Published 0 comment(s).');
        $this->assertNull(Comment::query()->find($other)->published_at, 'Pending comments wait for a moderator.');

        $this->actingAs($this->moderator())->patchJson("/rr/admin/moderation/comments/{$other}", ['action' => 'approve'])->assertOk();
        $this->assertNotNull(Comment::query()->find($other)->published_at, 'Approved after the window: published at once.');
        Event::assertDispatchedTimes(CommentPosted::class, 2);
    }
}
