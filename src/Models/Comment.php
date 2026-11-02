<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RatingsAndReviews\Contracts\Moderatable;
use RatingsAndReviews\Contracts\Votable;
use RatingsAndReviews\Database\Factories\CommentFactory;
use RatingsAndReviews\Services\DiscussionService;
use RatingsAndReviews\Support\ContentStatus;
use RatingsAndReviews\Support\VoteDimension;

/**
 * One comment in a threaded discussion.
 *
 * @property int $id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property string $section
 * @property int|null $parent_id
 * @property int|null $root_id
 * @property int|null $in_reply_to_id
 * @property int $depth
 * @property int $user_id
 * @property ContentStatus $status
 * @property bool $counted
 * @property string $body_html
 * @property string $body_text
 * @property int|null $quoted_comment_id
 * @property string|null $quote_text
 * @property int $agree_count
 * @property int $disagree_count
 * @property int $helpful_count
 * @property int $unhelpful_count
 * @property int $needs_source_count
 * @property float $helpful_score
 * @property float $controversy_score
 * @property int $flags_count
 * @property bool $is_edited
 * @property Carbon|null $edited_at
 * @property Carbon|null $author_removed_at
 * @property bool $held_as_evidence
 * @property Carbon|null $publish_at
 * @property Carbon|null $published_at
 * @property string|null $ip_hash
 * @property string|null $network_hash
 * @property string|null $device_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Comment extends Model implements Moderatable, Votable
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'rr_comments';

    protected $fillable = [
        'commentable_type', 'commentable_id', 'section', 'parent_id', 'root_id', 'in_reply_to_id', 'depth', 'user_id',
        'status', 'counted', 'body_html', 'body_text', 'quoted_comment_id', 'quote_text', 'agree_count', 'disagree_count',
        'helpful_count', 'unhelpful_count', 'needs_source_count', 'controversy_score', 'flags_count', 'is_edited',
        'edited_at', 'author_removed_at', 'held_as_evidence', 'publish_at', 'published_at', 'ip_hash', 'network_hash',
        'device_hash',
    ];

    protected $hidden = ['ip_hash', 'network_hash', 'device_hash'];

    protected function casts(): array
    {
        return [
            'commentable_id' => 'integer',
            'parent_id' => 'integer',
            'root_id' => 'integer',
            'in_reply_to_id' => 'integer',
            'depth' => 'integer',
            'user_id' => 'integer',
            'status' => ContentStatus::class,
            'counted' => 'boolean',
            'quoted_comment_id' => 'integer',
            'agree_count' => 'integer',
            'disagree_count' => 'integer',
            'helpful_count' => 'integer',
            'unhelpful_count' => 'integer',
            'needs_source_count' => 'integer',
            'helpful_score' => 'float',
            'controversy_score' => 'float',
            'flags_count' => 'integer',
            'is_edited' => 'boolean',
            'edited_at' => 'datetime',
            'author_removed_at' => 'datetime',
            'held_as_evidence' => 'boolean',
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function quotedComment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quoted_comment_id');
    }

    /**
     * @return HasOne<Poll, $this>
     */
    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class);
    }

    /**
     * @return HasMany<CommentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CommentRevision::class);
    }

    /**
     * @return MorphMany<Vote, $this>
     */
    public function votes(): MorphMany
    {
        return $this->morphMany(Vote::class, 'votable');
    }

    /**
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * @param  Builder<Comment>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', ContentStatus::Approved->value);
    }

    /**
     * Approved and published: what everyone sees. A comment inside its undo window is
     * approved but not yet published.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Approved->value)->whereNotNull('published_at');
    }

    /**
     * What this viewer sees: the published comments, plus their own comments that are
     * pending moderation or still inside the undo window (shown to them, marked as such).
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeVisibleTo(Builder $query, ?int $viewerId): void
    {
        $query->where(function (Builder $query) use ($viewerId): void {
            $query->where(fn (Builder $public) => $public->where('status', ContentStatus::Approved->value)->whereNotNull('published_at'));

            if ($viewerId !== null) {
                $query->orWhere(fn (Builder $own) => $own->where('user_id', $viewerId)
                    ->whereIn('status', [ContentStatus::Approved->value, ContentStatus::Pending->value])
                    ->whereNull('published_at'));
            }
        });
    }

    /**
     * @param  Builder<Comment>  $query
     */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('commentable_type', $subject->getMorphClass())->where('commentable_id', $subject->getKey());
    }

    /** Whether it is approved but waiting for its undo window to end. */
    public function isScheduled(): bool
    {
        return $this->status === ContentStatus::Approved && $this->published_at === null;
    }

    /** Whether the author can still take it back: unpublished and inside the undo window. */
    public function isUndoable(): bool
    {
        return $this->published_at === null
            && $this->publish_at !== null
            && $this->publish_at->isFuture()
            && in_array($this->status, [ContentStatus::Approved, ContentStatus::Pending], true)
            && ! $this->trashed();
    }

    /**
     * How the viewer sees it: `public`, `pending` (awaiting a moderator) or `scheduled`
     * (inside the undo window). Only the author is shown the last two.
     */
    public function visibility(): string
    {
        return match (true) {
            $this->status === ContentStatus::Pending => 'pending',
            $this->isScheduled() => 'scheduled',
            $this->status === ContentStatus::Approved => 'public',
            default => 'hidden',
        };
    }

    /** Whether the author removed the text and only a placeholder holds the replies' place. */
    public function isRemovedByAuthor(): bool
    {
        return $this->author_removed_at !== null;
    }

    /** Wilson lower bound of the helpfulness votes, the same formula as the stored helpful_score column. */
    public static function wilsonLowerBound(int $positive, int $negative, float $z = 1.96): float
    {
        $total = $positive + $negative;

        if ($total === 0) {
            return 0.0;
        }

        $phat = $positive / $total;

        return ($phat + $z * $z / (2 * $total) - $z * sqrt(($phat * (1 - $phat) + $z * $z / (4 * $total)) / $total)) / (1 + $z * $z / $total);
    }

    /** (agree + disagree) ^ (min / max); 0 without votes. */
    public static function controversy(int $agree, int $disagree): float
    {
        $max = max($agree, $disagree);

        return $max === 0 ? 0.0 : round(($agree + $disagree) ** (min($agree, $disagree) / $max), 6);
    }

    public function voteSection(): ?string
    {
        return $this->section;
    }

    public function votableAuthorId(): ?int
    {
        return $this->user_id;
    }

    public function acceptsVotes(): bool
    {
        return $this->isPubliclyVisible();
    }

    public function voteCounterColumns(): array
    {
        return [
            VoteDimension::Agreement->value => [1 => 'agree_count', -1 => 'disagree_count'],
            VoteDimension::Helpfulness->value => [1 => 'helpful_count', -1 => 'unhelpful_count'],
            VoteDimension::NeedsSource->value => [1 => 'needs_source_count'],
        ];
    }

    public function derivedVoteColumns(array $counters): array
    {
        return ['controversy_score' => self::controversy($counters['agree_count'] ?? 0, $counters['disagree_count'] ?? 0)];
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
        return $this->status === ContentStatus::Approved && $this->published_at !== null && ! $this->trashed() && ! $this->isRemovedByAuthor();
    }

    public function moderationSnapshot(): array
    {
        return [
            'kind' => 'comment',
            'id' => $this->id,
            'user_id' => $this->user_id,
            'section' => $this->section,
            'status' => $this->status->value,
            'parent_id' => $this->parent_id,
            'body_html' => $this->body_html,
            'body_text' => $this->body_text,
            'created_at' => $this->created_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
        ];
    }

    public function takeDown(int|string $moderatorId, ?string $note = null): void
    {
        app(DiscussionService::class)->moderate($this, ContentStatus::Rejected, $moderatorId, $note);
    }

    protected static function newFactory(): Factory
    {
        return CommentFactory::new();
    }
}
