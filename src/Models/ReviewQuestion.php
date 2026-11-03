<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RatingsAndReviews\Support\QuestionType;

/**
 * One question of the review questionnaire of a scope, optionally of one category. A
 * question in a more specific category with the same `key` replaces the inherited one (an
 * inactive one switches it off there). Labels are host data per locale ({nl, en}).
 *
 * @property int $id
 * @property string $scope
 * @property string|null $category_key
 * @property string $key
 * @property QuestionType $type
 * @property array<string, string> $label
 * @property array<string, string>|null $low_label
 * @property array<string, string>|null $high_label
 * @property list<array{value: string, label: array<string, string>}>|null $options
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 */
class ReviewQuestion extends Model
{
    protected $table = 'rr_review_questions';

    protected $fillable = ['scope', 'category_key', 'key', 'type', 'label', 'low_label', 'high_label', 'options', 'is_required', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'label' => 'array',
            'low_label' => 'array',
            'high_label' => 'array',
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ReviewAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ReviewAnswer::class, 'question_id');
    }

    /**
     * @param  Builder<ReviewQuestion>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return list<string>
     */
    public function optionValues(): array
    {
        return array_values(array_map(fn (array $option): string => (string) $option['value'], array_filter((array) $this->options, 'is_array')));
    }

    /** A text of this question in the given (or current) locale (host data, as RatingTopic names). */
    public static function localized(?array $texts, ?string $locale = null): string
    {
        if ($texts === null || $texts === []) {
            return '';
        }

        $locale ??= app()->getLocale();

        return (string) ($texts[$locale] ?? $texts[(string) config('app.fallback_locale')] ?? reset($texts) ?: '');
    }

    public function optionLabel(string $value): string
    {
        foreach ((array) $this->options as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === $value) {
                return self::localized(is_array($option['label'] ?? null) ? $option['label'] : null) ?: $value;
            }
        }

        return $value;
    }

    /**
     * The question as the review form and the summary show it.
     *
     * @return array{id: int, key: string, type: string, label: string, low_label: string|null, high_label: string|null, scale_max: int|null, required: bool, options: list<array{value: string, label: string}>}
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => self::localized($this->label),
            'low_label' => $this->type === QuestionType::Scale ? (self::localized($this->low_label) ?: null) : null,
            'high_label' => $this->type === QuestionType::Scale ? (self::localized($this->high_label) ?: null) : null,
            'scale_max' => $this->type === QuestionType::Scale ? self::scaleMax() : null,
            'required' => $this->is_required,
            'options' => array_map(fn (string $value): array => ['value' => $value, 'label' => $this->optionLabel($value)], $this->optionValues()),
        ];
    }

    public static function scaleMax(): int
    {
        return max(2, min(10, (int) config('ratings-and-reviews.review_questions.scale_max', 5)));
    }
}
