<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RatingsAndReviews\Contracts\Commentable;
use RatingsAndReviews\Contracts\Reviewable;
use RatingsAndReviews\Http\Requests\StoreUploadRequest;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use RatingsAndReviews\Models\Upload;
use RatingsAndReviews\Services\Participation;
use RatingsAndReviews\Services\Uploads;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST uploads stores an image for the editor or as a review photo; GET media/{path} serves
 * a private upload (uploads.visibility = private).
 */
class UploadController extends Controller
{
    public function __construct(private readonly Uploads $uploads) {}

    public function store(StoreUploadRequest $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $alt = $request->validated('alt');
        $upload = $this->uploads->store($request->file('image'), $user, is_string($alt) ? $alt : null);

        return new JsonResponse(['data' => self::present($upload)], 201);
    }

    /**
     * A private upload: always to its uploader and moderators, to others only while the review
     * or comment it belongs to is public and visible to them. Anything else is a 404, so the
     * route does not reveal which files exist.
     */
    public function show(Request $request, string $path): Response
    {
        if (! Upload::servedPrivately()) {
            throw new NotFoundHttpException;
        }

        $upload = Upload::query()->where('path', $path)->first();
        $viewer = $this->currentUser($request);

        if ($upload === null || ! $this->mayView($upload, $viewer)) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk($upload->disk);

        if (! $disk->exists($upload->path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response($upload->path, null, [
            'Content-Type' => $upload->mime_type ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /**
     * @return array{id: int, url: string, alt: string|null, width: int|null, height: int|null}
     */
    public static function present(Upload $upload): array
    {
        return ['id' => $upload->id, 'url' => $upload->url(), 'alt' => $upload->alt_text, 'width' => $upload->width, 'height' => $upload->height];
    }

    private function mayView(Upload $upload, ?Authenticatable $viewer): bool
    {
        $content = $upload->attached_id === null ? null : $upload->attached;

        if ($viewer !== null && ($this->userId($viewer) === $upload->user_id || $this->moderates($viewer, $content ?? $upload))) {
            return true;
        }

        // Content hidden for this viewer by a member block also hides its private media.
        $subject = $content instanceof Review ? $content->reviewable : ($content instanceof Comment ? $content->commentable : null);

        if ($viewer !== null && $content !== null && $subject instanceof Model
            && in_array((int) $content->user_id, app(Participation::class)->hiddenAuthorIds($subject, $this->userId($viewer)), true)) {
            return false;
        }

        return match (true) {
            $content instanceof Review => $content->isPubliclyVisible()
                && $content->reviewable instanceof Reviewable && $content->reviewable->reviewsVisibleTo($viewer),
            $content instanceof Comment => $content->isPubliclyVisible() && ! $content->isRemovedByAuthor()
                && $content->commentable instanceof Commentable && $content->commentable->commentsVisibleTo($viewer),
            default => false,
        };
    }
}
