<?php

namespace RatingsAndReviews\Tests\BladeFixture;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\CardExtensions;
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

    /**
     * A pinned, attributed comment seen by a moderator and the author's own review with an open
     * follow-up window: tests/blade-participation.spec.ts runs the pin and follow-up enhancement.
     */
    public function test_participation_features_render(): void
    {
        $this->configureSection('product', ['review_follow_up_after_days' => 30]);
        $product = $this->product();
        $author = $this->user();
        $moderator = $this->moderator();

        $review = Review::factory()->forSubject($product)->create(['user_id' => $author->id, 'title' => 'Eerste indruk', 'approved_at' => now()->subDays(31)]);
        Comment::factory()->forSubject($product)->create(['user_id' => $author->id, 'body_html' => '<p>Officieel antwoord</p>', 'body_text' => 'Officieel antwoord', 'pinned_at' => now(), 'attribution' => ['key' => 'brand:1', 'label' => 'Oatly', 'url' => null]]);
        Comment::factory()->forSubject($product)->create(['body_html' => '<p>Gewone reactie</p>', 'body_text' => 'Gewone reactie']);

        Route::middleware('web')->get('/reviews', fn () => Blade::render('<x-rr::reviews :subject="$p" />', ['p' => $product]));
        Route::middleware('web')->get('/discussion', fn () => Blade::render('<x-rr::discussion :subject="$p" action="/host/comments" />', ['p' => $product]));

        // One page with both: the author's review card (follow-up form) and the moderator's discussion (pin buttons).
        $own = $this->actingAs($author)->get('/reviews')->assertOk()->getContent();
        // The page-wide client data (messages, config, toast region) once, from the first part.
        $moderated = (string) preg_replace(['/<script type="application\/json" id="rr-(messages|config)">.*?<\/script>/s', '/<div class="rr-toast-region"[^>]*><\/div>/'], '', $this->actingAs($moderator)->get('/discussion')->assertOk()->getContent());
        $html = $own.$moderated;
        file_put_contents(dirname(__DIR__, 2).'/tests/fixtures/blade-page-participation.html', $html);

        $this->assertStringContainsString('data-rr-follow-up-form="'.$review->id.'"', $html);
        $this->assertStringContainsString('data-rr-pin=', $html);
        $this->assertStringContainsString('rr-tag--pinned', $html);
        $this->assertStringContainsString('Posted as Oatly', $html);
    }

    /**
     * Host card extensions (a reactions bar on reviews and comments, kudos in the review meta),
     * on page 1 and page 2 of the discussion: tests/blade-card-extensions.spec.ts runs the
     * browser hook registerCardExtension() against the first render, a re-render and "load more".
     */
    public function test_card_extensions_render(): void
    {
        config(['ratings-and-reviews.discussions.roots_per_page' => 2]);
        $product = $this->product();
        $viewer = $this->user();
        $review = Review::factory()->forSubject($product)->create(['title' => 'Met reacties']);
        $roots = collect([1, 2, 3])->map(fn (int $number) => Comment::factory()->forSubject($product)->create(['body_html' => "<p>Reactie {$number}</p>", 'body_text' => "Reactie {$number}", 'created_at' => now()->subMinutes(10 - $number)]));
        $reply = Comment::factory()->replyTo($roots->last())->create(['body_html' => '<p>Antwoord</p>', 'body_text' => 'Antwoord']);
        $preloads = [];
        $bar = function (array $item, ?Authenticatable $user, ?array $counts): HtmlString {
            $type = isset($item['rating']) ? 'review' : 'comment';

            return new HtmlString('<button type="button" class="host-reactions" data-host-type="'.$type.'" data-host-id="'.$item['id'].'" data-viewer="'.($user?->getAuthIdentifier() ?? '').'">👍 '.(int) ($counts[$item['id']] ?? 0).'</button>');
        };
        CardExtensions::preload(CardExtensions::REVIEW_FOOTER, function (Collection $items) use (&$preloads): array {
            $preloads[] = 'review:'.$items->pluck('id')->implode(',');

            return $items->mapWithKeys(fn (array $item): array => [$item['id'] => 3])->all();
        }, 'reactions');
        CardExtensions::preload(CardExtensions::COMMENT_FOOTER, function (Collection $items) use (&$preloads): array {
            $preloads[] = 'comment:'.$items->pluck('id')->implode(',');

            return $items->mapWithKeys(fn (array $item): array => [$item['id'] => 1])->all();
        }, 'reactions');
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, $bar, 'reactions');
        CardExtensions::register(CardExtensions::COMMENT_FOOTER, $bar, 'reactions');
        CardExtensions::register(CardExtensions::REVIEW_META, fn (array $item): string => 'Kudos <3', 'kudos');

        try {
            $this->page('<x-rr::reviews :subject="$p" /><x-rr::discussion :subject="$p" action="/host/comments" />', $product);
            $html = $this->actingAs($viewer)->get('/page')->assertOk()->getContent();
            file_put_contents(dirname(__DIR__, 2).'/tests/fixtures/blade-page-extensions.html', $html);
            $next = $this->actingAs($viewer)->get('/page?rr_cpage=2')->assertOk()->getContent();
            file_put_contents(dirname(__DIR__, 2).'/tests/fixtures/blade-page-extensions-2.html', $next);
        } finally {
            CardExtensions::forget();
        }

        $this->assertStringContainsString('data-rr-extension="review.footer" data-rr-item-type="review" data-rr-item-id="'.$review->id.'"', $html);
        $this->assertStringContainsString('>Kudos &lt;3</span>', $html);
        $this->assertSame(3, substr_count($html, 'data-rr-extension="comment.footer"'));
        $this->assertStringContainsString('data-rr-more', $html);
        $this->assertSame(1, substr_count($next, 'data-rr-extension="comment.footer"'));
        // One preload per list and request: the reviews, then every comment of the page with its replies.
        $this->assertSame(['review:'.$review->id, 'comment:'.$roots[2]->id.','.$reply->id.','.$roots[1]->id, 'review:'.$review->id, 'comment:'.$roots[0]->id], $preloads);
    }
}
