<?php

namespace RatingsAndReviews\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\ReadMarker;
use RatingsAndReviews\Support\SqlDialect;

/**
 * "New since your last visit": when a member last read a discussion. touch() only moves
 * the marker forward (GREATEST), so two open tabs cannot move it back.
 */
class ReadMarkers
{
    public function lastReadAt(int $userId, Model $subject): ?Carbon
    {
        $value = ReadMarker::query()
            ->where('user_id', $userId)
            ->where('commentable_type', $subject->getMorphClass())
            ->where('commentable_id', $subject->getKey())
            ->value('last_read_at');

        return $value === null ? null : Carbon::parse($value);
    }

    public function touch(int $userId, Model $subject, ?DateTimeInterface $at = null): void
    {
        $at = Carbon::instance($at ?? now())->format('Y-m-d H:i:s');
        $table = (new ReadMarker)->getTable();

        if (! SqlDialect::isMysql()) {
            // SQLite and PostgreSQL: the same "only forward" upsert through ON CONFLICT … excluded.
            DB::table($table)->upsert(
                [['user_id' => $userId, 'commentable_type' => $subject->getMorphClass(), 'commentable_id' => $subject->getKey(), 'last_read_at' => $at, 'created_at' => $at, 'updated_at' => $at]],
                ['user_id', 'commentable_type', 'commentable_id'],
                [
                    'last_read_at' => DB::raw(SqlDialect::greatest($table.'.last_read_at', 'excluded.last_read_at')),
                    'updated_at' => DB::raw('excluded.updated_at'),
                ],
            );

            return;
        }

        // MySQL 8.4 refers to the new row through a row alias (VALUES() is deprecated there);
        // MariaDB has no row alias and uses VALUES(column).
        [$alias, $incomingReadAt, $incomingUpdatedAt] = SqlDialect::isMariaDb()
            ? ['', 'VALUES(`last_read_at`)', 'VALUES(`updated_at`)']
            : [' AS `incoming`', '`incoming`.`last_read_at`', '`incoming`.`updated_at`'];

        DB::statement(
            "INSERT INTO `{$table}` (`user_id`, `commentable_type`, `commentable_id`, `last_read_at`, `created_at`, `updated_at`) "
            ."VALUES (?, ?, ?, ?, ?, ?){$alias} "
            ."ON DUPLICATE KEY UPDATE `last_read_at` = GREATEST(`{$table}`.`last_read_at`, {$incomingReadAt}), `updated_at` = {$incomingUpdatedAt}",
            [$userId, $subject->getMorphClass(), $subject->getKey(), $at, $at, $at],
        );
    }

    /**
     * The public comments by others published after the marker: how many, and the first.
     *
     * @return array{count: int, first_id: int|null, first_root_id: int|null}
     */
    public function newSince(Model $subject, ?Carbon $since, ?int $viewerId): array
    {
        if ($since === null) {
            return ['count' => 0, 'first_id' => null, 'first_root_id' => null];
        }

        $query = Comment::query()->forSubject($subject)->published()
            ->where('published_at', '>', $since)
            ->withContent()
            ->when($viewerId !== null, fn ($query) => $query->where('user_id', '!=', $viewerId));

        $count = (clone $query)->count();
        $first = $count === 0 ? null : $query->orderBy('published_at')->orderBy('id')->first(['id', 'root_id']);

        return ['count' => $count, 'first_id' => $first?->id, 'first_root_id' => $first === null ? null : ($first->root_id ?? $first->id)];
    }
}
