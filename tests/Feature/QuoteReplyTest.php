<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\TestCase;

class QuoteReplyTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/comments";
    }

    public function test_a_quote_of_a_comment_in_the_same_discussion_is_stored_and_shown_with_its_author(): void
    {
        $product = $this->product();
        $author = $this->user(['name' => 'Bron']);
        $quoted = $this->commentOn($product, ['user_id' => $author->id, 'body_html' => '<p>Na opwarmen smaakt het bitter.</p>', 'body_text' => 'Na opwarmen smaakt het bitter.']);

        $this->actingAs($this->user())->postJson($this->url($product->id), [
            'body' => '<blockquote data-rr-quote="'.$quoted->id.'">smaakt het bitter</blockquote><p>Bij mij niet.</p>',
            'quoted_comment_id' => $quoted->id,
            'quote_text' => "  smaakt   het\nbitter ",
        ])->assertCreated()
            ->assertJsonPath('data.quote.comment_id', $quoted->id)
            ->assertJsonPath('data.quote.text', 'smaakt het bitter')
            ->assertJsonPath('data.quote.author.name', 'Bron')
            ->assertJsonPath('data.body_html', '<blockquote data-rr-quote="'.$quoted->id.'">smaakt het bitter</blockquote><p>Bij mij niet.</p>');

        $reply = Comment::query()->latest('id')->first();
        $this->assertSame($quoted->id, $reply->quoted_comment_id);
        $this->assertSame('smaakt het bitter', $reply->quote_text);

        $tree = collect($this->getJson($this->url($product->id))->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['comment_id' => $quoted->id, 'root_id' => $quoted->id, 'text' => 'smaakt het bitter', 'author' => $tree[$quoted->id]['author'], 'available' => true], $tree[$reply->id]['quote']);
        $this->assertNull($tree[$quoted->id]['quote']);
    }

    public function test_quotes_that_do_not_match_or_point_elsewhere_are_refused(): void
    {
        config(['ratings-and-reviews.discussions.max_quote_length' => 20]);
        $product = $this->product();
        $quoted = $this->commentOn($product, ['body_text' => 'Romig en zoet, prima in koffie.']);
        $elsewhere = Comment::factory()->forSubject($this->product())->create(['body_text' => 'Romig en zoet']);
        $hidden = $this->commentOn($product, ['body_text' => 'Romig en zoet', 'status' => ContentStatus::Rejected]);

        $cases = [
            [['quoted_comment_id' => $quoted->id, 'quote_text' => 'romig en zoet'], 'quote_text'],          // case differs: not an exact substring
            [['quoted_comment_id' => $quoted->id, 'quote_text' => 'Romig en bitter'], 'quote_text'],
            [['quoted_comment_id' => $quoted->id, 'quote_text' => 'Romig en zoet, prima in'], 'quote_text'], // longer than 20
            [['quoted_comment_id' => $elsewhere->id, 'quote_text' => 'Romig en zoet'], 'quoted_comment_id'],
            [['quoted_comment_id' => $hidden->id, 'quote_text' => 'Romig en zoet'], 'quoted_comment_id'],
            [['quoted_comment_id' => 999999, 'quote_text' => 'Romig'], 'quoted_comment_id'],
            [['quoted_comment_id' => $quoted->id], 'quote_text'],
            [['quote_text' => 'Romig'], 'quoted_comment_id'],
        ];

        foreach ($cases as [$fields, $error]) {
            $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => 'Eens!'] + $fields)
                ->assertUnprocessable()->assertJsonValidationErrors($error);
        }

        $this->assertSame(3, Comment::query()->count());
    }

    public function test_a_body_cannot_claim_to_quote_a_comment_it_did_not_quote(): void
    {
        $product = $this->product();
        $quoted = $this->commentOn($product, ['body_text' => 'Echt waar']);

        $this->actingAs($this->user())->postJson($this->url($product->id), ['body' => '<blockquote data-rr-quote="'.$quoted->id.'">Echt</blockquote><p>Ja</p>'])
            ->assertCreated()->assertJsonPath('data.body_html', '<blockquote>Echt</blockquote><p>Ja</p>')->assertJsonPath('data.quote', null);

        $this->actingAs($this->user())->postJson($this->url($product->id), [
            'body' => '<blockquote data-rr-quote="12345">Echt</blockquote><p>Ja</p>',
            'quoted_comment_id' => $quoted->id,
            'quote_text' => 'Echt',
        ])->assertCreated()->assertJsonPath('data.body_html', '<blockquote>Echt</blockquote><p>Ja</p>');
    }

    public function test_the_quote_disappears_with_the_words_of_its_author_and_marks_a_removed_source(): void
    {
        $product = $this->product();
        $quoted = $this->commentOn($product, ['body_text' => 'Echt waar']);
        $replier = $this->user();
        $id = $this->actingAs($replier)->postJson($this->url($product->id), ['body' => 'Klopt', 'quoted_comment_id' => $quoted->id, 'quote_text' => 'Echt'])->json('data.id');

        $this->actingAs($this->moderator())->patchJson("/rr/admin/moderation/comments/{$quoted->id}", ['action' => 'reject'])->assertOk();
        $tree = collect($this->getJson($this->url($product->id))->json('data'))->keyBy('id');
        $this->assertFalse($tree[$id]['quote']['available']);
        $this->assertNull($tree[$id]['quote']['author']);

        Comment::factory()->replyTo(Comment::query()->find($id))->create();
        $this->actingAs($replier)->deleteJson("/rr/comments/{$id}")->assertNoContent();
        $this->assertNull(Comment::query()->find($id)->quote_text);
        $this->assertNull(collect($this->getJson($this->url($product->id))->json('data'))->keyBy('id')[$id]['quote']);
    }
}
