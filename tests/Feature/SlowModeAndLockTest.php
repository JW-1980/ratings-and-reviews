<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\DiscussionState;
use RatingsAndReviews\Tests\TestCase;

class SlowModeAndLockTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/comments";
    }

    public function test_the_section_slow_mode_spaces_a_members_comments_in_one_discussion(): void
    {
        $this->configureSection('product', ['slow_mode_seconds' => 60]);
        $product = $this->product();
        $user = $this->user();

        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Eerste'])->assertCreated();
        $this->travel(20)->seconds();
        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Tweede'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'Slow mode is on in this discussion. You can post again in 40 seconds.');
        $this->actingAs($user)->getJson($this->url($product->id))->assertJsonPath('meta.discussion.slow_mode_seconds', 60)->assertJsonPath('meta.discussion.seconds_until_next_post', 40);

        $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => 'Iemand anders'])->assertCreated();
        $this->actingAs($user)->postJson($this->url($this->product()->id), ['body' => 'Andere discussie'])->assertCreated();

        $this->app->setLocale('nl');
        $this->travel(39)->seconds();
        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Tweede'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'In deze discussie staat de slow mode aan. Je kunt over 1 seconde weer reageren.');

        $this->travel(1)->seconds();
        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Tweede'])->assertCreated();
    }

    public function test_deleting_a_comment_does_not_reset_the_slow_mode_clock_and_moderators_are_exempt(): void
    {
        $this->configureSection('product', ['slow_mode_seconds' => 300]);
        $product = $this->product();
        $user = $this->user();
        $id = $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Eerste'])->json('data.id');
        $this->actingAs($user)->deleteJson("/rr/comments/{$id}")->assertNoContent();

        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Opnieuw'])->assertUnprocessable();

        $moderator = $this->moderator();
        $this->actingAs($moderator)->postJson($this->url($product->id), ['body' => 'Een'])->assertCreated();
        $this->actingAs($moderator)->postJson($this->url($product->id), ['body' => 'Twee'])->assertCreated();
    }

    public function test_a_moderator_overrides_slow_mode_per_discussion_and_can_return_to_the_default(): void
    {
        $this->configureSection('product', ['slow_mode_seconds' => 600]);
        $product = $this->product();
        $moderator = $this->moderator();
        $user = $this->user();

        $this->actingAs($moderator)->patchJson("/rr/admin/discussions/product/{$product->id}", ['slow_mode_seconds' => 0])->assertOk()
            ->assertJsonPath('data.slow_mode_seconds', 0)
            ->assertJsonPath('data.section_slow_mode_seconds', 600)
            ->assertJsonPath('data.effective_slow_mode_seconds', 0);
        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Een'])->assertCreated();
        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Twee'])->assertCreated();

        $this->actingAs($moderator)->patchJson("/rr/admin/discussions/product/{$product->id}", ['slow_mode_seconds' => 30])->assertJsonPath('data.effective_slow_mode_seconds', 30);
        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Drie'])->assertUnprocessable();

        $this->actingAs($moderator)->patchJson("/rr/admin/discussions/product/{$product->id}", ['slow_mode_seconds' => null])->assertJsonPath('data.slow_mode_seconds', null)->assertJsonPath('data.effective_slow_mode_seconds', 600);
        $this->assertNull(DiscussionState::query()->sole()->slow_mode_seconds);
    }

    public function test_a_locked_discussion_refuses_members_but_not_moderators(): void
    {
        $product = $this->product();
        $moderator = $this->moderator();
        $user = $this->user();
        $own = $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Voor het slot'])->json('data.id');

        $this->actingAs($moderator)->patchJson("/rr/admin/discussions/product/{$product->id}", ['locked' => true])->assertOk()
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.locked_by', $moderator->id);

        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Na het slot'])->assertUnprocessable()->assertJsonPath('errors.body.0', 'This discussion is locked. No new comments can be posted.');
        $this->actingAs($user)->patchJson("/rr/comments/{$own}", ['body' => 'Aangepast'])->assertUnprocessable();
        $this->actingAs($user)->getJson($this->url($product->id))->assertJsonPath('meta.accepts_comments', false)->assertJsonPath('meta.discussion.locked', true);
        $this->actingAs($moderator)->postJson($this->url($product->id), ['body' => 'Moderator spreekt'])->assertCreated();
        $this->actingAs($moderator)->getJson($this->url($product->id))->assertJsonPath('meta.accepts_comments', true);

        $this->actingAs($moderator)->patchJson("/rr/admin/discussions/product/{$product->id}", ['locked' => false])->assertJsonPath('data.locked', false);
        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Weer open'])->assertCreated();
        $this->assertSame(3, Comment::query()->count());
    }

    public function test_only_moderators_reach_the_discussion_controls(): void
    {
        $product = $this->product();

        $this->getJson("/rr/admin/discussions/product/{$product->id}")->assertUnauthorized();
        $this->patchJson("/rr/admin/discussions/product/{$product->id}", ['locked' => true])->assertUnauthorized();
        $this->actingAs($this->user())->getJson("/rr/admin/discussions/product/{$product->id}")->assertForbidden();
        $this->actingAs($this->user())->patchJson("/rr/admin/discussions/product/{$product->id}", ['locked' => true])->assertForbidden();

        $moderator = $this->moderator();
        $this->actingAs($moderator)->patchJson("/rr/admin/discussions/product/{$product->id}", ['slow_mode_seconds' => -1])->assertUnprocessable();
        $this->actingAs($moderator)->patchJson("/rr/admin/discussions/product/{$product->id}", ['slow_mode_seconds' => 86401])->assertUnprocessable();
        $this->actingAs($moderator)->patchJson("/rr/admin/discussions/article/{$product->id}", ['locked' => true])->assertNotFound();
        $this->actingAs($moderator)->getJson("/rr/admin/discussions/product/{$product->id}")->assertOk()->assertJsonPath('data.locked', false);
        $this->assertSame(0, DiscussionState::query()->count());
    }
}
