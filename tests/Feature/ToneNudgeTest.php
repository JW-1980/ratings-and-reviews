<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Services\ToneNudge;
use RatingsAndReviews\Tests\TestCase;

class ToneNudgeTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/comments";
    }

    public function test_words_match_as_whole_words_in_any_language_and_case(): void
    {
        $nudge = $this->app->make(ToneNudge::class);

        $this->assertSame(['idioot'], $nudge->check('Wat een IDIOOT, echt.')?->matches);
        $this->assertSame(['shut up'], $nudge->check("Just   shut\nup already")?->matches, 'Phrases match across collapsed whitespace.');
        $this->assertSame(['idioot', 'stupid'], $nudge->check('Idioot! Stupid!')?->matches);
        $this->assertNull($nudge->check('Idiootje is geen woord in de lijst, idiootheid ook niet.'), 'A longer word containing a listed word is not a match.');
        $this->assertNull($nudge->check('Superdom? Nee: prima product.'));
        $this->assertNull($nudge->check(''));

        config(['ratings-and-reviews.tone.enabled' => false]);
        $this->assertNull($nudge->check('idioot'));
    }

    public function test_the_tone_check_endpoint_answers_with_or_without_a_nudge(): void
    {
        $this->postJson('/rr/tone-check', ['text' => '<p>Je bent een <strong>sukkel</strong></p>'])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.nudge.matches', ['sukkel'])
            ->assertJsonPath('data.nudge.message', 'This may come across as hostile. Would you like to put it differently? You can also post it as it is.');

        $this->postJson('/rr/tone-check', ['text' => 'Lekker romig'])->assertOk()->assertJsonPath('data.nudge', null);
        $this->postJson('/rr/tone-check', [])->assertUnprocessable()->assertJsonValidationErrors('text');

        $this->app->setLocale('nl');
        $this->postJson('/rr/tone-check', ['text' => 'idioot'])->assertJsonPath('data.nudge.message', 'Dit kan vijandig overkomen. Wil je het anders verwoorden? Je kunt het ook zo plaatsen.');
    }

    public function test_posting_a_hostile_comment_asks_first_and_posts_when_confirmed(): void
    {
        Event::fake([CommentPosted::class]);
        $product = $this->product();
        $user = $this->user();

        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Wat een idioot verhaal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_tone')
            ->assertJsonPath('tone_nudge.matches', ['idioot']);
        $this->assertSame(0, Comment::query()->count());
        Event::assertNotDispatched(CommentPosted::class);

        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Wat een idioot verhaal', 'confirm_tone' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, Comment::query()->count());
    }

    public function test_the_poll_text_and_edits_are_checked_too(): void
    {
        $product = $this->product();
        $user = $this->user();

        $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Wat vinden jullie?', 'poll' => ['question' => 'Wie is de grootste idioot?', 'options' => ['A', 'B']]])
            ->assertUnprocessable()->assertJsonPath('tone_nudge.matches', ['idioot']);

        $id = $this->actingAs($user)->postJson($this->url($product->id), ['body' => 'Vriendelijk begin'])->assertCreated()->json('data.id');
        $this->actingAs($user)->patchJson("/rr/comments/{$id}", ['body' => 'Doe niet zo dumb'])->assertUnprocessable()->assertJsonPath('tone_nudge.matches', ['dumb']);
        $this->actingAs($user)->patchJson("/rr/comments/{$id}", ['body' => 'Doe niet zo dumb', 'confirm_tone' => true])->assertOk();
        $this->assertSame('Doe niet zo dumb', Comment::query()->find($id)->body_text);
    }

    public function test_reviews_get_the_same_nudge_including_pros_and_cons(): void
    {
        $product = $this->product();
        $user = $this->user();
        $review = ['rating' => 2, 'body' => 'Smaakt naar karton.', 'cons' => ['de maker is een clown']];

        $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/reviews", $review)
            ->assertUnprocessable()->assertJsonPath('tone_nudge.matches', ['clown']);
        $this->assertSame(0, Review::query()->count());

        $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/reviews", $review + ['confirm_tone' => true])->assertCreated();
    }
}
