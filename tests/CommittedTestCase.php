<?php

namespace RatingsAndReviews\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;
use RatingsAndReviews\RatingsAndReviewsServiceProvider;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Tests\Fixtures\Article;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\Fixtures\User;

/**
 * For tests that need committed data: InnoDB FULLTEXT indexes only see committed rows, and
 * forked processes only see what the parent committed. Runs on its own MySQL 8.4 schema
 * (`<DB_DATABASE>_committed`, created when missing) with fresh migrations per test, so the
 * transactional tests on the main schema never see these rows.
 */
abstract class CommittedTestCase extends Orchestra
{
    use ConfiguresPackageEnvironment;

    private static int $sequence = 0;

    protected function getPackageProviders($app): array
    {
        return [RatingsAndReviewsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->configurePackageEnvironment($app);
        $schema = env('DB_DATABASE', 'rr_test').'_committed';

        $socket = (string) env('DB_SOCKET', '');
        $dsn = $socket !== ''
            ? "mysql:unix_socket={$socket}"
            : 'mysql:host='.env('DB_HOST', '127.0.0.1').';port='.env('DB_PORT', '3306');
        (new PDO($dsn, (string) env('DB_USERNAME', 'root'), (string) env('DB_PASSWORD', '')))
            ->exec("CREATE DATABASE IF NOT EXISTS `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $app['config']->set('database.connections.mysql.database', $schema);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--path' => [
            realpath(__DIR__.'/../database/migrations'),
            realpath(__DIR__.'/database/migrations'),
        ], '--realpath' => true])->assertSuccessful();

        Relation::morphMap(['product' => Product::class, 'article' => Article::class, 'user' => User::class]);

        foreach (Ability::all() as $ability) {
            Gate::define($ability, fn (User $user): bool => (bool) $user->is_moderator);
        }
    }

    protected function user(array $attributes = []): User
    {
        self::$sequence++;

        return User::query()->create($attributes + ['name' => 'Committed '.self::$sequence, 'is_moderator' => false, 'email' => 'c'.self::$sequence.'-'.uniqid().'@example.test']);
    }

    protected function product(array $attributes = []): Product
    {
        return Product::query()->create($attributes + ['name' => 'Oat drink']);
    }
}
