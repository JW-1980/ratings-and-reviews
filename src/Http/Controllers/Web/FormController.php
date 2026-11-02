<?php

namespace RatingsAndReviews\Http\Controllers\Web;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RatingsAndReviews\Exceptions\ToneNudgeRequired;
use RatingsAndReviews\Http\Controllers\CommentController;
use RatingsAndReviews\Http\Controllers\CommentVoteController;
use RatingsAndReviews\Http\Controllers\Controller;
use RatingsAndReviews\Http\Controllers\PollController;
use RatingsAndReviews\Http\Controllers\ReportController;
use RatingsAndReviews\Http\Controllers\ReviewController;
use RatingsAndReviews\Http\Controllers\ReviewVoteController;
use RatingsAndReviews\Http\Requests\StoreUploadRequest;
use RatingsAndReviews\Services\Uploads;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The no-JavaScript side of the package: plain HTML forms post here (routes/web.php) and are
 * answered with a redirect back to the page they came from.
 *
 * Each action runs the JSON API controller in-process, so permissions, validation, spam
 * checks and events are exactly those of the API. On success `rr_status` is flashed (the
 * API's message) and the redirect jumps to the item (`#rr-review-…`, `#rr-comment-…`).
 * Validation errors are flashed in a named error bag per form (`rr_review`, `rr_comment`,
 * `rr_report`, `rr_vote`) with the old input, and `rr_form` names the form they belong to, so several
 * components on one page do not show each other's errors. The rephrase nudge flashes
 * `rr_tone` (the form then offers "post anyway"). A 401, 403, 404 or 429 (upload quota) flashes `rr_error`; the throttle middleware itself still answers 429.
 *
 * Review photos without JavaScript: `photo_files[]` (new images, stored like POST uploads)
 * plus `photos[]` (ids of current photos to keep, with `photos_present=1`).
 */
class FormController extends Controller
{
    public function storeReview(Request $request, string $type, string $id): RedirectResponse
    {
        return $this->respond($request, 'rr_review', 'review', function () use ($request, $type, $id): Response {
            $this->mergePhotos($request);

            return app()->call([app(ReviewController::class), 'store'], ['type' => $type, 'id' => $id]);
        }, fn (array $data): string => 'rr-review-'.($data['data']['id'] ?? ''));
    }

    public function updateReview(Request $request, string $review): RedirectResponse
    {
        return $this->respond($request, 'rr_review', 'review', function () use ($request, $review): Response {
            $this->mergePhotos($request);

            return app()->call([app(ReviewController::class), 'update'], ['review' => $review]);
        }, fn (): string => 'rr-review-'.$review);
    }

    public function destroyReview(Request $request, string $review): RedirectResponse
    {
        return $this->respond($request, 'rr_review', 'review', fn (): Response => app()->call([app(ReviewController::class), 'destroy'], ['review' => $review]),
            null, (string) __('ratings-and-reviews::messages.review_deleted'));
    }

    public function voteReview(Request $request, string $review): RedirectResponse
    {
        return $this->respond($request, 'rr_vote', 'vote', fn (): Response => app()->call([app(ReviewVoteController::class), 'store'], ['review' => $review]),
            fn (): string => 'rr-review-'.$review, (string) __('ratings-and-reviews::messages.vote_saved'));
    }

    public function storeComment(Request $request, string $type, string $id): RedirectResponse
    {
        $form = 'comment:'.($request->input('parent_id') ?: 'root');

        return $this->respond($request, 'rr_comment', $form, fn (): Response => app()->call([app(CommentController::class), 'store'], ['type' => $type, 'id' => $id]),
            fn (array $data): string => 'rr-comment-'.($data['data']['id'] ?? ''));
    }

    public function updateComment(Request $request, string $comment): RedirectResponse
    {
        return $this->respond($request, 'rr_comment', 'edit:'.$comment, fn (): Response => app()->call([app(CommentController::class), 'update'], ['comment' => $comment]),
            fn (): string => 'rr-comment-'.$comment);
    }

    public function destroyComment(Request $request, string $comment): RedirectResponse
    {
        return $this->respond($request, 'rr_comment', 'delete:'.$comment, fn (): Response => app()->call([app(CommentController::class), 'destroy'], ['comment' => $comment]),
            null, (string) __('ratings-and-reviews::messages.comment_deleted'));
    }

    public function undoComment(Request $request, string $comment): RedirectResponse
    {
        return $this->respond($request, 'rr_comment', 'undo:'.$comment, fn (): Response => app()->call([app(CommentController::class), 'undo'], ['comment' => $comment]),
            null, (string) __('ratings-and-reviews::messages.comment_undone'));
    }

    public function voteComment(Request $request, string $comment): RedirectResponse
    {
        return $this->respond($request, 'rr_vote', 'vote', fn (): Response => app()->call([app(CommentVoteController::class), 'store'], ['comment' => $comment]),
            fn (): string => 'rr-comment-'.$comment, (string) __('ratings-and-reviews::messages.vote_saved'));
    }

    public function votePoll(Request $request, string $poll): RedirectResponse
    {
        return $this->respond($request, 'rr_vote', 'poll:'.$poll, fn (): Response => app()->call([app(PollController::class), 'vote'], ['poll' => $poll]),
            fn (): string => 'rr-poll-'.$poll, (string) __('ratings-and-reviews::messages.vote_saved'));
    }

    public function storeReport(Request $request): RedirectResponse
    {
        $target = (string) $request->input('reportable_type').':'.(string) $request->input('reportable_id');

        return $this->respond($request, 'rr_report', 'report:'.$target, fn (): Response => app()->call([app(ReportController::class), 'store']));
    }

    /**
     * Runs an API action and turns its outcome into a redirect back.
     *
     * @param  Closure(): Response  $action
     * @param  (Closure(array<string, mixed>): string)|null  $anchor  fragment to jump to after success
     */
    private function respond(Request $request, string $bag, string $form, Closure $action, ?Closure $anchor = null, ?string $message = null): RedirectResponse
    {
        $back = $this->previousUrl($request);

        try {
            $response = $action();
        } catch (ToneNudgeRequired $nudge) {
            $request->session()->flash('rr_form', $form);
            $request->session()->flash('rr_tone', $nudge->suggestion->message());

            return redirect()->to($back)->withInput($request->except(['photo_files']))->withErrors(['body' => $nudge->suggestion->message()], $bag);
        } catch (ValidationException $exception) {
            $request->session()->flash('rr_form', $form);

            return redirect()->to($back)->withInput($request->except(['photo_files']))->withErrors($exception->errors(), $bag);
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [401, 403, 404, 429], true)) {
                throw $exception;
            }

            $request->session()->flash('rr_form', $form);

            return redirect()->to($back)->with('rr_error', $exception->getMessage() !== '' ? $exception->getMessage() : (string) __('ratings-and-reviews::messages.forbidden'));
        }

        $data = $response instanceof JsonResponse ? (array) $response->getData(true) : [];
        $status = $message ?? (isset($data['message']) && is_string($data['message']) ? $data['message'] : null);
        $fragment = $anchor === null ? '' : $anchor($data);
        $url = $fragment === '' ? $back : preg_replace('/#.*$/', '', $back).'#'.$fragment;

        $redirect = redirect()->to($url);

        return $status === null ? $redirect : $redirect->with('rr_status', $status);
    }

    /**
     * Stores `photo_files[]` as uploads of the author and puts their ids, after the kept
     * `photos[]`, into `photos`. Without `photos_present` and new files, `photos` is left out,
     * so an edit keeps the current photos.
     *
     * @throws ValidationException
     */
    private function mergePhotos(Request $request): void
    {
        $files = array_values(array_filter((array) $request->file('photo_files', [])));
        $kept = array_values(array_filter((array) $request->input('photos', []), fn (mixed $id): bool => is_scalar($id) && ctype_digit((string) $id)));

        if ($files === [] && ! $request->boolean('photos_present')) {
            return;
        }

        $user = $this->requireUser($request);
        $max = max(0, (int) config('ratings-and-reviews.reviews.max_photos', 4));

        if (count($kept) + count($files) > $max) {
            throw ValidationException::withMessages(['photos' => trans_choice('ratings-and-reviews::messages.photos_too_many', $max, ['max' => $max])]);
        }

        $rules = StoreUploadRequest::imageRules('photo', true);
        $uploads = app(Uploads::class);

        foreach ($files as $file) {
            $validator = Validator::make(['photo' => $file], $rules, (array) trans('ratings-and-reviews::validation.messages'), (array) trans('ratings-and-reviews::validation.attributes'));

            if ($validator->fails()) {
                throw ValidationException::withMessages(['photos' => $validator->errors()->first('photo')]);
            }

            $kept[] = (string) $uploads->store($file, $user)->id;
        }

        $request->merge(['photos' => array_map('intval', $kept)]);
    }

    /** The page the form was posted from (falls back to the site root), without its fragment. */
    private function previousUrl(Request $request): string
    {
        $previous = url()->previous();

        return $previous !== '' ? (string) preg_replace('/#.*$/', '', $previous) : url('/');
    }
}
