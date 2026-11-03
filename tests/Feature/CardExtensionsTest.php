<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\CardExtensions;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\TestCase;
use RuntimeException;

/**
 * Host extension points on the Blade review and comment cards (Support\CardExtensions):
 * rendering with the item and the viewer, escaping, the once-per-list preload, host views
 * from the config, vendor view overrides and failure isolation.
 */
class CardExtensionsTest extends TestCase
{
    private string $viewDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->viewDirectory = sys_get_temp_dir().'/rr-card-extensions-'.uniqid();
        File::ensureDirectoryExists($this->viewDirectory.'/partials');
    }

    protected function tearDown(): void
    {
        CardExtensions::forget();
        File::deleteDirectory($this->viewDirectory);
        parent::tearDown();
    }

    private function page(Product $product, string $template): void
    {
        Route::middleware('web')->get('/page', fn () => Blade::render($template, ['p' => $product]));
    }

    /** The markup without the page-wide JSON (messages contain every UI string). */
    private function markup(string $html): string
    {
        return (string) preg_replace('/<script type="application\/json"[^>]*>.*?<\/script>/s', '', $html);
    }

    public function test_a_registered_extension_renders_on_every_review_card_with_the_item_and_the_viewer(): void
    {
        $product = $this->product();
        $first = Review::factory()->forSubject($product)->create(['title' => 'Eerste']);
        $second = Review::factory()->forSubject($product)->create(['title' => 'Tweede']);
        $viewer = $this->user(['name' => 'Kijker']);
        $seen = [];
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, function (array $review, ?Authenticatable $user) use (&$seen): HtmlString {
            $seen[] = [$review['id'], $user?->getAuthIdentifier()];

            return new HtmlString('<button type="button" class="host-kudos" data-review="'.$review['id'].'">Kudos voor '.e($review['title']).'</button>');
        });
        CardExtensions::register(CardExtensions::REVIEW_META, fn (array $review): string => 'meta-'.$review['id']);
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $html = $this->markup($this->actingAs($viewer)->get('/page')->assertOk()->getContent());

        foreach ([$first, $second] as $review) {
            $this->assertStringContainsString('<div class="rr-extensions rr-extensions--footer" data-rr-extension="review.footer" data-rr-item-type="review" data-rr-item-id="'.$review->id.'"><button type="button" class="host-kudos" data-review="'.$review->id.'">Kudos voor '.$review->title.'</button></div>', $html);
            $this->assertStringContainsString('<span class="rr-extensions rr-extensions--meta" data-rr-extension="review.meta" data-rr-item-type="review" data-rr-item-id="'.$review->id.'">meta-'.$review->id.'</span>', $html);
        }

        // Inside the card: the footer extension sits in the card's footer, the meta one in its header.
        $this->assertMatchesRegularExpression('/<footer class="rr-review__footer">.*?data-rr-extension="review\.footer" data-rr-item-type="review" data-rr-item-id="'.$first->id.'"/s', $html);
        $this->assertEqualsCanonicalizing([[$first->id, $viewer->id], [$second->id, $viewer->id]], $seen);

        // A guest: the viewer is null.
        $seen = [];
        $this->app['auth']->forgetGuards();
        $this->get('/page')->assertOk();
        $this->assertEqualsCanonicalizing([[$first->id, null], [$second->id, null]], $seen);
    }

    public function test_plain_strings_are_escaped_and_only_htmlable_output_is_printed_as_is(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create();
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): string => '<script>alert(1)</script>');
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): HtmlString => new HtmlString('<em class="host-safe">ok</em>'));
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): ?string => null);
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $html = $this->markup($this->get('/page')->assertOk()->getContent());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<em class="host-safe">ok</em></div>', $html);
    }

    public function test_nothing_is_rendered_without_registrations_or_when_every_extension_returns_nothing(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create();
        $this->page($product, '<x-rr::reviews :subject="$p" />');
        $this->assertStringNotContainsString('data-rr-extension', $this->get('/page')->assertOk()->getContent());

        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): string => '');
        $this->assertStringNotContainsString('data-rr-extension', $this->get('/page')->assertOk()->getContent());
    }

    public function test_the_preload_runs_once_per_review_list_with_every_card_including_the_own_review(): void
    {
        $product = $this->product();
        $viewer = $this->user();
        $own = Review::factory()->forSubject($product)->create(['user_id' => $viewer->id]);
        $others = Review::factory()->count(3)->forSubject($product)->create();
        $calls = [];
        CardExtensions::preload(CardExtensions::REVIEW_FOOTER, function (Collection $reviews, ?Authenticatable $user) use (&$calls): array {
            $calls[] = [$reviews->pluck('id')->sort()->values()->all(), $user?->getAuthIdentifier()];

            return $reviews->mapWithKeys(fn (array $review): array => [$review['id'] => 'kudos:'.($review['id'] * 10)])->all();
        }, 'kudos');
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (array $review, ?Authenticatable $user, ?array $kudos): string => $kudos[$review['id']] ?? 'missing', 'kudos');
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $html = $this->markup($this->actingAs($viewer)->get('/page')->assertOk()->getContent());

        $ids = collect([$own->id, ...$others->pluck('id')])->sort()->values()->all();
        $this->assertSame([[$ids, $viewer->id]], $calls);

        foreach ($ids as $id) {
            $this->assertStringContainsString('kudos:'.($id * 10), $html);
        }

        $this->assertStringNotContainsString('missing', $html);
    }

    public function test_a_preload_without_a_registered_renderer_at_its_position_is_not_run(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create();
        $calls = 0;
        CardExtensions::preload(CardExtensions::REVIEW_META, function () use (&$calls): array {
            $calls++;

            return [];
        });
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $this->get('/page')->assertOk();

        $this->assertSame(0, $calls);
    }

    public function test_comment_extensions_render_on_replies_and_the_preload_runs_once_for_the_whole_discussion(): void
    {
        $product = $this->product();
        $author = $this->user();
        $root = $this->commentOn($product, ['body_html' => '<p>Wortel</p>']);
        $reply = Comment::factory()->replyTo($root)->create(['body_html' => '<p>Antwoord</p>']);
        $removed = $this->commentOn($product, ['user_id' => $author->id, 'body_html' => '<p>Weg</p>']);
        $orphanReply = Comment::factory()->replyTo($removed)->create(['body_html' => '<p>Blijft</p>']);
        $this->actingAs($author)->deleteJson("/rr/comments/{$removed->id}")->assertSuccessful();
        $this->app['auth']->forgetGuards();

        $calls = [];
        CardExtensions::preload(CardExtensions::COMMENT_FOOTER, function (Collection $comments) use (&$calls): array {
            $calls[] = $comments->pluck('id')->sort()->values()->all();

            return $comments->mapWithKeys(fn (array $comment): array => [$comment['id'] => ['👍' => $comment['id']]])->all();
        });
        CardExtensions::register(CardExtensions::COMMENT_FOOTER, fn (array $comment, ?Authenticatable $viewer, array $reactions): string => '👍 '.$reactions[$comment['id']]['👍']);
        CardExtensions::register(CardExtensions::COMMENT_META, fn (array $comment): string => 'depth '.$comment['depth']);
        $this->page($product, '<x-rr::discussion :subject="$p" />');

        $html = $this->markup($this->get('/page')->assertOk()->getContent());

        // Once, with the roots and their replies, without the removed placeholder.
        $this->assertSame([collect([$root->id, $reply->id, $orphanReply->id])->sort()->values()->all()], $calls);

        foreach ([$root, $reply, $orphanReply] as $comment) {
            $this->assertStringContainsString('data-rr-extension="comment.footer" data-rr-item-type="comment" data-rr-item-id="'.$comment->id.'">👍 '.$comment->id.'</div>', $html);
        }

        $this->assertStringContainsString('data-rr-extension="comment.meta" data-rr-item-type="comment" data-rr-item-id="'.$reply->id.'">depth 1</span>', $html);
        $this->assertStringNotContainsString('data-rr-item-id="'.$removed->id.'"', $html);
    }

    public function test_host_views_from_the_config_render_with_the_item_viewer_and_preloaded_data(): void
    {
        File::put($this->viewDirectory.'/kudos.blade.php', '<span class="host-view" data-id="{{ $review[\'id\'] }}">{{ $item[\'title\'] }} · {{ $viewer?->name ?? \'gast\' }} · {{ $preloaded[$item[\'id\']] ?? 0 }} · {{ $position }}</span>');
        $this->app['view']->addNamespace('host', $this->viewDirectory);
        config(['ratings-and-reviews.card_extensions.views' => ['review.footer' => ['host::kudos']]]);
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create(['title' => 'Romig & <zoet>']);
        CardExtensions::preload(CardExtensions::REVIEW_FOOTER, fn (Collection $reviews): array => [$review->id => 7]);
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $html = $this->markup($this->actingAs($this->user(['name' => 'Kijker']))->get('/page')->assertOk()->getContent());

        $this->assertStringContainsString('<span class="host-view" data-id="'.$review->id.'">Romig &amp; &lt;zoet&gt; · Kijker · 7 · review.footer</span>', $html);
    }

    public function test_an_unknown_position_in_the_config_or_a_registration_is_an_error(): void
    {
        $this->assertThrows(fn () => CardExtensions::register('review.sidebar', fn (): string => 'x'), InvalidArgumentException::class);
        $this->assertThrows(fn () => CardExtensions::preload('comments.footer', fn (): array => []), InvalidArgumentException::class);

        config(['ratings-and-reviews.card_extensions.views' => ['review.header' => ['host::x']]]);
        $this->assertThrows(fn () => CardExtensions::forList('review', [], null), InvalidArgumentException::class);
        $this->assertThrows(fn () => CardExtensions::forList('poll', [], null), InvalidArgumentException::class);
    }

    public function test_registering_the_same_key_again_replaces_the_extension(): void
    {
        CardExtensions::register(CardExtensions::COMMENT_FOOTER, fn (): string => 'old', 'reactions');
        CardExtensions::register(CardExtensions::COMMENT_FOOTER, fn (): string => 'new', 'reactions');
        CardExtensions::register(CardExtensions::COMMENT_FOOTER, fn (): string => 'other');

        $html = CardExtensions::forList('comment', [['id' => 5]], null)->render('comment.footer', ['id' => 5])->toHtml();

        $this->assertSame('<div class="rr-extensions rr-extensions--footer" data-rr-extension="comment.footer" data-rr-item-type="comment" data-rr-item-id="5">newother</div>', $html);
        $this->assertThrows(fn () => CardExtensions::forList('comment', [], null)->render('review.footer', ['id' => 5]), InvalidArgumentException::class);
    }

    public function test_vendor_overrides_of_the_card_partials_keep_working_next_to_the_extensions(): void
    {
        // A host override of the attribution badge (resources/views/vendor/rr/partials/attribution.blade.php).
        File::put($this->viewDirectory.'/partials/attribution.blade.php', '@if (! empty($attribution[\'label\']))<b class="host-business">{{ $attribution[\'label\'] }} (zakelijk)</b>@endif');
        $this->app['view']->prependNamespace('rr', $this->viewDirectory);
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['attribution' => ['key' => 'shop:1', 'label' => 'Bakkerij Jansen', 'url' => null]]);
        $this->commentOn($product, ['attribution' => ['key' => 'shop:1', 'label' => 'Bakkerij Jansen', 'url' => null]]);
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): string => 'review-kudos');
        CardExtensions::register(CardExtensions::COMMENT_FOOTER, fn (): string => 'comment-kudos');
        $this->page($product, '<x-rr::reviews :subject="$p" /><x-rr::discussion :subject="$p" />');

        $html = $this->markup($this->get('/page')->assertOk()->getContent());

        $this->assertSame(2, substr_count($html, '<b class="host-business">Bakkerij Jansen (zakelijk)</b>'));
        $this->assertStringNotContainsString('rr-tag--attribution', $html);
        $this->assertStringContainsString('>review-kudos</div>', $html);
        $this->assertStringContainsString('>comment-kudos</div>', $html);
    }

    public function test_a_card_partial_overridden_without_the_extension_calls_still_renders(): void
    {
        // An older host override of the review card that predates the extension points.
        File::put($this->viewDirectory.'/partials/review-card.blade.php', '<article class="host-card" data-rr-review="{{ $review[\'id\'] }}">{{ $review[\'title\'] }}</article>');
        $this->app['view']->prependNamespace('rr', $this->viewDirectory);
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['title' => 'Eigen kaart']);
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): string => 'kudos');
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $html = $this->markup($this->get('/page')->assertOk()->getContent());

        $this->assertStringContainsString('<article class="host-card"', $html);
        $this->assertStringContainsString('Eigen kaart', $html);
        $this->assertStringNotContainsString('data-rr-extension', $html);
    }

    public function test_a_failing_extension_is_reported_and_left_out_without_breaking_the_list(): void
    {
        Exceptions::fake();
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['title' => 'Blijft staan']);
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): string => throw new RuntimeException('reactions table missing'));
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): string => 'kudos-ok');
        CardExtensions::preload(CardExtensions::REVIEW_META, fn (): array => throw new RuntimeException('preload failed'), 'badges');
        CardExtensions::register(CardExtensions::REVIEW_META, fn (): string => 'needs-preload', 'badges');
        CardExtensions::register(CardExtensions::REVIEW_META, fn (): object => new \stdClass);
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $html = $this->markup($this->get('/page')->assertOk()->getContent());

        $this->assertStringContainsString('Blijft staan', $html);
        $this->assertStringContainsString('>kudos-ok</div>', $html);
        $this->assertStringNotContainsString('needs-preload', $html);
        $this->assertStringNotContainsString('data-rr-extension="review.meta"', $html);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'reactions table missing');
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'preload failed');
        Exceptions::assertReported(fn (\UnexpectedValueException $exception): bool => str_contains($exception->getMessage(), 'returned stdClass'));
    }

    public function test_with_app_debug_on_a_failing_extension_is_thrown(): void
    {
        config(['app.debug' => true]);
        $this->withoutExceptionHandling();
        $product = $this->product();
        Review::factory()->forSubject($product)->create();
        CardExtensions::register(CardExtensions::REVIEW_FOOTER, fn (): string => throw new RuntimeException('reactions table missing'));
        $this->page($product, '<x-rr::reviews :subject="$p" />');

        $this->expectExceptionMessage('reactions table missing');
        $this->get('/page');
    }
}
