<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

class ThreadRepliesTest extends TestCase
{
    /**
     * A root with replies: two on level 1 (the second rejected), each with children, created a
     * minute apart so the order is deterministic.
     *
     * @return array{root: Comment, ids: array<string, int>}
     */
    private function thread(): array
    {
        $product = $this->product();
        $root = $this->commentOn($product);
        $minute = 0;
        $make = function (Comment $parent, array $state = []) use (&$minute): Comment {
            $minute++;

            return Comment::factory()->replyTo($parent)->create($state + ['created_at' => now()->addMinutes($minute)]);
        };

        $a = $make($root);
        $hidden = $make($root, ['status' => ContentStatus::Rejected, 'published_at' => null]);
        $a1 = $make($a);
        $underHidden = $make($hidden);
        $a2 = $make($a);
        $a1x = $make($a1);

        return ['root' => $root, 'ids' => ['a' => $a->id, 'hidden' => $hidden->id, 'a1' => $a1->id, 'under_hidden' => $underHidden->id, 'a2' => $a2->id, 'a1x' => $a1x->id]];
    }

    public function test_the_tree_reports_a_cut_thread_and_the_endpoint_pages_through_its_visible_replies_in_tree_order(): void
    {
        config(['ratings-and-reviews.discussions.max_replies_per_page' => 2]);
        ['root' => $root, 'ids' => $ids] = $this->thread();

        $page = $this->getJson("/rr/subjects/product/{$root->commentable_id}/comments")->assertOk();
        $this->assertSame(5, $page->json("meta.truncated_threads.{$root->id}"));

        $first = $this->getJson("/rr/comments/{$root->id}/replies?limit=2")->assertOk()
            ->assertJsonPath('meta.root_id', $root->id)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.has_more', true);
        $this->assertSame([$ids['a'], $ids['a1']], array_column($first->json('data'), 'id'));
        $this->assertSame([$root->id, $ids['a']], array_column($first->json('data'), 'parent_id'));
        $this->assertSame([], $first->json('data.0.replies'), 'Replies come flat; parent_id places them.');

        $second = $this->getJson("/rr/comments/{$root->id}/replies?limit=2&cursor=".$first->json('meta.next_cursor'))->assertOk()
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);
        $this->assertSame([$ids['a2'], $ids['a1x']], array_column($second->json('data'), 'id'));

        // The rejected reply and the reply beneath it never appear. (A request is capped at max_replies_per_page.)
        $this->assertCount(2, $this->getJson("/rr/comments/{$root->id}/replies?limit=50")->json('data'));
        config(['ratings-and-reviews.discussions.max_replies_per_page' => 400]);
        $all = array_column($this->getJson("/rr/comments/{$root->id}/replies")->json('data'), 'id');
        $this->assertNotContains($ids['hidden'], $all);
        $this->assertNotContains($ids['under_hidden'], $all);
        $this->assertCount(4, $all);

        // A reply's id leads to the same thread.
        $this->assertSame($all, array_column($this->getJson("/rr/comments/{$ids['a1']}/replies")->json('data'), 'id'));
    }

    public function test_the_viewer_sees_their_own_pending_reply_and_others_do_not(): void
    {
        ['root' => $root] = $this->thread();
        $author = $this->user();
        $pending = Comment::factory()->replyTo($root)->status(ContentStatus::Pending)->create(['user_id' => $author->id]);

        $this->assertContains($pending->id, array_column($this->actingAs($author)->getJson("/rr/comments/{$root->id}/replies")->json('data'), 'id'));
        $this->assertNotContains($pending->id, array_column($this->actingAs($this->user())->getJson("/rr/comments/{$root->id}/replies")->json('data'), 'id'));
    }

    public function test_hidden_threads_subjects_and_bad_cursors_are_refused(): void
    {
        ['root' => $root, 'ids' => $ids] = $this->thread();

        $this->getJson("/rr/comments/{$ids['hidden']}/replies")->assertOk(); // the root is public
        $this->getJson("/rr/comments/{$root->id}/replies?cursor=bm9wZQ")->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->getJson('/rr/comments/abc/replies')->assertNotFound();

        $root->forceFill(['status' => ContentStatus::Rejected])->save();
        $this->getJson("/rr/comments/{$root->id}/replies")->assertNotFound();

        $root->forceFill(['status' => ContentStatus::Approved])->save();
        $root->commentable->update(['is_hidden' => true]);
        $this->getJson("/rr/comments/{$root->id}/replies")->assertNotFound();
    }
}
