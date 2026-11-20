<?php

namespace RatingsAndReviews\Tests;

use RatingsAndReviews\Tests\Fixtures\User;

/**
 * The test application: MySQL 8.4 (default) or MariaDB from the DB_* environment
 * (phpunit.xml.dist), array cache, sync queue and the fixture user model.
 *
 * DB_CONNECTION selects Laravel's driver: `mysql` (default) or `mariadb`. The connection is
 * named after the driver, so `DB::connection()->getDriverName()` and `Support\SqlDialect`
 * see the same driver a host on that engine would configure.
 */
trait ConfiguresPackageEnvironment
{
    /** The connection (and driver) under test: `mysql` or `mariadb`. */
    protected static function testConnection(): string
    {
        $connection = (string) env('DB_CONNECTION', 'mysql');

        if (! in_array($connection, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException("DB_CONNECTION must be mysql or mariadb, got [{$connection}].");
        }

        return $connection;
    }

    protected function configurePackageEnvironment($app): void
    {
        $connection = self::testConnection();

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.locale', 'en');
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}", [
            'driver' => $connection,
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'rr_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('ratings-and-reviews.sections', ['product' => [], 'forum' => ['max_depth' => 2]]);
        $app['config']->set('ratings-and-reviews.spam.minimum_seconds', 2);
    }
}
