<?php

namespace App\Models;

use App\Support\Legacy\LegacyValue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * `dbo.system_config` — the operator settings key/value table (12 rows).
 *
 * Three behaviours of the original module are worth keeping in mind, because
 * they are load-bearing:
 *
 * 1. **A missing row is never an error.**  `app/services/system_config.py` treats
 *    it as "use the caller's default", and many keys are read by code but have no
 *    row at all today (see {@see self::KEYS_WITHOUT_ROWS}).  Nothing here may
 *    assume a row exists.
 * 2. **Writes are UPSERTs.**  `UPDATE`, then `INSERT` when no row existed yet, so
 *    a key never has to be seeded by hand.  Eloquent's `updateOrCreate()` is the
 *    same operation.
 * 3. **Failures degrade, they do not propagate.**  An unreadable database fell
 *    back to the default rather than breaking a request.  In Laravel that belongs
 *    in the settings service rather than the model, so it is not implemented here.
 *
 * The table has `updated_at` but **no** `created_at`; a brand-new key therefore
 * gets only the update stamp, exactly as the UPSERT did.
 */
#[Table(name: 'system_config', key: 'config_key', keyType: 'string', incrementing: false, timestamps: true)]
#[Fillable(['config_key', 'config_value', 'description', 'updated_by'])]
class SystemConfig extends LegacyTimestampedModel
{
    /** This table has no `created_at` column. */
    public const CREATED_AT = null;

    // ── Security and session settings (these have live rows) ─────────────────

    public const KEY_CAPTCHA_ENABLED = 'captcha_enabled';

    public const KEY_IDLE_TIMEOUT_ENABLED = 'idle_timeout_enabled';

    public const KEY_IDLE_TIMEOUT_SECONDS = 'idle_timeout_seconds';

    public const KEY_SESSION_TIMEOUT_MINUTES = 'session_timeout_minutes';

    public const KEY_MAX_LOGIN_ATTEMPTS = 'max_login_attempts';

    public const KEY_LOCKOUT_DURATION_MINUTES = 'lockout_duration_minutes';

    public const KEY_PASSWORD_RESET_CODE_TTL_MINUTES = 'password_reset_code_ttl_minutes';

    public const KEY_AUDIT_RETENTION_DAYS = 'audit_retention_days';

    public const KEY_SECURITY_RETENTION_DAYS = 'security_retention_days';

    // ── Printing (the calibration value must never be rewritten) ─────────────

    /** Printer name; live value is `EPSON TM-T88III Receipt`. */
    public const KEY_LABEL_TARGET_PRINTER = 'label_target_printer';

    /** Label geometry JSON; live value is the 76×105 mm, `layout_version: 6` set. */
    public const KEY_LABEL_PRINT_SETTINGS = 'label_print_settings';

    // ── Outage / LAN / Iran-only / login experience (no rows today) ──────────

    public const KEY_OUTAGE_MANUAL = 'outage_manual';

    public const KEY_OUTAGE_PAGE_ENABLED = 'outage_page_enabled';

    public const KEY_OUTAGE_PROBE_INTERVAL_SECONDS = 'outage_probe_interval_seconds';

    public const KEY_OUTAGE_PROBE_FAILURES = 'outage_probe_failures';

    public const KEY_OUTAGE_PROBE_TARGETS = 'outage_probe_targets';

    public const KEY_OUTAGE_TERMINATE_SESSIONS = 'outage_terminate_sessions';

    public const KEY_OUTAGE_SHOW_LAN_ADDRESS = 'outage_show_lan_address';

    public const KEY_OUTAGE_TITLE = 'outage_title';

    public const KEY_OUTAGE_MESSAGE = 'outage_message';

    public const KEY_LAN_ACCESS_ENABLED = 'lan_access_enabled';

    public const KEY_IRAN_ONLY_ENABLED = 'iran_only_enabled';

    public const KEY_IRAN_ONLY_TITLE = 'iran_only_title';

    public const KEY_IRAN_ONLY_MESSAGE = 'iran_only_message';

    public const KEY_IRAN_ONLY_HELP = 'iran_only_help';

    public const KEY_IRAN_ONLY_LOG_BLOCKED = 'iran_only_log_blocked';

    public const KEY_LOGIN_LOADER_ENABLED = 'login_loader_enabled';

    public const KEY_LOGIN_LOADER_SECONDS = 'login_loader_seconds';

    public const KEY_LOGIN_LOADER_TITLE = 'login_loader_title';

    public const KEY_LOGIN_LOADER_MESSAGE = 'login_loader_message';

    public const KEY_LOGIN_CAPTCHA_NOTICE = 'login_captcha_notice';

    public const KEY_LOGIN_CAPTCHA_TTL_SECONDS = 'login_captcha_ttl_seconds';

    /**
     * The only keys the master-admin settings endpoint will write.
     *
     * Transcribed from the hard-coded `allowed_keys` set in
     * `app/api/routes/master_admin.py`; everything else is rejected with
     * «کلید تنظیم مجاز نیست».  Keeping the list here means the new endpoint cannot
     * quietly widen it.
     *
     * @var array<int, string>
     */
    public const MASTER_ADMIN_WRITABLE_KEYS = [
        self::KEY_CAPTCHA_ENABLED,
        self::KEY_IDLE_TIMEOUT_ENABLED,
        self::KEY_IDLE_TIMEOUT_SECONDS,
        self::KEY_LABEL_TARGET_PRINTER,
        self::KEY_LABEL_PRINT_SETTINGS,
    ];

    /**
     * Keys read by code that have **no row** in the live database, so their
     * defaults come from the calling service.  Recorded because "the setting is
     * missing" is the normal state here, not a fault.
     *
     * @var array<int, string>
     */
    public const KEYS_WITHOUT_ROWS = [
        self::KEY_LAN_ACCESS_ENABLED,
        self::KEY_OUTAGE_PAGE_ENABLED,
        self::KEY_OUTAGE_PROBE_INTERVAL_SECONDS,
        self::KEY_OUTAGE_PROBE_FAILURES,
        self::KEY_OUTAGE_PROBE_TARGETS,
        self::KEY_OUTAGE_TERMINATE_SESSIONS,
        self::KEY_OUTAGE_SHOW_LAN_ADDRESS,
        self::KEY_OUTAGE_TITLE,
        self::KEY_OUTAGE_MESSAGE,
        self::KEY_IRAN_ONLY_ENABLED,
        self::KEY_IRAN_ONLY_TITLE,
        self::KEY_IRAN_ONLY_MESSAGE,
        self::KEY_IRAN_ONLY_HELP,
        self::KEY_IRAN_ONLY_LOG_BLOCKED,
        self::KEY_LOGIN_LOADER_ENABLED,
        self::KEY_LOGIN_LOADER_SECONDS,
        self::KEY_LOGIN_LOADER_TITLE,
        self::KEY_LOGIN_LOADER_MESSAGE,
        self::KEY_LOGIN_CAPTCHA_NOTICE,
        self::KEY_LOGIN_CAPTCHA_TTL_SECONDS,
    ];

    /** The stored value, or the supplied default when the row is missing. */
    public static function value(string $key, ?string $default = null): ?string
    {
        $row = static::query()->find($key);

        return $row === null ? $default : $row->config_value;
    }

    /** Boolean setting, using the original truthiness rules. */
    public static function flag(string $key, bool $default = false): bool
    {
        $value = static::value($key);

        return $value === null ? $default : LegacyValue::truthy($value);
    }

    /** Integer setting, clamped; the default is used when the row is missing. */
    public static function number(string $key, int $default, int $minimum = 0, int $maximum = PHP_INT_MAX): int
    {
        return LegacyValue::integer(static::value($key), $default, $minimum, $maximum);
    }

    /** One of the JSON settings, decoded; returns `$default` on malformed content. */
    public static function jsonValue(string $key, mixed $default = null): mixed
    {
        return LegacyValue::json(static::value($key), $default);
    }
}
