<?php

namespace RatingsAndReviews\Tests\Feature;

use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\Excerpt;
use RatingsAndReviews\Tests\TestCase;

class DiscussionDigestTest extends TestCase
{
    private function url(int $productId): string
    {
        return "/rr/subjects/product/{$productId}/digest";
    }

    public function test_below_the_threshold_or_with_threshold_zero_there_is_no_digest(): void
    {
        $this->configureSection('product', ['digest_threshold' => 3]);
        $product = $this->product();
        $this->commentOn($product, ['agree_count' => 5]);
        $this->commentOn($product, ['agree_count' => 5]);
        $this->commentOn($product, ['status' => ContentStatus::Pending, 'published_at' => null]);

        $this->getJson($this->url($product->id))->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.threshold', 3)
            ->assertJsonPath('data.total_comments', 2)
            ->assertJsonPath('data.most_agreed', []);

        $this->commentOn($product);
        $this->getJson($this->url($product->id))->assertJsonPath('data.available', true);

        $this->configureSection('product', ['digest_threshold' => 0]);
        $this->getJson($this->url($product->id))->assertJsonPath('data.available', false);
    }

    public function test_the_digest_ranks_by_net_agreement_and_never_lists_a_comment_twice(): void
    {
        $this->configureSection('product', ['digest_threshold' => 2]);
        config(['ratings-and-reviews.digest.per_side' => 2]);
        $product = $this->product();
        $agreed = $this->commentOn($product, ['agree_count' => 10, 'disagree_count' => 2, 'body_text' => 'Klopt helemaal. Ik merkte het ook na een week.']);
        $agreedMore = $this->commentOn($product, ['agree_count' => 12, 'disagree_count' => 1, 'body_text' => 'Prima spul!']);
        $agreedThird = $this->commentOn($product, ['agree_count' => 3, 'disagree_count' => 0]);
        $disagreed = $this->commentOn($product, ['agree_count' => 1, 'disagree_count' => 9, 'body_text' => 'Onzin? Dat denk ik niet.']);
        $split = $this->commentOn($product, ['agree_count' => 5, 'disagree_count' => 5]);
        $helpful = $this->commentOn($product, ['helpful_count' => 30, 'unhelpful_count' => 1]);
        $this->commentOn($product, ['agree_count' => 100, 'counted' => false]);
        $this->commentOn($product, ['agree_count' => 90, 'author_removed_at' => now(), 'body_text' => '']);
        $this->commentOn($product, ['agree_count' => 80, 'status' => ContentStatus::Rejected]);
        $this->commentOn($product, ['agree_count' => 70, 'published_at' => null, 'publish_at' => now()->addMinute()]);

        $data = $this->getJson($this->url($product->id))->assertOk()->json('data');

        $this->assertTrue($data['available']);
        $this->assertSame(6, $data['total_comments']);
        $this->assertSame([$agreedMore->id, $agreed->id], array_column($data['most_agreed'], 'id'));
        $this->assertSame([$disagreed->id], array_column($data['most_disagreed'], 'id'));
        $this->assertNotContains($split->id, [...array_column($data['most_agreed'], 'id'), ...array_column($data['most_disagreed'], 'id')]);
        $this->assertNotContains($agreedThird->id, array_column($data['most_agreed'], 'id'));
        $this->assertSame($helpful->id, $data['most_helpful']['id']);
        $this->assertSame('Klopt helemaal.', $data['most_agreed'][1]['excerpt']);
        $this->assertSame('Onzin?', $data['most_disagreed'][0]['excerpt']);
        $this->assertSame(['agree' => 31, 'disagree' => 17, 'agree_share' => round(31 / 48, 4)], $data['agreement']);
        $this->assertSame(6, $data['participants']);
        $this->assertNotNull($data['most_agreed'][0]['author']);
    }

    public function test_dimensions_the_section_does_not_use_are_left_out(): void
    {
        $this->configureSection('product', ['digest_threshold' => 1, 'vote_dimensions' => ['helpfulness']]);
        $product = $this->product();
        $this->commentOn($product, ['agree_count' => 4, 'helpful_count' => 2]);

        $this->getJson($this->url($product->id))
            ->assertJsonPath('data.agreement', null)
            ->assertJsonPath('data.most_agreed', [])
            ->assertJsonPath('data.most_helpful.helpful_count', 2);
    }

    public function test_hidden_or_unknown_subjects_are_not_found(): void
    {
        $this->getJson($this->url($this->product(['is_hidden' => true])->id))->assertNotFound();
        $this->getJson('/rr/subjects/article/1/digest')->assertNotFound();
        $this->getJson($this->url(999999))->assertNotFound();
    }

    public function test_excerpts_take_the_first_sentence_and_cut_at_a_word(): void
    {
        $this->assertSame('Eerste zin.', Excerpt::firstSentence('Eerste  zin. Tweede zin.', 200));
        $this->assertSame('Geen punt aan het eind', Excerpt::firstSentence('Geen punt aan het eind', 200));
        $this->assertSame('Versie 2.5 is beter!', Excerpt::firstSentence('Versie 2.5 is beter! Echt.', 200), 'A dot inside a number does not end the sentence.');
        $this->assertSame('Een heel lange…', Excerpt::firstSentence('Een heel lange zin zonder einde', 18));
    }
}
