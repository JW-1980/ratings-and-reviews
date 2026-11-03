<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\ContentAnnotator;
use RatingsAndReviews\Defaults\NullContentAnnotator;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\Annotation;
use RatingsAndReviews\Support\AuthorProfile;
use RatingsAndReviews\Tests\Fixtures\RecordingAnnotator;
use RatingsAndReviews\Tests\TestCase;
use RuntimeException;

/** ContentAnnotator: batched per list, sanitised, in the review and comment resources and the Blade cards. */
class ContentAnnotationsTest extends TestCase
{
    private RecordingAnnotator $annotator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->annotator = new RecordingAnnotator;
        $this->app->instance(ContentAnnotator::class, $this->annotator);
    }

    public function test_without_a_host_annotator_every_item_has_an_empty_list(): void
    {
        $this->app->forgetInstance(ContentAnnotator::class);
        $this->app->singleton(ContentAnnotator::class, NullContentAnnotator::class);
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['body_text' => 'Het heeft mij geneest']);
        $this->commentOn($product, ['body_text' => 'geneest']);

        $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()->assertJsonPath('data.0.annotations', []);
        $this->getJson("/rr/subjects/product/{$product->id}/comments")->assertOk()->assertJsonPath('data.0.annotations', []);
    }

    public function test_reviews_are_annotated_in_one_call_per_list_including_the_viewers_own_review(): void
    {
        $product = $this->product();
        $viewer = $this->user();
        $claim = Review::factory()->forSubject($product)->create(['body_text' => 'Dit drankje heeft mijn darmen geneest']);
        $plain = Review::factory()->forSubject($product)->create(['body_text' => 'Lekker']);
        $own = Review::factory()->forSubject($product)->create(['user_id' => $viewer->id, 'body_text' => 'Ook geneest']);

        $response = $this->actingAs($viewer)->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk();

        $this->assertCount(1, $this->annotator->calls, 'The annotator is asked once for the list, not per review.');
        $this->assertSame($viewer->id, $this->annotator->calls[0]['viewer']);
        $this->assertEqualsCanonicalizing(["review:{$claim->id}", "review:{$plain->id}", "review:{$own->id}"], $this->annotator->calls[0]['keys']);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertSame([['key' => 'health_claim', 'label' => 'Gezondheidsclaim', 'tone' => 'warning', 'url' => 'https://example.test/claims']], $byId[$claim->id]['annotations']);
        $this->assertSame([], $byId[$plain->id]['annotations']);
        $this->assertSame('health_claim', $response->json('meta.own_review.annotations.0.key'));
    }

    public function test_comments_and_nested_replies_are_annotated_in_one_call_and_removed_ones_are_left_out(): void
    {
        $product = $this->product();
        $root = $this->commentOn($product, ['body_text' => 'Normaal']);
        $reply = Comment::factory()->replyTo($root)->create(['body_text' => 'Mijn vader is geneest']);
        $nested = Comment::factory()->replyTo($reply)->create(['body_text' => 'Weer geneest']);
        $removedAuthor = $this->user();
        $removed = $this->commentOn($product, ['user_id' => $removedAuthor->id, 'body_text' => 'geneest']);
        Comment::factory()->replyTo($removed)->create(['body_text' => 'Een antwoord houdt de plaatshouder zichtbaar']);
        $this->actingAs($removedAuthor)->deleteJson("/rr/comments/{$removed->id}")->assertNoContent();

        $tree = $this->getJson("/rr/subjects/product/{$product->id}/comments")->assertOk();

        $this->assertCount(1, $this->annotator->calls);
        $keys = $this->annotator->calls[0]['keys'];
        $this->assertEqualsCanonicalizing(["comment:{$root->id}", "comment:{$reply->id}", "comment:{$nested->id}", 'comment:'.($removed->replies()->value('id') ?? 0)], $keys);
        $this->assertNotContains("comment:{$removed->id}", $keys, 'The placeholder of a removed comment is not annotated.');

        $comments = collect($tree->json('data'))->keyBy('id');
        $this->assertSame([], $comments[$root->id]['annotations']);
        $this->assertSame('health_claim', $comments[$root->id]['replies'][0]['annotations'][0]['key']);
        $this->assertSame('health_claim', $comments[$root->id]['replies'][0]['replies'][0]['annotations'][0]['key']);
        $this->assertSame([], $comments[$removed->id]['annotations']);

        $this->annotator->calls = [];
        $page = $this->getJson("/rr/comments/{$root->id}/replies")->assertOk();
        $this->assertCount(1, $this->annotator->calls);
        $this->assertSame('health_claim', collect($page->json('data'))->firstWhere('id', $reply->id)['annotations'][0]['key']);
    }

    public function test_a_freshly_saved_review_and_comment_carry_their_annotations(): void
    {
        $product = $this->product();
        $user = $this->user();

        $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 4, 'title' => 'Top', 'body' => 'Sinds ik dit drink ben ik helemaal geneest van mijn klachten.'])
            ->assertCreated()->assertJsonPath('data.annotations.0.key', 'health_claim');
        $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => 'Dit heeft mij geneest, echt waar'])
            ->assertCreated()->assertJsonPath('data.annotations.0.label', 'Gezondheidsclaim');
    }

    public function test_the_host_result_is_sanitised_deduplicated_and_limited(): void
    {
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create(['body_text' => 'neutraal']);
        $other = Review::factory()->forSubject($product)->create(['body_text' => 'neutraal']);
        $this->annotator->fixed = [
            "review:{$review->id}" => [
                new Annotation('a', '<b>Bold</b> label', 'danger', 'javascript:alert(1)'),
                new Annotation('a', 'Duplicate key'),
                new Annotation('empty', '   '),
                new Annotation('ok', 'Fine', 'success', '/claims'),
                'not an annotation',
                new Annotation('c', 'C'),
                new Annotation('d', 'D'),
                new Annotation('e', 'E'),
                new Annotation('f', 'F, over the limit'),
            ],
            'review:999999' => [new Annotation('ghost', 'Unknown item')],
            'nonsense' => [new Annotation('ghost', 'Bad key')],
        ];

        $data = collect($this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(
            [['a', 'Bold label', 'info', null], ['ok', 'Fine', 'success', '/claims'], ['c', 'C', 'info', null], ['d', 'D', 'info', null], ['e', 'E', 'info', null]],
            array_map(fn (array $row): array => [$row['key'], $row['label'], $row['tone'], $row['url']], $data[$review->id]['annotations']),
        );
        $this->assertSame([], $data[$other->id]['annotations']);
    }

    public function test_a_failing_annotator_does_not_break_the_list_unless_debugging(): void
    {
        Exceptions::fake();
        $product = $this->product();
        Review::factory()->forSubject($product)->create();
        $this->annotator->throws = new RuntimeException('detector down');

        $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()->assertJsonPath('data.0.annotations', []);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'detector down');

        config(['app.debug' => true]);
        $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertServerError();
    }

    public function test_author_badges_from_the_resolver_are_normalised(): void
    {
        $this->app->instance(AuthorResolver::class, new class implements AuthorResolver
        {
            public function resolve(array $userIds): array
            {
                return array_map(fn (int $id): AuthorProfile => new AuthorProfile($id, 'Lid '.$id, extra: [
                    'member_since' => '2020-01-01',
                    'badges' => ['Betrouwbaar', ['label' => '<i>Top</i> reviewer', 'tone' => 'success', 'url' => 'javascript:x', 'icon' => 'star'], ['label' => ''], 42],
                ]), array_combine($userIds, $userIds));
            }
        });
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['user_id' => $this->user()->id]);

        $extra = $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertOk()->json('data.0.author.extra');

        $this->assertSame('2020-01-01', $extra['member_since']);
        $this->assertSame([
            ['key' => 'badge', 'label' => 'Betrouwbaar', 'tone' => 'info', 'url' => null, 'icon' => null],
            ['key' => 'badge', 'label' => 'Top reviewer', 'tone' => 'success', 'url' => null, 'icon' => 'star'],
        ], $extra['badges']);
    }

    public function test_the_blade_cards_show_annotations_and_author_badges_accessibly(): void
    {
        $this->app->instance(AuthorResolver::class, new class implements AuthorResolver
        {
            public function resolve(array $userIds): array
            {
                return array_map(fn (int $id): AuthorProfile => new AuthorProfile($id, 'Lid '.$id, extra: ['badges' => [['key' => 'reliable', 'label' => 'Betrouwbare reviewer', 'tone' => 'success']]]), array_combine($userIds, $userIds));
            }
        });
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['user_id' => $this->user()->id, 'body_text' => 'geneest']);
        $this->commentOn($product, ['user_id' => $this->user()->id, 'body_text' => 'geneest']);
        $this->commentOn($product, ['body_text' => 'rustig']);
        Route::middleware('web')->get('/page', fn () => Blade::render('<x-rr::reviews :subject="$p" /><x-rr::discussion :subject="$p" />', ['p' => $product]));

        $html = (string) preg_replace('/<script type="application\/json"[^>]*>.*?<\/script>/s', '', $this->get('/page')->assertOk()->getContent());

        $this->assertSame(2, substr_count($html, 'data-rr-annotation="health_claim"'), 'One on the review, one on the flagged comment.');
        $this->assertStringContainsString('<span class="rr-tag rr-tag--annotation" data-rr-annotation="health_claim" data-rr-annotation-source="content" data-tone="warning">', $html);
        $this->assertStringContainsString('<a href="https://example.test/claims">Gezondheidsclaim</a>', $html);
        $this->assertStringContainsString('Warning: </span>', $html);
        $this->assertStringContainsString('aria-label="Notes"', $html);
        $this->assertSame(3, substr_count($html, 'data-rr-annotation-source="author"'), 'The author badge shows on the review and both comments.');
        $this->assertMatchesRegularExpression('/<header class="rr-comment__header">.*?rr-annotations.*?<\/header>/s', $html);
        $this->assertStringNotContainsString('rr-annotations', (string) preg_replace('/.*<\/article>/s', '', $html), 'No stray rows outside the cards.');
    }

    public function test_the_blade_card_without_annotations_has_no_row(): void
    {
        $this->app->forgetInstance(ContentAnnotator::class);
        $this->app->singleton(ContentAnnotator::class, NullContentAnnotator::class);
        $product = $this->product();
        Review::factory()->forSubject($product)->create();
        Route::middleware('web')->get('/page', fn () => Blade::render('<x-rr::reviews :subject="$p" />', ['p' => $product]));

        $this->assertStringNotContainsString('<ul class="rr-annotations"', $this->get('/page')->assertOk()->getContent());
    }
}
