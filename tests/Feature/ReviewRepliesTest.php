<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

/**
 * A review is a Commentable subject (morph alias rr_review) when reviews.replies is on.
 */
class ReviewRepliesTest extends TestCase
{
    private function enable(): void
    {
        config([
            'ratings-and-reviews.reviews.replies.enabled' => true,
            'ratings-and-reviews.sections.review_replies' => ['max_depth' => 1, 'vote_dimensions' => ['helpfulness']],
        ]);
        $this->app->make(SectionSettingsRepository::class)->forget('review_replies');
    }

    private function url(Review $review): string
    {
        return "/rr/subjects/rr_review/{$review->id}/comments";
    }

    public function test_replies_are_off_by_default(): void
    {
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create();

        $this->getJson("/rr/subjects/product/{$product->id}/reviews")
            ->assertJsonPath('data.0.replies_count', null)
            ->assertJsonPath('data.0.can.reply', false)
            ->assertJsonPath('meta.features.review_replies', false);
        $this->getJson($this->url($review))->assertNotFound();
        $this->actingAs($this->user())->postJson($this->url($review), ['body' => 'Eens'])->assertNotFound();
    }

    public function test_members_reply_to_a_review_through_the_comment_endpoints(): void
    {
        Event::fake([CommentPosted::class]);
        $this->enable();
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create(['user_id' => $this->user()->id]);
        $replier = $this->user();

        $id = $this->actingAs($replier)->postJson($this->url($review), ['body' => 'Herkenbaar, bij mij ook.'])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->user())->postJson($this->url($review), ['body' => 'Antwoord op het antwoord', 'parent_id' => $id])->assertCreated();
        Comment::factory()->forSubject($review)->status(ContentStatus::Pending)->create(['section' => 'review_replies']);

        $reply = Comment::query()->findOrFail($id);
        $this->assertSame('review_replies', $reply->section);
        $this->assertSame('rr_review', $reply->commentable_type);
        Event::assertDispatched(CommentPosted::class, fn (CommentPosted $event): bool => $event->comment->is($reply));

        $this->getJson("/rr/subjects/product/{$product->id}/reviews")
            ->assertJsonPath('data.0.replies_count', 2)
            ->assertJsonPath('data.0.can.reply', true)
            ->assertJsonPath('meta.features.review_replies', true)
            ->assertJsonPath('meta.review_replies_section', 'review_replies');

        $tree = $this->getJson($this->url($review))->assertOk();
        $tree->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('meta.settings.max_depth', 1)
            ->assertJsonPath('meta.settings.vote_dimensions', ['helpfulness']);
        $this->assertCount(1, $tree->json('data.0.replies'));

        $this->actingAs($this->user())->postJson("/rr/comments/{$id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertOk();
        $this->actingAs($this->user())->postJson("/rr/comments/{$id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertUnprocessable();
    }

    public function test_replies_follow_the_visibility_of_the_review(): void
    {
        $this->enable();
        $product = $this->product();
        $pending = Review::factory()->forSubject($product)->status(ContentStatus::Pending)->create();
        $closed = Review::factory()->forSubject($this->product(['is_open' => false]))->create();
        $hidden = Review::factory()->forSubject($this->product(['is_hidden' => true]))->create();

        $this->getJson($this->url($pending))->assertNotFound();
        $this->getJson($this->url($hidden))->assertNotFound();
        $this->getJson($this->url($closed))->assertOk()->assertJsonPath('meta.accepts_comments', false);
        $this->actingAs($this->user())->postJson($this->url($closed), ['body' => 'Te laat'])->assertUnprocessable();
    }

    public function test_reply_moderation_uses_the_reply_section(): void
    {
        $this->enable();
        $review = Review::factory()->forSubject($this->product())->create();
        $reply = Comment::factory()->forSubject($review)->status(ContentStatus::Pending)->create(['section' => 'review_replies']);

        $this->actingAs($this->moderator())->getJson('/rr/admin/moderation?kind=comments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reply->id)
            ->assertJsonPath('data.0.subject', ['type' => 'rr_review', 'id' => $review->id])
            ->assertJsonPath('meta.sections', ['review_replies']);
    }
}
