<?php

namespace RatingsAndReviews\Tests\Feature;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use RatingsAndReviews\Contracts\PriceAtReviewResolver;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Support\Money;
use RatingsAndReviews\Tests\TestCase;
use RuntimeException;

/**
 * Context facets, "compared with…" and the price at the time of the review.
 */
class ReviewContextTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/reviews";
    }

    private function bindPrice(?Money $price, bool $throws = false): void
    {
        $this->app->instance(PriceAtReviewResolver::class, new class($price, $throws) implements PriceAtReviewResolver
        {
            public int $calls = 0;

            public function __construct(private ?Money $price, private bool $throws) {}

            public function isEnabled(): bool
            {
                return true;
            }

            public function priceAt(Model $subject, DateTimeInterface $at): ?Money
            {
                $this->calls++;

                if ($this->throws) {
                    throw new RuntimeException('price service down');
                }

                return $this->price;
            }
        });
    }

    public function test_facets_are_validated_stored_returned_and_filterable(): void
    {
        $product = $this->product();
        $first = $this->user();

        $this->actingAs($first)->postJson($this->url($product->id), ['rating' => 4, 'facets' => ['purchase' => 'first_time', 'deal' => 'on_offer']])
            ->assertCreated()
            ->assertJsonPath('data.facets', [
                ['group' => 'deal', 'value' => 'on_offer', 'label' => 'Bought on offer'],
                ['group' => 'purchase', 'value' => 'first_time', 'label' => 'Bought for the first time'],
            ]);
        $this->actingAs($this->user())->postJson($this->url($product->id), ['rating' => 2, 'facets' => ['purchase' => 'regular']])->assertCreated();

        foreach ([['purchase' => 'sometimes'], ['colour' => 'red'], 'plain'] as $invalid) {
            $this->actingAs($this->user())->postJson($this->url($product->id), ['rating' => 3, 'facets' => $invalid])->assertUnprocessable();
        }
        $this->assertSame(2, Review::query()->count());
        $this->assertSame(['deal' => 'on_offer', 'purchase' => 'first_time'], Review::query()->where('user_id', $first->id)->sole()->facets);

        $this->getJson($this->url($product->id).'?facet[purchase]=first_time')->assertJsonCount(1, 'data')->assertJsonPath('data.0.rating', 4)->assertJsonPath('meta.filters.facets.purchase', 'first_time');
        $this->getJson($this->url($product->id).'?facet[purchase]=regular&facet[deal]=on_offer')->assertJsonCount(0, 'data');
        $this->getJson($this->url($product->id).'?facet[purchase]=bogus')->assertJsonCount(2, 'data', 'An unknown value is ignored, not an error.');
        $this->getJson($this->url($product->id))->assertJsonPath('meta.facets.0.group', 'purchase')->assertJsonPath('meta.facets.0.values.1.label', 'Regular buyer');
    }

    public function test_the_summary_counts_facets_of_counted_reviews(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['facets' => ['purchase' => 'first_time']]);
        Review::factory()->forSubject($product)->create(['facets' => ['purchase' => 'first_time', 'deal' => 'on_offer']]);
        Review::factory()->forSubject($product)->create(['facets' => ['purchase' => 'regular'], 'counted' => false]);
        Review::factory()->forSubject($product)->create(['facets' => null]);
        $this->app->make(RatingAggregator::class)->refresh($product);

        $facets = collect($this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->assertOk()->json('data.facets'))->keyBy('group');

        $this->assertSame([2, 0], array_column($facets['purchase']['values'], 'count'));
        $this->assertSame([1, 0], array_column($facets['deal']['values'], 'count'));
        $this->assertSame('Purchase', $facets['purchase']['label']);
    }

    public function test_compared_with_is_validated_through_the_morph_map_and_visibility(): void
    {
        $product = $this->product(['name' => 'Haverdrank']);
        $other = $this->product(['name' => 'Sojadrank']);
        $hidden = $this->product(['is_hidden' => true]);

        $cases = [
            ['compared_type' => 'product', 'compared_id' => $other->id],                                           // no comparison
            ['compared_type' => 'product', 'compared_id' => $other->id, 'comparison' => 'much_better'],            // unknown value
            ['compared_type' => 'nope', 'compared_id' => $other->id, 'comparison' => 'better'],                    // not in the morph map
            ['compared_type' => 'article', 'compared_id' => $other->id, 'comparison' => 'better'],                 // not Reviewable
            ['compared_type' => 'product', 'compared_id' => 999999, 'comparison' => 'better'],                     // missing
            ['compared_type' => 'product', 'compared_id' => $hidden->id, 'comparison' => 'better'],                // not visible
            ['compared_type' => 'product', 'compared_id' => $product->id, 'comparison' => 'better'],               // itself
            ['compared_type' => 'RatingsAndReviews\\Tests\\Fixtures\\Product', 'compared_id' => $other->id, 'comparison' => 'better'],
        ];

        foreach ($cases as $index => $case) {
            $this->actingAs($this->user())->postJson($this->url($product->id), ['rating' => 3] + $case)->assertUnprocessable();
            $this->assertSame(0, Review::query()->count(), "Case {$index} was stored.");
        }

        $this->actingAs($this->user())->postJson($this->url($product->id), ['rating' => 3, 'compared_type' => 'product', 'compared_id' => $other->id, 'comparison' => 'worse'])
            ->assertCreated()
            ->assertJsonPath('data.compared', ['type' => 'product', 'id' => $other->id, 'title' => 'Sojadrank', 'url' => '/products/'.$other->id, 'comparison' => 'worse', 'comparison_label' => 'Worse']);

        $this->getJson($this->url($product->id))->assertJsonPath('data.0.compared.title', 'Sojadrank');
    }

    public function test_the_summary_says_where_reviewers_often_switch_to(): void
    {
        $product = $this->product();
        $soy = $this->product(['name' => 'Sojadrank']);
        $rice = $this->product(['name' => 'Rijstdrank']);
        $gone = $this->product(['is_hidden' => true]);

        foreach (['worse', 'worse', 'worse', 'better'] as $comparison) {
            Review::factory()->forSubject($product)->create(['compared_type' => 'product', 'compared_id' => $soy->id, 'comparison' => $comparison]);
        }
        Review::factory()->forSubject($product)->create(['compared_type' => 'product', 'compared_id' => $rice->id, 'comparison' => 'worse']);
        Review::factory()->forSubject($product)->create(['compared_type' => 'product', 'compared_id' => $gone->id, 'comparison' => 'worse']);
        Review::factory()->forSubject($product)->create(['compared_type' => 'product', 'compared_id' => $soy->id, 'comparison' => 'worse', 'counted' => false]);
        $this->app->make(RatingAggregator::class)->refresh($product);

        $comparisons = $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->json('data.comparisons');

        $this->assertSame(['type' => 'product', 'id' => $soy->id, 'title' => 'Sojadrank', 'url' => '/products/'.$soy->id, 'count' => 3], $comparisons['often_switch_to']);
        $this->assertSame([$soy->id, $rice->id], array_column($comparisons['alternatives'], 'id'), 'The hidden alternative is left out.');
        $this->assertSame(['better' => 1, 'worse' => 3, 'same' => 0, 'total' => 4], array_intersect_key($comparisons['alternatives'][0], array_flip(['better', 'worse', 'same', 'total'])));

        // One reviewer is not "often": below switch_min_reviews nothing is claimed.
        $single = $this->product();
        Review::factory()->forSubject($single)->create(['compared_type' => 'product', 'compared_id' => $soy->id, 'comparison' => 'worse']);
        $this->app->make(RatingAggregator::class)->refresh($single);
        $this->assertNull($this->getJson("/rr/subjects/product/{$single->id}/rating-summary")->json('data.comparisons.often_switch_to'));
    }

    public function test_the_price_at_review_is_stored_on_submit_and_not_changed_by_edits(): void
    {
        $this->bindPrice(new Money(189, 'eur'));
        $product = $this->product();
        $user = $this->user();

        $id = $this->actingAs($user)->postJson($this->url($product->id), ['rating' => 4])
            ->assertCreated()
            ->assertJsonPath('data.price.cents', 189)
            ->assertJsonPath('data.price.currency', 'EUR')
            ->json('data.id');

        $this->bindPrice(new Money(249, 'EUR'));
        $this->actingAs($user)->patchJson("/rr/reviews/{$id}", ['rating' => 5])->assertOk()->assertJsonPath('data.price.cents', 189);
        $this->getJson($this->url($product->id))->assertJsonPath('meta.features.price_at_review', true);
    }

    public function test_a_failing_or_invalid_price_never_costs_the_review(): void
    {
        $product = $this->product();

        $this->bindPrice(null, throws: true);
        $this->actingAs($this->user())->postJson($this->url($product->id), ['rating' => 4])->assertCreated()->assertJsonPath('data.price', null);

        $this->bindPrice(new Money(100, 'euro'));
        $this->actingAs($this->user())->postJson($this->url($product->id), ['rating' => 4])->assertCreated()->assertJsonPath('data.price', null);

        $this->bindPrice(new Money(-5, 'EUR'));
        $this->actingAs($this->user())->postJson($this->url($product->id), ['rating' => 4])->assertCreated()->assertJsonPath('data.price', null);

        $this->assertSame(3, Review::query()->whereNull('price_cents')->count());
    }

    public function test_without_a_resolver_no_price_is_stored_or_asked(): void
    {
        $product = $this->product();

        $this->actingAs($this->user())->postJson($this->url($product->id), ['rating' => 4])->assertCreated()->assertJsonPath('data.price', null);
        $this->getJson($this->url($product->id))->assertJsonPath('meta.features.price_at_review', false);
    }
}
