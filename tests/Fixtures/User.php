<?php

namespace RatingsAndReviews\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_moderator
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_moderator' => 'boolean'];
    }
}
