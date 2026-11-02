<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\TestCase;

/**
 * The Blade components with the package form routes on: every action works as a plain HTML
 * form (no JavaScript), and the flashed outcome lands in the component it belongs to.
 */
class BladeFormsTest extends TestCase
{
    private const TEMPLATE = '<x-rr::reviews :subject="$p" /><x-rr::review-form :subject="$p" /><x-rr::discussion :subject="$p" />';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('ratings-and-reviews.web_routes.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function page(Product $product, string $template = self::TEMPLATE): void
    {
        Route::middleware('web')->get('/page', fn () => Blade::render($template, ['p' => $product]));
    }

    /** The page without the translations JSON (which contains every UI string). */
    private function markup(string $html): string
    {
        return (string) preg_replace('/<script type="application\/json"[^>]*>.*?<\/script>/s', '', $html);
    }

    /** The action of the form whose markup contains `$marker`. */
    private function formAction(string $html, string $marker): string
    {
        preg_match_all('/<form\b[^>]*\baction="([^"]*)"[^>]*>(.*?)<\/form>/s', $html, $forms, PREG_SET_ORDER);

        foreach ($forms as $form) {
            if (str_contains($form[0], $marker)) {
                return html_entity_decode($form[1]);
            }
        }

        $this->fail("No form with {$marker}.");
    }

    public function test_the_composer_posts_to_the_form_route_and_errors_stay_with_their_form(): void
    {
        $product = $this->product();
        $this->page($product);
        $member = $this->user();

        $html = $this->actingAs($member)->get('/page')->assertOk()->getContent();
        $action = $this->formAction($html, 'data-rr-composer');
        $this->assertSame(route('rr.web.comments.store', ['type' => 'product', 'id' => $product->id]), $action);
        $this->assertStringNotContainsString(__('ratings-and-reviews::ui.common.js_required'), $this->markup($html));

        // Too short: the error and the old text come back in the composer, not in the review form.
        $this->from('/page')->post($action, ['body' => 'x'])->assertRedirect('/page');
        $after = $this->markup($this->get('/page')->getContent());
        $this->assertMatchesRegularExpression('/data-rr-error>[^<]*at least 2/', $after);
        $this->assertStringNotContainsString(__('ratings-and-reviews::ui.form.errors'), $after);

        $this->from('/page')->post($action, ['body' => 'Een echte reactie'])->assertRedirect('/page#rr-comment-'.Comment::query()->sole()->id);
        $after = $this->markup($this->get('/page')->getContent());
        $this->assertSame(1, substr_count($after, 'Your comment has been posted.'), 'Only the discussion shows its status.');
    }

    public function test_votes_delete_edit_and_report_render_as_forms_without_javascript(): void
    {
        $product = $this->product();
        $author = $this->user();
        $comment = $this->commentOn($product, ['user_id' => $author->id]);
        $other = $this->commentOn($product);
        $this->page($product);

        $html = $this->actingAs($author)->get('/page')->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('rr.web.comments.votes.store', ['comment' => $other->id]).'"', $html);
        $this->assertStringContainsString('action="'.route('rr.web.comments.destroy', ['comment' => $comment->id]).'"', $html);
        $this->assertStringContainsString('action="'.route('rr.web.reports.store').'"', $html);
        $this->assertStringContainsString('rr_cedit='.$comment->id, $html);

        // Edit without JavaScript: ?rr_cedit=ID shows the form; saving goes through the form route.
        $edit = $this->get('/page?rr_cedit='.$comment->id)->getContent();
        $action = $this->formAction($edit, 'name="_method" value="PATCH"');
        $this->from('/page?rr_cedit='.$comment->id)->post($action, ['_method' => 'PATCH', 'body' => 'Nieuwe tekst hier'])->assertRedirect('/page?rr_cedit='.$comment->id.'#rr-comment-'.$comment->id);
        $this->assertSame('Nieuwe tekst hier', $comment->fresh()->body_text);

        // A report with a missing detail comes back open in that item's report form.
        $this->from('/page')->post(route('rr.web.reports.store'), ['reportable_type' => 'rr_comment', 'reportable_id' => $other->id, 'reason' => 'other']);
        $this->assertMatchesRegularExpression('/<details class="rr-nojs-only rr-disclosure"\s+open\s*>/', $this->get('/page')->getContent());
    }

    public function test_the_review_form_follows_the_sections_extended_fields_and_sends_photos(): void
    {
        $this->configureSection('product', ['review_extended_fields' => false, 'review_min_length' => 5]);
        $product = $this->product();
        $member = $this->user();
        $this->page($product);

        $html = $this->markup($this->actingAs($member)->get('/page')->assertOk()->getContent());
        $this->assertStringNotContainsString('name="title"', $html);
        $this->assertStringNotContainsString('name="pros[]"', $html);
        $this->assertStringContainsString('Write at least 5 characters.', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="photo_files[]"', $html);

        $action = $this->formAction($html, 'data-rr-review-form');
        $this->from('/page')->post($action, ['rating' => '5', 'body' => 'Heel lekker', 'photo_files' => [UploadedFile::fake()->image('a.jpg', 60, 60)]])
            ->assertSessionHas('rr_status');
        $review = Review::query()->sole();
        $this->assertSame(1, $review->photos()->count());

        // The own review now shows its photo with an alt text, and the form edits it (PATCH, kept photo ticked).
        $html = $this->get('/page')->getContent();
        $this->assertMatchesRegularExpression('/<img src="\/storage\/rr-uploads\/[^"]+" alt="Photo 1 with this review"/', $html);
        $this->assertStringContainsString('action="'.route('rr.web.reviews.update', ['review' => $review->id]).'"', $html);
        $this->assertMatchesRegularExpression('/name="photos\[\]" value="'.$review->photos()->value('id').'"\s+checked/', $html);
    }

    public function test_show_more_replies_loads_the_rest_of_a_cut_thread_without_javascript(): void
    {
        config(['ratings-and-reviews.discussions.max_replies_per_page' => 2, 'ratings-and-reviews.discussions.replies_per_request' => 2]);
        $product = $this->product();
        $root = $this->commentOn($product);
        $replies = [];

        foreach (range(1, 5) as $minute) {
            $replies[] = Comment::factory()->replyTo($root)->create(['created_at' => now()->addMinutes($minute), 'body_html' => "<p>Antwoord {$minute}</p>", 'body_text' => "Antwoord {$minute}"]);
        }

        $this->page($product, '<x-rr::discussion :subject="$p" />');

        $html = $this->markup($this->get('/page')->assertOk()->getContent());
        $this->assertStringContainsString('2 of 5 replies shown', $html);
        $this->assertStringNotContainsString('Antwoord 3', $html);
        $this->assertStringContainsString('rr_cthread='.$root->id, $html);

        $more = $this->markup($this->get('/page?rr_cthread='.$root->id)->getContent());
        $this->assertStringContainsString('Antwoord 3', $more);
        $this->assertStringNotContainsString('Antwoord 5', $more);
        $this->assertStringContainsString('rr_cthread_pages=2', $more);

        $all = $this->markup($this->get('/page?rr_cthread='.$root->id.'&rr_cthread_pages=2')->getContent());
        $this->assertStringContainsString('Antwoord 5', $all);
        $this->assertStringNotContainsString('replies shown', $all);
        $this->assertTrue(strpos($all, 'Antwoord 1') < strpos($all, 'Antwoord 5'), 'Replies read oldest first.');
    }

    public function test_without_form_routes_the_components_keep_their_previous_markup(): void
    {
        config(['ratings-and-reviews.web_routes.enabled' => false]);
        $product = $this->product();
        $this->commentOn($product);
        $this->page($product);

        $html = $this->markup($this->actingAs($this->user())->get('/page')->assertOk()->getContent());
        $this->assertStringNotContainsString('rr-forms/', $html);
        $this->assertStringContainsString(__('ratings-and-reviews::ui.common.js_required'), $html);
        $this->assertStringContainsString('type="button"', $html);
    }
}
