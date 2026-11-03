<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\Participation;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\ParticipationKind;
use RatingsAndReviews\Tests\Fixtures\Branch;
use RatingsAndReviews\Tests\Fixtures\GroupPost;
use RatingsAndReviews\Tests\TestCase;

/**
 * KindAwareParticipationRules: a subject decides per kind of contribution. Replies to a
 * review are asked of the reviewed subject as `review_reply`, so branch staff can answer
 * reviews they could not write.
 */
class KindAwareParticipationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ratings-and-reviews.reviews.replies.enabled' => true,
            'ratings-and-reviews.sections.review_replies' => ['max_depth' => 1, 'vote_dimensions' => ['helpfulness']],
        ]);
        $this->app->make(SectionSettingsRepository::class)->forget('review_replies');
    }

    private function branch(): Branch
    {
        return Branch::query()->create(['name' => 'Filiaal Centrum']);
    }

    public function test_branch_staff_reply_to_reviews_they_may_not_write(): void
    {
        $branch = $this->branch();
        $customer = $this->user();
        $staff = $this->user();
        Branch::$allowed = [
            ParticipationKind::REVIEW => [$customer->id],
            ParticipationKind::REVIEW_REPLY => [$staff->id],
            ParticipationKind::COMMENT => [],
        ];

        $this->actingAs($staff)->postJson("/rr/subjects/branch/{$branch->id}/reviews", ['rating' => 5, 'body' => 'Wij zijn de beste.'])
            ->assertForbidden();
        $this->actingAs($staff)->getJson("/rr/subjects/branch/{$branch->id}/reviews")
            ->assertJsonPath('meta.can_participate', false);

        $reviewId = $this->actingAs($customer)->postJson("/rr/subjects/branch/{$branch->id}/reviews", ['rating' => 4, 'body' => 'Vriendelijk geholpen, snel klaar.'])
            ->assertCreated()->json('data.id');

        $replies = "/rr/subjects/rr_review/{$reviewId}/comments";
        $this->actingAs($staff)->getJson($replies)->assertOk()->assertJsonPath('meta.can_participate', true);
        $this->actingAs($staff)->postJson($replies, ['body' => 'Dank voor je bezoek, tot ziens!'])->assertCreated();

        // The customer may review but not reply; nobody may comment on the branch itself.
        $this->actingAs($customer)->getJson($replies)->assertJsonPath('meta.can_participate', false);
        $this->actingAs($customer)->postJson($replies, ['body' => 'Graag gedaan.'])->assertForbidden();
        $this->actingAs($customer)->postJson("/rr/subjects/branch/{$branch->id}/comments", ['body' => 'Een reactie op het filiaal.'])->assertForbidden();
        $this->actingAs($customer)->getJson("/rr/subjects/branch/{$branch->id}/comments")->assertJsonPath('meta.can_participate', false);

        $this->assertSame(1, Comment::query()->where('commentable_type', 'rr_review')->count());
        $this->assertContains(ParticipationKind::REVIEW_REPLY, Branch::$asked);
    }

    public function test_topic_ratings_and_follow_ups_are_asked_with_their_own_kind(): void
    {
        $this->configureSection('product', ['standalone_topic_ratings' => true, 'review_follow_up_after_days' => 30, 'review_follow_up_window_days' => 60]);
        $branch = $this->branch();
        $topic = RatingTopic::factory()->create();
        $member = $this->user();
        Branch::$allowed = [ParticipationKind::TOPIC_RATING => [], ParticipationKind::FOLLOW_UP => []];

        $this->actingAs($member)->putJson("/rr/subjects/branch/{$branch->id}/topic-ratings", ['ratings' => [$topic->id => 4]])->assertForbidden();

        $review = Review::factory()->forSubject($branch)->rated(4)->create(['user_id' => $member->id]);
        $this->travel(31)->days();
        $this->actingAs($member)->postJson("/rr/reviews/{$review->id}/follow-up", ['rating' => 2])->assertForbidden();

        Branch::$allowed = [];
        $this->actingAs($member)->putJson("/rr/subjects/branch/{$branch->id}/topic-ratings", ['ratings' => [$topic->id => 4]])->assertOk();
        $this->actingAs($member)->postJson("/rr/reviews/{$review->id}/follow-up", ['rating' => 2])->assertCreated();

        $this->assertSame([ParticipationKind::TOPIC_RATING, ParticipationKind::FOLLOW_UP], array_values(array_unique(array_intersect(Branch::$asked, [ParticipationKind::TOPIC_RATING, ParticipationKind::FOLLOW_UP]))));
    }

    public function test_a_subject_with_only_participation_rules_decides_every_kind_as_before(): void
    {
        $post = GroupPost::query()->create(['name' => 'Groepspost']);
        $member = $this->user();
        $outsider = $this->user();
        GroupPost::$members = [$member->id];
        $review = Review::factory()->forSubject($post)->create(['user_id' => $this->user()->id]);
        $participation = $this->app->make(Participation::class);

        foreach (ParticipationKind::all() as $kind) {
            $this->assertTrue($participation->canParticipate($member, $post, $kind), $kind);
            $this->assertFalse($participation->canParticipate($outsider, $post, $kind), $kind);
        }

        // Replies on a review of the post follow the post's single rule.
        $this->assertTrue($participation->canParticipate($member, $review));
        $this->assertFalse($participation->canParticipate($outsider, $review));
        $this->actingAs($outsider)->postJson("/rr/subjects/rr_review/{$review->id}/comments", ['body' => 'Mag ik iets vragen?'])->assertForbidden();
        $this->actingAs($member)->postJson("/rr/subjects/rr_review/{$review->id}/comments", ['body' => 'Mag ik iets vragen?'])->assertCreated();
    }

    public function test_without_any_rules_everyone_who_sees_the_subject_participates(): void
    {
        $product = $this->product();
        $participation = $this->app->make(Participation::class);

        foreach (ParticipationKind::all() as $kind) {
            $this->assertTrue($participation->canParticipate($this->user(), $product, $kind), $kind);
        }
    }
}
