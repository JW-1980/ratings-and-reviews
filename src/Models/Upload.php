<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An image a user uploaded for the editor. The sanitizer only keeps images whose source is
 * one of the author's uploads.
 *
 * @property int $id
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string|null $alt_text
 * @property string|null $attached_type
 * @property int|null $attached_id
 * @property Carbon|null $attached_at
 */
class Upload extends Model
{
    protected $table = 'rr_uploads';

    protected $fillable = ['user_id', 'disk', 'path', 'alt_text', 'attached_type', 'attached_id', 'attached_at'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'attached_id' => 'integer', 'attached_at' => 'datetime'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attached(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(): string
    {
        return rtrim((string) config('ratings-and-reviews.uploads.url_prefix', '/storage/'), '/').'/'.ltrim($this->path, '/');
    }
}
