<?php

namespace RatingsAndReviews\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turns a `{type}/{id}` pair from a URL into a host model. `type` must be an alias in the
 * morph map whose model implements the required contract; anything else is a 404, so a
 * URL can never name an arbitrary class.
 */
class SubjectResolver
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return Model&T
     *
     * @throws NotFoundHttpException
     */
    public function resolve(string $type, string $id, string $contract): Model
    {
        $class = Relation::getMorphedModel($type);

        if ($class === null || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, $contract)) {
            throw new NotFoundHttpException;
        }

        $subject = $class::query()->find($id);

        if (! $subject instanceof $contract) {
            throw new NotFoundHttpException;
        }

        return $subject;
    }

    /**
     * Like resolve(), for a subject that implements at least one of the contracts.
     *
     * @param  list<class-string>  $contracts
     *
     * @throws NotFoundHttpException
     */
    public function resolveAny(string $type, string $id, array $contracts): Model
    {
        $class = Relation::getMorphedModel($type);
        $fits = $class !== null && is_subclass_of($class, Model::class)
            && array_filter($contracts, fn (string $contract): bool => is_subclass_of($class, $contract)) !== [];

        $subject = $fits ? $class::query()->find($id) : null;

        if (! $subject instanceof Model) {
            throw new NotFoundHttpException;
        }

        return $subject;
    }

    /**
     * The model class of a morph alias implementing the contract, or null.
     *
     * @return class-string<Model>|null
     */
    public function classFor(string $type, string $contract): ?string
    {
        $class = Relation::getMorphedModel($type);

        return $class !== null && is_subclass_of($class, Model::class) && is_subclass_of($class, $contract) ? $class : null;
    }
}
