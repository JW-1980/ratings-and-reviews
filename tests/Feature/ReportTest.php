<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use RatingsAndReviews\Events\ContentReported;
use RatingsAndReviews\Events\ReportResolved;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\ReportStatus;
use RatingsAndReviews\Tests\TestCase;

class ReportTest extends TestCase
{
    public function test_a_member_reports_content_with_a_snapshot(): void
    {
        Event::fake([ContentReported::class]);
        $comment = Comment::factory()->forSubject($this->product())->create(['body_html' => '<p>Origineel</p>', 'body_text' => 'Origineel']);
        $reporter = $this->user();

        $this->actingAs($reporter)->postJson('/rr/reports', ['reportable_type' => 'rr_comment', 'reportable_id' => $comment->id, 'reason' => 'offensive'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('message', 'Thank you. A moderator will look at your report.');

        $report = Report::query()->sole();
        $this->assertSame($reporter->id, $report->reporter_id);
        $this->assertSame('<p>Origineel</p>', $report->snapshot['body_html']);
        $this->assertSame(1, $comment->fresh()->flags_count);
        Event::assertDispatched(ContentReported::class);
    }

    public function test_one_open_report_per_reporter_and_item(): void
    {
        $review = Review::factory()->forSubject($this->product())->create();
        $reporter = $this->user();
        $payload = ['reportable_type' => 'rr_review', 'reportable_id' => $review->id, 'reason' => 'spam'];

        $this->actingAs($reporter)->postJson('/rr/reports', $payload)->assertCreated();
        $this->actingAs($reporter)->postJson('/rr/reports', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reportable_id.0', 'You have already reported this. A moderator will look at it.');
        $this->actingAs($this->user())->postJson('/rr/reports', $payload)->assertCreated();
        $this->assertSame(2, $review->fresh()->flags_count);

        // Once handled, the same member may report it again.
        Report::query()->where('reporter_id', $reporter->id)->update(['status' => 'dismissed']);
        $this->actingAs($reporter)->postJson('/rr/reports', $payload)->assertCreated();
    }

    public function test_the_database_refuses_a_second_open_report_even_without_the_service(): void
    {
        $review = Review::factory()->forSubject($this->product())->create();
        Report::factory()->forSubject($review)->create(['reporter_id' => 5]);
        Report::factory()->forSubject($review)->create(['reporter_id' => 5, 'status' => ReportStatus::Resolved]);

        $this->expectException(UniqueConstraintViolationException::class);
        Report::factory()->forSubject($review)->create(['reporter_id' => 5]);
    }

    public function test_invalid_reports_are_refused(): void
    {
        $author = $this->user();
        $comment = Comment::factory()->forSubject($this->product())->create(['user_id' => $author->id]);
        $pending = Comment::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create();
        $hidden = Comment::factory()->forSubject($this->product(['is_hidden' => true]))->create();
        $base = ['reportable_type' => 'rr_comment', 'reportable_id' => $comment->id, 'reason' => 'spam'];

        $this->postJson('/rr/reports', $base)->assertUnauthorized();
        $this->actingAs($author)->postJson('/rr/reports', $base)->assertForbidden();
        $this->actingAs($this->user())->postJson('/rr/reports', ['reason' => 'boring'] + $base)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->user())->postJson('/rr/reports', ['reason' => 'other'] + $base)->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->actingAs($this->user())->postJson('/rr/reports', ['reportable_type' => 'product'] + $base)->assertNotFound();
        $this->actingAs($this->user())->postJson('/rr/reports', ['reportable_type' => 'nope'] + $base)->assertNotFound();
        $this->actingAs($this->user())->postJson('/rr/reports', ['reportable_id' => $pending->id] + $base)->assertUnprocessable();
        $this->actingAs($this->user())->postJson('/rr/reports', ['reportable_id' => $hidden->id] + $base)->assertNotFound();

        $this->assertSame(0, Report::query()->count());
    }

    public function test_a_moderator_resolves_a_report_and_takes_the_content_down(): void
    {
        Event::fake([ReportResolved::class]);
        $review = Review::factory()->forSubject($this->product())->create();
        $report = Report::factory()->forSubject($review)->create();
        Review::query()->whereKey($review->id)->update(['flags_count' => 1]);
        $moderator = $this->moderator();

        $this->actingAs($this->user())->patchJson("/rr/admin/reports/{$report->id}", ['status' => 'resolved'])->assertForbidden();

        $this->actingAs($moderator)->patchJson("/rr/admin/reports/{$report->id}", ['status' => 'resolved', 'note' => 'Reclame', 'take_down' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.handled_by', $moderator->id)
            ->assertJsonPath('data.resolution_note', 'Reclame');

        $report->refresh();
        $this->assertNotNull($report->handled_at);
        $this->assertSame(ContentStatus::Rejected, $review->fresh()->status);
        $this->assertSame(0, $review->fresh()->flags_count);
        Event::assertDispatched(ReportResolved::class, fn (ReportResolved $event): bool => $event->tookDown);

        $this->actingAs($moderator)->patchJson("/rr/admin/reports/{$report->id}", ['status' => 'dismissed'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'This report has already been handled.');
    }

    public function test_dismissing_leaves_the_content_alone_and_the_inbox_lists_open_reports(): void
    {
        $comment = Comment::factory()->forSubject($this->product())->create();
        $open = Report::factory()->forSubject($comment)->create();
        Report::factory()->forSubject($comment)->create(['status' => ReportStatus::Dismissed]);
        $moderator = $this->moderator();

        $this->actingAs($moderator)->getJson('/rr/admin/reports')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $open->id)->assertJsonPath('data.0.reason_label', 'Spam or advertising');

        $this->actingAs($moderator)->patchJson("/rr/admin/reports/{$open->id}", ['status' => 'dismissed', 'take_down' => true])->assertOk();
        $this->assertSame(ContentStatus::Approved, $comment->fresh()->status, 'A dismissed report never takes content down.');
    }

    public function test_reports_without_a_reporter_are_listed_and_handled(): void
    {
        $comment = Comment::factory()->forSubject($this->product())->create();
        $first = Report::factory()->forSubject($comment)->create(['reporter_id' => null]);
        $second = Report::factory()->forSubject($comment)->create(['reporter_id' => null]);
        $moderator = $this->moderator();

        $this->actingAs($moderator)->getJson('/rr/admin/reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.reporter_id', null);

        $this->actingAs($moderator)->patchJson("/rr/admin/reports/{$first->id}", ['status' => 'resolved', 'take_down' => true])->assertOk();
        $this->assertNull($second->fresh()->reporter_id, 'Two open reports without a reporter do not collide.');
        $this->assertSame(ContentStatus::Rejected, $comment->fresh()->status);
    }
}
