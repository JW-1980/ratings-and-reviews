<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Contracts\OfficialReplyResolver;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\TopicRating;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\OfficialReply;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\TestCase;

/**
 * The Blade side of official replies, the photo filter, replies to reviews and the topic
 * scores without a review (with the form routes on).
 */
class BladeNewFeaturesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('ratings-and-reviews.web_routes.enabled', true);
    }

    private function page(Product $product, string $template): void
    {
        Route::middleware('web')->get('/page', fn () => Blade::render($template, ['p' => $product]));
    }

    private function markup(string $html): string
    {
        return (string) preg_replace('/<script type="application\/json"[^>]*>.*?<\/script>/s', '', $html);
    }

    public function test_the_review_card_shows_the_official_reply_and_the_list_filters_on_photos(): void
    {
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create(['title' => 'Met foto']);
        Review::factory()->forSubject($product)->create(['title' => 'Zonder foto']);
        Upload::query()->create(['user_id' => $review->user_id, 'disk' => 'public', 'path' => 'p.jpg', 'attached_type' => 'rr_review', 'attached_id' => $review->id, 'role' => Upload::ROLE_PHOTO]);
        $this->app->instance(OfficialReplyResolver::class, new class($review->id) implements OfficialReplyResolver
        {
            public function __construct(private int $reviewId) {}

            public function isEnabled(): bool
            {
                return true;
            }

            public function repliesFor(array $reviews): array
            {
                return [$this->reviewId => new OfficialReply('Oatly', 'Bedankt <3')];
            }
        });
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $html = $this->markup($this->get('/page')->assertOk()->getContent());
        $this->assertStringContainsString('Official reply from Oatly', $html);
        $this->assertStringContainsString('<p>Bedankt &lt;3</p>', $html);
        $this->assertStringContainsString('name="rr_photos"', $html);

        $filtered = $this->markup($this->get('/page?rr_photos=1')->assertOk()->getContent());
        $this->assertStringContainsString('Met foto', $filtered);
        $this->assertStringNotContainsString('Zonder foto', $filtered);
    }

    public function test_replies_to_a_review_open_below_the_list(): void
    {
        config(['ratings-and-reviews.reviews.replies.enabled' => true, 'ratings-and-reviews.sections.review_replies' => ['max_depth' => 1]]);
        $this->app->make(SectionSettingsRepository::class)->forget('review_replies');
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create();
        Comment::factory()->forSubject($review)->create(['section' => 'review_replies', 'body_html' => '<p>Helemaal mee eens</p>']);
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $closed = $this->markup($this->get('/page')->assertOk()->getContent());
        $this->assertStringContainsString('Show replies (1)', $closed);
        $this->assertStringContainsString('rr_rthread='.$review->id, $closed);
        $this->assertStringNotContainsString('Helemaal mee eens', $closed);

        $open = $this->markup($this->get('/page?rr_rthread='.$review->id)->assertOk()->getContent());
        $this->assertStringContainsString('id="rr-review-replies-'.$review->id.'"', $open);
        $this->assertStringContainsString('Helemaal mee eens', $open);
        $this->assertStringContainsString('data-rr="discussion"', $open);
    }

    public function test_members_give_topic_scores_without_javascript(): void
    {
        $this->configureSection('product', ['standalone_topic_ratings' => true]);
        $product = $this->product();
        $topic = RatingTopic::factory()->create(['name' => ['nl' => 'Smaak', 'en' => 'Taste']]);
        $user = $this->user();
        $this->page($product, '<x-rr::topic-ratings :subject="$p" login-url="/login" />');

        $guest = $this->markup($this->get('/page')->assertOk()->getContent());
        $this->assertStringContainsString('Taste', $guest);
        $this->assertStringContainsString('href="/login"', $guest);
        $this->assertStringNotContainsString('name="ratings['.$topic->id.']"', $guest);

        $form = $this->markup($this->actingAs($user)->get('/page')->assertOk()->getContent());
        $this->assertStringContainsString('name="ratings['.$topic->id.']"', $form);
        $this->assertStringContainsString('name="_method" value="PUT"', $form);

        $this->actingAs($user)->from('/page')->put("/rr-forms/subjects/product/{$product->id}/topic-ratings", ['ratings' => [$topic->id => '4']])
            ->assertRedirect('/page#rr-topic-ratings-product-'.$product->id);
        $this->assertSame(4, TopicRating::query()->sole()->value);

        $after = $this->markup($this->actingAs($user)->get('/page')->assertOk()->getContent());
        $this->assertStringContainsString('Your scores have been saved.', $after);
        $this->assertStringContainsString('4.0 out of 5 (1 score)', $after);

        $this->actingAs($user)->from('/page')->put("/rr-forms/subjects/product/{$product->id}/topic-ratings", ['ratings' => [$topic->id => '9']])->assertRedirect();
        $this->assertStringContainsString('Choose a score from 1 to 5.', $this->markup($this->actingAs($user)->get('/page')->getContent()));
    }

    public function test_the_topic_scores_component_renders_nothing_while_the_section_has_them_off(): void
    {
        $product = $this->product();
        RatingTopic::factory()->create();
        $this->page($product, '<x-rr::topic-ratings :subject="$p" />');

        $this->assertStringNotContainsString('rr-topic-ratings', $this->markup($this->get('/page')->assertOk()->getContent()));
    }
}
