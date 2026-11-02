<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\PollOption;
use RatingsAndReviews\Models\PollVote;
use RatingsAndReviews\Services\DiscussionService;
use RatingsAndReviews\Services\PollService;
use RatingsAndReviews\Tests\CommittedTestCase;
use Throwable;

/**
 * Two processes, each on its own MySQL connection, write at the same moment.
 */
class ConcurrentWritesTest extends CommittedTestCase
{
    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency tests.');
        }

        parent::setUp();
    }

    /**
     * Runs $work in two forked children at the same moment; returns their outcomes, sorted.
     *
     * @param  callable(int): string  $work
     * @return list<string>
     */
    private function race(callable $work): array
    {
        $results = sys_get_temp_dir().'/rr-race-'.getmypid().'-'.uniqid();
        mkdir($results);
        $startAt = microtime(true) + 1.0;
        DB::disconnect();
        $children = [];

        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $outcome = 'error';

                try {
                    DB::purge();
                    // Hold the row lock a moment so the two transactions really overlap.
                    DB::listen(function ($query): void {
                        if (str_contains($query->sql, 'for update')) {
                            usleep(300000);
                        }
                    });
                    time_sleep_until($startAt);
                    $outcome = $work($worker);
                } catch (ValidationException) {
                    $outcome = 'refused';
                } catch (Throwable $exception) {
                    $outcome = 'error: '.$exception->getMessage();
                }

                file_put_contents("{$results}/{$worker}", $outcome);
                posix_kill(posix_getpid(), SIGKILL);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge();
        $outcomes = [(string) file_get_contents("{$results}/1"), (string) file_get_contents("{$results}/2")];
        array_map('unlink', glob("{$results}/*") ?: []);
        rmdir($results);
        sort($outcomes);

        return $outcomes;
    }

    public function test_slow_mode_lets_only_one_of_two_simultaneous_posts_through(): void
    {
        config(['ratings-and-reviews.sections.product.slow_mode_seconds' => 60]);
        $user = $this->user();
        $product = $this->product();

        $outcomes = $this->race(function (int $worker) use ($user, $product): string {
            $this->app->make(DiscussionService::class)->post(Request::create('/', 'POST'), $user, $product, "Gelijktijdig {$worker}");

            return 'posted';
        });

        $this->assertSame(['posted', 'refused'], $outcomes);
        $this->assertSame(1, Comment::query()->count());
    }

    public function test_two_simultaneous_votes_of_one_voter_store_one_vote(): void
    {
        $voter = $this->user();
        $comment = Comment::factory()->forSubject($this->product())->create();
        $poll = Poll::query()->create(['comment_id' => $comment->id, 'question' => 'V?']);
        $first = PollOption::query()->create(['poll_id' => $poll->id, 'label' => 'A', 'sort_order' => 0]);
        $second = PollOption::query()->create(['poll_id' => $poll->id, 'label' => 'B', 'sort_order' => 1]);

        $outcomes = $this->race(function (int $worker) use ($voter, $poll, $first, $second): string {
            $this->app->make(PollService::class)->vote(Request::create('/', 'POST'), $voter, $poll, $worker === 1 ? $first->id : $second->id);

            return 'voted';
        });

        $this->assertSame(['voted', 'voted'], $outcomes, 'The second one switches the vote.');
        $this->assertSame(1, PollVote::query()->count());
        $this->assertSame(1, (int) PollOption::query()->where('poll_id', $poll->id)->sum('votes_count'));
        $this->assertSame(PollVote::query()->sole()->option_id, PollOption::query()->where('votes_count', 1)->sole()->id);
    }
}
