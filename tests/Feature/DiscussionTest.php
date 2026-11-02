<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Events\CommentDeleted;
use RatingsAndReviews\Events\CommentEdited;
use RatingsAndReviews\Events\CommentModerated;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\CommentRevision;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Services\DiscussionService;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\SectionSettings;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\TestCase;

class DiscussionTest extends TestCase
{
    private function url(Product $product): string
    {
        return "/rr/subjects/product/{$product->id}/comments";
    }

    public function test_a_member_posts_a_sanitized_comment_and_it_is_announced(): void
    {
        Event::fake([CommentPosted::class]);
        $user = $this->user();
        $product = $this->product();

        $this->actingAs($user)->postJson($this->url($product), ['body' => '<p>Goed <strong>spul</strong><script>alert(1)</script><img src="https://x.test/a.png" onerror="x()"></p>'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.body_html', '<p>Goed <strong>spul</strong></p>')
            ->assertJsonPath('data.depth', 0);

        $comment = Comment::query()->sole();
        $this->assertSame('Goed spul', $comment->body_text);
        $this->assertSame('product', $comment->section);
        $this->assertNotNull($comment->published_at);
        Event::assertDispatched(CommentPosted::class, fn (CommentPosted $event): bool => $event->comment->is($comment));
    }

    public function test_guests_cannot_post_and_invalid_bodies_are_refused(): void
    {
        $product = $this->product();

        $this->postJson($this->url($product), ['body' => 'Hallo daar'])->assertUnauthorized();
        $this->actingAs($this->user())->postJson($this->url($product), [])->assertUnprocessable()->assertJsonPath('errors.body.0', 'The text field is required.');
        $this->actingAs($this->user())->postJson($this->url($product), ['body' => '<script>only</script>'])->assertUnprocessable()->assertJsonPath('errors.body.0', 'Please write at least 2 characters.');

        config(['ratings-and-reviews.sections.product.max_length' => 10]);
        $this->app->make(SectionSettingsRepository::class)->forget('product');
        $this->actingAs($this->user())->postJson($this->url($product), ['body' => 'Dit is veel te lang voor deze sectie'])->assertUnprocessable()->assertJsonPath('errors.body.0', 'Please keep it under 10 characters.');

        $this->assertSame(0, Comment::query()->count());
    }

    public function test_a_closed_discussion_refuses_comments(): void
    {
        $this->actingAs($this->user())->postJson($this->url($this->product(['is_open' => false])), ['body' => 'Hallo daar'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'Comments cannot be posted here at the moment.');
    }

    public function test_replies_beyond_the_section_depth_are_flattened_onto_the_deepest_level(): void
    {
        $product = $this->product(['section' => 'forum']); // max_depth 2
        $user = $this->user();

        $root = $this->actingAs($user)->postJson($this->url($product), ['body' => 'Root'])->json('data.id');
        $one = $this->actingAs($user)->postJson($this->url($product), ['body' => 'Een', 'parent_id' => $root])->json('data.id');
        $two = $this->actingAs($user)->postJson($this->url($product), ['body' => 'Twee', 'parent_id' => $one])->json('data.id');
        $three = $this->actingAs($user)->postJson($this->url($product), ['body' => 'Drie', 'parent_id' => $two])->assertCreated()->json('data');

        $this->assertSame(2, Comment::query()->find($two)->depth);
        $this->assertSame($one, $three['parent_id'], 'Attached to the ancestor one level above the deepest.');
        $this->assertSame(2, $three['depth']);
        $this->assertSame($two, $three['in_reply_to_id']);
        $this->assertSame($root, $three['root_id']);
    }

    public function test_a_flat_section_turns_replies_into_top_level_comments(): void
    {
        config(['ratings-and-reviews.sections.product.max_depth' => 0]);
        $this->app->make(SectionSettingsRepository::class)->forget('product');
        $product = $this->product();
        $user = $this->user();
        $root = $this->actingAs($user)->postJson($this->url($product), ['body' => 'Root'])->json('data.id');

        $reply = $this->actingAs($user)->postJson($this->url($product), ['body' => 'Antwoord', 'parent_id' => $root])->json('data');

        $this->assertNull($reply['parent_id']);
        $this->assertSame(0, $reply['depth']);
        $this->assertSame($root, $reply['in_reply_to_id']);
    }

    public function test_replies_to_hidden_or_foreign_comments_are_refused(): void
    {
        $product = $this->product();
        $hidden = Comment::factory()->forSubject($product)->status(ContentStatus::Rejected)->create();
        $elsewhere = Comment::factory()->forSubject($this->product())->create();

        foreach ([$hidden->id, $elsewhere->id, 999999] as $parentId) {
            $this->actingAs($this->user())->postJson($this->url($product), ['body' => 'Antwoord', 'parent_id' => $parentId])
                ->assertUnprocessable()
                ->assertJsonPath('errors.parent_id.0', 'You cannot reply to a hidden or removed comment.');
        }
    }

    public function test_an_author_deleting_a_comment_with_replies_leaves_a_placeholder_and_keeps_the_replies(): void
    {
        Event::fake([CommentDeleted::class]);
        $product = $this->product();
        $author = $this->user();
        $root = Comment::factory()->forSubject($product)->create(['user_id' => $author->id, 'body_html' => '<p>Geheim</p>', 'body_text' => 'Geheim']);
        $replier = $this->user();
        $reply = Comment::factory()->replyTo($root)->create(['user_id' => $replier->id]);
        CommentRevision::query()->create(['comment_id' => $root->id, 'revision' => 1, 'body_html' => '<p>oud</p>', 'body_text' => 'oud', 'edited_at' => now()]);

        $this->actingAs($author)->deleteJson("/rr/comments/{$root->id}")->assertNoContent();

        $root->refresh();
        $this->assertNotNull($root->author_removed_at);
        $this->assertSame('', $root->body_html);
        $this->assertNull($root->deleted_at);
        $this->assertSame(0, CommentRevision::query()->where('comment_id', $root->id)->count(), 'History goes with the words.');
        $this->assertNotSoftDeleted('rr_comments', ['id' => $reply->id]);

        $tree = $this->getJson($this->url($product))->assertOk();
        $tree->assertJsonPath('data.0.is_removed', true)
            ->assertJsonPath('data.0.body_html', '')
            ->assertJsonPath('data.0.author', null)
            ->assertJsonPath('data.0.removed_label', 'Comment removed by its author')
            ->assertJsonPath('data.0.replies.0.id', $reply->id);

        // When the last reply goes, the placeholder that only stood for it goes too.
        $this->actingAs($replier)->deleteJson("/rr/comments/{$reply->id}")->assertNoContent();
        $this->assertSoftDeleted('rr_comments', ['id' => $reply->id]);
        $this->assertSoftDeleted('rr_comments', ['id' => $root->id]);

        Event::assertDispatched(CommentDeleted::class, fn (CommentDeleted $event): bool => $event->placeholder && $event->comment->is($root));
    }

    public function test_a_placeholder_of_a_reported_comment_is_kept_as_evidence(): void
    {
        $product = $this->product();
        $author = $this->user();
        $root = Comment::factory()->forSubject($product)->create(['user_id' => $author->id, 'body_html' => '<p>Bewijs</p>', 'body_text' => 'Bewijs']);
        Comment::factory()->replyTo($root)->create();
        Report::factory()->forSubject($root)->create();

        $this->actingAs($author)->deleteJson("/rr/comments/{$root->id}")->assertNoContent();

        $root->refresh();
        $this->assertTrue($root->held_as_evidence);
        $this->assertSame('<p>Bewijs</p>', CommentRevision::query()->where('comment_id', $root->id)->sole()->body_html);
        $this->getJson("/rr/comments/{$root->id}/revisions")->assertNotFound();
        $this->actingAs($this->moderator())->getJson("/rr/comments/{$root->id}/revisions")->assertOk()->assertJsonPath('data.0.body_html', '<p>Bewijs</p>');
    }

    public function test_an_author_deleting_a_comment_without_replies_removes_it(): void
    {
        $author = $this->user();
        $comment = Comment::factory()->forSubject($this->product())->create(['user_id' => $author->id]);

        $this->actingAs($this->user())->deleteJson("/rr/comments/{$comment->id}")->assertForbidden();
        $this->actingAs($author)->deleteJson("/rr/comments/{$comment->id}")->assertNoContent();

        $this->assertSoftDeleted('rr_comments', ['id' => $comment->id]);
    }

    public function test_a_moderator_deletes_the_whole_subtree(): void
    {
        $product = $this->product();
        $root = Comment::factory()->forSubject($product)->create();
        $child = Comment::factory()->replyTo($root)->create();
        $grandchild = Comment::factory()->replyTo($child)->create();
        $sibling = Comment::factory()->forSubject($product)->create();

        $this->actingAs($this->moderator())->deleteJson("/rr/comments/{$root->id}")->assertNoContent();

        foreach ([$root, $child, $grandchild] as $comment) {
            $this->assertSoftDeleted('rr_comments', ['id' => $comment->id]);
        }
        $this->assertNotSoftDeleted('rr_comments', ['id' => $sibling->id]);
    }

    public function test_hiding_a_comment_hides_its_replies_and_the_first_approval_announces_it(): void
    {
        Event::fake([CommentPosted::class, CommentModerated::class]);
        $product = $this->product();
        $root = Comment::factory()->forSubject($product)->create();
        Comment::factory()->replyTo($root)->create();
        $pending = Comment::factory()->forSubject($product)->status(ContentStatus::Pending)->create();
        $moderator = $this->moderator();

        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/comments/{$root->id}", ['action' => 'reject'])->assertOk();
        $this->getJson($this->url($product))->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');

        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/comments/{$pending->id}", ['action' => 'approve'])->assertOk();
        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/comments/{$pending->id}", ['action' => 'reject'])->assertOk();
        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/comments/{$pending->id}", ['action' => 'approve'])->assertOk();

        Event::assertDispatchedTimes(CommentPosted::class, 1);
        Event::assertDispatchedTimes(CommentModerated::class, 4);
    }

    public function test_editing_keeps_a_revision_and_rechecks_spam(): void
    {
        Event::fake([CommentEdited::class]);
        $author = $this->user();
        $product = $this->product();
        $id = $this->actingAs($author)->postJson($this->url($product), ['body' => 'Eerste versie'])->json('data.id');

        $this->actingAs($author)->patchJson("/rr/comments/{$id}", ['body' => 'Eerste versie'])->assertOk();
        $this->assertSame(0, CommentRevision::query()->count(), 'An unchanged edit is not stored.');

        $this->actingAs($author)->patchJson("/rr/comments/{$id}", ['body' => 'Tweede versie'])->assertOk()->assertJsonPath('data.is_edited', true);
        $this->getJson("/rr/comments/{$id}/revisions")->assertOk()->assertJsonPath('data.0.revision', 1)->assertJsonPath('data.0.body_html', '<p>Eerste versie</p>');
        Event::assertDispatched(CommentEdited::class, fn (CommentEdited $event): bool => ! $event->sentBackToModeration && $event->revision->revision === 1);

        $this->actingAs($author)->patchJson("/rr/comments/{$id}", ['body' => 'Zie https://a.test https://b.test https://c.test https://d.test'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        Event::assertDispatched(CommentEdited::class, fn (CommentEdited $event): bool => $event->sentBackToModeration);

        $this->actingAs($author)->patchJson("/rr/comments/{$id}", ['body' => 'Nog eens', 'website' => 'bot'])->assertUnprocessable();
        $this->assertSame(2, CommentRevision::query()->count());
    }

    public function test_only_the_author_within_the_window_or_a_moderator_edits(): void
    {
        $author = $this->user();
        $comment = Comment::factory()->forSubject($this->product())->create(['user_id' => $author->id, 'created_at' => now()->subMinutes(31)]);

        $this->actingAs($this->user())->patchJson("/rr/comments/{$comment->id}", ['body' => 'Overgenomen'])->assertForbidden();
        $this->actingAs($author)->patchJson("/rr/comments/{$comment->id}", ['body' => 'Te laat'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'The time for editing this comment has passed.');

        $moderator = $this->moderator();
        $this->actingAs($moderator)->patchJson("/rr/comments/{$comment->id}", ['body' => 'Door moderator ingekort'])
            ->assertOk()
            ->assertJsonPath('data.body_html', '<p>Door moderator ingekort</p>');
        $this->assertSame($moderator->id, CommentRevision::query()->sole()->edited_by);
    }

    public function test_the_tree_sorts_roots_and_reads_replies_oldest_first(): void
    {
        $product = $this->product();
        $helpful = Comment::factory()->forSubject($product)->create(['helpful_count' => 20, 'unhelpful_count' => 1, 'created_at' => now()->subHours(3)]);
        $few = Comment::factory()->forSubject($product)->create(['helpful_count' => 2, 'unhelpful_count' => 0, 'created_at' => now()->subHours(2)]);
        $controversial = Comment::factory()->forSubject($product)->create(['agree_count' => 10, 'disagree_count' => 9, 'controversy_score' => Comment::controversy(10, 9), 'created_at' => now()->subHour()]);
        $late = Comment::factory()->replyTo($helpful)->create(['created_at' => now()]);
        $early = Comment::factory()->replyTo($helpful)->create(['created_at' => now()->subMinutes(30)]);

        $this->getJson($this->url($product))->assertJsonPath('data.*.id', [$helpful->id, $few->id, $controversial->id])
            ->assertJsonPath('data.0.replies.*.id', [$early->id, $late->id]);
        $this->getJson($this->url($product).'?sort=newest')->assertJsonPath('data.*.id', [$controversial->id, $few->id, $helpful->id]);
        $this->getJson($this->url($product).'?sort=oldest')->assertJsonPath('data.*.id', [$helpful->id, $few->id, $controversial->id]);
        $this->getJson($this->url($product).'?sort=controversial')->assertJsonPath('data.0.id', $controversial->id);
    }

    public function test_the_stored_helpful_score_is_the_wilson_lower_bound(): void
    {
        $comment = Comment::factory()->forSubject($this->product())->create(['helpful_count' => 7, 'unhelpful_count' => 3]);
        $empty = Comment::factory()->forSubject($this->product())->create();

        $this->assertEqualsWithDelta(Comment::wilsonLowerBound(7, 3), $comment->fresh()->helpful_score, 1e-9);
        $this->assertEqualsWithDelta(0.396773, $comment->fresh()->helpful_score, 1e-6);
        $this->assertSame(0.0, $empty->fresh()->helpful_score);
        $this->assertSame(0.0, Comment::controversy(0, 0));
        $this->assertSame(1.0, Comment::controversy(5, 0));
        $this->assertEqualsWithDelta(19 ** 0.9, Comment::controversy(10, 9), 1e-6);
    }

    public function test_roots_are_paginated(): void
    {
        config(['ratings-and-reviews.discussions.roots_per_page' => 2]);
        $product = $this->product();
        Comment::factory()->count(5)->forSubject($product)->sequence(fn ($sequence) => ['created_at' => now()->subMinutes(10 - $sequence->index)])->create();

        $this->getJson($this->url($product).'?sort=oldest&page=1')->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.total', 5);
        $this->getJson($this->url($product).'?sort=oldest&page=3')->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);
    }

    public function test_loading_the_tree_takes_the_same_number_of_queries_however_deep_it_is(): void
    {
        $shallow = $this->product();
        Comment::factory()->forSubject($shallow)->create();

        $deep = $this->product();
        $parent = Comment::factory()->forSubject($deep)->create();
        foreach (range(1, 5) as $level) {
            $parent = Comment::factory()->replyTo($parent)->create();
            Comment::factory()->count(2)->replyTo($parent)->create();
        }

        $count = function (Product $product): int {
            $this->app->forgetInstance('db');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url($product))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $shallowQueries = $count($shallow);
        $this->assertSame($shallowQueries, $count($deep));
        $this->assertLessThanOrEqual(8, $shallowQueries);
    }

    public function test_pending_and_other_peoples_hidden_comments_cannot_be_acted_on(): void
    {
        $pending = Comment::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create();

        $this->actingAs($this->user())->patchJson("/rr/comments/{$pending->id}", ['body' => 'Hallo'])->assertNotFound();
        $this->actingAs($this->user())->deleteJson("/rr/comments/{$pending->id}")->assertNotFound();
        $this->getJson("/rr/comments/{$pending->id}/revisions")->assertNotFound();
    }

    public function test_the_service_flattening_walks_up_past_levels_left_by_a_lowered_depth(): void
    {
        $product = $this->product();
        $parent = Comment::factory()->forSubject($product)->create();
        $chain = [$parent];
        foreach (range(1, 4) as $level) {
            $chain[] = $parent = Comment::factory()->replyTo($parent)->create();
        }

        $placement = $this->app->make(DiscussionService::class)->placement($product, SectionSettings::fromArray('product', ['max_depth' => 2]), $parent->id);

        $this->assertSame(['parent_id' => $chain[1]->id, 'root_id' => $chain[0]->id, 'in_reply_to_id' => $parent->id, 'depth' => 2], $placement);
    }
}
