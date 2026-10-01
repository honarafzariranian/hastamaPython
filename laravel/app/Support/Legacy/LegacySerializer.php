<?php

namespace App\Support\Legacy;

/**
 * Row serialisation, matching `_serialize()` in `app/api/routes/master_admin.py`.
 *
 * The legacy function is short and its behaviour is the API contract:
 *
 *     def _serialize(row: dict) -> dict:
 *         for key in row:
 *             if isinstance(row[key], datetime):  row[key] = row[key].isoformat()
 *             elif isinstance(row[key], date):    row[key] = row[key].isoformat()
 *             elif isinstance(row[key], Decimal): row[key] = float(row[key])
 *             elif isinstance(row[key], bytes):   row[key] = row[key].hex() if row[key] else None
 *         return row
 *
 * Porting it is not a matter of copying four lines, because the types `pyodbc`
 * returns and the types `pdo_sqlsrv` returns are not the same.  `pyodbc` gives a
 * `datetime`, a `bool`, an `int`, a `Decimal` and `bytes`; `pdo_sqlsrv` gives a
 * string for **all** of them.  So the coercion has to be driven by the column's
 * declared SQL type ({@see LegacySchema}) instead of by the runtime type, and the
 * specific string formatting has to reproduce `isoformat()` — including its
 * "omit the fraction when the microsecond is zero, otherwise emit exactly six
 * digits" rule, which is what turns
 * `2026-09-30 08:53:41.864` into `2026-09-30T08:53:41.864000`.
 *
 * What deliberately does **not** happen here:
 *
 * * **No trimming.**  `user_table.role` really is `'admin     '` in the database
 *   and `pyodbc` really does return the padding, so `_serialize` published it.
 *   The models trim on read for application logic; the API layer must not, or a
 *   client's cached copy and the server's answer would disagree.
 * * **No key reordering or dropping.**  `_dict_rows` zipped the cursor's own
 *   description order into the dict; the response key order is therefore the
 *   SELECT's column order, which this preserves by construction.
 * * **No JSON decoding** of the legacy text columns (`before_data`, `metadata`).
 *   The Python endpoint published them as strings, and the admin UI parses them
 *   itself.
 */
final class LegacySerializer
{
    /** Integer columns. `tinyint` is included: SQL Server has no smaller int. */
    private const INTEGER_TYPES = ['int', 'bigint', 'smallint', 'tinyint'];

    /** Floating-point and exact-numeric columns → PHP float, as `Decimal` → `float`. */
    private const FLOAT_TYPES = ['decimal', 'numeric', 'money', 'smallmoney', 'float', 'real'];

    /** Date/time columns → the `isoformat()` string. */
    private const DATETIME_TYPES = ['datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'];

    /** Binary columns → lowercase hex, or null when empty. */
    private const BINARY_TYPES = ['varbinary', 'binary', 'image', 'timestamp', 'rowversion'];

    /**
     * Serialise a list of rows.
     *
     * Rows may be `stdClass` (what `DB::select()` returns) or arrays.
     *
     * @param  iterable<int, object|array<string, mixed>>  $rows
     * @param  array<string, string>|null  $types  An explicit type map; omitted in
     *                                             production, supplied by tests that
     *                                             must run without a database.
     * @return array<int, array<string, mixed>>
     */
    public static function rows(string $table, iterable $rows, ?array $types = null): array
    {
        $types ??= LegacySchema::types($table);
        $serialised = [];

        foreach ($rows as $row) {
            $serialised[] = self::row($table, $row, $types);
        }

        return $serialised;
    }

    /**
     * Serialise one row.
     *
     * A null row stays null so a caller can distinguish "no such record" without a
     * second query, which is how the detail endpoints were written.
     *
     * @param  object|array<string, mixed>|null  $row
     * @param  array<string, string>|null  $types
     * @return array<string, mixed>|null
     */
    public static function row(string $table, object|array|null $row, ?array $types = null): ?array
    {
        if ($row === null) {
            return null;
        }

        $types ??= LegacySchema::types($table);
        $values = is_array($row) ? $row : (array) $row;

        foreach ($values as $column => $value) {
            $values[$column] = self::value($types[$column] ?? '', $value);
        }

        return $values;
    }

    /**
     * Coerce one value according to its declared SQL type.
     *
     * An unknown type falls through unchanged, which is what an expression alias
     * (a `COUNT(*) AS total`) hits — the endpoints cast those themselves, because
     * `_serialize` never saw an alias either; it saw an `int` from `pyodbc`.
     */
    public static function value(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // SQL Server `bit` is the one case where the JSON type really changes:
        // `pyodbc` yields True/False, `pdo_sqlsrv` yields "0"/"1".
        if ($type === 'bit') {
            return self::toBoolean($value);
        }

        if (in_array($type, self::INTEGER_TYPES, true)) {
            return is_string($value) ? (int) trim($value) : (int) $value;
        }

        if (in_array($type, self::FLOAT_TYPES, true)) {
            return (float) $value;
        }

        if (in_array($type, self::DATETIME_TYPES, true)) {
            return self::dateTime($value);
        }

        if ($type === 'date') {
            return self::date($value);
        }

        if ($type === 'time') {
            return self::time($value);
        }

        if (in_array($type, self::BINARY_TYPES, true)) {
            $hex = bin2hex(self::binary($value));

            // `row[key].hex() if row[key] else None` — empty bytes serialised as null.
            return $hex === '' ? null : $hex;
        }

        return $value;
    }

    /**
     * `bool` from whatever the driver produced.
     *
     * `pyodbc` gives `True`/`False` for `bit`; `pdo_sqlsrv` gives `"1"`/`"0"`.
     * PHP's `(bool) "0"` is already false, but the explicit comparison keeps the
     * intent readable and survives a driver that answers `"false"`.
     */
    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return ! in_array(mb_strtolower(trim((string) $value)), ['', '0', 'false'], true);
    }

    /**
     * A UTC timestamp with a `Z` suffix — the `_iso()` of the call-system module.
     *
     * `app/api/routes/call_system.py` formats its own timestamps rather than reusing
     * the admin module's `_serialize()`, and the two differ in exactly one byte:
     *
     * ```python
     * def _iso(value):
     *     if not value: return None
     *     if isinstance(value, str): return value
     *     if value.tzinfo is None: value = value.replace(tzinfo=timezone.utc)
     *     return value.isoformat().replace("+00:00", "Z")
     * ```
     *
     * `SYSUTCDATETIME()` and the `datetime2` columns are naive, so the value is stamped
     * UTC and the offset is then rewritten as `Z` — `2026-09-30T08:53:41.864000Z`, where
     * the admin module would have answered `2026-09-30T08:53:41.864000`.  Both are live:
     * `/master-admin/api/sessions` and `/api/calls/recent` describe the same column and
     * spell it differently, and a client that parses either is equally happy.
     *
     * The one case the Python's `isinstance(value, str)` branch covered cannot arise
     * here — `pdo_sqlsrv` hands back strings for **everything**, so an unparseable value
     * is the equivalent situation and is returned raw rather than given a `Z` that would
     * make it look like a timestamp it is not.
     *
     * A `datetimeoffset` keeps its own offset instead of being forced to `Z`, because
     * Python's `isoformat()` on a tz-aware value would have printed that offset.
     */
    public static function isoUtcZ(mixed $value): ?string
    {
        // `if not value: return None` — NULL and the empty string both serialise as null.
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $raw = is_string($value) ? trim($value) : '';

        if ($raw === '') {
            return null;
        }

        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:?\d{2})?$/',
            $raw,
            $matches
        ) !== 1) {
            // Not a timestamp this code understands: hand back what is stored rather
            // than inventing a suffix.  The admin module makes the same choice.
            return $raw;
        }

        $formatted = self::formatDateTime($matches[1].' '.$matches[2], $matches[3] ?? '');
        $offset = $matches[4] ?? '';

        return $formatted.($offset === '' || $offset === 'Z' ? 'Z' : self::normaliseOffset($offset));
    }

    /**
     * A `date` column → `YYYY-MM-DD`, as `datetime.date.isoformat()` gives.
     *
     * Returns the raw value when it is not a recognisable date rather than
     * discarding it: a malformed stored date should be visible in the admin UI, not
     * silently replaced.
     */
    private static function date(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    /**
     * A `datetime`/`datetime2` column → the `isoformat()` string.
     *
     * `datetime.isoformat()` uses `timespec='auto'`: with a zero microsecond it
     * emits no fractional part at all, otherwise exactly six digits.  SQL Server's
     * text form is the opposite (`2026-09-30 08:53:41` or `2026-09-30
     * 08:53:41.864`), so the fraction is right-padded to six digits and dropped
     * when it is zero.
     */
    private static function dateTime(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return self::formatDateTime(
                $value->format('Y-m-d H:i:s'),
                (string) $value->format('u')
            );
        }

        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:?\d{2})?$/',
            $trimmed,
            $matches
        ) !== 1) {
            return $value;
        }

        $formatted = self::formatDateTime($matches[1].' '.$matches[2], $matches[3] ?? '');

        // A `datetimeoffset` keeps its offset, as Python's tz-aware `isoformat()`
        // would; this deployment stores UTC and the columns are all `datetime2`,
        // but handling it means the serializer is not silently lossy if one appears.
        if (($matches[4] ?? '') !== '') {
            $formatted .= self::normaliseOffset($matches[4]);
        }

        return $formatted;
    }

    private static function formatDateTime(string $dateAndTime, string $fraction): string
    {
        $isoDate = str_replace(' ', 'T', $dateAndTime);

        $microseconds = (int) str_pad(substr($fraction, 0, 6), 6, '0');

        return $microseconds === 0
            ? $isoDate
            : $isoDate.'.'.sprintf('%06d', $microseconds);
    }

    private static function normaliseOffset(string $offset): string
    {
        if ($offset === 'Z') {
            return '+00:00';
        }

        return strlen($offset) === 5 ? substr($offset, 0, 3).':'.substr($offset, 3) : $offset;
    }

    /**
     * A `time` column → `HH:MM:SS` (+ fraction), as `datetime.time.isoformat()`.
     */
    private static function time(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return self::timeFromParts($value->format('H:i:s'), $value->format('u'));
        }

        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if (preg_match('/^(\d{2}:\d{2}:\d{2})(?:\.(\d+))?$/', $trimmed, $matches) !== 1) {
            return $value;
        }

        return self::timeFromParts($matches[1], $matches[2] ?? '');
    }

    private static function timeFromParts(string $clock, string $fraction): string
    {
        $microseconds = (int) str_pad(substr($fraction, 0, 6), 6, '0');

        return $microseconds === 0 ? $clock : $clock.'.'.sprintf('%06d', $microseconds);
    }

    /**
     * The raw bytes of a binary column.
     *
     * `pdo_sqlsrv` returns the bytes as a PHP string, so no conversion is needed —
     * the indirection exists so the intent is clear and so a driver that starts
     * returning a stream is handled in one place.
     */
    private static function binary(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return is_string($value) ? $value : '';
    }
}
