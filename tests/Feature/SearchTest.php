<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\Erasure;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\CommittedTestCase;

/**
 * Runs on committed data: InnoDB FULLTEXT indexes do not see uncommitted rows.
 */
class SearchTest extends CommittedTestCase
{
    private function url(int $productId, string $query): string
    {
        return "/rr/subjects/product/{$productId}/search?q=".rawurlencode($query);
    }

    public function test_reviews_and_comments_of_one_subject_are_found_with_marked_snippets(): void
    {
        $product = $this->product();
        $author = $this->user(['name' => 'Saar']);
        $review = Review::factory()->forSubject($product)->create(['user_id' => $author->id, 'title' => 'Lactosevrij en romig', 'body_text' => 'Ik drink het om de lactose. Smaak na opwarmen blijft goed.', 'body_html' => '<p>x</p>']);
        $comment = Comment::factory()->forSubject($product)->create(['body_text' => 'Bevat dit echt geen lactose? Bij mij wel klachten.']);
        Review::factory()->forSubject($product)->create(['body_text' => 'Niets over dat onderwerp.']);
        Review::factory()->forSubject($product)->status(ContentStatus::Pending)->create(['body_text' => 'Pending over lactose']);
        Comment::factory()->forSubject($product)->status(ContentStatus::Rejected)->create(['body_text' => 'Verborgen lactose']);
        Comment::factory()->forSubject($product)->create(['body_text' => 'Nog niet gepubliceerd lactose', 'published_at' => null, 'publish_at' => now()->addMinute()]);
        Review::factory()->forSubject($this->product())->create(['body_text' => 'Ander product met lactose']);

        $response = $this->getJson($this->url($product->id, 'lactose'))->assertOk();

        $response->assertJsonPath('meta.terms', ['lactose'])
            ->assertJsonPath('meta.total_reviews', 1)
            ->assertJsonPath('meta.total_comments', 1)
            ->assertJsonPath('data.reviews.0.id', $review->id)
            ->assertJsonPath('data.reviews.0.author.name', 'Saar')
            ->assertJsonPath('data.reviews.0.title_html', '<mark>Lactosevrij</mark> en romig')
            ->assertJsonPath('data.reviews.0.snippet_html', 'Ik drink het om de <mark>lactose</mark>. Smaak na opwarmen blijft goed.')
            ->assertJsonPath('data.comments.0.id', $comment->id)
            ->assertJsonPath('data.comments.0.snippet_html', 'Bevat dit echt geen <mark>lactose</mark>? Bij mij wel klachten.');
    }

    public function test_every_word_is_required_phrases_match_in_order_and_case_and_accents_do_not_matter(): void
    {
        $product = $this->product();
        $hit = Review::factory()->forSubject($product)->create(['title' => null, 'body_text' => 'De smaak na opwarmen is als crème brûlée.']);
        Review::factory()->forSubject($product)->create(['title' => null, 'body_text' => 'Opwarmen gaat snel, de geur na een dag minder.']);

        $this->getJson($this->url($product->id, 'SMAAK opwarmen'))->assertJsonPath('meta.total_reviews', 1)->assertJsonPath('data.reviews.0.id', $hit->id)
            ->assertJsonPath('data.reviews.0.snippet_html', 'De <mark>smaak</mark> na <mark>opwarmen</mark> is als crème brûlée.');
        $this->getJson($this->url($product->id, '"smaak opwarmen"'))->assertJsonPath('meta.phrases', ['smaak opwarmen'])->assertJsonPath('meta.total_reviews', 0);
        $this->getJson($this->url($product->id, '"crème brûlée"'))->assertJsonPath('meta.total_reviews', 1)
            ->assertJsonPath('data.reviews.0.snippet_html', 'De smaak na opwarmen is als <mark>crème brûlée</mark>.');
        $this->getJson($this->url($product->id, 'creme'))->assertJsonPath('meta.total_reviews', 1)
            ->assertJsonPath('data.reviews.0.snippet_html', 'De smaak na opwarmen is als <mark>crème</mark> brûlée.');
        $this->getJson($this->url($product->id, 'opwar'))->assertJsonPath('meta.total_reviews', 2, 'Terms are prefixes.');
    }

    public function test_operators_and_markup_in_the_query_or_the_text_are_harmless(): void
    {
        $product = $this->product();
        $review = Review::factory()->forSubject($product)->create(['title' => null, 'body_text' => 'Prijs < 2 euro & "<script>alert(1)</script>" smaak']);

        foreach (['smaak -prijs', '+smaak ~euro*', 'smaak") OR 1=1 --', '@smaak >(<prijs', 'smaak\\\\'] as $query) {
            $this->getJson($this->url($product->id, $query))->assertOk()->assertJsonPath('data.reviews.0.id', $review->id);
        }

        $snippet = $this->getJson($this->url($product->id, 'script'))->assertOk()->json('data.reviews.0.snippet_html');
        $this->assertSame('Prijs &lt; 2 euro &amp; &quot;&lt;<mark>script</mark>&gt;alert(1)&lt;/<mark>script</mark>&gt;&quot; smaak', $snippet);
    }

    public function test_long_texts_get_a_window_around_the_first_hit(): void
    {
        config(['ratings-and-reviews.search.snippet_length' => 60]);
        $product = $this->product();
        Comment::factory()->forSubject($product)->create(['body_text' => str_repeat('woord ', 40).'gevonden hier '.str_repeat('vulling ', 40)]);

        $snippet = $this->getJson($this->url($product->id, 'gevonden'))->json('data.comments.0.snippet_html');

        $this->assertStringStartsWith('…', $snippet);
        $this->assertStringEndsWith('…', $snippet);
        $this->assertStringContainsString('<mark>gevonden</mark> hier', $snippet);
        $this->assertLessThanOrEqual(60 + 2, mb_strlen(strip_tags($snippet)));
    }

    public function test_invalid_queries_and_subjects_are_refused(): void
    {
        $product = $this->product();

        $this->getJson("/rr/subjects/product/{$product->id}/search")->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson($this->url($product->id, 'ab - ""'))->assertUnprocessable()->assertJsonPath('errors.q.0', 'Search for at least one word of 3 or more characters.');
        $this->getJson($this->url($product->id, 'smaak').'&in=everything')->assertUnprocessable();
        $this->getJson($this->url($this->product(['is_hidden' => true])->id, 'smaak'))->assertNotFound();
        $this->getJson('/rr/subjects/article/'.$product->id.'/search?q=smaak')->assertNotFound();
        $this->getJson('/rr/subjects/nope/1/search?q=smaak')->assertNotFound();
    }

    public function test_in_limits_the_search_to_reviews_or_comments(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['body_text' => 'Haver is lekker']);
        Comment::factory()->forSubject($product)->create(['body_text' => 'Haver is gezond']);

        $this->getJson($this->url($product->id, 'haver').'&in=reviews')->assertJsonPath('meta.total_reviews', 1)->assertJsonPath('meta.total_comments', 0);
        $this->getJson($this->url($product->id, 'haver').'&in=comments')->assertJsonPath('meta.total_reviews', 0)->assertJsonPath('meta.total_comments', 1);
    }

    public function test_content_withheld_for_an_erasure_request_is_not_found(): void
    {
        $product = $this->product();
        $leaver = $this->user();
        Review::factory()->forSubject($product)->create(['user_id' => $leaver->id, 'body_text' => 'Haverdrank zonder suiker']);
        Comment::factory()->forSubject($product)->create(['user_id' => $leaver->id, 'body_text' => 'Haverdrank is prima']);
        $visible = Comment::factory()->forSubject($product)->create(['body_text' => 'Haverdrank smaakt mij goed']);

        app(Erasure::class)->withhold($leaver->id);

        $this->getJson($this->url($product->id, 'haverdrank'))
            ->assertOk()
            ->assertJsonPath('meta.total_reviews', 0)
            ->assertJsonPath('meta.total_comments', 1)
            ->assertJsonPath('data.comments.0.id', $visible->id);
    }
}
