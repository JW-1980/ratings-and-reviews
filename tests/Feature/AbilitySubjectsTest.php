<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Sticker;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ModerationScope;
use RatingsAndReviews\Support\SectionScope;
use RatingsAndReviews\Tests\Fixtures\User;
use RatingsAndReviews\Tests\TestCase;

/**
 * Every ability check names what it is about, so a host can split moderation per section
 * and kind; the moderation lists only hold what the user may moderate.
 */
class AbilitySubjectsTest extends TestCase
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $asked = [];

    /**
     * A host that lets this user moderate reviews (not comments) in the `product` section
     * only, reports about them, and manage the `product` section and topics only.
     */
    private function bindSectionModerator(): void
    {
        $asked = &$this->asked;

        $this->app->instance(AbilityResolver::class, new class($asked) implements AbilityResolver
        {
            /** @param  list<array{0: string, 1: mixed}>  $asked */
            public function __construct(private array &$asked) {}

            public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool
            {
                $this->asked[] = [$ability, $subject];

                if (! $user instanceof User) {
                    return false;
                }

                return match (true) {
                    $subject instanceof ModerationScope && $subject->inbox => in_array($subject->kind, [ModerationScope::REVIEW, ModerationScope::REPORT], true),
                    $subject instanceof ModerationScope => in_array($subject->kind, [ModerationScope::REVIEW, ModerationScope::REPORT], true)
                        && $subject->section === 'product' && in_array($subject->contentType, [null, 'rr_review'], true),
                    $subject instanceof Review => $subject->section === 'product',
                    $subject instanceof Report => $subject->section === 'product' && $subject->reportable_type === 'rr_review',
                    $subject instanceof Comment => false,
                    $subject instanceof SectionScope => $subject->section === 'product',
                    $subject instanceof RatingTopic => $subject->scope === 'product',
                    $subject instanceof Sticker => true,
                    $subject === null => true,
                    default => false,
                };
            }
        });
    }

    /**
     * @return list<string>
     */
    private function subjectsAskedFor(string $ability): array
    {
        return array_values(array_unique(array_map(
            fn (array $call): string => $call[1] === null ? 'null' : get_debug_type($call[1]),
            array_filter($this->asked, fn (array $call): bool => $call[0] === $ability),
        )));
    }

    public function test_reviews_store_the_section_of_their_subject(): void
    {
        $user = $this->user();
        $product = $this->product(['section' => 'forum']);

        $id = $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'body' => '<p>Smooth and not too sweet.</p>'])
            ->assertCreated()->json('data.id');

        $this->assertSame('forum', Review::query()->findOrFail($id)->section);
    }

    public function test_the_review_inbox_lists_only_the_sections_the_user_may_moderate(): void
    {
        $this->bindSectionModerator();
        $allowed = Review::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create();
        Review::factory()->forSubject($this->product(['section' => 'forum']))->status(ContentStatus::Pending)->create();
        Review::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create(['section' => null]);
        $moderator = $this->user();

        $this->actingAs($moderator)->getJson('/rr/admin/moderation?kind=reviews')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $allowed->id)
            ->assertJsonPath('meta.sections', ['product']);

        $this->actingAs($moderator)->getJson('/rr/admin/moderation?kind=comments')->assertForbidden();

        $scopes = array_values(array_filter(array_column($this->asked, 1), fn (mixed $subject): bool => $subject instanceof ModerationScope));
        $this->assertTrue($scopes[0]->inbox);
        $this->assertEqualsCanonicalizing(['product', 'forum', null], array_map(fn (ModerationScope $scope): ?string => $scope->section, array_slice($scopes, 1, 3)));
    }

    public function test_a_moderation_decision_is_checked_on_the_item(): void
    {
        $this->bindSectionModerator();
        $moderator = $this->user();
        $mine = Review::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create();
        $other = Review::factory()->forSubject($this->product(['section' => 'forum']))->status(ContentStatus::Pending)->create();
        $comment = Comment::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create();

        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/reviews/{$mine->id}", ['action' => 'approve'])->assertOk();
        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/reviews/{$other->id}", ['action' => 'approve'])->assertForbidden();
        $this->actingAs($moderator)->patchJson("/rr/admin/moderation/comments/{$comment->id}", ['action' => 'approve'])->assertForbidden();
        $this->actingAs($moderator)->patchJson('/rr/admin/moderation/reviews/999999', ['action' => 'approve'])->assertNotFound();

        $this->assertSame(ContentStatus::Approved, $mine->refresh()->status);
        $this->assertSame(ContentStatus::Pending, $other->refresh()->status);
        $this->assertSame([Review::class, Comment::class], $this->subjectsAskedFor(Ability::MODERATE));
    }

    public function test_the_report_inbox_is_filtered_by_item_type_and_section_and_resolving_is_checked_on_the_report(): void
    {
        $this->bindSectionModerator();
        $moderator = $this->user();
        $review = Review::factory()->forSubject($this->product())->create();
        $forumReview = Review::factory()->forSubject($this->product(['section' => 'forum']))->create();
        $comment = Comment::factory()->forSubject($this->product())->create();
        $reporter = $this->user();
        $ids = [];

        foreach ([$review, $forumReview, $comment] as $item) {
            $ids[] = $this->actingAs($reporter)->postJson('/rr/reports', ['reportable_type' => $item->getMorphClass(), 'reportable_id' => $item->id, 'reason' => 'spam'])
                ->assertCreated()->json('data.id');
        }

        $this->assertSame(['product', 'forum', 'product'], Report::query()->orderBy('id')->pluck('section')->all());

        $this->actingAs($moderator)->getJson('/rr/admin/reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ids[0]);

        $this->actingAs($moderator)->patchJson("/rr/admin/reports/{$ids[1]}", ['status' => 'dismissed'])->assertForbidden();
        $this->actingAs($moderator)->patchJson("/rr/admin/reports/{$ids[0]}", ['status' => 'dismissed'])->assertOk();
        $this->assertContains(Report::class, $this->subjectsAskedFor(Ability::MODERATE));
    }

    public function test_discussion_controls_are_checked_on_the_subject(): void
    {
        $product = $this->product();
        $seen = [];
        $this->app->instance(AbilityResolver::class, new class($seen) implements AbilityResolver
        {
            /** @param  list<mixed>  $seen */
            public function __construct(private array &$seen) {}

            public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool
            {
                $this->seen[] = $subject;

                return $user !== null;
            }
        });

        $this->actingAs($this->user())->getJson("/rr/admin/discussions/product/{$product->id}")->assertOk();
        $this->actingAs($this->user())->patchJson("/rr/admin/discussions/product/{$product->id}", ['locked' => true])->assertOk();

        $this->assertCount(2, $seen);
        $this->assertTrue($seen[0]->is($product));
        $this->assertTrue($seen[1]->is($product));
    }

    public function test_sections_topics_and_stickers_are_checked_on_what_is_managed(): void
    {
        $this->bindSectionModerator();
        config(['ratings-and-reviews.sections.forum' => ['max_depth' => 2]]);
        $admin = $this->user();
        $productTopic = RatingTopic::factory()->create(['scope' => 'product']);
        $forumTopic = RatingTopic::factory()->create(['scope' => 'forum']);
        $sticker = Sticker::query()->create(['name' => ['nl' => 'Hart', 'en' => 'Heart'], 'path' => 'stickers/heart.png']);

        $this->actingAs($admin)->getJson('/rr/admin/sections')->assertOk()->assertJsonPath('data.*.section', ['product']);
        $this->actingAs($admin)->getJson('/rr/admin/sections/forum')->assertForbidden();
        $this->actingAs($admin)->patchJson('/rr/admin/sections/forum', ['max_depth' => 1])->assertForbidden();
        $this->actingAs($admin)->patchJson('/rr/admin/sections/product', ['max_depth' => 1])->assertOk();

        $this->actingAs($admin)->getJson('/rr/admin/topics')->assertOk()->assertJsonPath('data.*.id', [$productTopic->id]);
        $this->actingAs($admin)->patchJson("/rr/admin/topics/{$forumTopic->id}", ['sort_order' => 3])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/rr/admin/topics/{$forumTopic->id}")->assertForbidden();
        $this->actingAs($admin)->patchJson("/rr/admin/topics/{$productTopic->id}", ['sort_order' => 3])->assertOk();
        $this->actingAs($admin)->postJson('/rr/admin/topics', ['scope' => 'forum', 'name' => ['nl' => 'Toon', 'en' => 'Tone']])->assertForbidden();
        $this->actingAs($admin)->postJson('/rr/admin/topics', ['scope' => 'product', 'name' => ['nl' => 'Smaak', 'en' => 'Taste']])->assertCreated();

        $this->actingAs($admin)->patchJson("/rr/admin/stickers/{$sticker->id}", ['sort_order' => 2])->assertOk();

        $this->assertContains(SectionScope::class, $this->subjectsAskedFor(Ability::MANAGE_SETTINGS));
        $this->assertContains(Sticker::class, $this->subjectsAskedFor(Ability::MANAGE_SETTINGS));
        $this->assertContains(RatingTopic::class, $this->subjectsAskedFor(Ability::MANAGE_TOPICS));
    }

    public function test_moderator_checks_in_the_public_api_name_the_item(): void
    {
        $this->bindSectionModerator();
        $moderator = $this->user();
        $review = Review::factory()->forSubject($this->product())->create();
        $forumReview = Review::factory()->forSubject($this->product(['section' => 'forum']))->create();

        $this->actingAs($moderator)->deleteJson("/rr/reviews/{$forumReview->id}")->assertForbidden();
        $this->actingAs($moderator)->deleteJson("/rr/reviews/{$review->id}")->assertNoContent();
        $this->assertSoftDeleted($review);
        $this->assertNotSoftDeleted($forumReview);
    }

    public function test_the_migration_fills_the_section_of_existing_reviews_and_reports(): void
    {
        $review = Review::factory()->forSubject($this->product(['section' => 'forum']))->create();
        Review::query()->whereKey($review->id)->update(['section' => null]);
        $report = Report::factory()->create(['reportable_type' => 'rr_review', 'reportable_id' => $review->id]);
        Report::query()->whereKey($report->id)->update(['section' => null]);

        $migration = require __DIR__.'/../../database/migrations/2026_11_02_000008_add_section_to_rr_reviews_and_rr_reports.php';
        (function (): void {
            $this->fillReviewSections();
            $this->fillReportSections();
        })->call($migration);

        $this->assertSame('forum', $review->refresh()->section);
        $this->assertSame('forum', $report->refresh()->section);
    }
}
