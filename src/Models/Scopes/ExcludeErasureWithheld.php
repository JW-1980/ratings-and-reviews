<?php

namespace RatingsAndReviews\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Leaves out rows withheld for a pending erasure request (`erasure_withheld_at` set). On
 * Review and TopicRating; Services\Erasure (and anything that must see them) uses
 * `withoutGlobalScope(ExcludeErasureWithheld::class)` or the model's withErasureWithheld().
 */
class ExcludeErasureWithheld implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn('erasure_withheld_at'));
    }
}
