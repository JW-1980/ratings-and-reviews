<?php

namespace RatingsAndReviews\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A host model that is in the morph map but implements neither contract.
 */
class Article extends Model
{
    protected $table = 'products';

    protected $guarded = [];
}
