<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Tests\TestCase;

class WebRoutesTest extends TestCase
{
    private const PAGE = 'http://localhost/products/1';

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

    private function form(string $method, string $uri, array $data = [], array $files = []): TestResponse
    {
        return $this->from(self::PAGE)->call($method, '/rr-forms/'.ltrim($uri, '/'), $data, [], $files, ['HTTP_ACCEPT' => 'text/html']);
    }

    public function test_the_form_routes_are_registered_only_when_enabled(): void
    {
        $this->assertTrue(Route::has('rr.web.comments.store'));
        $this->assertSame('http://localhost/rr-forms/subjects/product/5/comments', route('rr.web.comments.store', ['type' => 'product', 'id' => 5]));
        $this->assertContains('web', Route::getRoutes()->getByName('rr.web.comments.store')->gatherMiddleware());
    }

    public function test_posting_a_comment_redirects_back_to_it_with_the_status(): void
    {
        $product = $this->product();
        $this->actingAs($this->user());

        $response = $this->form('POST', "subjects/product/{$product->id}/comments", ['body' => "Eerste regel\n\nTweede alinea"]);

        $comment = Comment::query()->sole();
        $response->assertRedirect(self::PAGE.'#rr-comment-'.$comment->id)->assertSessionHas('rr_status', 'Your comment has been posted.');
        $this->assertSame('<p>Eerste regel</p><p>Tweede alinea</p>', $comment->body_html);
    }

    public function test_validation_errors_come_back_in_the_forms_own_bag_with_the_old_input(): void
    {
        $product = $this->product();
        $parent = $this->commentOn($product);
        $this->actingAs($this->user());

        $this->form('POST', "subjects/product/{$product->id}/comments", ['body' => 'x', 'parent_id' => $parent->id])
            ->assertRedirect(self::PAGE)
            ->assertSessionHasErrors(['body'], null, 'rr_comment')
            ->assertSessionHas('rr_form', 'comment:'.$parent->id)
            ->assertSessionHasInput('body', 'x');
        $this->assertSame(1, Comment::query()->count());
    }

    public function test_the_rephrase_nudge_is_flashed_and_posting_again_with_confirm_tone_goes_through(): void
    {
        $product = $this->product();
        $this->actingAs($this->user());

        $this->form('POST', "subjects/product/{$product->id}/comments", ['body' => 'Wat een idioot verhaal'])
            ->assertRedirect(self::PAGE)
            ->assertSessionHas('rr_tone')
            ->assertSessionHasErrors(['body'], null, 'rr_comment');
        $this->assertSame(0, Comment::query()->count());

        $this->form('POST', "subjects/product/{$product->id}/comments", ['body' => 'Wat een idioot verhaal', 'confirm_tone' => '1'])->assertSessionHas('rr_status');
        $this->assertSame(1, Comment::query()->count());
    }

    public function test_a_guest_gets_the_login_message_instead_of_an_error_page(): void
    {
        $product = $this->product();

        $this->form('POST', "subjects/product/{$product->id}/comments", ['body' => 'Hallo daar'])
            ->assertRedirect(self::PAGE)
            ->assertSessionHas('rr_error', 'Please sign in to do this.');
        $this->assertSame(0, Comment::query()->count());
    }

    public function test_editing_undoing_and_deleting_comments(): void
    {
        $product = $this->product();
        $author = $this->user();
        $this->actingAs($author);
        $this->form('POST', "subjects/product/{$product->id}/comments", ['body' => 'Origineel bericht']);
        $id = Comment::query()->sole()->id;

        $this->form('PATCH', "comments/{$id}", ['body' => 'Bewerkt bericht'])->assertRedirect(self::PAGE.'#rr-comment-'.$id)->assertSessionHas('rr_status');
        $this->assertSame('Bewerkt bericht', Comment::query()->sole()->body_text);

        $this->actingAs($this->user());
        $this->form('DELETE', "comments/{$id}")->assertSessionHas('rr_error', 'You can only change your own contributions.');
        $this->assertFalse(Comment::query()->findOrFail($id)->trashed());

        $this->actingAs($author);
        $this->form('DELETE', "comments/{$id}")->assertRedirect(self::PAGE)->assertSessionHas('rr_status', 'The comment has been deleted.');
        $this->assertNull(Comment::query()->find($id));

        $this->configureSection('product', ['undo_window_seconds' => 30]);
        $this->form('POST', "subjects/product/{$product->id}/comments", ['body' => 'Toch niet']);
        $scheduled = Comment::query()->sole()->id;
        $this->form('POST', "comments/{$scheduled}/undo")->assertSessionHas('rr_status', 'Your comment has been withdrawn.');
        $this->assertSame(0, Comment::query()->withTrashed()->whereKey($scheduled)->count());
    }

    public function test_votes_poll_votes_and_reports_from_forms(): void
    {
        $product = $this->product();
        $comment = $this->commentOn($product);
        $poll = Poll::query()->create(['comment_id' => $comment->id, 'question' => 'Warm of koud?']);
        $option = $poll->options()->create(['label' => 'Warm', 'sort_order' => 0]);
        $poll->options()->create(['label' => 'Koud', 'sort_order' => 1]);

        // A guest vote, as the section allows guest votes.
        $this->form('POST', "comments/{$comment->id}/votes", ['dimension' => 'agreement', 'value' => '1'])
            ->assertRedirect(self::PAGE.'#rr-comment-'.$comment->id)->assertSessionHas('rr_status', 'Your vote has been saved.');
        $this->assertSame(1, Vote::query()->count());

        $this->actingAs($this->user());
        $this->form('POST', "polls/{$poll->id}/votes", ['option_id' => $option->id])->assertRedirect(self::PAGE.'#rr-poll-'.$poll->id);
        $this->assertSame(1, $option->fresh()->votes_count);

        $this->form('POST', 'reports', ['reportable_type' => 'rr_comment', 'reportable_id' => $comment->id, 'reason' => 'other'])
            ->assertSessionHasErrors(['details'], null, 'rr_report')->assertSessionHas('rr_form', 'report:rr_comment:'.$comment->id);
        $this->form('POST', 'reports', ['reportable_type' => 'rr_comment', 'reportable_id' => $comment->id, 'reason' => 'spam'])
            ->assertSessionHas('rr_status', 'Thank you. A moderator will look at your report.');
        $this->assertSame(1, Report::query()->count());

        $this->form('POST', "comments/{$comment->id}/votes", ['dimension' => 'nope', 'value' => '1'])->assertSessionHasErrors(['dimension'], null, 'rr_vote');
    }

    public function test_reviews_with_photos_without_javascript(): void
    {
        $product = $this->product();
        $author = $this->user();
        $this->actingAs($author);

        $this->form('POST', "subjects/product/{$product->id}/reviews", ['rating' => '4', 'body' => 'Romig en zacht', 'title' => 'Fijn', 'pros' => ['Smaak', ''], 'topic_ratings' => []], [
            'photo_files' => [UploadedFile::fake()->image('a.jpg', 90, 90), UploadedFile::fake()->image('b.png', 90, 90)],
        ])->assertSessionHas('rr_status', 'Thank you, your review has been published.');

        $review = Review::query()->sole();
        $this->assertSame(['Smaak'], $review->pros);
        $photos = $review->photos()->get();
        $this->assertCount(2, $photos);

        // Keep the second photo only.
        $this->form('PATCH', "reviews/{$review->id}", ['rating' => '5', 'body' => 'Nog beter', 'photos_present' => '1', 'photos' => [(string) $photos[1]->id]])
            ->assertRedirect(self::PAGE.'#rr-review-'.$review->id);
        $this->assertSame([$photos[1]->id], $review->photos()->pluck('id')->all());
        $this->assertSame(5, $review->fresh()->rating);

        // An edit without the photo fields keeps the photos.
        $this->form('PATCH', "reviews/{$review->id}", ['rating' => '3', 'body' => 'Iets minder'])->assertSessionHas('rr_status');
        $this->assertSame(1, $review->photos()->count());

        // Too many photos: a validation error in the review bag, nothing stored.
        config(['ratings-and-reviews.reviews.max_photos' => 1]);
        $before = Upload::query()->count();
        $this->form('PATCH', "reviews/{$review->id}", ['rating' => '3', 'body' => 'Iets minder', 'photos_present' => '1', 'photos' => [(string) $photos[1]->id]], ['photo_files' => [UploadedFile::fake()->image('c.jpg', 90, 90)]])
            ->assertSessionHasErrors(['photos'], null, 'rr_review')->assertSessionHas('rr_form', 'review');
        $this->assertSame($before, Upload::query()->count());

        $this->form('DELETE', "reviews/{$review->id}")->assertSessionHas('rr_status', 'Your review has been deleted.');
        $this->assertTrue(Review::query()->withTrashed()->sole()->trashed());
    }

    public function test_review_validation_errors_and_helpful_votes(): void
    {
        $product = $this->product();
        $this->actingAs($this->user());

        $this->form('POST', "subjects/product/{$product->id}/reviews", ['body' => 'Geen score'])
            ->assertSessionHasErrors(['rating'], null, 'rr_review')->assertSessionHasInput('body', 'Geen score');

        $review = Review::factory()->create(['reviewable_type' => 'product', 'reviewable_id' => $product->id]);
        $this->form('POST', "reviews/{$review->id}/votes", ['dimension' => 'helpfulness', 'value' => '1'])->assertRedirect(self::PAGE.'#rr-review-'.$review->id);
        $this->assertSame(1, $review->fresh()->helpful_count);
    }

    public function test_pinning_and_follow_ups_work_without_javascript(): void
    {
        $product = $this->product();
        $comment = $this->commentOn($product);
        $this->actingAs($this->moderator());

        $this->form('POST', "comments/{$comment->id}/pin")->assertRedirect(self::PAGE.'#rr-comment-'.$comment->id)
            ->assertSessionHas('rr_status', __('ratings-and-reviews::messages.comment_pinned'));
        $this->assertNotNull($comment->fresh()->pinned_at);
        $this->form('DELETE', "comments/{$comment->id}/pin")->assertSessionHas('rr_status', __('ratings-and-reviews::messages.comment_unpinned'));
        $this->assertNull($comment->fresh()->pinned_at);

        $this->configureSection('product', ['review_follow_up_after_days' => 1]);
        $author = $this->user();
        $review = Review::factory()->forSubject($product)->create(['user_id' => $author->id, 'approved_at' => now()->subDays(2)]);
        $this->actingAs($author);

        $this->form('POST', "reviews/{$review->id}/follow-up", ['rating' => 9])->assertRedirect(self::PAGE)->assertSessionHasErrors('rating', null, 'rr_review');
        $this->form('POST', "reviews/{$review->id}/follow-up", ['rating' => 2, 'body' => 'Minder lekker na een tijdje.'])
            ->assertRedirect(self::PAGE.'#rr-review-'.$review->id)->assertSessionHas('rr_status', __('ratings-and-reviews::messages.follow_up_saved'));
        $this->assertSame(2, $review->followUp()->value('rating'));
        $this->form('DELETE', "reviews/{$review->id}/follow-up")->assertSessionHas('rr_status', __('ratings-and-reviews::messages.follow_up_deleted'));
        $this->assertFalse($review->followUp()->exists());
    }
}
