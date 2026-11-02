<?php

namespace RatingsAndReviews\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;
use RatingsAndReviews\RatingsAndReviewsServiceProvider;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Tests\Fixtures\Article;
use RatingsAndReviews\Tests\Fixtures\Product;
use RatingsAndReviews\Tests\Fixtures\User;

/**
 * Runs against a real MySQL 8.4 server (phpunit.xml.dist, overridable with DB_* env vars).
 */
abstract class TestCase extends Orchestra
{
    use ConfiguresPackageEnvironment;
    use RefreshDatabase;

    private static int $userSequence = 0;

    protected function getPackageProviders($app): array
    {
        return [RatingsAndReviewsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->configurePackageEnvironment($app);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['product' => Product::class, 'article' => Article::class, 'user' => User::class]);

        foreach (Ability::all() as $ability) {
            Gate::define($ability, fn (User $user): bool => (bool) $user->is_moderator);
        }
    }

    protected function user(array $attributes = []): User
    {
        self::$userSequence++;

        return User::query()->create($attributes + [
            'name' => 'User '.self::$userSequence,
            'is_moderator' => false,
            'email' => 'user'.self::$userSequence.'-'.uniqid().'@example.test',
        ]);
    }

    protected function moderator(): User
    {
        return $this->user(['is_moderator' => true]);
    }

    protected function product(array $attributes = []): Product
    {
        return Product::query()->create($attributes + ['name' => 'Oat drink']);
    }
}
