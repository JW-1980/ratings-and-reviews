<?php

namespace RatingsAndReviews\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\CardResolver;
use RatingsAndReviews\Contracts\ContributionCounter;
use RatingsAndReviews\Contracts\OfficialReplyResolver;
use RatingsAndReviews\Contracts\PriceAtReviewResolver;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Contracts\WantToTryList;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Support\Ability;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Tests\Fixtures\User;
use RatingsAndReviews\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_package_models_have_morph_aliases_without_enforcing_the_map(): void
    {
        $this->assertSame(Review::class, Relation::getMorphedModel('rr_review'));
        $this->assertSame(Comment::class, Relation::getMorphedModel('rr_comment'));
        $this->assertSame('rr_review', (new Review)->getMorphClass());
        $this->assertFalse(Relation::requiresMorphMap());
    }

    public function test_every_contract_has_a_safe_default_binding(): void
    {
        foreach ([AuthorResolver::class, AbilityResolver::class, RiskSignals::class, VoterIdentity::class, PriceAtReviewResolver::class, CardResolver::class, WantToTryList::class, ContributionCounter::class, OfficialReplyResolver::class] as $contract) {
            $this->assertInstanceOf($contract, $this->app->make($contract));
        }

        $this->assertFalse($this->app->make(PriceAtReviewResolver::class)->isEnabled());
        $this->assertFalse($this->app->make(CardResolver::class)->isEnabled());
        $this->assertFalse($this->app->make(WantToTryList::class)->isEnabled());
        $this->assertFalse($this->app->make(OfficialReplyResolver::class)->isEnabled());
        $this->assertFalse($this->app->make(AbilityResolver::class)->allows(null, Ability::MODERATE));
        $this->assertFalse($this->app->make(AbilityResolver::class)->allows($this->user(), 'rr.not-defined-by-the-host'));
    }

    public function test_the_default_contribution_counter_counts_approved_contributions(): void
    {
        $product = $this->product();
        Review::factory()->forSubject($product)->create(['user_id' => 77]);
        Review::factory()->forSubject($this->product())->status(ContentStatus::Pending)->create(['user_id' => 77]);
        Comment::factory()->count(2)->forSubject($product)->create(['user_id' => 77]);
        Comment::factory()->forSubject($product)->create(['user_id' => 77, 'author_removed_at' => now()]);

        $this->assertSame(3, $this->app->make(ContributionCounter::class)->approvedContributions(77));
    }

    public function test_every_write_route_is_throttled_by_a_named_limiter(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'rr.'));
        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as $route) {
            if (array_intersect($route->methods(), ['POST', 'PATCH', 'PUT', 'DELETE']) === []) {
                continue;
            }

            $throttles = array_filter($route->gatherMiddleware(), fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, ThrottleRequests::class.':rr-'));
            $this->assertNotEmpty($throttles, "Write route {$route->getName()} has no named throttle.");
        }
    }

    public function test_the_named_limiter_answers_too_many_requests(): void
    {
        config(['ratings-and-reviews.rate_limits.rr-report' => 2]);
        $user = $this->user();

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($user)->postJson('/rr/reports', [])->assertUnprocessable();
        }

        $this->actingAs($user)->postJson('/rr/reports', [])->assertTooManyRequests();
    }

    public function test_the_default_author_resolver_reads_the_host_user_model(): void
    {
        $user = $this->user(['name' => 'Anouk']);

        $profiles = $this->app->make(AuthorResolver::class)->resolve([$user->id, 999999]);

        $this->assertSame('Anouk', $profiles[$user->id]->displayName);
        $this->assertSame('Member', $profiles[999999]->displayName);
    }

    public function test_the_default_voter_identity_keys_guests_on_their_network_without_storing_the_address(): void
    {
        $identity = $this->app->make(VoterIdentity::class);
        $request = fn (string $ip): Request => Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Test']);

        $guest = $identity->voterKey($request('2001:db8:abcd:1::5'), null);
        $this->assertStringStartsWith('g:', $guest);
        $this->assertStringNotContainsString('2001', $guest);
        $this->assertSame($guest, $identity->voterKey($request('2001:db8:abcd:ffff::9'), null), 'One IPv6 /48 is one network.');
        $this->assertNotSame($guest, $identity->voterKey($request('2001:db8:abce::1'), null));
        $this->assertSame('u:12', $identity->voterKey($request('203.0.113.1'), new User(['id' => 12])));
    }
}
