<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Carbon;
use RatingsAndReviews\Contracts\OfficialReplyResolver;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Support\OfficialReply;
use RatingsAndReviews\Tests\TestCase;
use RuntimeException;

class OfficialRepliesAndPhotoFilterTest extends TestCase
{
    /**
     * @param  array<int, OfficialReply>  $replies
     * @param  list<list<int>>  $calls
     */
    private function bindReplies(array $replies, array &$calls = [], bool $fail = false): void
    {
        $this->app->instance(OfficialReplyResolver::class, new class($replies, $calls, $fail) implements OfficialReplyResolver
        {
            /**
             * @param  array<int, OfficialReply>  $replies
             * @param  list<list<int>>  $calls
             */
            public function __construct(private array $replies, private array &$calls, private bool $fail) {}

            public function isEnabled(): bool
            {
                return true;
            }

            public function repliesFor(array $reviews): array
            {
                $this->calls[] = array_map(fn (Review $review): int => $review->id, $reviews);

                if ($this->fail) {
                    throw new RuntimeException('Brand service down');
                }

                return array_intersect_key($this->replies, array_flip(array_map(fn (Review $review): int => $review->id, $reviews)));
            }
        });
    }

    public function test_official_replies_are_absent_by_default(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create();

        $response = $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()->assertJsonPath('meta.features.official_replies', false);
        $this->assertArrayNotHasKey('official_reply', $response->json('data.0'));
    }

    public function test_a_bound_resolver_adds_the_official_reply_in_one_batched_lookup(): void
    {
        $product = $this->product();
        $answered = Review::factory()->forSubject($product)->create(['helpful_count' => 5]);
        Review::factory()->forSubject($product)->create(['helpful_count' => 1]);
        $calls = [];
        $this->bindReplies([$answered->id => new OfficialReply('Oatly', "Dank je!\nWe geven het door.\n\nGroet, <team>", Carbon::parse('2026-10-01 12:00:00'), 'Merk')], $calls);

        $response = $this->getJson("/rr/subjects/product/{$product->id}/reviews?sort=helpful")
            ->assertOk()
            ->assertJsonPath('meta.features.official_replies', true)
            ->assertJsonPath('data.0.official_reply.author.name', 'Oatly')
            ->assertJsonPath('data.0.official_reply.label', 'Merk')
            ->assertJsonPath('data.0.official_reply.body_html', '<p>Dank je!<br>'."\n".'We geven het door.</p><p>Groet, &lt;team&gt;</p>')
            ->assertJsonPath('data.1.official_reply', null);

        $this->assertSame('2026-10-01T12:00:00+00:00', $response->json('data.0.official_reply.replied_at'));
        $this->assertCount(1, $calls);
        $this->assertCount(2, $calls[0]);
    }

    public function test_a_failing_resolver_does_not_break_the_list(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create();
        $calls = [];
        $this->bindReplies([], $calls, fail: true);

        $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()->assertJsonPath('data.0.official_reply', null);
    }

    public function test_the_list_can_be_filtered_to_reviews_with_photos(): void
    {
        $product = $this->product();
        $withPhoto = Review::factory()->forSubject($product)->create();
        $inlineOnly = Review::factory()->forSubject($product)->create();
        Review::factory()->forSubject($product)->create();
        Upload::query()->create(['user_id' => $withPhoto->user_id, 'disk' => 'public', 'path' => 'a.jpg', 'attached_type' => 'rr_review', 'attached_id' => $withPhoto->id, 'role' => Upload::ROLE_PHOTO]);
        Upload::query()->create(['user_id' => $inlineOnly->user_id, 'disk' => 'public', 'path' => 'b.jpg', 'attached_type' => 'rr_review', 'attached_id' => $inlineOnly->id, 'role' => Upload::ROLE_INLINE]);

        $this->getJson("/rr/subjects/product/{$product->id}/reviews?with_photos=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $withPhoto->id)
            ->assertJsonPath('meta.filters.with_photos', true);
        $this->getJson("/rr/subjects/product/{$product->id}/reviews?with_photos=0")->assertJsonPath('meta.total', 3)->assertJsonPath('meta.filters.with_photos', false);
    }
}
