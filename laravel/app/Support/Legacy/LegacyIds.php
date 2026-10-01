<?php

namespace App\Support\Legacy;

/**
 * The legacy identifier format: `HST-YYYYMMDD-XXXXXXXX`.
 *
 * `app/services/audit.py` generates every correlation identifier the same way —
 * `generate_event_id()`, `generate_request_id()` and `generate_action_id()` are
 * three names for one shape:
 *
 *     f"HST-{datetime.now(timezone.utc).strftime('%Y%m%d')}-{token_hex(4)}".upper()
 *
 * That shape is not cosmetic.  `validate_request_id()` accepts nothing else
 * (`^HST-\d{8}-[0-9A-Fa-f]{8}$`), the recovery flow matches on it, and the control
 * centre shows it to the operator, so a login page that forged a different format
 * would produce requests the admin panel cannot resolve.
 *
 * The date part is the **UTC** date, and the hex part is upper-cased by the
 * trailing `.upper()`.
 */
final class LegacyIds
{
    /** 21 characters total: `HST-` (4) + 8 digits + `-` (1) + 8 hex. */
    public const LENGTH = 21;

    /** The single pattern every legacy identifier satisfies. */
    public const PATTERN = '/^HST-\d{8}-[0-9A-F]{8}$/';

    public static function eventId(): string
    {
        return self::make();
    }

    public static function requestId(): string
    {
        return self::make();
    }

    public static function actionId(): string
    {
        return self::make();
    }

    /**
     * Whether a string is a well-formed legacy identifier.
     *
     * The Python validator accepted lower-case hex as well (its pattern is
     * `[0-9A-Fa-f]{8}`), and `verify_recovery_code` compares the stored value, so
     * this accepts both cases and the comparison stays case-sensitive at the call
     * site — exactly like the original.
     */
    public static function isValid(?string $value): bool
    {
        return is_string($value) && preg_match('/^HST-\d{8}-[0-9A-Fa-f]{8}$/', $value) === 1;
    }

    /**
     * Mint one identifier.
     *
     * The random half is **hex**, because `validate_request_id()` in the Python
     * application matches `[0-9A-Fa-f]{8}` and rejects anything else.  A generic
     * alphanumeric generator would produce ids that this very class' `isValid()`
     * refuses — an identifier the recovery flow could never look up again.
     */
    public static function make(): string
    {
        return 'HST-'.now()->utc()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
    }
}
