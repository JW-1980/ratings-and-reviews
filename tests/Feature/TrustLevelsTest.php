<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

class TrustLevelsTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/comments";
    }

    private function contributions(int $userId, int $count, array $attributes = []): void
    {
        Comment::factory()->count($count)->forSubject($this->product())->create(['user_id' => $userId] + $attributes);
    }

    public function test_links_unlock_after_enough_approved_contributions(): void
    {
        $this->configureSection('product', ['trust_levels' => ['link' => 2]]);
        $product = $this->product();
        $user = $this->user();
        $body = '<p>Zie <a href="https://example.test/a">deze bron</a></p>';

        $this->actingAs($user)->postJson($this->url($product->id), ['body' => $body])->assertCreated()->assertJsonPath('data.body_html', '<p>Zie deze bron</p>');
        $this->actingAs($user)->getJson($this->url($product->id))
            ->assertJsonPath('meta.trust.locked', [['key' => 'link', 'required' => 2]])
            ->assertJsonPath('meta.trust.approved_contributions', 1)
            ->assertJsonMissingPath('meta.settings.editor_tools.7');
        $this->assertNotContains('link', $this->actingAs($user)->getJson($this->url($product->id))->json('meta.settings.editor_tools'));

        $this->contributions($user->id, 1);
        $this->actingAs($user)->postJson($this->url($product->id), ['body' => $body])->assertCreated()
            ->assertJsonPath('data.body_html', '<p>Zie <a href="https://example.test/a" rel="nofollow ugc noopener noreferrer">deze bron</a></p>');
        $this->assertContains('link', $this->actingAs($user)->getJson($this->url($product->id))->json('meta.settings.editor_tools'));
    }

    public function test_pending_removed_and_uncounted_contributions_do_not_unlock_anything(): void
    {
        $this->configureSection('product', ['trust_levels' => ['link' => 2]]);
        $user = $this->user();
        $this->contributions($user->id, 1, ['status' => ContentStatus::Pending, 'published_at' => null]);
        $this->contributions($user->id, 1, ['author_removed_at' => now()]);
        $this->contributions($user->id, 1, ['counted' => false]);
        Review::factory()->forSubject($this->product())->create(['user_id' => $user->id, 'counted' => false]);
        $this->contributions($user->id, 1, ['published_at' => null, 'publish_at' => now()->addMinute()]);

        $this->actingAs($user)->getJson($this->url($this->product()->id))->assertJsonPath('meta.trust.approved_contributions', 0);
    }

    public function test_deep_replies_are_flattened_onto_the_first_reply_level_until_unlocked(): void
    {
        $this->configureSection('product', ['trust_levels' => ['deep_reply' => 5]]);
        $product = $this->product();
        $root = $this->commentOn($product);
        $reply = Comment::factory()->replyTo($root)->create();
        $newcomer = $this->user();

        $placed = $this->actingAs($newcomer)->postJson($this->url($product->id), ['body' => 'Antwoord', 'parent_id' => $reply->id])->assertCreated()->json('data');

        $this->assertSame(1, $placed['depth']);
        $this->assertSame($root->id, $placed['parent_id']);
        $this->assertSame($reply->id, $placed['in_reply_to_id']);

        $this->contributions($newcomer->id, 5);
        $deep = $this->actingAs($newcomer)->postJson($this->url($product->id), ['body' => 'Nu dieper', 'parent_id' => $reply->id])->json('data');
        $this->assertSame(2, $deep['depth']);
        $this->assertSame($reply->id, $deep['parent_id']);
    }

    public function test_polls_need_their_trust_level_and_moderators_are_exempt_from_all(): void
    {
        $this->configureSection('product', ['trust_levels' => ['poll' => 3, 'link' => 3]]);
        $product = $this->product();
        $poll = ['question' => 'Ja of nee?', 'options' => ['Ja', 'Nee']];

        $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => 'Stem', 'poll' => $poll])
            ->assertUnprocessable()
            ->assertJsonPath('errors.poll.0', 'This becomes available after 3 approved contributions.');

        $moderator = $this->moderator();
        $this->actingAs($moderator)->postJson($this->url($product->id), ['body' => '<p><a href="https://x.test">bron</a></p>', 'poll' => $poll])->assertCreated()
            ->assertJsonPath('data.body_html', '<p><a href="https://x.test" rel="nofollow ugc noopener noreferrer">bron</a></p>')
            ->assertJsonPath('data.poll.question', 'Ja of nee?');
        $this->actingAs($moderator)->getJson($this->url($product->id))->assertJsonPath('meta.trust.exempt', true)->assertJsonPath('meta.trust.locked', []);
    }

    public function test_review_bodies_follow_the_same_trust_levels(): void
    {
        $this->configureSection('product', ['trust_levels' => ['link' => 1]]);
        $product = $this->product();

        $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'body' => '<p><a href="https://x.test">winkel</a></p>'])
            ->assertCreated()->assertJsonPath('data.body_html', '<p>winkel</p>');

        // That approved review is the author's first contribution; a newcomer still sees the tool locked.
        $this->actingAs($this->user())->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonPath('meta.editor.locked.0.key', 'link');
    }
}
