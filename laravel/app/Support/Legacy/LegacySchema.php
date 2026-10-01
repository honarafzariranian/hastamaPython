<?php

namespace App\Support\Legacy;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The declared SQL type of each column, keyed by table.
 *
 * This exists because Laravel's SQL Server connection does **not** cast query
 * results: every scalar arrives as a `string`.  A probe of the live database
 * confirmed it —
 *
 *     audit_logs.created_at   string   2026-09-30 08:53:41.864
 *     user_sessions.is_active string   0
 *     user_table.id           string   1
 *
 * — where `pyodbc` (what the running application uses) returns a `datetime`, a
 * `bool` and an `int` respectively.  Serialising those strings as-is would put
 * `"id": "319"` and `"is_active": "0"` on the wire where the existing front-end
 * receives `319` and `false`.  The Vue client compares and sorts on those values,
 * so the difference is not cosmetic.
 *
 * The lookup goes through Laravel's schema builder rather than a hard-coded map so
 * it cannot drift from the database: `Schema::getColumns()` reads
 * `INFORMATION_SCHEMA`, so a column added or retyped by the operator is picked up
 * on the next request.  The answer is cached per process because these tables do
 * not change shape at runtime, and every read endpoint needs the same few tables.
 */
final class LegacySchema
{
    /**
     * Declared types, keyed by `table => [column => type_name]`.
     *
     * @var array<string, array<string, string>>
     */
    private static array $types = [];

    /**
     * Column name → `type_name` for one table.
     *
     * `type_name` is the bare SQL Server type (`nvarchar`, `bit`, `datetime2`),
     * without the length or precision suffix.  That is exactly the granularity the
     * serializer needs; the length is never consulted, because the driver already
     * applied it.
     *
     * A table that cannot be read yields an empty map, which makes the serializer
     * fall back to "leave the value as the driver returned it".  That is the right
     * failure direction: a missing type map must not turn a working endpoint into a
     * 500, and an uncoerced value still carries the correct data.
     *
     * @return array<string, string>
     */
    public static function types(string $table): array
    {
        if (isset(self::$types[$table])) {
            return self::$types[$table];
        }

        return self::$types[$table] = self::read($table);
    }

    /** Forget the cached maps.  Used by tests that swap the connection. */
    public static function flush(): void
    {
        self::$types = [];
    }

    /**
     * @return array<string, string>
     */
    private static function read(string $table): array
    {
        try {
            $columns = Schema::getColumns(self::qualified($table));
        } catch (Throwable) {
            return [];
        }

        $types = [];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');
            $type = strtolower((string) ($column['type_name'] ?? ''));

            if ($name !== '' && $type !== '') {
                $types[$name] = $type;
            }
        }

        return $types;
    }

    /**
     * Qualify a bare table name with the schema the legacy database uses.
     *
     * `dbo` is also Laravel's default for this connection, but stating it keeps the
     * lookup identical to the SQL the endpoints issue, which matters for the one
     * table the Python code always qualified (`dbo.customer_subscriptions`).
     */
    private static function qualified(string $table): string
    {
        return str_contains($table, '.') ? $table : 'dbo.'.$table;
    }
}
