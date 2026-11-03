<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\TopicRating;
use RatingsAndReviews\Services\Erasure;
use RatingsAndReviews\Services\Participation;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\Fixtures\GroupPost;
use RatingsAndReviews\Tests\TestCase;

/**
 * The optional subject hooks: ParticipationRules, ScopedModeration, HasDiscussionOwner,
 * HasDiscussionContext and SyncsCommentCount (fixture GroupPost).
 */
class ParticipationHooksTest extends TestCase
{
    private function groupPost(array $attributes = []): GroupPost
    {
        return GroupPost::query()->create($attributes + ['name' => 'Group post']);
    }

    private function comments(GroupPost $post): string
    {
        return "/rr/subjects/group_post/{$post->id}/comments";
    }

    public function test_only_participants_write_while_everyone_who_sees_it_reads(): void
    {
        $post = $this->groupPost();
        $member = $this->user();
        $outsider = $this->user();
        GroupPost::$members = [$member->id];

        $this->actingAs($outsider)->postJson($this->comments($post), ['body' => 'Mag ik meedoen?'])
            ->assertForbidden()->assertJsonPath('message', __('ratings-and-reviews::messages.participation_denied'));
        $this->assertSame(0, Comment::query()->count());

        $this->actingAs($outsider)->getJson($this->comments($post))->assertOk()
            ->assertJsonPath('meta.can_participate', false)
            ->assertJsonPath('meta.accepts_comments', false);
        $this->actingAs($member)->getJson($this->comments($post))->assertOk()
            ->assertJsonPath('meta.can_participate', true)
            ->assertJsonPath('meta.accepts_comments', true);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->comments($post))->assertOk()->assertJsonPath('meta.can_participate', null);

        $this->actingAs($member)->postJson($this->comments($post), ['body' => 'Ik doe mee.'])->assertCreated();

        $this->actingAs($outsider)->postJson("/rr/subjects/group_post/{$post->id}/reviews", ['rating' => 4, 'body' => 'Goed'])->assertForbidden();
        $this->actingAs($member)->postJson("/rr/subjects/group_post/{$post->id}/reviews", ['rating' => 4, 'body' => 'Goed'])->assertCreated();
        $this->actingAs($outsider)->getJson("/rr/subjects/group_post/{$post->id}/reviews")
            ->assertJsonPath('meta.can_participate', false)
            ->assertJsonPath('meta.accepts_reviews', false);
    }

    public function test_outsiders_cannot_rate_topics_either(): void
    {
        $this->configureSection('product', ['standalone_topic_ratings' => true]);
        $post = $this->groupPost();
        $topic = RatingTopic::factory()->create(['scope' => 'product']);
        GroupPost::$members = [];

        $this->actingAs($this->user())->putJson("/rr/subjects/group_post/{$post->id}/topic-ratings", ['ratings' => [$topic->id => 4]])->assertForbidden();
        $this->assertSame(0, TopicRating::query()->count());
    }

    public function test_the_subject_forces_or_skips_pre_moderation(): void
    {
        config(['ratings-and-reviews.spam.hold_phrases' => ['gratis proefpakket']]);
        $post = $this->groupPost();
        $held = $this->user();
        $trusted = $this->user();
        $regular = $this->user();
        GroupPost::$holds = [$held->id => true, $trusted->id => false];

        $this->actingAs($held)->postJson($this->comments($post), ['body' => 'Een gewone reactie.'])->assertCreated()->assertJsonPath('data.visibility', 'pending');
        $this->actingAs($trusted)->postJson($this->comments($post), ['body' => 'Vraag een gratis proefpakket aan.'])->assertCreated()->assertJsonPath('data.visibility', 'public');
        $this->actingAs($regular)->postJson($this->comments($post), ['body' => 'Vraag een gratis proefpakket aan.'])->assertCreated()->assertJsonPath('data.visibility', 'pending');

        // A hard refusal still refuses, also for a member whose holds are skipped.
        config(['ratings-and-reviews.spam.blocked_phrases' => ['koop nu']]);
        $this->actingAs($trusted)->postJson($this->comments($post), ['body' => 'Koop nu bij ons.'])->assertUnprocessable();

        // Reviews: the section requires approval, but the subject skips it for this member.
        $this->configureSection('product', ['review_requires_approval' => true]);
        $this->actingAs($trusted)->postJson("/rr/subjects/group_post/{$post->id}/reviews", ['rating' => 5, 'body' => 'Prima'])->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->actingAs($regular)->postJson("/rr/subjects/group_post/{$post->id}/reviews", ['rating' => 5, 'body' => 'Prima'])->assertCreated()->assertJsonPath('data.status', 'pending');

        // Editing an approved comment: a forced hold sends it back to moderation.
        $comment = Comment::query()->where('user_id', $trusted->id)->firstOrFail();
        GroupPost::$holds[$trusted->id] = true;
        $this->actingAs($trusted)->patchJson("/rr/comments/{$comment->id}", ['body' => 'Aangepaste tekst.'])->assertOk();
        $this->assertSame(ContentStatus::Pending, $comment->fresh()->status);
    }

    public function test_scoped_moderators_moderate_items_of_their_subject_only(): void
    {
        $post = $this->groupPost();
        $other = $this->groupPost(['name' => 'Another post']);
        $scoped = $this->user();
        GroupPost::$moderators = [$post->id => [$scoped->id]];
        $comment = Comment::factory()->forSubject($post)->create();
        $elsewhere = Comment::factory()->forSubject($other)->create();
        $foreign = Comment::factory()->forSubject($this->product())->create();

        $this->actingAs($scoped)->getJson($this->comments($post))->assertJsonPath('data.0.can.edit', true)->assertJsonPath('data.0.can.delete', true);
        $this->actingAs($scoped)->patchJson("/rr/admin/moderation/comments/{$comment->id}", ['action' => 'reject'])->assertOk();
        $this->assertSame(ContentStatus::Rejected, $comment->fresh()->status);

        $this->actingAs($scoped)->patchJson("/rr/admin/discussions/group_post/{$post->id}", ['locked' => true])->assertOk()->assertJsonPath('data.locked', true);
        $this->actingAs($scoped)->postJson($this->comments($post), ['body' => 'Moderator in een gesloten draad.'])->assertCreated();

        $this->actingAs($scoped)->deleteJson("/rr/comments/{$elsewhere->id}")->assertForbidden();
        $this->actingAs($scoped)->patchJson("/rr/admin/moderation/comments/{$foreign->id}", ['action' => 'reject'])->assertForbidden();
        // The inboxes stay with rr.moderate.
        $this->actingAs($scoped)->getJson('/rr/admin/moderation?kind=comments')->assertForbidden();

        $this->assertTrue($this->app->make(Participation::class)->mayModerate($scoped, $post));
        $this->assertFalse($this->app->make(Participation::class)->mayModerate($scoped, $other));
    }

    public function test_comment_posted_names_the_owner_or_the_author_answered(): void
    {
        $owner = $this->user();
        $post = $this->groupPost(['owner_id' => $owner->id]);
        $member = $this->user();
        $replier = $this->user();
        $events = [];
        Event::listen(CommentPosted::class, function (CommentPosted $event) use (&$events): void {
            $events[$event->comment->id] = $event->recipients;
        });

        $root = $this->actingAs($member)->postJson($this->comments($post), ['body' => 'Een nieuwe draad.'])->json('data.id');
        $reply = $this->actingAs($replier)->postJson($this->comments($post), ['body' => 'Een antwoord.', 'parent_id' => $root])->json('data.id');
        $ownRoot = $this->actingAs($owner)->postJson($this->comments($post), ['body' => 'De eigenaar zelf.'])->json('data.id');

        $this->assertSame([['user_id' => $owner->id, 'reason' => 'owner']], $events[$root]);
        $this->assertSame([['user_id' => $member->id, 'reason' => 'reply']], $events[$reply]);
        $this->assertSame([], $events[$ownRoot]);
    }

    public function test_a_reply_to_a_review_names_the_review_author(): void
    {
        config(['ratings-and-reviews.reviews.replies.enabled' => true]);
        $author = $this->user();
        $review = Review::factory()->forSubject($this->product())->create(['user_id' => $author->id]);
        Event::fake([CommentPosted::class]);

        $this->actingAs($this->user())->postJson("/rr/subjects/rr_review/{$review->id}/comments", ['body' => 'Goede review.'])->assertCreated();

        Event::assertDispatched(CommentPosted::class, fn (CommentPosted $event): bool => $event->recipients === [['user_id' => $author->id, 'reason' => 'owner']]);
        $this->getJson("/rr/subjects/rr_review/{$review->id}/comments")
            ->assertJsonPath('meta.subject.context_url', '/products/'.$review->reviewable_id);
    }

    public function test_the_host_counter_follows_every_change_of_public_comments(): void
    {
        $post = $this->groupPost();
        $author = $this->user();

        $first = $this->actingAs($author)->postJson($this->comments($post), ['body' => 'Eerste reactie.'])->json('data.id');
        $this->actingAs($this->user())->postJson($this->comments($post), ['body' => 'Tweede reactie.', 'parent_id' => $first])->assertCreated();
        $this->assertSame(2, $post->fresh()->comment_count);

        $this->actingAs($this->moderator())->patchJson("/rr/admin/moderation/comments/{$first}", ['action' => 'reject'])->assertOk();
        $this->assertSame(1, $post->fresh()->comment_count);

        $this->actingAs($this->moderator())->patchJson("/rr/admin/moderation/comments/{$first}", ['action' => 'approve'])->assertOk();
        $this->assertSame(2, $post->fresh()->comment_count);

        // The author removes a comment with a reply: the placeholder no longer counts.
        $this->actingAs($author)->deleteJson("/rr/comments/{$first}")->assertNoContent();
        $this->assertSame(1, $post->fresh()->comment_count);

        $this->app->make(Erasure::class)->withhold(Comment::query()->whereNotNull('parent_id')->value('user_id'));
        $this->assertSame(0, $post->fresh()->comment_count);
    }

    public function test_the_comment_list_names_the_subject_and_its_context(): void
    {
        $post = $this->groupPost(['name' => 'Wie kookt er mee?']);

        $this->getJson($this->comments($post))->assertOk()
            ->assertJsonPath('meta.subject.title', 'Wie kookt er mee?')
            ->assertJsonPath('meta.subject.url', '/products/'.$post->id)
            ->assertJsonPath('meta.subject.context_url', '/groups/'.$post->id);
        $product = $this->product();
        $this->getJson("/rr/subjects/product/{$product->id}/comments")->assertJsonPath('meta.subject.context_url', '/products/'.$product->id);
    }
}
