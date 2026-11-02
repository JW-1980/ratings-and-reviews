<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RatingsAndReviews\Contracts\Moderatable;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Contracts\Votable;
use RatingsAndReviews\Database\Factories\ReviewFactory;
use RatingsAndReviews\Services\ReviewService;
use RatingsAndReviews\Support\Comparison;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\VoteDimension;

/**
 * @property int $id
 * @property string $reviewable_type
 * @property int $reviewable_id
 * @property int $user_id
 * @property string|null $section the subject's reviewSectionKey() when written (null: rows from before it was stored)
 * @property ContentStatus $status
 * @property int $rating
 * @property int $scale_max
 * @property string|null $title
 * @property string $body_html
 * @property string $body_text
 * @property list<string>|null $pros
 * @property list<string>|null $cons
 * @property array<string, mixed>|null $facets
 * @property int|null $price_cents
 * @property string|null $price_currency
 * @property string|null $compared_type
 * @property int|null $compared_id
 * @property Comparison|null $comparison
 * @property int|null $quality_score
 * @property int $helpful_count
 * @property int $flags_count
 * @property string|null $ip_hash
 * @property string|null $network_hash
 * @property string|null $device_hash
 * @property bool $counted
 * @property Carbon|null $approved_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Review extends Model implements Moderatable, Votable
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'rr_reviews';

    protected $fillable = [
        'reviewable_type', 'reviewable_id', 'user_id', 'section', 'status', 'rating', 'scale_max', 'title', 'body_html', 'body_text',
        'pros', 'cons', 'facets', 'price_cents', 'price_currency', 'compared_type', 'compared_id', 'comparison',
        'quality_score', 'helpful_count', 'flags_count', 'ip_hash', 'network_hash', 'device_hash', 'counted',
        'approved_at', 'edited_at',
    ];

    protected $hidden = ['ip_hash', 'network_hash', 'device_hash'];

    protected function casts(): array
    {
        return [
            'reviewable_id' => 'integer',
            'user_id' => 'integer',
            'status' => ContentStatus::class,
            'rating' => 'integer',
            'scale_max' => 'integer',
            'pros' => 'array',
            'cons' => 'array',
            'facets' => 'array',
            'price_cents' => 'integer',
            'compared_id' => 'integer',
            'comparison' => Comparison::class,
            'quality_score' => 'integer',
            'helpful_count' => 'integer',
            'flags_count' => 'integer',
            'counted' => 'boolean',
            'approved_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The alternative named in "compared with…" (a Reviewable host model).
     *
     * @return MorphTo<Model, $this>
     */
    public function compared(): MorphTo
    {
        return $this->morphTo('compared', 'compared_type', 'compared_id');
    }

    /**
     * @return HasMany<ReviewTopicRating, $this>
     */
    public function topicRatings(): HasMany
    {
        return $this->hasMany(ReviewTopicRating::class);
    }

    /**
     * @return MorphMany<Vote, $this>
     */
    public function votes(): MorphMany
    {
        return $this->morphMany(Vote::class, 'votable');
    }

    /**
     * The review's photos (rr_uploads with role `photo`), in their order.
     *
     * @return MorphMany<Upload, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Upload::class, 'attached')->photos();
    }

    /**
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * @param  Builder<Review>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', ContentStatus::Approved->value);
    }

    /**
     * @param  Builder<Review>  $query
     */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('reviewable_type', $subject->getMorphClass())->where('reviewable_id', $subject->getKey());
    }

    /** The rating mapped onto 0 (lowest) … 1 (highest) of its own scale. */
    public function normalizedRating(): float
    {
        return self::normalize($this->rating, $this->scale_max);
    }

    public static function normalize(int $value, int $scaleMax): float
    {
        return $scaleMax <= 1 ? 0.0 : max(0.0, min(1.0, ($value - 1) / ($scaleMax - 1)));
    }

    public function voteSection(): ?string
    {
        $subject = $this->reviewable;

        return $subject instanceof Reviewable ? $subject->reviewSectionKey() : null;
    }

    public function votableAuthorId(): ?int
    {
        return $this->user_id;
    }

    public function acceptsVotes(): bool
    {
        return $this->status === ContentStatus::Approved && ! $this->trashed();
    }

    public function voteCounterColumns(): array
    {
        return [VoteDimension::Helpfulness->value => [1 => 'helpful_count']];
    }

    public function derivedVoteColumns(array $counters): array
    {
        return [];
    }

    public function supportsDimension(VoteDimension $dimension): bool
    {
        return array_key_exists($dimension->value, $this->voteCounterColumns());
    }

    public function moderationAuthorId(): ?int
    {
        return $this->user_id;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === ContentStatus::Approved && ! $this->trashed();
    }

    public function moderationSnapshot(): array
    {
        return [
            'kind' => 'review',
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'rating' => $this->rating,
            'scale_max' => $this->scale_max,
            'title' => $this->title,
            'body_html' => $this->body_html,
            'body_text' => $this->body_text,
            'pros' => $this->pros,
            'cons' => $this->cons,
            'created_at' => $this->created_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
        ];
    }

    public function takeDown(int|string $moderatorId, ?string $note = null): void
    {
        app(ReviewService::class)->moderate($this, ContentStatus::Rejected, $moderatorId, $note);
    }

    protected static function newFactory(): Factory
    {
        return ReviewFactory::new();
    }
}
