<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Contracts\WantToTryList;
use RatingsAndReviews\Events\WantToTryRequested;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

class WantToTryTest extends TestCase
{
    /** @var object{items: array<int, array{0: int|string, 1: string, 2: int|null}>} */
    private object $list;

    private function enableList(): void
    {
        $this->list = new class implements WantToTryList
        {
            public array $items = [];

            public function isEnabled(): bool
            {
                return true;
            }

            public function add(Authenticatable $user, Model $subject, ?Model $source = null): bool
            {
                $key = $user->getAuthIdentifier().':'.$subject->getMorphClass().':'.$subject->getKey();

                if (isset($this->items[$key])) {
                    return false;
                }

                $this->items[$key] = [$user->getAuthIdentifier(), $subject->getMorphClass(), $source?->getKey()];

                return true;
            }
        };

        $this->app->instance(WantToTryList::class, $this->list);
    }

    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/want-to-try";
    }

    public function test_it_is_not_found_while_the_host_has_no_list(): void
    {
        $this->actingAs($this->user())->postJson($this->url($this->product()->id))->assertNotFound();
    }

    public function test_a_member_puts_a_subject_on_their_list_from_a_review(): void
    {
        Event::fake([WantToTryRequested::class]);
        $this->enableList();
        $user = $this->user();
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create();

        $this->actingAs($user)->postJson($this->url($product->id), ['source_review_id' => $review->id])
            ->assertCreated()
            ->assertJsonPath('data.added', true)
            ->assertJsonPath('message', 'Added to your list.');
        $this->actingAs($user)->postJson($this->url($product->id))->assertOk()->assertJsonPath('data.added', false);

        $this->assertSame([[$user->id, 'product', $review->id]], array_values($this->list->items));
        Event::assertDispatchedTimes(WantToTryRequested::class, 1);
        Event::assertDispatched(WantToTryRequested::class, fn (WantToTryRequested $event): bool => $event->userId === $user->id && $event->subject->is($product) && $event->source?->is($review));
    }

    public function test_guests_hidden_subjects_and_foreign_or_hidden_source_reviews_are_refused(): void
    {
        $this->enableList();
        $product = $this->product();
        $elsewhere = Review::factory()->forSubject($this->product())->create();
        $pending = Review::factory()->forSubject($product)->status(ContentStatus::Pending)->create();

        $this->postJson($this->url($product->id))->assertUnauthorized();
        $this->actingAs($this->user())->postJson($this->url($this->product(['is_hidden' => true])->id))->assertNotFound();
        $this->actingAs($this->user())->postJson('/rr/subjects/article/1/want-to-try')->assertNotFound();
        $this->actingAs($this->user())->postJson($this->url($product->id), ['source_review_id' => $elsewhere->id])->assertUnprocessable()->assertJsonValidationErrors('source_review_id');
        $this->actingAs($this->user())->postJson($this->url($product->id), ['source_review_id' => $pending->id])->assertUnprocessable();
        $this->actingAs($this->user())->postJson($this->url($product->id), ['source_review_id' => 'x'])->assertUnprocessable();

        $this->assertSame([], $this->list->items);
    }
}
