<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase as Orchestra;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\RatingsAndReviewsServiceProvider;
use RatingsAndReviews\Services\RatingTopicCatalog;
use RatingsAndReviews\Tests\ConfiguresPackageEnvironment;
use Throwable;

/**
 * Two processes create the last allowed topic at the same moment, each on its own MySQL
 * connection with committed data. Without the locking read both would see room and the
 * scope would end up over the cap.
 */
class RatingTopicConcurrencyTest extends Orchestra
{
    use ConfiguresPackageEnvironment;

    private const SCOPE = 'concurrency_probe';

    protected function getPackageProviders($app): array
    {
        return [RatingsAndReviewsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->configurePackageEnvironment($app);
    }

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }

        parent::setUp();

        if (! RefreshDatabaseState::$migrated || ! DB::getSchemaBuilder()->hasTable('rr_rating_topics')) {
            $this->artisan('migrate:fresh', ['--path' => [
                realpath(__DIR__.'/../../database/migrations'),
                realpath(__DIR__.'/../database/migrations'),
            ], '--realpath' => true]);
        }

        RatingTopic::query()->where('scope', self::SCOPE)->delete();
    }

    protected function tearDown(): void
    {
        if (! function_exists('pcntl_fork')) {
            parent::tearDown();

            return;
        }

        RatingTopic::query()->where('scope', self::SCOPE)->delete();
        parent::tearDown();
    }

    public function test_two_concurrent_creates_cannot_exceed_the_cap(): void
    {
        foreach (range(1, 3) as $i) {
            RatingTopic::query()->create(['scope' => self::SCOPE, 'name' => ['nl' => "T{$i}", 'en' => "T{$i}"], 'is_active' => true]);
        }

        $results = sys_get_temp_dir().'/rr-concurrency-'.getmypid();
        @mkdir($results);
        $startAt = microtime(true) + 1.0;

        DB::disconnect();
        $children = [];

        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $outcome = 'error';

                try {
                    DB::purge();
                    // Hold the lock a moment inside the transaction so the two really overlap.
                    DB::listen(function ($query): void {
                        if (str_contains($query->sql, 'for update')) {
                            usleep(300000);
                        }
                    });
                    time_sleep_until($startAt);
                    $this->app->make(RatingTopicCatalog::class)->create(['scope' => self::SCOPE, 'name' => ['nl' => "W{$worker}", 'en' => "W{$worker}"]]);
                    $outcome = 'created';
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
        $outcomes = [file_get_contents("{$results}/1"), file_get_contents("{$results}/2")];
        array_map('unlink', glob("{$results}/*") ?: []);
        @rmdir($results);
        sort($outcomes);

        $this->assertSame(['created', 'refused'], $outcomes);
        $this->assertSame(4, RatingTopic::query()->where('scope', self::SCOPE)->where('is_active', true)->count());
    }
}
