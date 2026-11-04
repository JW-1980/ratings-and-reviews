<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Contracts\AttributionResolver;
use RatingsAndReviews\Events\CommentPinned;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\Attribution;
use RatingsAndReviews\Tests\Fixtures\GroupPost;
use RatingsAndReviews\Tests\Fixtures\StaticAttributions;
use RatingsAndReviews\Tests\TestCase;

/**
 * Posting "as" a brand (AttributionResolver) and pinning one top-level comment per discussion.
 */
class AttributionAndPinsTest extends TestCase
{
    private function attributions(): StaticAttributions
    {
        $resolver = new StaticAttributions;
        $this->app->instance(AttributionResolver::class, $resolver);

        return $resolver;
    }

    public function test_a_member_posts_as_an_allowed_brand_only(): void
    {
        $resolver = $this->attributions();
        $member = $this->user();
        $resolver->byUser[$member->id] = [new Attribution('brand:7', 'Oatly', 'https://example.test/brands/7'), new Attribution('bad', ' ')];
        $product = $this->product();
        $url = "/rr/subjects/product/{$product->id}/comments";

        $this->actingAs($member)->getJson($url)->assertJsonPath('meta.attributions', [['key' => 'brand:7', 'label' => 'Oatly', 'url' => 'https://example.test/brands/7']]);
        $this->actingAs($this->user())->getJson($url)->assertJsonPath('meta.attributions', []);

        $id = $this->actingAs($member)->postJson($url, ['body' => 'Officieel antwoord van het merk.', 'attribution' => 'brand:7'])
            ->assertCreated()->assertJsonPath('data.attribution.label', 'Oatly')->json('data.id');
        $this->assertEquals(['key' => 'brand:7', 'label' => 'Oatly', 'url' => 'https://example.test/brands/7'], Comment::query()->findOrFail($id)->attribution);

        // The badge is a snapshot: it stays when the member leaves the team.
        $resolver->byUser = [];
        $this->getJson($url)->assertJsonPath('data.0.attribution.label', 'Oatly');

        $this->actingAs($member)->postJson($url, ['body' => 'Nog een antwoord.', 'attribution' => 'brand:7'])
            ->assertUnprocessable()->assertJsonValidationErrors('attribution');
        $this->actingAs($this->user())->postJson($url, ['body' => 'Ik ben ook het merk.', 'attribution' => 'bad'])->assertUnprocessable();
        $this->actingAs($member)->postJson($url, ['body' => 'Als mezelf.'])->assertCreated()->assertJsonPath('data.attribution', null);
    }

    public function test_without_a_resolver_an_attribution_is_refused(): void
    {
        $product = $this->product();

        $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => 'Namens het merk.', 'attribution' => 'brand:1'])
            ->assertUnprocessable()->assertJsonValidationErrors('attribution');
        $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'attribution' => 'brand:1'])
            ->assertUnprocessable()->assertJsonValidationErrors('attribution');
    }

    public function test_a_review_keeps_its_attribution_on_an_edit_until_it_is_cleared(): void
    {
        $resolver = $this->attributions();
        $member = $this->user();
        $resolver->byUser[$member->id] = [new Attribution('shop:3', 'Bakkerij Jansen')];
        $product = $this->product();

        $id = $this->actingAs($member)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'body' => 'Van de bakker zelf.', 'attribution' => 'shop:3'])
            ->assertCreated()->assertJsonPath('data.attribution.label', 'Bakkerij Jansen')->json('data.id');
        $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonPath('data.0.attribution.key', 'shop:3');

        $this->actingAs($member)->patchJson("/rr/reviews/{$id}", ['rating' => 5, 'body' => 'Nog beter.'])->assertOk()->assertJsonPath('data.attribution.key', 'shop:3');
        $this->actingAs($member)->patchJson("/rr/reviews/{$id}", ['rating' => 5, 'attribution' => null])->assertOk()->assertJsonPath('data.attribution', null);
        $this->assertNull(Review::query()->findOrFail($id)->attribution);
    }

    public function test_a_moderator_pins_one_top_level_comment_that_leads_every_sort(): void
    {
        Event::fake([CommentPinned::class]);
        $product = $this->product();
        $old = Comment::factory()->forSubject($product)->create(['created_at' => now()->subDays(3), 'published_at' => now()->subDays(3)]);
        $new = Comment::factory()->forSubject($product)->create();
        $reply = Comment::factory()->replyTo($new)->create();
        $moderator = $this->moderator();
        $url = "/rr/subjects/product/{$product->id}/comments";

        $this->actingAs($this->user())->postJson("/rr/comments/{$old->id}/pin")->assertForbidden();
        $this->actingAs($moderator)->postJson("/rr/comments/{$reply->id}/pin")->assertUnprocessable();
        $this->actingAs($moderator)->postJson("/rr/comments/{$old->id}/pin")->assertOk()->assertJsonPath('data.is_pinned', true);
        Event::assertDispatched(CommentPinned::class, fn (CommentPinned $event): bool => $event->comment->is($old) && $event->pinned && $event->unpinnedId === null);

        $this->getJson($url.'?sort=newest')->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.0.is_pinned', true)
            ->assertJsonPath('meta.pinned_comment_id', $old->id)->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        $this->actingAs($moderator)->getJson($url)->assertJsonPath('meta.can_pin', true)->assertJsonPath('data.0.can.pin', true);
        $this->actingAs($this->user())->getJson($url)->assertJsonPath('meta.can_pin', false)->assertJsonPath('data.0.can.pin', false);

        // Pinning another takes the pin away from the first.
        $this->actingAs($moderator)->postJson("/rr/comments/{$new->id}/pin")->assertOk();
        Event::assertDispatched(CommentPinned::class, fn (CommentPinned $event): bool => $event->comment->is($new) && $event->unpinnedId === $old->id);
        $this->assertNull($old->fresh()->pinned_at);
        $this->getJson($url.'?sort=oldest')->assertJsonPath('data.0.id', $new->id)->assertJsonPath('data.1.is_pinned', false);

        $this->actingAs($moderator)->deleteJson("/rr/comments/{$new->id}/pin")->assertOk()->assertJsonPath('data.is_pinned', false);
        $this->getJson($url.'?sort=oldest')->assertJsonPath('data.0.id', $old->id)->assertJsonPath('meta.pinned_comment_id', null);
    }

    public function test_a_pinned_comment_does_not_shift_the_pages_behind_it(): void
    {
        config(['ratings-and-reviews.discussions.roots_per_page' => 1]);
        $product = $this->product();
        $first = Comment::factory()->forSubject($product)->create(['created_at' => now()->subDays(2)]);
        $second = Comment::factory()->forSubject($product)->create(['created_at' => now()->subDay()]);
        Comment::factory()->forSubject($product)->create();
        $this->actingAs($this->moderator())->postJson("/rr/comments/{$first->id}/pin")->assertOk();

        $url = "/rr/subjects/product/{$product->id}/comments?sort=oldest";
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonCount(1, 'data');
        $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonCount(1, 'data');
        $this->getJson($url.'&page=3')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotSame($first->id, $this->getJson($url.'&page=3')->json('data.0.id'));
    }

    public function test_the_discussion_owner_pins_as_well(): void
    {
        $owner = $this->user();
        $post = GroupPost::query()->create(['name' => 'Post', 'owner_id' => $owner->id]);
        $comment = Comment::factory()->forSubject($post)->create();

        $this->actingAs($this->user())->postJson("/rr/comments/{$comment->id}/pin")->assertForbidden();
        $this->actingAs($owner)->postJson("/rr/comments/{$comment->id}/pin")->assertOk();
        $this->actingAs($owner)->getJson("/rr/subjects/group_post/{$post->id}/comments")->assertJsonPath('meta.can_pin', true)->assertJsonPath('data.0.is_pinned', true);
    }
}
