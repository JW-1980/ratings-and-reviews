<?php

namespace RatingsAndReviews\Tests\BladeFixture;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Tests\TestCase;

/**
 * The same page with the package form routes on (web_routes.enabled): votes, polls, deletes
 * and reports are plain forms and the review form takes photos. Writes
 * tests/fixtures/blade-page-forms.html for the enhancement tests that run on top of them.
 *
 * Run from the package root:
 *   DB_DATABASE=rr_ui_views vendor/bin/phpunit --bootstrap vendor/autoload.php packages/vue/scripts/blade-fixture/BladeFormRoutesRenderTest.php
 */
class BladeFormRoutesRenderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('ratings-and-reviews.web_routes.enabled', true);
    }

    public function test_components_render_their_no_javascript_forms(): void
    {
        $this->configureSection('product', ['editor_tools' => ['bold', 'image', 'sticker'], 'vote_dimensions' => ['agreement', 'helpfulness']]);
        $product = $this->product();
        $author = $this->user();
        $viewer = $this->user();

        $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'title' => 'Lekker', 'body' => '<p>Romig</p>'])->assertCreated();
        $root = $this->actingAs($author)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => '<p>Te zoet voor mij.</p>', 'poll' => ['question' => 'Warm of koud?', 'options' => ['Warm', 'Koud']]])->assertCreated()->json('data');

        Route::middleware('web')->get('/page', fn () => Blade::render('<x-rr::reviews :subject="$p" /><x-rr::review-form :subject="$p" /><x-rr::discussion :subject="$p" />', ['p' => $product]));
        $html = $this->actingAs($viewer)->get('/page')->assertOk()->getContent();
        file_put_contents(dirname(__DIR__, 2).'/tests/fixtures/blade-page-forms.html', $html);

        $this->assertStringContainsString('action="'.route('rr.web.comments.votes.store', ['comment' => $root['id']]).'"', $html);
        $this->assertStringContainsString('action="'.route('rr.web.polls.votes.store', ['poll' => $root['poll']['id']]).'"', $html);
        $this->assertStringContainsString('name="photo_files[]"', $html);
        $this->assertStringContainsString('data-rr-photos', $html);
    }
}
