<?php

namespace RatingsAndReviews\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RatingsAndReviews\Contracts\AuthorResolver;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\Review;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The profile hovercard: what the AuthorResolver shows of an author (name, avatar, profile
 * URL and the host's `extra`, such as badges or "member since") plus the number of their
 * public reviews and comments in the package tables. Nothing private: no e-mail, no
 * pending or hidden content. A user without any public contribution has no card (404), so
 * the endpoint cannot be used to probe accounts.
 */
class AuthorCardController extends Controller
{
    public function __construct(private readonly AuthorResolver $authors) {}

    public function show(string $id): JsonResponse
    {
        if (! ctype_digit($id) || (int) $id < 1) {
            throw new NotFoundHttpException;
        }

        $userId = (int) $id;
        $reviews = Review::query()->approved()->where('user_id', $userId)->count();
        $comments = Comment::query()->published()->where('user_id', $userId)->whereNull('author_removed_at')->count();
        $profile = $this->authors->resolve([$userId])[$userId] ?? null;

        if ($profile === null || $reviews + $comments === 0) {
            throw new NotFoundHttpException;
        }

        return new JsonResponse(['data' => $profile->toArray() + ['counts' => ['reviews' => $reviews, 'comments' => $comments]]]);
    }
}
