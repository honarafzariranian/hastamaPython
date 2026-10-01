<?php

namespace App\Services\Settings;

use App\Models\SystemConfig;
use App\Support\Legacy\LegacyValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cached, fail-soft access to `system_config`.
 *
 * `app/services/system_config.py` has three rules that callers rely on, and they
 * are reproduced here rather than re-decided per feature:
 *
 * 1. **A missing row is never an error** — the caller's default wins.
 * 2. **An unreadable database degrades to the default** instead of breaking a
 *    request or a startup hook.  A settings lookup must not be able to take the
 *    login page down.
 * 3. **Flag parsing is the legacy set** — `1 / true / yes / on / enabled`.
 *
 * One addition over the Python module: reads are cached for a few seconds, because
 * the login path consults several of these keys per request and the values change
 * only when an administrator edits them.  The cache is keyed per setting and is
 * flushed on write by {@see forget()}, so an operator's change takes effect
 * immediately rather than after a TTL.
 */
final class SystemSettings
{
    /** How long a read is cached. */
    private const TTL_SECONDS = 10;

    /**
     * Keys whose values must never be served to an unauthenticated caller.
     *
     * `/api/system-config` publishes a hand-picked subset instead of the whole
     * table for this reason; the printer name and the label calibration are
     * operator data.
     *
     * @var array<int, string>
     */
    public const PUBLIC_KEYS = [
        SystemConfig::KEY_CAPTCHA_ENABLED,
        SystemConfig::KEY_IDLE_TIMEOUT_ENABLED,
        SystemConfig::KEY_IDLE_TIMEOUT_SECONDS,
    ];

    public function value(string $key, ?string $default = null): ?string
    {
        try {
            $cached = Cache::remember(
                $this->cacheKey($key),
                self::TTL_SECONDS,
                fn (): array => ['value' => SystemConfig::value($key)],
            );
        } catch (Throwable $exception) {
            // Rule 2: unreadable settings degrade to the default.
            Log::warning('setting could not be read', [
                'key' => $key,
                'exception' => $exception::class,
            ]);

            return $default;
        }

        $value = $cached['value'] ?? null;

        return $value === null ? $default : (string) $value;
    }

    public function flag(string $key, bool $default = false): bool
    {
        $value = $this->value($key);

        return $value === null ? $default : LegacyValue::truthy($value);
    }

    public function number(string $key, int $default, int $minimum = 0, int $maximum = PHP_INT_MAX): int
    {
        return LegacyValue::integer($this->value($key), $default, $minimum, $maximum);
    }

    /**
     * Whether the login CAPTCHA is required.
     *
     * Deliberately **not** `flag()`.  The Python login endpoint has its own
     * narrower rule — it disables the CAPTCHA only for `0`, `false` or `False`,
     * and leaves it enabled when the row is missing or the read fails:
     *
     *     captcha_enabled = True
     *     if row and str(row[0]).strip() in ('0', 'false', 'False'): captcha_enabled = False
     *
     * Keeping that asymmetry matters: it fails *closed*.  A settings outage must
     * not silently drop a security control.
     */
    public function captchaEnabled(): bool
    {
        $value = $this->value(SystemConfig::KEY_CAPTCHA_ENABLED);

        if ($value === null) {
            return true;
        }

        return ! in_array(trim($value), ['0', 'false', 'False'], true);
    }

    /**
     * The idle-logout settings the login page and the panels read.
     *
     * `idle_timeout_seconds` is a **client-side** control in the existing
     * application (`app/static/js/admin.js` starts a timer and redirects to
     * `/login`), which is why it is published by the public config endpoint.  The
     * server-side idle check uses a different value — see
     * `config('hastama.session.idle_seconds')`.
     *
     * @return array{captcha_enabled: string, idle_timeout_enabled: string, idle_timeout_seconds: string}
     */
    public function publicRuntimeSettings(): array
    {
        return [
            'captcha_enabled' => $this->value(SystemConfig::KEY_CAPTCHA_ENABLED, '1') ?? '1',
            'idle_timeout_enabled' => $this->value(SystemConfig::KEY_IDLE_TIMEOUT_ENABLED, '1') ?? '1',
            'idle_timeout_seconds' => (string) $this->number(SystemConfig::KEY_IDLE_TIMEOUT_SECONDS, 300, 0),
        ];
    }

    /** Drop a key's cached value after a write. */
    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    /** Drop every cached setting. */
    public function flush(): void
    {
        foreach (array_merge(SystemConfig::MASTER_ADMIN_WRITABLE_KEYS, self::PUBLIC_KEYS) as $key) {
            $this->forget($key);
        }
    }

    private function cacheKey(string $key): string
    {
        return 'hastama.system_config.'.$key;
    }
}
