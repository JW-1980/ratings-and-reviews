<?php

namespace RatingsAndReviews\Support;

/**
 * The outcome of a vote toggle: the viewer's current value on the dimension (null when
 * withdrawn), whether it counts, and the fresh counters of the item.
 *
 * `counts` holds the raw counter columns (`agree_count`, …) as VoteService::recount()
 * returns them; `countsByDimension` the same numbers per dimension as `{up, down}`, the
 * shape CommentResource uses for `counts` (a dimension without a negative has `down` 0).
 */
final readonly class VoteResult
{
    /**
     * @param  array<string, int>  $counts  counter column => value
     * @param  array<string, array{up: int, down: int}>  $countsByDimension  dimension => {up, down}
     */
    public function __construct(
        public VoteDimension $dimension,
        public ?int $value,
        public bool $counted,
        public ?string $uncountedReason,
        public array $counts,
        public array $countsByDimension = [],
    ) {}

    /**
     * The counters per dimension as `{up, down}`, from the item's counter columns.
     *
     * @param  array<string, array<int, string>>  $columns  Votable::voteCounterColumns()
     * @param  array<string, int>  $counters  counter column => value
     * @return array<string, array{up: int, down: int}>
     */
    public static function byDimension(array $columns, array $counters): array
    {
        $tallies = [];

        foreach ($columns as $dimension => $values) {
            $tallies[$dimension] = [
                'up' => (int) ($counters[$values[1] ?? ''] ?? 0),
                'down' => (int) ($counters[$values[-1] ?? ''] ?? 0),
            ];
        }

        return $tallies;
    }

    /**
     * The voted dimension's `{up, down}`.
     *
     * @return array{up: int, down: int}
     */
    public function count(): array
    {
        return $this->countsByDimension[$this->dimension->value] ?? ['up' => 0, 'down' => 0];
    }

    /**
     * The JSON body of `POST reviews/{id}/votes` and `POST comments/{id}/votes` (`data`).
     *
     * @return array{dimension: string, value: int|null, counted: bool, count: array{up: int, down: int}, counts_by_dimension: array<string, array{up: int, down: int}>|object, counts: array<string, int>|object}
     */
    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension->value,
            'value' => $this->value,
            'counted' => $this->counted,
            'count' => $this->count(),
            'counts_by_dimension' => (object) $this->countsByDimension,
            'counts' => (object) $this->counts,
        ];
    }
}
