<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property array<string, string> $name
 * @property string $path
 * @property bool $is_active
 * @property int $sort_order
 */
class Sticker extends Model
{
    protected $table = 'rr_stickers';

    protected $fillable = ['name', 'path', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['name' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * @param  Builder<Sticker>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function url(): string
    {
        return rtrim((string) config('ratings-and-reviews.stickers.url_prefix', '/storage/'), '/').'/'.ltrim($this->path, '/');
    }
}
