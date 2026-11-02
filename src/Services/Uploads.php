<?php

namespace RatingsAndReviews\Services;

use GdImage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Models\Report;
use RatingsAndReviews\Models\Upload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * The package's image uploads: storing (quota, metadata stripping), attaching them to the
 * review or comment that uses them, releasing and deleting them, and pruning the ones that
 * were never used.
 *
 * Lifecycle: an upload starts unattached. Saving a review or comment attaches the uploads its
 * text and photos use (`inline` or `photo`). Undoing a comment releases them again (the text
 * returns to the composer, so they can be posted again); an unattached upload older than
 * `uploads.prune_after_hours` is deleted with its file by rr:prune-uploads. When an author
 * deletes their review or comment its files are deleted at once, unless an open report keeps
 * the content as evidence. A moderator's removal keeps the files (it is a soft delete).
 */
class Uploads
{
    /** Formats GD can re-encode without losing what the image is. */
    private const REENCODED = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Stores a validated image for the user and returns its row.
     *
     * @throws TooManyRequestsHttpException when the daily quota is used up
     * @throws ValidationException when the image cannot be read or processed
     */
    public function store(UploadedFile $file, Authenticatable $user, ?string $alt = null): Upload
    {
        $userId = (int) $user->getAuthIdentifier();
        $retryAfter = $this->quotaRetryAfter($userId);

        if ($retryAfter !== null) {
            throw new TooManyRequestsHttpException($retryAfter, (string) __('ratings-and-reviews::messages.upload_quota', ['count' => $this->dailyQuota()]));
        }

        $mime = (string) $file->getMimeType();
        $info = @getimagesize((string) $file->getRealPath());

        if ($info === false) {
            throw ValidationException::withMessages(['image' => __('ratings-and-reviews::messages.upload_unreadable')]);
        }

        [$bytes, $width, $height] = $this->processed($file, $mime, (int) $info[0], (int) $info[1]);

        $disk = $this->diskName();
        $directory = trim((string) config('ratings-and-reviews.uploads.directory', 'rr-uploads'), '/');
        $path = ($directory === '' ? '' : $directory.'/').now()->format('Y/m').'/'.Str::random(40).'.'.$this->extension($mime);
        $visibility = Upload::servedPrivately() ? 'private' : 'public';

        if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => $visibility])) {
            throw ValidationException::withMessages(['image' => __('ratings-and-reviews::messages.upload_failed')]);
        }

        try {
            return Upload::query()->create([
                'user_id' => $userId,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $mime,
                'size' => strlen($bytes),
                'width' => min($width, 65535),
                'height' => min($height, 65535),
                'alt_text' => $alt === null || trim($alt) === '' ? null : trim(strip_tags($alt)),
            ]);
        } catch (Throwable $exception) {
            // No row: the file would be invisible to prune, so remove it now.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    public function dailyQuota(): int
    {
        return max(0, (int) config('ratings-and-reviews.uploads.daily_quota', 50));
    }

    /**
     * Seconds until the user may upload again, or null when the quota (uploads in the last 24
     * hours) is not used up. A quota of 0 means no limit.
     */
    public function quotaRetryAfter(int $userId): ?int
    {
        $quota = $this->dailyQuota();

        if ($quota === 0) {
            return null;
        }

        $since = now()->subDay();
        $recent = Upload::query()->where('user_id', $userId)->where('created_at', '>', $since)->orderByDesc('created_at')->limit($quota)->pluck('created_at');

        if ($recent->count() < $quota) {
            return null;
        }

        $oldest = Carbon::parse($recent->last());

        return max(1, (int) ceil(now()->diffInSeconds($oldest->copy()->addDay(), true)));
    }

    /**
     * Attaches the author's uploads referenced in a text (inline images). Uploads attached to
     * other content are left alone; the sanitizer already refused them.
     *
     * @param  list<string>  $paths
     */
    public function attachInline(Model $content, int $authorId, array $paths): void
    {
        if ($paths === []) {
            return;
        }

        Upload::query()
            ->where('user_id', $authorId)
            ->whereIn('path', $paths)
            ->whereNull('attached_id')
            ->update(['attached_type' => $content->getMorphClass(), 'attached_id' => $content->getKey(), 'attached_at' => now(), 'role' => Upload::ROLE_INLINE, 'position' => 0]);
    }

    /**
     * The photos for a review: the author's own image uploads, not attached elsewhere.
     *
     * @param  list<int>  $ids  in display order
     * @return list<int> the validated ids, in order
     *
     * @throws ValidationException
     */
    public function validatePhotos(array $ids, int $authorId, ?Model $content): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $max = max(0, (int) config('ratings-and-reviews.reviews.max_photos', 4));

        if ($ids === []) {
            return [];
        }

        if (count($ids) > $max) {
            throw ValidationException::withMessages(['photos' => trans_choice('ratings-and-reviews::messages.photos_too_many', $max, ['max' => $max])]);
        }

        $found = Upload::query()
            ->whereKey($ids)
            ->where('user_id', $authorId)
            ->where(function ($query) use ($content): void {
                $query->whereNull('attached_id');

                if ($content !== null && $content->exists) {
                    $query->orWhere(fn ($query) => $query->where('attached_type', $content->getMorphClass())->where('attached_id', $content->getKey()));
                }
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['photos' => __('ratings-and-reviews::messages.photo_unavailable')]);
        }

        return $ids;
    }

    /**
     * Makes `$ids` the photos of the content, in order. Former photos that are no longer listed
     * are released (inline images of the same upload stay attached as inline).
     *
     * @param  list<int>  $ids  validated by validatePhotos()
     * @param  list<string>  $inlinePaths  paths the text still uses
     */
    public function syncPhotos(Model $content, array $ids, array $inlinePaths = []): void
    {
        $attributes = ['attached_type' => $content->getMorphClass(), 'attached_id' => $content->getKey()];

        foreach ($ids as $position => $id) {
            Upload::query()->whereKey($id)->update($attributes + ['role' => Upload::ROLE_PHOTO, 'position' => $position, 'attached_at' => now()]);
        }

        $dropped = Upload::query()->attachedTo($content)->where('role', Upload::ROLE_PHOTO)->whereNotIn('id', $ids === [] ? [0] : $ids)->get();

        foreach ($dropped as $upload) {
            in_array($upload->path, $inlinePaths, true)
                ? $upload->forceFill(['role' => Upload::ROLE_INLINE, 'position' => 0])->save()
                : $upload->forceFill(['attached_type' => null, 'attached_id' => null, 'attached_at' => null, 'role' => null, 'position' => 0])->save();
        }
    }

    /**
     * Releases inline uploads the text no longer uses (after an edit without revision history,
     * as for reviews), so rr:prune-uploads can remove them.
     *
     * @param  list<string>  $inlinePaths
     */
    public function releaseUnusedInline(Model $content, array $inlinePaths): void
    {
        Upload::query()->attachedTo($content)
            ->where(fn ($query) => $query->whereNull('role')->orWhere('role', Upload::ROLE_INLINE))
            ->whereNotIn('path', $inlinePaths === [] ? [''] : $inlinePaths)
            ->update(['attached_type' => null, 'attached_id' => null, 'attached_at' => null, 'role' => null, 'position' => 0]);
    }

    /** Releases every upload of the content for reuse (undo: the text goes back to the composer). */
    public function release(Model $content): void
    {
        Upload::query()->attachedTo($content)->update(['attached_type' => null, 'attached_id' => null, 'attached_at' => null, 'role' => null, 'position' => 0]);
    }

    /** Whether an open report keeps this content (and so its images) as evidence. */
    public function heldAsEvidence(Model $content): bool
    {
        return Report::query()->open()->where('reportable_type', $content->getMorphClass())->where('reportable_id', $content->getKey())->exists();
    }

    /**
     * Deletes the files and rows of every upload attached to the content. Call it after the
     * transaction that removed the content has committed.
     *
     * @return int uploads deleted
     */
    public function purge(Model $content): int
    {
        return $this->deleteUploads(Upload::query()->attachedTo($content)->get());
    }

    /**
     * Deletes unattached uploads created before the grace period, in batches.
     *
     * @return int uploads deleted
     */
    public function prune(?int $hours = null): int
    {
        $hours ??= max(0, (int) config('ratings-and-reviews.uploads.prune_after_hours', 24));
        $batch = max(1, (int) config('ratings-and-reviews.uploads.prune_batch_size', 200));
        $before = now()->subHours($hours);
        $deleted = 0;
        $lastId = 0;

        do {
            $uploads = Upload::query()->whereNull('attached_id')->where('created_at', '<', $before)->where('id', '>', $lastId)->orderBy('id')->limit($batch)->get();

            if ($uploads->isEmpty()) {
                break;
            }

            $lastId = (int) $uploads->last()->id;
            $deleted += $this->deleteUploads($uploads);
        } while ($uploads->count() === $batch);

        return $deleted;
    }

    /**
     * Deletes each file and then its row. A file that cannot be deleted keeps its row, so a
     * later prune retries it instead of leaving an untracked file behind.
     *
     * @param  Collection<int, Upload>  $uploads
     */
    private function deleteUploads(Collection $uploads): int
    {
        $deleted = 0;

        foreach ($uploads as $upload) {
            try {
                $disk = Storage::disk($upload->disk);
                $gone = ! $disk->exists($upload->path) || $disk->delete($upload->path);
            } catch (Throwable $exception) {
                report($exception);
                $gone = false;
            }

            if (! $gone) {
                Log::warning('[ratings-and-reviews] Could not delete an upload file; the row is kept for a retry.', ['upload_id' => $upload->id, 'disk' => $upload->disk, 'path' => $upload->path]);

                continue;
            }

            $upload->delete();
            $deleted++;
        }

        return $deleted;
    }

    private function diskName(): string
    {
        return (string) config('ratings-and-reviews.uploads.disk', 'public');
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            default => 'img',
        };
    }

    /**
     * The bytes to store, with width and height. With `strip_metadata` and GD, JPEG, PNG and
     * WebP are re-encoded so EXIF (location, device) is not published; a JPEG is first turned
     * upright by its EXIF orientation. Without GD the original is stored.
     *
     * @return array{0: string, 1: int, 2: int}
     *
     * @throws ValidationException
     */
    private function processed(UploadedFile $file, string $mime, int $width, int $height): array
    {
        $original = (string) file_get_contents((string) $file->getRealPath());

        if (! config('ratings-and-reviews.uploads.strip_metadata', true) || ! in_array($mime, self::REENCODED, true) || ! extension_loaded('gd')) {
            return [$original, $width, $height];
        }

        if (! $this->fitsInMemory($width, $height)) {
            throw ValidationException::withMessages(['image' => __('ratings-and-reviews::messages.upload_too_large_to_process')]);
        }

        $image = @imagecreatefromstring($original);

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages(['image' => __('ratings-and-reviews::messages.upload_unreadable')]);
        }

        if ($mime === 'image/jpeg') {
            $image = $this->upright($image, (string) $file->getRealPath());
        }

        ob_start();

        try {
            $written = match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 88),
                'image/png' => (imagesavealpha($image, true) && imagepng($image, null, 6)),
                default => (imagesavealpha($image, true) && imagewebp($image, null, 85)),
            };
        } finally {
            $bytes = (string) ob_get_clean();
        }

        if (! $written || $bytes === '') {
            throw ValidationException::withMessages(['image' => __('ratings-and-reviews::messages.upload_failed')]);
        }

        return [$bytes, imagesx($image), imagesy($image)];
    }

    /** Rough GD need (4 bytes a pixel, twice for a rotation) against the free memory. */
    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $bytes = (int) $limit;
        $unit = strtolower(substr($limit, -1));
        $bytes *= match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return ($width * $height * 4 * 2.2) < ($bytes - memory_get_usage(true)) * 0.9;
    }

    private function upright(GdImage $image, string $path): GdImage
    {
        $orientation = 1;

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        }

        $rotate = fn (GdImage $source, int $angle): GdImage => ($rotated = imagerotate($source, $angle, 0)) instanceof GdImage ? $rotated : $source;

        // GD rotates anticlockwise: 270 is a quarter turn clockwise.
        $result = match ($orientation) {
            3 => $rotate($image, 180),
            6, 5 => $rotate($image, 270),
            8, 7 => $rotate($image, 90),
            default => $image,
        };

        match ($orientation) {
            2, 5, 7 => imageflip($result, IMG_FLIP_HORIZONTAL),
            4 => imageflip($result, IMG_FLIP_VERTICAL),
            default => null,
        };

        return $result;
    }
}
