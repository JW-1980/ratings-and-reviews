<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Contracts\CardResolver;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\Money;
use RatingsAndReviews\Support\ProductCard;
use RatingsAndReviews\Tests\TestCase;

class CardsAndHovercardTest extends TestCase
{
    private function enableCards(): void
    {
        $this->app->instance(CardResolver::class, new class implements CardResolver
        {
            public function isEnabled(): bool
            {
                return true;
            }

            public function resolve(string $type, string $id, ?Authenticatable $viewer): ?ProductCard
            {
                return $type === 'product' && $id === '7'
                    ? new ProductCard('product', 7, 'Havermelk', '/p/7', '/img/7.png', new Money(189, 'EUR'), 4.2, 5)
                    : null;
            }
        });
    }

    public function test_product_cards_are_not_found_without_a_resolver(): void
    {
        $this->getJson('/rr/cards/product/7')->assertNotFound();
    }

    public function test_a_card_is_returned_by_the_host_resolver_and_bad_references_are_not_found(): void
    {
        $this->enableCards();

        $this->getJson('/rr/cards/product/7')->assertOk()->assertExactJson(['data' => [
            'type' => 'product', 'id' => 7, 'title' => 'Havermelk', 'url' => '/p/7', 'image_url' => '/img/7.png',
            'price' => ['cents' => 189, 'currency' => 'EUR'], 'score' => 4.2, 'scale_max' => 5,
        ]]);
        $this->getJson('/rr/cards/product/8')->assertNotFound();
        $this->getJson('/rr/cards/Product/7')->assertNotFound();
        $this->getJson('/rr/cards/a.b/7')->assertNotFound();
    }

    public function test_the_product_card_tool_is_removed_while_cards_are_off(): void
    {
        $this->configureSection('product', ['editor_tools' => ['bold', 'product_card']]);
        $product = $this->product();
        $body = '<p>Probeer <a data-rr-card="product:7">Havermelk</a></p>';

        $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => $body])
            ->assertCreated()->assertJsonPath('data.body_html', '<p>Probeer Havermelk</p>');
        $this->getJson("/rr/subjects/product/{$product->id}/comments")->assertJsonPath('meta.settings.editor_tools', ['bold']);
    }

    public function test_the_product_card_tool_works_once_the_host_resolves_cards(): void
    {
        $this->enableCards();
        $this->configureSection('product', ['editor_tools' => ['bold', 'product_card']]);
        $product = $this->product();

        $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Probeer <a data-rr-card="product:7" onclick="x()">Havermelk</a> <a data-rr-card="javascript:alert(1)">x</a></p>'])
            ->assertCreated()->assertJsonPath('data.body_html', '<p>Probeer <a data-rr-card="product:7" rel="nofollow ugc noopener noreferrer">Havermelk</a> x</p>');
        $this->getJson("/rr/subjects/product/{$product->id}/comments")->assertJsonPath('meta.settings.editor_tools', ['bold', 'product_card']);
    }

    public function test_the_hovercard_shows_public_counts_and_no_private_data(): void
    {
        $user = $this->user(['name' => 'Anouk']);
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['user_id' => $user->id]);
        Review::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create(['user_id' => $user->id]);
        Comment::factory()->count(2)->forSubject($product)->create(['user_id' => $user->id]);
        Comment::factory()->forSubject($product)->create(['user_id' => $user->id, 'author_removed_at' => now()]);
        Comment::factory()->forSubject($product)->create(['user_id' => $user->id, 'published_at' => null, 'publish_at' => now()->addMinute()]);

        $response = $this->getJson("/rr/authors/{$user->id}/card")->assertOk()
            ->assertJsonPath('data.name', 'Anouk')
            ->assertJsonPath('data.counts', ['reviews' => 1, 'comments' => 2]);

        $this->assertSame(['id', 'name', 'avatar_url', 'profile_url', 'extra', 'counts'], array_keys($response->json('data')));
        $this->assertStringNotContainsString('@example.test', $response->getContent());
    }

    public function test_a_user_without_public_contributions_has_no_card(): void
    {
        $quiet = $this->user();
        Review::factory()->forSubject($this->product())->status(ContentStatus::Rejected)->create(['user_id' => $quiet->id]);

        $this->getJson("/rr/authors/{$quiet->id}/card")->assertNotFound();
        $this->getJson('/rr/authors/999999/card')->assertNotFound();
        $this->getJson('/rr/authors/abc/card')->assertNotFound();
        $this->getJson('/rr/authors/0/card')->assertNotFound();
    }
}
