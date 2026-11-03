<?php

namespace RatingsAndReviews\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\RatingsAndReviewsServiceProvider;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Tests\Fixtures\Article;
use RatingsAndReviews\Tests\Fixtures\GroupPost;
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

    /**
     * The fixture tables join the one migrate:fresh that RefreshDatabase runs. Loading them
     * again in every later test would make Testbench migrate and roll them back per test; that
     * rollback is DDL, which commits the test transaction implicitly (and, in a test that
     * swaps the `db` instance, waits forever on the test's own metadata lock).
     */
    protected function defineDatabaseMigrations(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['product' => Product::class, 'article' => Article::class, 'user' => User::class, 'group_post' => GroupPost::class]);
        GroupPost::reset();

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

    /** Overrides config keys of a section and drops its cached settings. */
    protected function configureSection(string $section, array $values): void
    {
        foreach ($values as $key => $value) {
            config(["ratings-and-reviews.sections.{$section}.{$key}" => $value]);
        }

        $this->app->make(SectionSettingsRepository::class)->forget($section);
    }

    /** A published comment on the product. */
    protected function commentOn(Product $product, array $attributes = []): Comment
    {
        return Comment::factory()->forSubject($product)->create($attributes);
    }
}
