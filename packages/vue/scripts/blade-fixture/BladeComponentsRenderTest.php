<?php

namespace RatingsAndReviews\Tests\BladeFixture;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Tests\TestCase;

/**
 * Renders the four Blade components through a real web route (MySQL 8.4, the package's
 * Testbench setup), checks the server output and writes it to
 * packages/vue/tests/fixtures/blade-page*.html, where tests/blade.spec.ts runs the
 * browser enhancement (resources/js/blade) against exactly this markup.
 *
 * Run from the package root (any empty MySQL schema; RefreshDatabase migrates it):
 *   DB_DATABASE=rr_ui_views vendor/bin/phpunit --bootstrap vendor/autoload.php packages/vue/scripts/blade-fixture/BladeComponentsRenderTest.php
 */
class BladeComponentsRenderTest extends TestCase
{
    private function page(string $template, $product): void
    {
        Route::middleware('web')->get('/page', fn () => Blade::render($template, ['p' => $product]));
    }

    public function test_components_render_with_real_data(): void
    {
        $this->configureSection('product', ['editor_tools' => ['bold', 'italic', 'link', 'quote', 'product_card'], 'vote_dimensions' => ['agreement', 'helpfulness', 'needs_source'], 'review_scale' => 5, 'digest_threshold' => 2]);
        $product = $this->product();
        $author = $this->user();
        $viewer = $this->user();

        $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'title' => 'Lekker', 'body' => '<p>Romig <script>x</script></p>', 'pros' => ['Romig'], 'cons' => ['Duur'], 'facets' => ['purchase' => 'first_time']])->assertCreated();
        $root = $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Ik vind het te zoet voor mij.</p>', 'poll' => ['question' => 'Warm of koud?', 'options' => ['Warm', 'Koud']]])->assertCreated()->json('data');
        $this->actingAs($viewer)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Eens, echt te zoet.</p>', 'parent_id' => $root['id'], 'quoted_comment_id' => $root['id'], 'quote_text' => 'te zoet'])->assertCreated();
        $this->actingAs($viewer)->postJson("/rr/comments/{$root['id']}/votes", ['dimension' => 'agreement', 'value' => 1])->assertOk();

        $this->page('<x-rr::rating-summary :subject="$p" /><x-rr::reviews :subject="$p" /><x-rr::review-form :subject="$p" action="/host/reviews" /><x-rr::discussion :subject="$p" action="/host/comments" />', $product);

        $response = $this->actingAs($viewer)->get('/page?rr_rating=4&rr_cq=zoet');
        $response->assertOk();
        $html = $response->getContent();
        file_put_contents(dirname(__DIR__, 2).'/tests/fixtures/blade-page.html', $html);

        $this->assertStringContainsString('data-rr="summary"', $html);
        $this->assertStringContainsString('data-rr="reviews"', $html);
        $this->assertStringContainsString('data-rr="review-form"', $html);
        $this->assertStringContainsString('data-rr="discussion"', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('Warm of koud?', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('id="rr-messages"', $html);
        $this->assertSame(1, substr_count($html, 'id="rr-messages"'));
        $this->assertStringContainsString('Filtered on 4 out of 5', $html);
        $this->assertStringContainsString('Nothing found for &quot;zoet&quot;', $html); // FULLTEXT does not see uncommitted rows (RefreshDatabase).
        $this->assertStringContainsString('data-rr-quality-rules', $html);
        $this->assertStringContainsString('name="rating"', $html);
        $this->assertStringContainsString('<template data-rr-reply-template>', $html);

        // Guest view: no composer, no form, login notices.
        $this->app['auth']->forgetGuards();
        $guest = $this->get('/page')->assertOk()->getContent();
        file_put_contents(dirname(__DIR__, 2).'/tests/fixtures/blade-page-guest.html', $guest);
        $this->assertStringNotContainsString('data-rr-composer', $guest);
        $this->assertStringContainsString('Log in to comment.', $guest);
        $this->assertStringContainsString('Log in to write a review.', $guest);

        // No-JS reply: ?rr_creply=<id> renders the main composer as a reply.
        $reply = $this->actingAs($viewer)->get('/page?rr_creply='.$root['id'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="parent_id" value="'.$root['id'].'"/', $reply);
    }
}
