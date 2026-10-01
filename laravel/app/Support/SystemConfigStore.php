<?php

namespace App\Support;

use App\Services\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `app/services/system_config.py`, ported once for the settings surfaces.
 *
 * The contract the Python module fixed and every settings endpoint inherits:
 *
 * * **A missing row is never an error** — the caller's default wins;
 * * **reads fail soft** — an unreadable table degrades to the default instead of
 *   breaking a request (the caching read lives in {@see SystemSettings});
 * * **writes are UPSERTs** — `UPDATE`, then `INSERT` when the key had no row, so
 *   a setting does not have to be seeded by hand;
 * * **writes never raise** — `write_value()` logs and returns `False`, and the
 *   caller decides what the response says (`saved: false` in the payload).
 *
 * `truthy()` is the Python's, not `LegacyValue::truthy()`: the flag is parsed
 * from a JSON body, where the Python's `str(value or "").strip().lower()` treats
 * the integer `5` as *off* (`"5"` is not in the set) while PHP's numeric
 * shortcut would treat it as on.  The set is verbatim `_TRUTHY`.
 */
final class SystemConfigStore
{
    /** `_TRUTHY` from `system_config.py`. */
    private const TRUTHY = ['1', 'true', 'yes', 'on', 'enabled'];

    /**
     * `system_config.truthy()` — the flag parser used for stored **and** posted values.
     */
    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // Python: `str(value or "")`.  None, `[]`, `{}` and every other falsy
        // value become `""`; a non-empty container becomes its `str()` form,
        // which is never in the set — so `false` is the faithful answer for all
        // of them, without an "Array to string conversion" warning on the way.
        if (! is_scalar($value) || $value === '' || $value === 0 || $value === 0.0) {
            return false;
        }

        // `str(1.0)` is `"1.0"`, not `"1"` — `var_export` is the PHP spelling of
        // Python's repr for floats, and `"1.0"` is *not* in the set.
        $text = is_float($value) ? var_export($value, true) : (string) $value;

        return in_array(mb_strtolower(trim($text)), self::TRUTHY, true);
    }

    /** Stored value of *key*, or `$default` when the row is missing/unreadable. */
    public static function value(string $key, ?string $default = null): ?string
    {
        return app(SystemSettings::class)->value($key, $default);
    }

    /** Boolean setting; `$default` is used when the row is missing or unreadable. */
    public static function readFlag(string $key, bool $default = false): bool
    {
        return app(SystemSettings::class)->flag($key, $default);
    }

    /** Integer setting clamped into `[$minimum, $maximum]`. */
    public static function readInt(string $key, int $default, int $minimum = 0, int $maximum = PHP_INT_MAX): int
    {
        return app(SystemSettings::class)->number($key, $default, $minimum, $maximum);
    }

    /**
     * UPSERT one setting; `false` when the write failed (it never throws).
     *
     * The cache entry is forgotten only on success — a failed write left the
     * stored value alone, so the cached copy is still correct.
     */
    public static function upsert(string $key, string $value, string $actor, string $description = ''): bool
    {
        try {
            $updated = DB::table('system_config')
                ->where('config_key', $key)
                ->update([
                    'config_value' => $value,
                    'updated_by' => $actor,
                    'updated_at' => DB::raw('SYSUTCDATETIME()'),
                ]);

            if ($updated === 0) {
                DB::table('system_config')->insert([
                    'config_key' => $key,
                    'config_value' => $value,
                    'description' => $description,
                    'updated_by' => $actor,
                    'updated_at' => DB::raw('SYSUTCDATETIME()'),
                ]);
            }
        } catch (Throwable $exception) {
            Log::error("setting {$key} could not be saved: ".get_class($exception).": {$exception->getMessage()}");

            return false;
        }

        app(SystemSettings::class)->forget($key);

        return true;
    }

    /** UPSERT a boolean setting as `"1"` / `"0"`. */
    public static function writeFlag(string $key, bool $value, string $actor, string $description = ''): bool
    {
        return self::upsert($key, $value ? '1' : '0', $actor, $description);
    }
}
