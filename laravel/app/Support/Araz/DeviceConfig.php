<?php

namespace App\Support\Araz;

/**
 * The device connection configuration — the port of the module-level
 * `_device_config` dict in `app/api/routes/araz_api.py`.
 *
 * The Python keeps this in process memory ("should be moved to DB/settings in
 * production"), so a static is the faithful shape: it lives for the life of
 * the process, is shared by every request, and is not persisted anywhere.
 * The defaults are the connector's own (`DEFAULT_IP`, `DEFAULT_PORT`,
 * `DEFAULT_DEVICE_NUMBER`, `timeout = 10.0`).
 *
 * `resetToDefaults()` exists for tests, where a static would otherwise leak
 * one test's override into the next.
 */
final class DeviceConfig
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'ip' => '192.168.3.200',
        'port' => 1001,
        'device_number' => 1,
        'timeout' => 10.0,
    ];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::$config === [] ? self::DEFAULTS : self::$config;
    }

    /**
     * Apply a partial update, as `update_device_config` does: only the keys
     * present in the request change, the rest keep their value.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function update(array $changes): array
    {
        $current = self::all();

        foreach (['ip', 'port', 'device_number', 'timeout'] as $key) {
            if (array_key_exists($key, $changes)) {
                $current[$key] = $changes[$key];
            }
        }

        return self::$config = $current;
    }

    /** Restore the defaults.  Test seam only. */
    public static function resetToDefaults(): void
    {
        self::$config = [];
    }
}
