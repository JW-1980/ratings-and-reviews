<?php

namespace RatingsAndReviews\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RatingsAndReviews\Contracts\VoterIdentity;

/**
 * The viewer's voter key per votable item (VoterIdentity::voterKey with the item), asked
 * once per item and request. Used to show the viewer's own votes on a page; a host can give
 * one viewer a different voice per item.
 */
final class VoterKeys
{
    /** @var array<string, string|null> */
    private array $keys = [];

    public function __construct(
        private readonly VoterIdentity $identity,
        private readonly Request $request,
        private readonly ?Authenticatable $user,
    ) {}

    public function for(Model $votable): ?string
    {
        $cacheKey = $votable->getMorphClass().':'.$votable->getKey();

        if (! array_key_exists($cacheKey, $this->keys)) {
            $this->keys[$cacheKey] = $this->identity->voterKey($this->request, $this->user, $votable);
        }

        return $this->keys[$cacheKey];
    }

    /**
     * The items grouped by the viewer's key for them; items without a key are left out.
     *
     * @template TModel of Model
     *
     * @param  iterable<TModel>  $items
     * @return array<string, list<TModel>>
     */
    public function group(iterable $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $key = $this->for($item);

            if ($key !== null) {
                $groups[$key][] = $item;
            }
        }

        return $groups;
    }
}
