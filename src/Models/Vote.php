<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RatingsAndReviews\Support\VoteDimension;

/**
 * One vote on one dimension of a review or comment. Never refused: an untrusted vote is
 * stored with `counted = false` and its reason.
 *
 * @property int $id
 * @property string $votable_type
 * @property int $votable_id
 * @property VoteDimension $dimension
 * @property int $value
 * @property int|null $user_id
 * @property string $voter_key
 * @property string|null $network_hash
 * @property string|null $device_hash
 * @property bool $counted
 * @property string|null $uncounted_reason
 */
class Vote extends Model
{
    protected $table = 'rr_votes';

    protected $fillable = [
        'votable_type', 'votable_id', 'dimension', 'value', 'user_id', 'voter_key', 'network_hash', 'device_hash',
        'counted', 'uncounted_reason',
    ];

    protected $hidden = ['voter_key', 'network_hash', 'device_hash'];

    protected function casts(): array
    {
        return [
            'votable_id' => 'integer',
            'dimension' => VoteDimension::class,
            'value' => 'integer',
            'user_id' => 'integer',
            'counted' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function votable(): MorphTo
    {
        return $this->morphTo();
    }
}
