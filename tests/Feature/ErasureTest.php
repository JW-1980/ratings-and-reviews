<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RatingsAndReviews\Contracts\ContributionCounter;
use RatingsAndReviews\Events\UserErasureProcessed;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\CommentRevision;
use RatingsAndReviews\Models\PollOption;
use RatingsAndReviews\Models\PollVote;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\ReadMarker;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewInvitation;
use RatingsAndReviews\Models\TopicRating;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Services\Erasure;
use RatingsAndReviews\Services\ReadMarkers;
use RatingsAndReviews\Services\ReviewInvitations;
use RatingsAndReviews\Services\TopicRatings;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\Fixtures\User;
use RatingsAndReviews\Tests\TestCase;

class ErasureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** Resolved when used, so Event::fake() in a test reaches its dispatcher. */
    private function erasure(): Erasure
    {
        return $this->app->make(Erasure::class);
    }

    private function summaryCount(Product $product): int
    {
        return (int) $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->json('data.count');
    }

    /**
     * @return array<string, mixed>
     */
    private function tree(Product $product): array
    {
        return $this->getJson("/rr/subjects/product/{$product->id}/comments?sort=oldest")->assertOk()->json();
    }

    private function photo(User $user): Upload
    {
        $id = $this->actingAs($user)->post('/rr/uploads', ['image' => UploadedFile::fake()->image('p.jpg', 100, 80)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        return Upload::query()->findOrFail($id);
    }

    public function test_withholding_hides_reviews_and_comments_and_restoring_brings_them_back(): void
    {
        Event::fake([UserErasureProcessed::class]);
        $product = $this->product();
        $leaver = $this->user();
        Review::factory()->forSubject($product)->create(['user_id' => $leaver->id]);
        Review::factory()->forSubject($product)->create();
        $leaf = Comment::factory()->forSubject($product)->create(['user_id' => $leaver->id, 'body_text' => 'Alleen ik']);
        $parent = Comment::factory()->forSubject($product)->create(['user_id' => $leaver->id, 'body_html' => '<p>Mijn vraag</p>']);
        $reply = Comment::factory()->replyTo($parent)->create();
        $this->assertSame(2, $this->summaryCount($product));

        $counts = $this->erasure()->withhold($leaver->id);

        $this->assertSame(['reviews' => 1, 'comments' => 2, 'topic_ratings' => 0], $counts);
        $this->assertSame(1, $this->summaryCount($product), 'Aggregates are recomputed without the withheld review.');
        $this->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonPath('meta.total', 1);
        $this->actingAs($leaver)->getJson("/rr/subjects/product/{$product->id}/reviews")->assertJsonPath('meta.own_review', null);

        $tree = $this->tree($product);
        $this->assertSame([$parent->id], array_column($tree['data'], 'id'), 'A withheld comment without replies is gone; one with replies stays as a placeholder.');
        $this->assertTrue($tree['data'][0]['is_removed']);
        $this->assertSame('', $tree['data'][0]['body_html']);
        $this->assertNull($tree['data'][0]['author']);
        $this->assertSame('This comment is temporarily hidden', $tree['data'][0]['removed_label']);
        $this->assertSame($reply->id, $tree['data'][0]['replies'][0]['id']);
        $this->assertSame(1, $tree['meta']['total']);
        $this->assertSame(0, $this->app->make(ContributionCounter::class)->approvedContributions($leaver->id));
        $this->assertSame(1, $product->rrApprovedComments()->count());
        $this->actingAs($this->user())->postJson("/rr/comments/{$leaf->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertUnprocessable();

        $this->assertSame(['reviews' => 1, 'comments' => 2, 'topic_ratings' => 0], $this->erasure()->restore($leaver->id));
        $this->assertSame(2, $this->summaryCount($product));
        $this->assertCount(2, $this->tree($product)['data']);
        Event::assertDispatched(UserErasureProcessed::class, fn (UserErasureProcessed $event): bool => $event->action === UserErasureProcessed::WITHHOLD && $event->userId === $leaver->id);
        Event::assertDispatched(UserErasureProcessed::class, fn (UserErasureProcessed $event): bool => $event->action === UserErasureProcessed::RESTORE);
    }

    public function test_a_withheld_review_cannot_be_voted_on_or_replied_to_and_a_withheld_quote_is_hidden(): void
    {
        $product = $this->product();
        $leaver = $this->user();
        $review = Review::factory()->forSubject($product)->create(['user_id' => $leaver->id]);
        $quoted = Comment::factory()->forSubject($product)->create(['user_id' => $leaver->id, 'body_text' => 'Romig en zoet']);
        Comment::factory()->forSubject($product)->create(['quoted_comment_id' => $quoted->id, 'quote_text' => 'Romig en zoet']);
        Comment::factory()->replyTo($quoted)->create();

        $this->erasure()->withhold($leaver->id);

        $this->actingAs($this->user())->postJson("/rr/reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => 1])->assertNotFound();
        $this->actingAs($this->user())->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => 'Eens', 'parent_id' => $quoted->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $quote = collect($this->tree($product)['data'])->firstWhere('quote.comment_id', $quoted->id)['quote'];
        $this->assertNull($quote['text']);
        $this->assertFalse($quote['available']);
    }

    public function test_withholding_hides_standalone_topic_scores_from_the_averages(): void
    {
        $product = $this->product();
        $topic = RatingTopic::factory()->create();
        $this->app->make(TopicRatings::class)->import(901, $product, $topic->id, 5, 5);
        $this->app->make(TopicRatings::class)->import(902, $product, $topic->id, 1, 5);

        $this->erasure()->withhold(901);

        $topics = $this->getJson("/rr/subjects/product/{$product->id}/rating-summary")->json('data.topics');
        $this->assertSame(1, $topics[0]['count']);
        $this->assertEquals(1, $topics[0]['average']);
    }

    public function test_purging_removes_the_users_data_and_recounts_what_depended_on_it(): void
    {
        Event::fake([UserErasureProcessed::class]);
        $this->configureSection('product', ['editor_tools' => ['bold', 'image']]);
        $product = $this->product();
        $leaver = $this->user();
        $other = $this->user();
        $moderator = $this->moderator();

        // Their review with a photo and a reply by someone else.
        $photo = $this->photo($leaver);
        $reviewId = $this->actingAs($leaver)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 5, 'body' => '<p>Erg lekker in koffie.</p>', 'photos' => [$photo->id]])
            ->assertCreated()->json('data.id');
        $replyToReview = Comment::factory()->forSubject(Review::query()->findOrFail($reviewId))->create(['user_id' => $other->id, 'section' => 'review_replies']);
        Review::factory()->forSubject($product)->create(['user_id' => $other->id]);

        // Their votes, a poll vote, comments with and without replies, a quote of them.
        $othersComment = Comment::factory()->forSubject($product)->create(['user_id' => $other->id]);
        $this->actingAs($leaver)->postJson("/rr/comments/{$othersComment->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertOk();
        $poll = $this->actingAs($other)->postJson("/rr/subjects/product/{$product->id}/comments", ['body' => 'Peiling', 'poll' => ['question' => 'Warm?', 'options' => ['Ja', 'Nee']]])->json('data.poll');
        $this->actingAs($leaver)->postJson("/rr/polls/{$poll['id']}/votes", ['option_id' => $poll['options'][0]['id']])->assertOk();
        $withReplies = Comment::factory()->forSubject($product)->create(['user_id' => $leaver->id, 'body_html' => '<p>Mijn vraag</p>', 'body_text' => 'Mijn vraag']);
        CommentRevision::query()->create(['comment_id' => $withReplies->id, 'revision' => 1, 'body_html' => '<p>Oud</p>', 'body_text' => 'Oud', 'edited_by' => $leaver->id, 'edited_at' => now()]);
        $answer = Comment::factory()->replyTo($withReplies)->create(['user_id' => $other->id, 'quoted_comment_id' => $withReplies->id, 'quote_text' => 'Mijn vraag']);
        $this->actingAs($other)->postJson("/rr/comments/{$withReplies->id}/votes", ['dimension' => 'agreement', 'value' => 1])->assertOk();
        $leaf = Comment::factory()->forSubject($product)->create(['user_id' => $leaver->id]);
        $ownChain = Comment::factory()->forSubject($product)->create(['user_id' => $leaver->id]);
        Comment::factory()->replyTo($ownChain)->create(['user_id' => $leaver->id]);
        $looseUpload = $this->photo($leaver);

        // Read marker, invitation, topic score, a report they filed and one they handled.
        $this->app->make(ReadMarkers::class)->touch($leaver->id, $product);
        $this->app->make(ReviewInvitations::class)->schedule($leaver, $this->product(), 'purchase', 1);
        $this->app->make(TopicRatings::class)->import($leaver->id, $product, RatingTopic::factory()->create()->id, 4, 5);
        $filed = Report::factory()->forSubject($othersComment)->create(['reporter_id' => $leaver->id]);
        $handled = Report::factory()->forSubject($othersComment)->create(['handled_by' => $leaver->id, 'status' => 'dismissed']);
        $this->assertSame(2, $this->summaryCount($product));

        $counts = $this->erasure()->purge($leaver->id);

        $this->assertSame(1, $counts['votes']);
        $this->assertSame(1, $counts['poll_votes']);
        $this->assertSame(1, $counts['reviews_deleted']);
        $this->assertSame(3, $counts['comments_deleted'], 'The leaf and their own chain (deepest first).');
        $this->assertSame(1, $counts['comments_anonymised']);
        $this->assertSame(2, $counts['uploads_deleted']);

        $this->assertSame(0, Vote::query()->where('user_id', $leaver->id)->count());
        $this->assertSame(0, $othersComment->refresh()->agree_count, 'The item is recounted.');
        $this->assertSame(0, PollVote::query()->count());
        $this->assertSame(0, (int) PollOption::query()->sum('votes_count'));
        $this->assertSame(0, ReadMarker::query()->count());
        $this->assertSame(0, ReviewInvitation::query()->count());
        $this->assertSame(0, TopicRating::query()->withoutGlobalScopes()->count());

        $this->assertNull(Review::query()->withErasureWithheld()->withTrashed()->find($reviewId));
        $this->assertNull(Comment::query()->withTrashed()->find($replyToReview->id), 'Replies to a removed review go with it.');
        $this->assertSame(0, Upload::query()->where('user_id', $leaver->id)->count());
        Storage::disk('public')->assertMissing($photo->path);
        Storage::disk('public')->assertMissing($looseUpload->path);
        $this->assertSame(1, $this->summaryCount($product));
        $this->assertSame(1, RatingAggregate::query()->where('reviewable_id', $product->id)->sole()->review_count);

        $placeholder = $withReplies->refresh();
        $this->assertNull($placeholder->user_id);
        $this->assertSame('', $placeholder->body_html);
        $this->assertNotNull($placeholder->author_removed_at);
        $this->assertSame(0, $placeholder->agree_count);
        $this->assertSame(0, CommentRevision::query()->where('comment_id', $withReplies->id)->count());
        $this->assertNull($answer->refresh()->quote_text);
        $this->assertNull(Comment::query()->withTrashed()->find($leaf->id));
        $this->assertNull(Comment::query()->withTrashed()->find($ownChain->id));

        $tree = collect($this->tree($product)['data'])->firstWhere('id', $withReplies->id);
        $this->assertSame('Comment removed', $tree['removed_label']);
        $this->assertNull($tree['author']);
        $this->assertSame($answer->id, $tree['replies'][0]['id']);

        $this->assertNull($filed->refresh()->reporter_id);
        $this->assertNull($handled->refresh()->handled_by);
        $this->actingAs($moderator)->getJson('/rr/admin/reports')->assertOk()->assertJsonPath('data.0.reporter_id', null);
        Event::assertDispatched(UserErasureProcessed::class, fn (UserErasureProcessed $event): bool => $event->action === UserErasureProcessed::PURGE && $event->counts['reviews_deleted'] === 1);
    }

    public function test_purging_keeps_content_held_as_evidence_without_its_hashes(): void
    {
        $product = $this->product();
        $leaver = $this->user();
        $review = Review::factory()->forSubject($product)->create(['user_id' => $leaver->id, 'ip_hash' => 'ip', 'network_hash' => 'net', 'device_hash' => 'dev']);
        $comment = Comment::factory()->forSubject($product)->create(['user_id' => $leaver->id, 'network_hash' => 'net', 'held_as_evidence' => true, 'author_removed_at' => now()]);
        Comment::factory()->replyTo($comment)->create();
        Report::factory()->forSubject($review)->create();

        $counts = $this->erasure()->purge($leaver->id);

        $this->assertSame(1, $counts['reviews_kept_as_evidence']);
        $this->assertSame(1, $counts['comments_kept_as_evidence']);
        $kept = Review::query()->withErasureWithheld()->withTrashed()->findOrFail($review->id);
        $this->assertTrue($kept->trashed());
        $this->assertNotNull($kept->erasure_withheld_at);
        $this->assertSame([null, null, null], [$kept->ip_hash, $kept->network_hash, $kept->device_hash]);
        $this->assertSame($leaver->id, $kept->user_id, 'Evidence stays attributable for the moderator.');
        $this->assertNull(Review::query()->find($review->id));
        $this->assertSame(0, $this->summaryCount($product));

        $comment->refresh();
        $this->assertNull($comment->network_hash);
        $this->assertSame($leaver->id, $comment->user_id);
        $this->assertNotNull($comment->erasure_withheld_at);
    }
}
