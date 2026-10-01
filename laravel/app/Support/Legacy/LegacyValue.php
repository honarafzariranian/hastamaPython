<?php

namespace App\Support\Legacy;

/**
 * Value coercion for the loose columns the legacy schema is full of.
 *
 * `system_config.config_value` is a free-form `nvarchar(2000)`, `slides.is_active`
 * is a `bit`, `queue_tickets.status` is `nvarchar(20)` holding words, and
 * `ticket_table.is_read` is a `varchar(max)` holding `0`/`1`.  None of them can be
 * trusted to be the type their name suggests, so the coercions live here once.
 *
 * `truthy()` is a direct port of `app/services/system_config.py`:
 *
 *     _TRUTHY = {"1", "true", "yes", "on", "enabled"}
 *
 * Everything else — including `"0"`, `"false"`, `""` and a missing value — is
 * false.  Note that a *missing row* is never an error in that module: the caller's
 * default wins, which is why every accessor here takes one.
 */
final class LegacyValue
{
    /**
     * Values the legacy application accepts as "on".
     *
     * @var array<int, string>
     */
    private const TRUTHY = ['1', 'true', 'yes', 'on', 'enabled'];

    /** Interpret a stored flag. */
    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }

        return in_array(mb_strtolower(PersianText::strip(is_string($value) ? $value : '')), self::TRUTHY, true);
    }

    /**
     * Interpret a stored integer, clamped into `[$minimum, $maximum]`.
     *
     * An unparsable or missing value yields `$default` — the clamping in the
     * Python implementation (`read_int`) applies only to values that parsed.
     */
    public static function integer(mixed $value, int $default, int $minimum = 0, int $maximum = PHP_INT_MAX): int
    {
        if (! is_numeric(PersianText::strip(is_string($value) ? $value : (is_scalar($value) ? (string) $value : '')))) {
            return $default;
        }

        return max($minimum, min($maximum, (int) $value));
    }

    /** A trimmed string, or null when the value is absent or empty. */
    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = PersianText::strip(is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Decode one of the legacy JSON columns.
     *
     * The Python application stores `json.dumps(...)` output in
     * `nvarchar(max)`/`varchar(max)` columns (`payload_json`, `metadata`,
     * `before_data`, `label_print_settings`).  Malformed content must not throw —
     * the old application logged and fell back to a default, so this returns
     * `$default` instead.
     */
    public static function json(mixed $value, mixed $default = null): mixed
    {
        if (! is_string($value) || PersianText::strip($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}
