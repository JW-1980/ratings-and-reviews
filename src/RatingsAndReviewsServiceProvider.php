<?php

namespace RatingsAndReviews;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\CardResolver;
use RatingsAndReviews\Contracts\ContributionCounter;
use RatingsAndReviews\Contracts\PriceAtReviewResolver;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Contracts\WantToTryList;
use RatingsAndReviews\Defaults\AllowAllRiskSignals;
use RatingsAndReviews\Defaults\DatabaseContributionCounter;
use RatingsAndReviews\Defaults\DefaultAuthorResolver;
use RatingsAndReviews\Defaults\DefaultVoterIdentity;
use RatingsAndReviews\Defaults\GateAbilityResolver;
use RatingsAndReviews\Defaults\NullCardResolver;
use RatingsAndReviews\Defaults\NullPriceAtReviewResolver;
use RatingsAndReviews\Defaults\NullWantToTryList;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\CommentRevision;
use RatingsAndReviews\Models\DiscussionState;
use RatingsAndReviews\Models\Poll;
use RatingsAndReviews\Models\PollOption;
use RatingsAndReviews\Models\PollVote;
use RatingsAndReviews\Models\RatingAggregate;
use RatingsAndReviews\Models\RatingTopic;
use RatingsAndReviews\Models\ReadMarker;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\ReviewInvitation;
use RatingsAndReviews\Models\ReviewTopicRating;
use RatingsAndReviews\Models\SectionSetting;
use RatingsAndReviews\Models\Sticker;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Services\SectionSettingsRepository;

class RatingsAndReviewsServiceProvider extends ServiceProvider
{
    /**
     * The morph aliases of the package models. Registered with Relation::morphMap() (not
     * enforced), so the host's own enforceMorphMap() keeps working.
     *
     * @var array<string, class-string<Model>>
     */
    public const MORPH_MAP = [
        'rr_section_setting' => SectionSetting::class,
        'rr_rating_topic' => RatingTopic::class,
        'rr_review' => Review::class,
        'rr_review_topic_rating' => ReviewTopicRating::class,
        'rr_rating_aggregate' => RatingAggregate::class,
        'rr_comment' => Comment::class,
        'rr_comment_revision' => CommentRevision::class,
        'rr_vote' => Vote::class,
        'rr_report' => Report::class,
        'rr_discussion_state' => DiscussionState::class,
        'rr_read_marker' => ReadMarker::class,
        'rr_poll' => Poll::class,
        'rr_poll_option' => PollOption::class,
        'rr_poll_vote' => PollVote::class,
        'rr_review_invitation' => ReviewInvitation::class,
        'rr_upload' => Upload::class,
        'rr_sticker' => Sticker::class,
    ];

    /**
     * Default bindings; a host overrides any of them in its own provider.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        AuthorResolver::class => DefaultAuthorResolver::class,
        AbilityResolver::class => GateAbilityResolver::class,
        RiskSignals::class => AllowAllRiskSignals::class,
        VoterIdentity::class => DefaultVoterIdentity::class,
        PriceAtReviewResolver::class => NullPriceAtReviewResolver::class,
        CardResolver::class => NullCardResolver::class,
        WantToTryList::class => NullWantToTryList::class,
        ContributionCounter::class => DatabaseContributionCounter::class,
        SectionSettingsRepository::class => SectionSettingsRepository::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ratings-and-reviews.php', 'ratings-and-reviews');
    }

    public function boot(): void
    {
        Relation::morphMap(self::MORPH_MAP);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'ratings-and-reviews');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rr');

        $this->registerRateLimiters();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/ratings-and-reviews.php' => $this->app->configPath('ratings-and-reviews.php')], 'ratings-and-reviews-config');
            $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'ratings-and-reviews-migrations');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/ratings-and-reviews')], 'ratings-and-reviews-translations');
            $this->publishes([__DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/rr')], 'ratings-and-reviews-views');
        }
    }

    private function registerRateLimiters(): void
    {
        /** @var RateLimiter $limiter */
        $limiter = $this->app->make(RateLimiter::class);

        foreach (['rr-write', 'rr-vote', 'rr-report', 'rr-admin', ...array_keys((array) config('ratings-and-reviews.rate_limits', []))] as $name) {
            $limiter->for((string) $name, function (Request $request) use ($name): Limit {
                $user = $request->user(config('ratings-and-reviews.routes.guard'));
                $key = $user !== null ? 'u:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();
                $perMinute = (int) config('ratings-and-reviews.rate_limits.'.$name, 60);

                return Limit::perMinute(max(1, $perMinute))->by($name.'|'.$key);
            });
        }
    }

    private function registerRoutes(): void
    {
        if (! config('ratings-and-reviews.routes.enabled', true) || ($this->app->routesAreCached())) {
            return;
        }

        Route::prefix((string) config('ratings-and-reviews.routes.prefix', 'rr'))
            ->name((string) config('ratings-and-reviews.routes.name', 'rr.'))
            ->middleware((array) config('ratings-and-reviews.routes.middleware', ['api']))
            ->group(__DIR__.'/../routes/api.php');
    }
}
