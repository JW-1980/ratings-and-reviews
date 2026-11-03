<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Contracts\AttributionResolver;
use RatingsAndReviews\Contracts\BlockList;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewAnswer;
use RatingsAndReviews\Models\ReviewFollowUp;
use RatingsAndReviews\Models\ReviewQuestion;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Support\Attribution;
use RatingsAndReviews\Tests\Fixtures\ArrayBlockList;
use RatingsAndReviews\Tests\Fixtures\GroupPost;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\Fixtures\StaticAttributions;
use RatingsAndReviews\Tests\TestCase;

/**
 * The Blade side of attribution badges, pins, blocks, participation rules, review questions
 * and follow-ups (with the form routes on).
 */
class BladeParticipationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('ratings-and-reviews.web_routes.enabled', true);
    }

    private function page(Product $subject, string $template): void
    {
        Route::middleware('web')->get('/page', fn () => Blade::render($template, ['p' => $subject]));
    }

    private function html(): string
    {
        return (string) preg_replace('/<script type="application\/json"[^>]*>.*?<\/script>/s', '', $this->get('/page')->assertOk()->getContent());
    }

    private function question(array $attributes = []): ReviewQuestion
    {
        return ReviewQuestion::query()->create($attributes + [
            'scope' => 'product', 'key' => 'sweet', 'type' => 'scale',
            'label' => ['nl' => 'Zoet', 'en' => 'Sweetness'], 'low_label' => ['nl' => 'Niet', 'en' => 'Not sweet'], 'high_label' => ['nl' => 'Heel', 'en' => 'Very sweet'],
        ]);
    }

    public function test_the_discussion_shows_the_pinned_comment_badges_and_blocked_members(): void
    {
        $blocks = new ArrayBlockList;
        $this->app->instance(BlockList::class, $blocks);
        $product = $this->product();
        $viewer = $this->moderator();
        $blocked = $this->user();
        $blocks->block($viewer->id, $blocked->id);
        Comment::factory()->forSubject($product)->create(['body_html' => '<p>Gewone reactie</p>', 'user_id' => $blocked->id]);
        Comment::factory()->forSubject($product)->create(['body_html' => '<p>Officieel antwoord</p>', 'pinned_at' => now(), 'attribution' => ['key' => 'brand:1', 'label' => 'Oatly', 'url' => null]]);
        $this->page($product, '<x-rr::discussion :subject="$p" />');
        $this->actingAs($viewer);

        $html = $this->html();
        $this->assertLessThan(strpos($html, 'Gewone reactie'), strpos($html, 'Officieel antwoord'), 'The pinned comment comes first.');
        $this->assertStringContainsString('rr-tag--pinned', $html);
        $this->assertStringContainsString('Posted as Oatly', $html);
        $this->assertStringContainsString('data-rr-pin=', $html);
        $this->assertStringContainsString('/rr-forms/comments/', $html);
        $this->assertStringContainsString(__('ratings-and-reviews::ui.discussion.blocked'), $html);
        $this->assertStringContainsString('data-blocked="1"', $html);
    }

    public function test_members_kept_out_by_the_participation_rules_see_a_notice_instead_of_the_composer(): void
    {
        $post = GroupPost::query()->create(['name' => 'Groepspost']);
        GroupPost::$members = [];
        $this->page($post, '<x-rr::discussion :subject="$p" /><x-rr::review-form :subject="$p" />');
        $this->actingAs($this->user());

        $html = $this->html();
        $this->assertStringContainsString(__('ratings-and-reviews::ui.discussion.participation_denied'), $html);
        $this->assertStringContainsString(__('ratings-and-reviews::ui.reviews.participation_denied'), $html);
        $this->assertStringNotContainsString('data-rr-composer', $html);
        $this->assertStringNotContainsString('data-rr-review-form', $html);
    }

    public function test_the_composer_and_the_review_form_offer_the_allowed_attributions_and_questions(): void
    {
        $resolver = new StaticAttributions;
        $this->app->instance(AttributionResolver::class, $resolver);
        $member = $this->user();
        $resolver->byUser[$member->id] = [new Attribution('brand:2', 'Alpro')];
        $this->question();
        $this->question(['key' => 'moments', 'type' => 'multi', 'label' => ['nl' => 'Wanneer', 'en' => 'When'], 'low_label' => null, 'high_label' => null,
            'options' => [['value' => 'breakfast', 'label' => ['nl' => 'Ontbijt', 'en' => 'Breakfast']], ['value' => 'lunch', 'label' => ['nl' => 'Lunch', 'en' => 'Lunch']]]]);
        $product = $this->product();
        $this->page($product, '<x-rr::discussion :subject="$p" /><x-rr::review-form :subject="$p" />');
        $this->actingAs($member);

        $html = $this->html();
        $this->assertSame(3, substr_count($html, '<option value="brand:2"'), 'The composer, the reply template and the review form.');
        $this->assertStringContainsString('name="answers[sweet]"', $html);
        $this->assertStringContainsString('name="answers[moments][]" value="breakfast"', $html);
        $this->assertStringContainsString('Very sweet', $html);

        // Posting the form without JavaScript stores the answers and the attribution.
        $this->from('/page')->post("/rr-forms/subjects/product/{$product->id}/reviews", ['rating' => 4, 'answers' => ['sweet' => '5', 'moments' => ['lunch']], 'attribution' => 'brand:2'])->assertRedirect();
        $review = Review::query()->sole();
        $this->assertSame('Alpro', $review->attribution['label']);
        $this->assertSame(2, ReviewAnswer::query()->where('review_id', $review->id)->count());
    }

    public function test_the_review_card_and_summary_show_answers_and_follow_ups(): void
    {
        $this->configureSection('product', ['review_follow_up_after_days' => 30]);
        $sweet = $this->question();
        $product = $this->product();
        $author = $this->user();
        $other = Review::factory()->forSubject($product)->rated(4)->create(['approved_at' => now()->subDays(40), 'title' => 'Andere review']);
        ReviewAnswer::query()->create(['review_id' => $other->id, 'question_id' => $sweet->id, 'value' => 4]);
        ReviewFollowUp::query()->create(['review_id' => $other->id, 'user_id' => $other->user_id, 'rating' => 2, 'scale_max' => 5, 'initial_rating' => 4, 'initial_scale_max' => 5, 'body_text' => 'Na een maand <b>te zoet</b>']);
        Review::factory()->forSubject($product)->create(['user_id' => $author->id, 'approved_at' => now()->subDays(31)]);
        $this->app->make(RatingAggregator::class)->refresh($product);
        $this->page($product, '<x-rr::rating-summary :subject="$p" /><x-rr::reviews :subject="$p" />');
        $this->actingAs($author);

        $html = $this->html();
        $this->assertStringContainsString('What reviewers say', $html);
        $this->assertStringContainsString('4 out of 5', $html);
        $this->assertStringContainsString('After 40 days', $html);
        $this->assertStringContainsString('Na een maand &lt;b&gt;te zoet&lt;/b&gt;', $html);
        $this->assertStringContainsString('Was 4 out of 5, now 2 out of 5', $html);
        $this->assertStringContainsString('data-rr-follow-up-form=', $html);
        $this->assertStringContainsString('/rr-forms/reviews/', $html);
        $this->assertStringContainsString(__('ratings-and-reviews::ui.summary.follow_up_few'), $html);
    }

    public function test_the_list_and_the_form_disappear_when_reviews_are_off(): void
    {
        $this->configureSection('product', ['reviews_enabled' => false]);
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['title' => 'Oude review']);
        $this->page($product, '<x-rr::reviews :subject="$p" /><x-rr::review-form :subject="$p" />');
        $this->actingAs($this->user());

        $html = $this->html();
        $this->assertStringNotContainsString('rr-reviews', $html);
        $this->assertStringNotContainsString('data-rr-review-form', $html);
        $this->assertStringNotContainsString('Oude review', $html);
    }
}
