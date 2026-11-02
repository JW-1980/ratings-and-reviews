<?php

namespace RatingsAndReviews\Http\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\AbilityResolver;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * The current user through the configured guard, and the 401/403 the API answers with.
 */
trait ResolvesCurrentUser
{
    protected function currentUser(Request $request): ?Authenticatable
    {
        $user = $request->user(config('ratings-and-reviews.routes.guard'));

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @throws UnauthorizedHttpException
     */
    protected function requireUser(Request $request): Authenticatable
    {
        return $this->currentUser($request)
            ?? throw new UnauthorizedHttpException('', (string) __('ratings-and-reviews::messages.login_required'));
    }

    /**
     * @throws UnauthorizedHttpException|AccessDeniedHttpException
     */
    protected function requireAbility(Request $request, string $ability, mixed $subject = null): Authenticatable
    {
        $user = $this->requireUser($request);

        if (! app(AbilityResolver::class)->allows($user, $ability, $subject)) {
            throw new AccessDeniedHttpException((string) __('ratings-and-reviews::messages.forbidden'));
        }

        return $user;
    }

    protected function userId(?Authenticatable $user): ?int
    {
        return $user === null ? null : (int) $user->getAuthIdentifier();
    }
}
