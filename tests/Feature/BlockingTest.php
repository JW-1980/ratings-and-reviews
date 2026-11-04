<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Contracts\BlockList;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Tests\Fixtures\ArrayBlockList;
use RatingsAndReviews\Tests\Fixtures\GroupPost;
use RatingsAndReviews\Tests\TestCase;

/**
 * Member blocks (BlockList): no replies, quotes or votes across a block, nobody told across a
 * block, and blocked members hidden where the subject asks for it (HidesBlockedMembers).
 */
class BlockingTest extends TestCase
{
    private ArrayBlockList $blocks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blocks = new ArrayBlockList;
        $this->app->instance(BlockList::class, $this->blocks);
    }

    public function test_nobody_replies_to_or_quotes_a_member_across_a_block(): void
    {
        $product = $this->product();
        $author = $this->user();
        $blocked = $this->user();
        $comment = Comment::factory()->forSubject($product)->create(['user_id' => $author->id, 'body_text' => 'Romig en zoet van smaak', 'body_html' => '<p>Romig en zoet van smaak</p>']);
        $this->blocks->block($author->id, $blocked->id);
        $url = "/rr/subjects/product/{$product->id}/comments";

        $this->actingAs($blocked)->postJson($url, ['body' => 'Niet mee eens.', 'parent_id' => $comment->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id' => __('ratings-and-reviews::messages.reply_blocked')]);
        $this->actingAs($blocked)->postJson($url, ['body' => 'Citaat', 'quoted_comment_id' => $comment->id, 'quote_text' => 'Romig en zoet'])
            ->assertUnprocessable()->assertJsonValidationErrors('quoted_comment_id');
        $this->assertSame(1, Comment::query()->count());

        // Someone else may reply; the block is between these two only.
        $this->actingAs($this->user())->postJson($url, ['body' => 'Mee eens.', 'parent_id' => $comment->id])->assertCreated();

        // The viewer sees the comment marked, with reply and vote switched off.
        $this->actingAs($blocked)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.is_blocked', true)
            ->assertJsonPath('data.0.can.reply', false)
            ->assertJsonPath('data.0.can.vote', false)
            ->assertJsonPath('data.0.replies.0.is_blocked', false);
        $this->actingAs($this->user())->getJson($url)->assertJsonPath('data.0.is_blocked', false)->assertJsonPath('data.0.can.reply', true);
    }

    public function test_nobody_votes_on_a_member_across_a_block(): void
    {
        $product = $this->product();
        $author = $this->user();
        $voter = $this->user();
        $comment = Comment::factory()->forSubject($product)->create(['user_id' => $author->id]);
        $review = Review::factory()->forSubject($product)->create(['user_id' => $author->id]);
        $this->blocks->block($voter->id, $author->id);

        $this->actingAs($voter)->postJson("/rr/comments/{$comment->id}/votes", ['dimension' => 'agreement', 'value' => 1])
            ->assertForbidden()->assertJsonPath('message', __('ratings-and-reviews::messages.vote_blocked'));
        $this->actingAs($voter)->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertForbidden();
        $this->assertSame(0, Vote::query()->count());

        $poll = $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => 'Hoe drink je het?', 'poll' => ['question' => 'Warm of koud?', 'options' => ['Warm', 'Koud']]])
            ->assertCreated()->json('data.poll');
        $this->actingAs($voter)->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $poll['options'][0]['id']])->assertForbidden();
        $this->actingAs($this->user())->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $poll['options'][0]['id']])->assertOk();

        $this->actingAs($voter)->getJson("/rr/subjects/product/{$product->id}/reviews")
            ->assertJsonPath('data.0.is_blocked', true)->assertJsonPath('data.0.can.vote', false);
    }

    public function test_nobody_replies_to_the_review_of_a_member_across_a_block(): void
    {
        config(['ratings-and-reviews.reviews.replies.enabled' => true]);
        $author = $this->user();
        $blocked = $this->user();
        $review = Review::factory()->forSubject($this->product())->create(['user_id' => $author->id]);
        $this->blocks->block($blocked->id, $author->id);

        $this->actingAs($blocked)->postJson("/rr/subjects/rr_review/{$review->id}/comments", ['body' => 'Een vraag over je review.'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($blocked)->getJson("/rr/subjects/product/{$review->reviewable_id}/reviews")->assertJsonPath('data.0.can.reply', false);
        $this->actingAs($this->user())->postJson("/rr/subjects/rr_review/{$review->id}/comments", ['body' => 'Een vraag over je review.'])->assertCreated();
    }

    public function test_the_owner_is_not_told_about_a_comment_across_a_block(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $post = GroupPost::query()->create(['name' => 'Post', 'owner_id' => $owner->id]);
        $this->blocks->block($owner->id, $member->id);
        GroupPost::$hideBlocked = false;
        Event::fake([CommentPosted::class]);

        $this->actingAs($member)->postJson("/rr/subjects/group_post/{$post->id}/comments", ['body' => 'Hallo allemaal.'])->assertCreated();

        Event::assertDispatched(CommentPosted::class, fn (CommentPosted $event): bool => $event->recipients === []);
    }

    public function test_digest_revisions_and_review_threads_do_not_leak_blocked_content(): void
    {
        config(['ratings-and-reviews.reviews.replies.enabled' => true]);
        $this->configureSection('product', ['digest_threshold' => 1]);
        $post = GroupPost::query()->create(['name' => 'Post']);
        $viewer = $this->user();
        $blocked = $this->user();
        $this->blocks->block($viewer->id, $blocked->id);

        $comment = Comment::factory()->forSubject($post)->create([
            'user_id' => $blocked->id,
            'body_text' => 'Dit mag de geblokkeerde niet meer laten zien.',
            'body_html' => '<p>Dit mag de geblokkeerde niet meer laten zien.</p>',
            'agree_count' => 9,
        ]);
        $review = Review::factory()->forSubject($post)->create(['user_id' => $blocked->id]);

        $this->actingAs($viewer)->getJson("/rr/subjects/group_post/{$post->id}/digest")->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.total_comments', 0);
        $this->actingAs($viewer)->getJson("/rr/comments/{$comment->id}/revisions")->assertNotFound();
        $this->actingAs($viewer)->getJson("/rr/subjects/rr_review/{$review->id}/comments")->assertNotFound();
    }

    public function test_a_subject_that_hides_blocked_members_leaves_them_out_for_the_viewer(): void
    {
        $post = GroupPost::query()->create(['name' => 'Post']);
        $viewer = $this->user();
        $blocked = $this->user();
        $other = $this->user();
        $this->blocks->block($viewer->id, $blocked->id);

        $hiddenRoot = Comment::factory()->forSubject($post)->create(['user_id' => $blocked->id]);
        Comment::factory()->replyTo($hiddenRoot)->create(['user_id' => $other->id]);
        $root = Comment::factory()->forSubject($post)->create(['user_id' => $other->id]);
        $hiddenReply = Comment::factory()->replyTo($root)->create(['user_id' => $blocked->id]);
        Comment::factory()->replyTo($hiddenReply)->create(['user_id' => $other->id]);
        Review::factory()->forSubject($post)->create(['user_id' => $blocked->id]);
        Review::factory()->forSubject($post)->create(['user_id' => $other->id]);
        $url = "/rr/subjects/group_post/{$post->id}/comments";

        $seen = $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data');
        $this->assertSame($root->id, $seen->json('data.0.id'));
        $this->assertSame([], $seen->json('data.0.replies'), 'The blocked reply goes with the reply beneath it.');
        $this->actingAs($viewer)->getJson("/rr/comments/{$hiddenRoot->id}/replies")->assertNotFound();
        $this->actingAs($viewer)->getJson("/rr/comments/{$root->id}/replies")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->getJson("/rr/subjects/group_post/{$post->id}/reviews")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.author.name', $other->name);

        $this->actingAs($other)->getJson($url)->assertJsonPath('meta.total', 2);
        $this->actingAs($other)->getJson("/rr/subjects/group_post/{$post->id}/reviews")->assertJsonPath('meta.total', 2);
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertJsonPath('meta.total', 2);

        GroupPost::$hideBlocked = false;
        $this->actingAs($viewer)->getJson($url)->assertJsonPath('meta.total', 2);
    }

    public function test_the_block_list_is_asked_once_per_request(): void
    {
        $product = $this->product();
        $viewer = $this->user();
        Comment::factory()->count(5)->forSubject($product)->create();

        $this->actingAs($viewer)->getJson("/rr/subjects/product/{$product->id}/comments")->assertOk();

        $this->assertSame(1, $this->blocks->lookups);
    }
}
