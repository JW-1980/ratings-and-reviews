<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RatingsAndReviews\Events\ReviewInvitationDue;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewInvitation;
use RatingsAndReviews\Services\ReviewInvitations;
use RatingsAndReviews\Tests\TestCase;

class ReviewInvitationsTest extends TestCase
{
    private ReviewInvitations $invitations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invitations = $this->app->make(ReviewInvitations::class);
    }

    public function test_scheduling_is_idempotent_and_skips_subjects_already_reviewed(): void
    {
        $user = $this->user();
        $product = $this->product();

        $first = $this->invitations->schedule($user, $product, 'receipt_scan', 7);
        $again = $this->invitations->schedule($user->id, $product, 'shopping_list', 1);

        $this->assertSame($first->id, $again->id);
        $this->assertSame('receipt_scan', $again->source, 'The first invitation is kept.');
        $this->assertTrue($first->due_at->isSameDay(now()->addDays(7)));
        $this->assertSame(1, ReviewInvitation::query()->count());

        $reviewed = $this->product();
        Review::factory()->forSubject($reviewed)->create(['user_id' => $user->id]);
        $this->assertNull($this->invitations->schedule($user, $reviewed, 'receipt_scan', 3));
    }

    public function test_invalid_sources_and_delays_are_refused(): void
    {
        foreach ([['Receipt Scan', 3], ['', 3], ['receipt', -1], ['receipt', 366]] as [$source, $days]) {
            try {
                $this->invitations->schedule($this->user(), $this->product(), $source, $days);
                $this->fail("Accepted {$source} / {$days}.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, ReviewInvitation::query()->count());
    }

    public function test_the_command_fires_due_invitations_once_and_never_after_a_review_or_dismissal(): void
    {
        Event::fake([ReviewInvitationDue::class]);
        $due = $this->invitations->schedule($this->user(), $this->product(), 'receipt_scan', 0);
        $later = $this->invitations->schedule($this->user(), $this->product(), 'receipt_scan', 5);
        $dismissed = $this->invitations->schedule($this->user(), $this->product(), 'receipt_scan', 0);
        $this->invitations->dismiss($dismissed);
        $reviewer = $this->user();
        $reviewedProduct = $this->product();
        $reviewedLater = $this->invitations->schedule($reviewer, $reviewedProduct, 'receipt_scan', 0);
        Review::factory()->forSubject($reviewedProduct)->create(['user_id' => $reviewer->id]);
        $closed = $this->invitations->schedule($this->user(), $this->product(['is_open' => false]), 'receipt_scan', 0);

        $this->artisan('rr:send-review-invitations')->assertSuccessful()->expectsOutputToContain('Sent 1, completed 1, removed 0, waiting 1.');
        $this->artisan('rr:send-review-invitations')->assertSuccessful()->expectsOutputToContain('Sent 0, completed 0');

        Event::assertDispatchedTimes(ReviewInvitationDue::class, 1);
        Event::assertDispatched(ReviewInvitationDue::class, fn (ReviewInvitationDue $event): bool => $event->invitation->is($due));
        $this->assertNotNull($due->fresh()->sent_at);
        $this->assertNull($later->fresh()->sent_at);
        $this->assertNull($dismissed->fresh()->sent_at);
        $this->assertNotNull($reviewedLater->fresh()->completed_at);
        $this->assertNull($closed->fresh()->sent_at, 'A subject that does not accept reviews waits.');
        $this->assertTrue($closed->fresh()->due_at->isFuture(), 'A waiting invitation moves aside so it cannot starve later ones.');

        $this->travel(6)->days();
        $this->artisan('rr:send-review-invitations')->assertSuccessful();
        Event::assertDispatchedTimes(ReviewInvitationDue::class, 2);
    }

    public function test_a_run_looks_reviews_and_subjects_up_once_however_many_invitations_are_due(): void
    {
        Event::fake([ReviewInvitationDue::class]);

        $run = function (int $count): array {
            $sent = [];

            foreach (range(1, $count) as $n) {
                $sent[] = $this->invitations->schedule($this->user(), $this->product(), 'receipt_scan', 0);
            }

            // Crossed pairs: A is invited for X and reviewed Y, B is invited for Y and reviewed X.
            // Neither reviewed the subject of their own invitation, so both are sent.
            [$a, $b, $x, $y] = [$this->user(), $this->user(), $this->product(), $this->product()];
            $sent[] = $this->invitations->schedule($a, $x, 'receipt_scan', 0);
            $sent[] = $this->invitations->schedule($b, $y, 'receipt_scan', 0);
            Review::factory()->forSubject($y)->create(['user_id' => $a->id]);
            Review::factory()->forSubject($x)->create(['user_id' => $b->id]);

            DB::flushQueryLog();
            DB::enableQueryLog();
            // Resolved after Event::fake(): the service keeps the dispatcher it was built with.
            $result = $this->app->make(ReviewInvitations::class)->sendDue();
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            $this->assertSame(['sent' => $count + 2, 'completed' => 0, 'removed' => 0, 'waiting' => 0], $result);

            foreach ($sent as $invitation) {
                $this->assertNotNull($invitation->fresh()->sent_at);
            }

            return [
                // Not the section's settings: those are read once and cached.
                count(array_filter($queries, fn (string $query): bool => str_starts_with($query, 'select') && ! str_contains($query, '`rr_section_settings`'))),
                count(array_filter($queries, fn (string $query): bool => str_starts_with($query, 'update'))),
            ];
        };

        [$selectsFew, $updatesFew] = $run(2);
        [$selectsMany, $updatesMany] = $run(9);

        // The due invitations, their reviews and their subjects: three reads per run.
        $this->assertSame(3, $selectsFew);
        $this->assertSame(3, $selectsMany);
        // One claim per invitation: that is what keeps two runs from sending it twice.
        $this->assertSame(4, $updatesFew);
        $this->assertSame(11, $updatesMany);

        Event::assertDispatched(ReviewInvitationDue::class, fn (ReviewInvitationDue $event): bool => $event->invitation->sent_at !== null
            && $event->invitation->sent_at->equalTo($event->invitation->fresh()->sent_at)
            && $event->invitation->relationLoaded('reviewable')
            && ! $event->invitation->isDirty());
    }

    public function test_an_invitation_for_a_deleted_subject_is_removed(): void
    {
        Event::fake([ReviewInvitationDue::class]);
        $product = $this->product();
        $invitation = $this->invitations->schedule($this->user(), $product, 'receipt_scan', 0);
        $product->delete();

        $this->artisan('rr:send-review-invitations')->assertSuccessful();

        $this->assertNull($invitation->fresh());
        Event::assertNotDispatched(ReviewInvitationDue::class);
    }

    public function test_submitting_the_review_completes_the_invitation(): void
    {
        $user = $this->user();
        $product = $this->product();
        $invitation = $this->invitations->schedule($user, $product, 'receipt_scan', 0);

        $this->actingAs($user)->postJson("/rr/subjects/product/{$product->id}/reviews", ['rating' => 5])->assertCreated();

        $this->assertNotNull($invitation->fresh()->completed_at);
    }

    public function test_members_list_and_dismiss_only_their_own_sent_invitations(): void
    {
        Event::fake([ReviewInvitationDue::class]);
        $user = $this->user();
        $mine = $this->invitations->schedule($user, $this->product(['name' => 'Kaas']), 'receipt_scan', 0);
        $this->invitations->schedule($user, $this->product(), 'receipt_scan', 9);
        $theirs = $this->invitations->schedule($this->user(), $this->product(), 'receipt_scan', 0);
        $this->invitations->sendDue();
        $this->getJson('/rr/review-invitations')->assertUnauthorized();

        $this->actingAs($user)->getJson('/rr/review-invitations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.subject.title', 'Kaas');

        $this->actingAs($user)->postJson("/rr/review-invitations/{$theirs->id}/dismiss")->assertNotFound();
        $this->actingAs($user)->postJson('/rr/review-invitations/abc/dismiss')->assertNotFound();
        $this->assertNull($theirs->fresh()->dismissed_at);

        $this->actingAs($user)->postJson("/rr/review-invitations/{$mine->id}/dismiss")->assertOk();
        $this->assertNotNull($mine->fresh()->dismissed_at);
        $this->actingAs($user)->getJson('/rr/review-invitations')->assertJsonCount(0, 'data');
    }

    public function test_the_package_schedules_its_commands(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $invitations = $events->first(fn ($event): bool => str_contains((string) $event->command, 'rr:send-review-invitations'));
        $publisher = $events->first(fn ($event): bool => str_contains((string) $event->command, 'rr:publish-due'));

        $this->assertSame('0 * * * *', $invitations?->expression);
        $this->assertSame('* * * * *', $publisher?->expression);
        $this->assertTrue($publisher->withoutOverlapping);
    }
}
