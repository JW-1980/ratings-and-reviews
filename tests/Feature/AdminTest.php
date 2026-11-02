<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\SectionSetting;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

class AdminTest extends TestCase
{
    public function test_section_settings_require_the_manage_settings_ability(): void
    {
        $this->getJson('/rr/admin/sections')->assertUnauthorized();
        $this->actingAs($this->user())->getJson('/rr/admin/sections')->assertForbidden();
        $this->actingAs($this->user())->patchJson('/rr/admin/sections/product', ['max_depth' => 1])->assertForbidden();

        $this->actingAs($this->moderator())->getJson('/rr/admin/sections')
            ->assertOk()
            ->assertJsonPath('data.*.section', ['forum', 'product'])
            ->assertJsonPath('data.0.settings.max_depth', 2)
            ->assertJsonPath('meta.sorts.3.value', 'controversial');
    }

    public function test_an_admin_saves_section_settings_over_the_config_defaults(): void
    {
        $repository = $this->app->make(SectionSettingsRepository::class);
        $this->assertSame(5, $repository->for('product')->maxDepth);
        $admin = $this->moderator();

        $this->actingAs($admin)->patchJson('/rr/admin/sections/product', [
            'max_depth' => 3,
            'editor_tools' => ['bold', 'link'],
            'vote_dimensions' => ['helpfulness', 'needs_source'],
            'default_sort' => 'controversial',
            'review_scale' => 10,
            'unknown_key' => 'ignored',
        ])->assertOk()
            ->assertJsonPath('data.settings.max_depth', 3)
            ->assertJsonPath('data.settings.vote_dimensions', ['helpfulness', 'needs_source'])
            ->assertJsonPath('data.settings.review_scale', 10);

        $settings = $repository->for('product');
        $this->assertSame(3, $settings->maxDepth);
        $this->assertSame(['bold', 'link'], $settings->toolKeys());
        $this->assertTrue($settings->guestVotes, 'Unsaved keys keep their defaults.');

        $row = SectionSetting::query()->where('section', 'product')->sole();
        $this->assertSame($admin->id, $row->updated_by);
        $this->assertArrayNotHasKey('unknown_key', $row->settings);
    }

    public function test_section_settings_are_validated_and_unknown_sections_are_not_found(): void
    {
        $admin = $this->moderator();

        $this->actingAs($admin)->patchJson('/rr/admin/sections/product', ['max_depth' => 11, 'editor_tools' => ['bold', 'marquee'], 'review_scale' => 7, 'default_sort' => 'random'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_depth', 'editor_tools.1', 'review_scale', 'default_sort']);

        $this->actingAs($admin)->getJson('/rr/admin/sections/nope')->assertNotFound();
        $this->actingAs($admin)->patchJson('/rr/admin/sections/nope', ['max_depth' => 1])->assertNotFound();
        $this->assertSame(0, SectionSetting::query()->count());
    }

    public function test_the_moderation_inbox_lists_pending_items_and_applies_decisions(): void
    {
        $product = $this->product();
        $pendingReview = Review::factory()->forSubject($product)->status(ContentStatus::Pending)->create();
        Review::factory()->forSubject($product)->create();
        $pendingComment = Comment::factory()->forSubject($product)->status(ContentStatus::Pending)->create();
        $moderator = $this->moderator();

        $this->actingAs($this->user())->getJson('/rr/admin/moderation')->assertForbidden();

        $this->actingAs($moderator)->getJson('/rr/admin/moderation?kind=reviews')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pendingReview->id);
        $this->actingAs($moderator)->getJson('/rr/admin/moderation?kind=comments')->assertJsonPath('data.0.id', $pendingComment->id);

        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/reviews/{$pendingReview->id}", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertNotNull($pendingReview->fresh()->approved_at);

        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/comments/{$pendingComment->id}", ['action' => 'delete'])->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertSoftDeleted('rr_comments', ['id' => $pendingComment->id]);

        $this->actingAs($moderator)->patchJson('/rr/admin/moderation/things/1', ['action' => 'approve'])->assertNotFound();
        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/reviews/{$pendingReview->id}", ['action' => 'publish'])->assertUnprocessable();
    }
}
