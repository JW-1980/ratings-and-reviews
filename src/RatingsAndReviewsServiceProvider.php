<?php

namespace RatingsAndReviews;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RatingsAndReviews\Console\PruneUploadsCommand;
use RatingsAndReviews\Console\PublishDueCommentsCommand;
use RatingsAndReviews\Console\SendReviewInvitationsCommand;
use RatingsAndReviews\Contracts\AbilityResolver;
use RatingsAndReviews\Contracts\AttributionResolver;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Contracts\BlockList;
use RatingsAndReviews\Contracts\CardResolver;
use RatingsAndReviews\Contracts\ContentAnnotator;
use RatingsAndReviews\Contracts\ContributionCounter;
use RatingsAndReviews\Contracts\OfficialReplyResolver;
use RatingsAndReviews\Contracts\PriceAtReviewResolver;
use RatingsAndReviews\Contracts\RiskSignals;
use RatingsAndReviews\Contracts\SummaryExtension;
use RatingsAndReviews\Contracts\UploadScanner;
use RatingsAndReviews\Contracts\VoterIdentity;
use RatingsAndReviews\Contracts\WantToTryList;
use RatingsAndReviews\Defaults\AllowAllRiskSignals;
use RatingsAndReviews\Defaults\DatabaseContributionCounter;
use RatingsAndReviews\Defaults\DefaultAuthorResolver;
use RatingsAndReviews\Defaults\DefaultVoterIdentity;
use RatingsAndReviews\Defaults\GateAbilityResolver;
use RatingsAndReviews\Defaults\NullAttributionResolver;
use RatingsAndReviews\Defaults\NullBlockList;
use RatingsAndReviews\Defaults\NullCardResolver;
use RatingsAndReviews\Defaults\NullContentAnnotator;
use RatingsAndReviews\Defaults\NullOfficialReplyResolver;
use RatingsAndReviews\Defaults\NullPriceAtReviewResolver;
use RatingsAndReviews\Defaults\NullUploadScanner;
use RatingsAndReviews\Defaults\NullWantToTryList;
use RatingsAndReviews\Events\CommentDeleted;
use RatingsAndReviews\Events\CommentEdited;
use RatingsAndReviews\Events\CommentModerated;
use RatingsAndReviews\Events\CommentPosted;
use RatingsAndReviews\Listeners\SyncSubjectCommentCount;
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
use RatingsAndReviews\Models\ReviewAnswer;
use RatingsAndReviews\Models\ReviewFollowUp;
use RatingsAndReviews\Models\ReviewInvitation;
use RatingsAndReviews\Models\ReviewQuestion;
use RatingsAndReviews\Models\ReviewTopicRating;
use RatingsAndReviews\Models\SectionSetting;
use RatingsAndReviews\Models\Sticker;
use RatingsAndReviews\Models\TopicRating;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Models\Vote;
use RatingsAndReviews\Services\Participation;
use RatingsAndReviews\Services\RatingAggregator;
use RatingsAndReviews\Services\ReviewQuestions;
use RatingsAndReviews\Services\SectionSettingsRepository;
use RatingsAndReviews\Summary\ComparisonSummary;
use RatingsAndReviews\Summary\FacetSummary;
use RatingsAndReviews\Summary\FollowUpSummary;
use RatingsAndReviews\Summary\ReviewQuestionSummary;
use RatingsAndReviews\Summary\ScoreTrend;
use RatingsAndReviews\Summary\UncertaintyBand;
use RatingsAndReviews\Support\CardExtensionRegistry;

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
        'rr_topic_rating' => TopicRating::class,
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
        'rr_review_question' => ReviewQuestion::class,
        'rr_review_answer' => ReviewAnswer::class,
        'rr_review_follow_up' => ReviewFollowUp::class,
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
        OfficialReplyResolver::class => NullOfficialReplyResolver::class,
        BlockList::class => NullBlockList::class,
        AttributionResolver::class => NullAttributionResolver::class,
        UploadScanner::class => NullUploadScanner::class,
        ContentAnnotator::class => NullContentAnnotator::class,
        SectionSettingsRepository::class => SectionSettingsRepository::class,
    ];

    /**
     * The summary keys the package adds itself; a host tags its own SummaryExtension with
     * RatingAggregator::EXTENSION_TAG as well.
     *
     * @var list<class-string<SummaryExtension>>
     */
    public const SUMMARY_EXTENSIONS = [UncertaintyBand::class, ScoreTrend::class, FacetSummary::class, ComparisonSummary::class, ReviewQuestionSummary::class, FollowUpSummary::class];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ratings-and-reviews.php', 'ratings-and-reviews');
        $this->app->tag(self::SUMMARY_EXTENSIONS, RatingAggregator::EXTENSION_TAG);
        // Scoped: it caches the BlockList per request (and per job under Octane or a queue worker).
        $this->app->scoped(Participation::class);
        $this->app->scoped(ReviewQuestions::class);
        // Boot-time registrations of the host (like routes and listeners): one per process.
        $this->app->singleton(CardExtensionRegistry::class);
    }

    public function boot(): void
    {
        Relation::morphMap(self::MORPH_MAP);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'ratings-and-reviews');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rr');

        $this->registerRateLimiters();
        $this->registerListeners();
        $this->registerRoutes();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([PublishDueCommentsCommand::class, SendReviewInvitationsCommand::class, PruneUploadsCommand::class]);
            $this->publishes([__DIR__.'/../config/ratings-and-reviews.php' => $this->app->configPath('ratings-and-reviews.php')], 'ratings-and-reviews-config');
            $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'ratings-and-reviews-migrations');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/ratings-and-reviews')], 'ratings-and-reviews-translations');
            $this->publishes([__DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/rr')], 'ratings-and-reviews-views');
        }
    }

    /** Keeps a host's own comment counter in step (SyncsCommentCount). */
    private function registerListeners(): void
    {
        foreach ([CommentPosted::class, CommentDeleted::class, CommentModerated::class, CommentEdited::class] as $event) {
            Event::listen($event, SyncSubjectCommentCount::class);
        }
    }

    private function registerRateLimiters(): void
    {
        /** @var RateLimiter $limiter */
        $limiter = $this->app->make(RateLimiter::class);

        foreach (['rr-write', 'rr-vote', 'rr-report', 'rr-admin', 'rr-lookup', 'rr-upload', ...array_keys((array) config('ratings-and-reviews.rate_limits', []))] as $name) {
            $limiter->for((string) $name, function (Request $request) use ($name): Limit {
                $user = $request->user(config('ratings-and-reviews.routes.guard'));
                $key = $user !== null ? 'u:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();
                $perMinute = (int) config('ratings-and-reviews.rate_limits.'.$name, 60);

                return Limit::perMinute(max(1, $perMinute))->by($name.'|'.$key);
            });
        }
    }

    /**
     * rr:publish-due every minute (undo window), rr:send-review-invitations hourly and
     * rr:prune-uploads hourly, unless `schedule.enabled` is false (the host then schedules them
     * itself).
     */
    private function registerSchedule(): void
    {
        if (! config('ratings-and-reviews.schedule.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('rr:publish-due')->everyMinute()->withoutOverlapping()->name('rr:publish-due');
            $schedule->command('rr:send-review-invitations')->hourly()->withoutOverlapping()->name('rr:send-review-invitations');
            $schedule->command('rr:prune-uploads')->hourly()->withoutOverlapping()->name('rr:prune-uploads');
        });
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        if (config('ratings-and-reviews.routes.enabled', true)) {
            Route::prefix((string) config('ratings-and-reviews.routes.prefix', 'rr'))
                ->name((string) config('ratings-and-reviews.routes.name', 'rr.'))
                ->middleware((array) config('ratings-and-reviews.routes.middleware', ['api']))
                ->group(__DIR__.'/../routes/api.php');
        }

        // Opt-in form routes for posting without JavaScript (session + CSRF via `web`).
        if (config('ratings-and-reviews.web_routes.enabled', false)) {
            Route::prefix((string) config('ratings-and-reviews.web_routes.prefix', 'rr-forms'))
                ->name((string) config('ratings-and-reviews.web_routes.name', 'rr.web.'))
                ->middleware((array) config('ratings-and-reviews.web_routes.middleware', ['web']))
                ->group(__DIR__.'/../routes/web.php');
        }
    }
}
