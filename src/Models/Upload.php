<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An image a user uploaded: an inline image of the editor or a review photo. The sanitizer
 * only keeps images whose source is one of the author's own uploads that is not attached to
 * other content.
 *
 * @property int $id
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string|null $mime_type
 * @property int|null $size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property string|null $attached_type
 * @property int|null $attached_id
 * @property Carbon|null $attached_at
 * @property string|null $role
 * @property int $position
 * @property Carbon|null $created_at
 */
class Upload extends Model
{
    public const ROLE_INLINE = 'inline';

    public const ROLE_PHOTO = 'photo';

    protected $table = 'rr_uploads';

    protected $fillable = [
        'user_id', 'disk', 'path', 'mime_type', 'size', 'width', 'height', 'alt_text',
        'attached_type', 'attached_id', 'attached_at', 'role', 'position',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'attached_id' => 'integer',
            'attached_at' => 'datetime',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attached(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Upload>  $query
     */
    public function scopePhotos(Builder $query): void
    {
        $query->where('role', self::ROLE_PHOTO)->orderBy('position')->orderBy('id');
    }

    /**
     * @param  Builder<Upload>  $query
     */
    public function scopeAttachedTo(Builder $query, Model $model): void
    {
        $query->where('attached_type', $model->getMorphClass())->where('attached_id', $model->getKey());
    }

    /** Whether uploads are served by the package (private disk) instead of the host's public URL. */
    public static function servedPrivately(): bool
    {
        return config('ratings-and-reviews.uploads.visibility', 'public') === 'private';
    }

    /**
     * The URL prefix every upload URL starts with: `uploads.url_prefix` for public uploads,
     * the package's media route for private ones. The sanitizer accepts images under it.
     */
    public static function urlPrefix(): string
    {
        if (self::servedPrivately()) {
            $prefix = trim((string) config('ratings-and-reviews.routes.prefix', 'rr'), '/');

            return '/'.($prefix === '' ? '' : $prefix.'/').'media/';
        }

        return rtrim((string) config('ratings-and-reviews.uploads.url_prefix', '/storage/'), '/').'/';
    }

    public function url(): string
    {
        return self::urlPrefix().ltrim($this->path, '/');
    }

    public function isAttached(): bool
    {
        return $this->attached_id !== null;
    }
}
