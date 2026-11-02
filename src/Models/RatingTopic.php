<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RatingsAndReviews\Database\Factories\RatingTopicFactory;

/**
 * A star-rating subject ("beoordelingsonderwerp"). Topics belong to a scope and optionally
 * to a category; a category topic with a parent replaces that parent in the category.
 *
 * @property int $id
 * @property string $scope
 * @property string|null $category_key
 * @property int|null $parent_id
 * @property array<string, string> $name
 * @property int $scale_max
 * @property int $sort_order
 * @property bool $is_active
 */
class RatingTopic extends Model
{
    /** @use HasFactory<RatingTopicFactory> */
    use HasFactory;

    protected $table = 'rr_rating_topics';

    protected $fillable = ['scope', 'category_key', 'parent_id', 'name', 'scale_max', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'parent_id' => 'integer',
            'scale_max' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RatingTopic, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ReviewTopicRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(ReviewTopicRating::class, 'topic_id');
    }

    /**
     * @param  Builder<RatingTopic>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** The name in the given (or current) locale, falling back to the app fallback locale. */
    public function localizedName(?string $locale = null): string
    {
        $names = $this->name;
        $locale ??= app()->getLocale();

        return (string) ($names[$locale] ?? $names[(string) config('app.fallback_locale')] ?? reset($names) ?: '');
    }

    protected static function newFactory(): Factory
    {
        return RatingTopicFactory::new();
    }
}
