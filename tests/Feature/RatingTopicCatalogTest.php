<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Services\RatingTopicCatalog;
use RatingsAndReviews\Tests\TestCase;

class RatingTopicCatalogTest extends TestCase
{
    private RatingTopicCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = $this->app->make(RatingTopicCatalog::class);
    }

    private function create(array $data = []): RatingTopic
    {
        return $this->catalog->create($data + ['scope' => 'product', 'name' => ['nl' => 'Smaak', 'en' => 'Taste']]);
    }

    public function test_at_most_four_topics_are_active_per_scope_and_category(): void
    {
        foreach (range(1, 4) as $i) {
            $this->create();
        }

        try {
            $this->create();
            $this->fail('A fifth active topic was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['At most 4 rating topics can be active at the same time. Switch one off first.'], $exception->errors()['is_active']);
        }

        $inactive = $this->create(['is_active' => false]);
        $this->assertFalse($inactive->is_active);

        $this->expectException(ValidationException::class);
        $this->catalog->update($inactive, ['is_active' => true]);
    }

    public function test_the_cap_is_counted_per_category_and_scope(): void
    {
        foreach (range(1, 4) as $i) {
            $this->create();
        }

        $this->assertTrue($this->create(['category_key' => 'drinks'])->is_active);
        $this->assertTrue($this->create(['scope' => 'additive'])->is_active);
        $this->assertSame(4, RatingTopic::query()->where('scope', 'product')->whereNull('category_key')->where('is_active', true)->count());
    }

    public function test_switching_a_topic_off_makes_room(): void
    {
        $topics = collect(range(1, 4))->map(fn (): RatingTopic => $this->create());
        $this->catalog->update($topics->first(), ['is_active' => false]);

        $this->assertTrue($this->create()->is_active);
    }

    public function test_category_topics_replace_their_parent_or_are_added(): void
    {
        $taste = $this->create(['name' => ['nl' => 'Smaak', 'en' => 'Taste'], 'sort_order' => 1]);
        $price = $this->create(['name' => ['nl' => 'Prijs', 'en' => 'Price'], 'sort_order' => 2]);
        $coffeeTaste = $this->create(['category_key' => 'coffee', 'parent_id' => $taste->id, 'name' => ['nl' => 'Aroma', 'en' => 'Aroma']]);
        $foam = $this->create(['category_key' => 'coffee', 'name' => ['nl' => 'Schuim', 'en' => 'Foam']]);
        $this->create(['category_key' => 'tea', 'name' => ['nl' => 'Thee', 'en' => 'Tea']]);

        $this->assertSame([$taste->id, $price->id], $this->catalog->effective('product')->modelKeys());
        $this->assertSame([$coffeeTaste->id, $price->id, $foam->id], $this->catalog->effective('product', ['drinks', 'coffee'])->modelKeys());
    }

    public function test_a_parent_must_come_from_a_broader_group_of_the_same_scope(): void
    {
        $global = $this->create();
        $otherScope = $this->create(['scope' => 'additive']);

        foreach ([
            ['category_key' => null, 'parent_id' => $global->id],
            ['category_key' => 'coffee', 'parent_id' => $otherScope->id],
            ['category_key' => 'coffee', 'parent_id' => 999999],
        ] as $data) {
            try {
                $this->create($data);
                $this->fail('An invalid parent was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
            }
        }
    }

    public function test_the_admin_api_requires_the_manage_topics_ability(): void
    {
        $payload = ['scope' => 'product', 'name' => ['nl' => 'Smaak', 'en' => 'Taste']];

        $this->postJson('/rr/admin/topics', $payload)->assertUnauthorized();
        $this->actingAs($this->user())->postJson('/rr/admin/topics', $payload)->assertForbidden();
        $this->actingAs($this->user())->getJson('/rr/admin/topics')->assertForbidden();
        $this->assertSame(0, RatingTopic::query()->count());

        $moderator = $this->moderator();
        $id = $this->actingAs($moderator)->postJson('/rr/admin/topics', $payload)->assertCreated()->assertJsonPath('data.label', 'Taste')->json('data.id');
        $this->actingAs($moderator)->postJson('/rr/admin/topics', ['scope' => 'product', 'name' => ['nl' => 'X']])->assertUnprocessable()->assertJsonValidationErrors('name.en');
        $this->actingAs($moderator)->patchJson("/rr/admin/topics/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($moderator)->getJson('/rr/admin/topics?scope=product')->assertOk()->assertJsonPath('data.0.ratings_count', 0)->assertJsonPath('meta.max_active', 4);
        $this->actingAs($moderator)->deleteJson("/rr/admin/topics/{$id}")->assertOk()->assertJsonPath('data.deleted_ratings', 0);
        $this->actingAs($moderator)->deleteJson("/rr/admin/topics/{$id}")->assertNotFound();
    }
}
