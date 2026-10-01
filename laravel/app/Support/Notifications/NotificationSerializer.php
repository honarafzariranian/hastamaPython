<?php

namespace App\Support\Notifications;

use App\Support\Legacy\LegacySerializer;

/**
 * Row serialisation for the notification module — the module's own `_serialize()`.
 *
 * Every handler in `app/api/routes/notifications.py` serialises through the same four
 * lines:
 *
 *     def _serialize(row: dict) -> dict:
 *         for key in ("created_at", "updated_at", "published_at", "scheduled_at",
 *                     "archived_at", "delivered_at", "read_at"):
 *             if key in row:
 *                 row[key] = _iso(row[key])
 *         return row
 *
 * `_iso()` stamps a naive datetime as UTC and rewrites the offset as `Z`, so those
 * seven keys are the one place the module's timestamps differ from the rest of the
 * application's (`LegacySerializer::dateTime()` leaves a naive value without a suffix).
 * Everything else passes through untouched — which, against `pdo_sqlsrv`, means the
 * coercion has to be driven by the key rather than by the runtime type, exactly as
 * `LegacySchema` does it for the other modules.
 *
 * One column escapes the seven: `admin_read_at` is **not** in the key list, so the
 * Python left it a `datetime` and FastAPI's `jsonable_encoder` rendered it with a
 * plain `isoformat()` — `2026-09-30T08:53:41.864000`, no `Z`.  It is serialised here
 * through `LegacySerializer::value('datetime2', …)`, which is that exact format.
 */
final class NotificationSerializer
{
    /** The keys `_serialize()` converts through `_iso()` — the `Z`-suffixed form. */
    private const ISO_KEYS = [
        'created_at',
        'updated_at',
        'published_at',
        'scheduled_at',
        'archived_at',
        'delivered_at',
        'read_at',
    ];

    /**
     * Serialise one row.
     *
     * @param  object|array<string, mixed>|null  $row
     * @return array<string, mixed>|null
     */
    public static function row(object|array|null $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $values = is_array($row) ? $row : (array) $row;
        $serialised = [];

        foreach ($values as $key => $value) {
            $serialised[$key] = match (true) {
                // `pyodbc` answered an `int` for the `BIGINT` primary key;
                // `pdo_sqlsrv` answers a string, and the front-end compares it.
                $key === 'id' => $value === null ? null : (int) $value,
                $key === 'admin_read_at' => LegacySerializer::value('datetime2', $value),
                in_array($key, self::ISO_KEYS, true) => LegacySerializer::isoUtcZ($value),
                default => $value,
            };
        }

        return $serialised;
    }

    /**
     * Serialise a list of rows.
     *
     * @param  iterable<int, object|array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function rows(iterable $rows): array
    {
        $serialised = [];

        foreach ($rows as $row) {
            $serialised[] = self::row($row);
        }

        return $serialised;
    }

    /**
     * The admin list's `stats` block.
     *
     * `COUNT(*)` and the four `SUM(CASE…)` expressions came back from `pyodbc` as
     * `int` — and as `None` for a `SUM` over an empty table, which is why the cast
     * preserves NULL rather than collapsing it to `0`.
     *
     * @param  object|array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function stats(object|array $row): array
    {
        $values = is_array($row) ? $row : (array) $row;
        $stats = [];

        foreach ($values as $key => $value) {
            $stats[$key] = $value === null ? null : (int) $value;
        }

        return $stats;
    }
}
