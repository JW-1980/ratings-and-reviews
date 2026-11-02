<?php

namespace RatingsAndReviews\Tests;

use RatingsAndReviews\Tests\Fixtures\User;

/**
 * The test application: MySQL 8.4 from the DB_* environment (phpunit.xml.dist), array
 * cache, sync queue and the fixture user model.
 */
trait ConfiguresPackageEnvironment
{
    protected function configurePackageEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.locale', 'en');
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
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
