<?php

namespace RatingsAndReviews\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RatingsAndReviews\Models\Comment;
use RatingsAndReviews\Models\ReadMarker;

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

        DB::statement(
            "INSERT INTO `{$table}` (`user_id`, `commentable_type`, `commentable_id`, `last_read_at`, `created_at`, `updated_at`) "
            .'VALUES (?, ?, ?, ?, ?, ?) AS `incoming` '
            ."ON DUPLICATE KEY UPDATE `last_read_at` = GREATEST(`{$table}`.`last_read_at`, `incoming`.`last_read_at`), `updated_at` = `incoming`.`updated_at`",
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
            ->whereNull('author_removed_at')
            ->when($viewerId !== null, fn ($query) => $query->where('user_id', '!=', $viewerId));

        $count = (clone $query)->count();
        $first = $count === 0 ? null : $query->orderBy('published_at')->orderBy('id')->first(['id', 'root_id']);

        return ['count' => $count, 'first_id' => $first?->id, 'first_root_id' => $first === null ? null : ($first->root_id ?? $first->id)];
    }
}
