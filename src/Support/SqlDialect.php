<?php

namespace RatingsAndReviews\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The few SQL fragments that differ between database engines.
 *
 * MySQL 8.4 is the package's target and test engine. Hosts whose own test suites run on
 * SQLite (or PostgreSQL) still migrate and run the basic flows: every fragment here has a
 * portable equivalent for those engines, and the MySQL-only features (FULLTEXT search, the
 * generated Wilson column) are guarded or replaced. Only the MySQL behaviour is tested.
 */
final class SqlDialect
{
    public static function driver(?ConnectionInterface $connection = null): string
    {
        $connection ??= DB::connection();

        return method_exists($connection, 'getDriverName') ? (string) $connection->getDriverName() : 'mysql';
    }

    public static function driverFor(Model $model): string
    {
        return self::driver($model->getConnection());
    }

    /** MySQL or MariaDB: FULLTEXT indexes, MATCH … AGAINST, generated columns with SQRT. */
    public static function isMysql(?ConnectionInterface $connection = null): bool
    {
        return in_array(self::driver($connection), ['mysql', 'mariadb'], true);
    }

    public static function isSqlite(?ConnectionInterface $connection = null): bool
    {
        return self::driver($connection) === 'sqlite';
    }

    /** The larger of two SQL expressions: GREATEST(a, b), or the two-argument MAX(a, b) on SQLite. */
    public static function greatest(string $a, string $b, ?ConnectionInterface $connection = null): string
    {
        return (self::isSqlite($connection) ? 'MAX(' : 'GREATEST(').$a.', '.$b.')';
    }

    /**
     * The text value of a top-level key of a JSON column (null when missing), with the key
     * as one binding (jsonTextBinding()): JSON_UNQUOTE(JSON_EXTRACT(column, ?)) on MySQL,
     * json_extract(column, ?) on SQLite (which returns strings unquoted already) and
     * column::jsonb ->> ? on PostgreSQL.
     */
    public static function jsonText(string $column, ?ConnectionInterface $connection = null): string
    {
        return match (self::driver($connection)) {
            'sqlite' => 'json_extract('.$column.', ?)',
            'pgsql' => '('.$column.'::jsonb ->> ?)',
            default => 'JSON_UNQUOTE(JSON_EXTRACT('.$column.', ?))',
        };
    }

    /** The binding for jsonText(): a JSON path on MySQL and SQLite, the plain key on PostgreSQL. */
    public static function jsonTextBinding(string $key, ?ConnectionInterface $connection = null): string
    {
        return self::driver($connection) === 'pgsql' ? $key : '$."'.$key.'"';
    }

    /** "YYYY-MM" of a timestamp column. */
    public static function yearMonth(string $column, ?ConnectionInterface $connection = null): string
    {
        return match (self::driver($connection)) {
            'sqlite' => "strftime('%Y-%m', ".$column.')',
            'pgsql' => 'to_char('.$column.", 'YYYY-MM')",
            default => 'DATE_FORMAT('.$column.", '%Y-%m')",
        };
    }
}
