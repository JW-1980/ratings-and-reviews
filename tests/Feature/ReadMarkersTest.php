<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\ReadMarker;
use RatingsAndReviews\Services\ReadMarkers;
use RatingsAndReviews\Tests\TestCase;

class ReadMarkersTest extends TestCase
{
    private function url(int $productId, string $query = ''): string
    {
        return "/rr/subjects/product/{$productId}/comments{$query}";
    }

    public function test_the_first_visit_marks_nothing_new_and_sets_the_marker(): void
    {
        $product = $this->product();
        $this->commentOn($product);
        $reader = $this->user();

        $this->actingAs($reader)->getJson($this->url($product->id))->assertOk()
            ->assertJsonPath('meta.new_since.last_visit_at', null)
            ->assertJsonPath('meta.new_since.count', 0)
            ->assertJsonPath('data.0.is_new', false);

        $this->assertNotNull(ReadMarker::query()->where('user_id', $reader->id)->value('last_read_at'));
    }

    public function test_comments_by_others_published_after_the_last_visit_are_new(): void
    {
        $product = $this->product();
        $reader = $this->user();
        $old = $this->commentOn($product, ['published_at' => now()->subDay(), 'created_at' => now()->subDay()]);
        $this->actingAs($reader)->getJson($this->url($product->id))->assertOk();

        $this->travel(10)->minutes();
        $firstNew = $this->commentOn($product);
        $this->travel(1)->minutes();
        $own = $this->commentOn($product, ['user_id' => $reader->id]);
        $reply = Comment::factory()->replyTo($old)->create();

        $response = $this->actingAs($reader)->getJson($this->url($product->id, '?sort=oldest'))->assertOk();
        $data = collect($response->json('data'))->keyBy('id');

        $response->assertJsonPath('meta.new_since.count', 2)
            ->assertJsonPath('meta.new_since.first_new_id', $firstNew->id)
            ->assertJsonPath('meta.new_since.first_new_root_id', $firstNew->id);
        $this->assertFalse($data[$old->id]['is_new']);
        $this->assertTrue($data[$old->id]['replies'][0]['is_new']);
        $this->assertSame($reply->id, $data[$old->id]['replies'][0]['id']);
        $this->assertTrue($data[$firstNew->id]['is_new']);
        $this->assertFalse($data[$own->id]['is_new'], 'Your own comment is never new to you.');

        // That visit moved the marker: nothing is new any more.
        $this->actingAs($reader)->getJson($this->url($product->id))->assertJsonPath('meta.new_since.count', 0);
    }

    public function test_mark_read_zero_reads_without_moving_the_marker_and_guests_get_nothing(): void
    {
        $product = $this->product();
        $reader = $this->user();
        $this->actingAs($reader)->getJson($this->url($product->id));
        $this->travel(5)->minutes();
        $this->commentOn($product);

        $this->actingAs($reader)->getJson($this->url($product->id, '?mark_read=0'))->assertJsonPath('meta.new_since.count', 1);
        $this->actingAs($reader)->getJson($this->url($product->id, '?mark_read=0'))->assertJsonPath('meta.new_since.count', 1);

        auth()->forgetGuards();
        $this->getJson($this->url($product->id))->assertJsonPath('meta.new_since.last_visit_at', null)->assertJsonPath('data.0.is_new', false);
        $this->assertSame(1, ReadMarker::query()->count());
    }

    public function test_the_marker_only_moves_forward(): void
    {
        $markers = $this->app->make(ReadMarkers::class);
        $product = $this->product();

        $markers->touch(5, $product, now());
        $markers->touch(5, $product, now()->subHour());

        $this->assertTrue($markers->lastReadAt(5, $product)->equalTo(now()->startOfSecond()));
        $this->assertSame(1, ReadMarker::query()->count());

        $markers->touch(5, $product, now()->addMinute());
        $this->assertTrue($markers->lastReadAt(5, $product)->equalTo(now()->addMinute()->startOfSecond()));
    }
}
