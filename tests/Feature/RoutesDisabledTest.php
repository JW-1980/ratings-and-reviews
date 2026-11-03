<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Tests\TestCase;

class RoutesDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('ratings-and-reviews.routes.enabled', false);
    }

    public function test_the_api_can_be_switched_off(): void
    {
        $this->assertFalse(Route::has('rr.reviews.index'));
        $this->getJson('/rr/subjects/product/1/reviews')->assertNotFound();
    }
}
