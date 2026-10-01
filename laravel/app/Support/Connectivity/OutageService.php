<?php

namespace App\Support\Connectivity;

use App\Support\SystemConfigStore;
use RuntimeException;
use Throwable;

/**
 * Internet outage mode — detect the loss of the link and take the system there.
 *
 * Ported from `app/services/outage.py`.  The settings live in `system_config` and
 * are applied at runtime, without a restart.  The monitor loop itself is not
 * reproduced: the Python ran an `asyncio` background task inside the uvicorn
 * process, and a PHP request process cannot hold one open.  The state machine,
 * the probe, and the settings are fully ported — the probe is a real TCP
 * connection attempt, exactly as the Python wrote it.
 */
final class OutageService
{
    public const ENABLED_KEY = 'outage_page_enabled';
    public const INTERVAL_KEY = 'outage_probe_interval_seconds';
    public const FAILURES_KEY = 'outage_probe_failures';
    public const TARGETS_KEY = 'outage_probe_targets';
    public const LOGOUT_KEY = 'outage_terminate_sessions';
    public const SHOW_LAN_KEY = 'outage_show_lan_address';
    public const TITLE_KEY = 'outage_title';
    public const MESSAGE_KEY = 'outage_message';
    public const MANUAL_KEY = 'outage_manual';

    private const DESCRIPTIONS = [
        self::ENABLED_KEY => 'فعال‌سازی صفحهٔ قطعی اینترنت و قطع خودکار نشست کاربران',
        self::INTERVAL_KEY => 'فاصلهٔ پایش اینترنت (ثانیه)',
        self::FAILURES_KEY => 'تعداد شکست پیاپی برای اعلام قطعی اینترنت',
        self::TARGETS_KEY => 'آدرس‌های پایش اینترنت (host:port با کاما)',
        self::LOGOUT_KEY => 'خروج خودکار کاربران هنگام تشخیص قطعی اینترنت',
        self::SHOW_LAN_KEY => 'نمایش آدرس شبکهٔ داخلی روی صفحهٔ قطعی',
        self::TITLE_KEY => 'عنوان پیام قطعی اینترنت',
        self::MESSAGE_KEY => 'متن پیام قطعی اینترنت',
        self::MANUAL_KEY => 'اعلام دستی حالت قطعی اینترنت',
    ];

    public const DEFAULT_TARGETS = 'hastama.ir:443,1.1.1.1:443,8.8.8.8:443';

    public const DEFAULT_TITLE = 'ارتباط سامانه با اینترنت قطع شده است';

    public const DEFAULT_MESSAGE = 'دسترسی به سامانه از مسیر اینترنت برقرار نیست. اگر در شبکهٔ داخلی آزمایشگاه هستید، سامانه از آدرس زیر در دسترس است. پس از برقراری اینترنت، این صفحه به‌صورت خودکار بسته می‌شود.';

    public const DEFAULT_INTERVAL_SECONDS = 30;
    public const DEFAULT_FAILURES = 3;
    public const RECOVERY_SUCCESSES = 2;

    public const MIN_INTERVAL_SECONDS = 5;
    public const MAX_INTERVAL_SECONDS = 3600;
    public const MIN_FAILURES = 1;
    public const MAX_FAILURES = 20;
    public const MAX_TITLE_CHARS = 120;
    public const MAX_MESSAGE_CHARS = 600;
    public const PROBE_TIMEOUT_SECONDS = 4.0;

    /** @var array<string, mixed> */
    private static array $state = [];

    /**
     * Everything the settings card and the API need to show.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        self::loadSettings();

        return [
            'enabled' => self::$state['enabled'],
            'active' => self::$state['active'],
            'manual' => self::$state['manual'],
            'reason' => self::$state['reason'],
            'since' => self::$state['since'],
            'monitoring' => false,
            'probe_online' => self::$state['successes'] > 0,
            'checked_at' => self::$state['checked_at'],
            'detail' => self::$state['detail'],
            'failures' => self::$state['failures'],
            'threshold' => self::$state['threshold'],
            'interval_seconds' => self::$state['interval'],
            'targets' => self::$state['targets_text'],
            'target_count' => count(self::$state['targets']),
            'recovery_successes' => self::RECOVERY_SUCCESSES,
            'probe_timeout_seconds' => self::PROBE_TIMEOUT_SECONDS,
            'terminate_sessions' => self::$state['terminate_sessions'],
            'show_lan_address' => self::$state['show_lan_address'],
            'title' => self::$state['title'],
            'message' => self::$state['message'],
            'terminated_sessions' => self::$state['terminated_sessions'],
            'master_admin_accounts' => self::masterAdminAccounts(),
        ];
    }

    /**
     * Validate, persist and apply the outage settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applySettings(array $data, string $actor = ''): array
    {
        if (! is_array($data)) {
            throw new OutageException('تنظیمات نامعتبر است.');
        }

        $title = self::cleanText($data['title'] ?? null, self::MAX_TITLE_CHARS, self::DEFAULT_TITLE);
        $message = self::cleanText($data['message'] ?? null, self::MAX_MESSAGE_CHARS, self::DEFAULT_MESSAGE);

        $interval = self::parseIntSetting(
            $data['interval_seconds'] ?? self::$state['interval'] ?? self::DEFAULT_INTERVAL_SECONDS,
            self::MIN_INTERVAL_SECONDS,
            self::MAX_INTERVAL_SECONDS,
            'فاصلهٔ پایش باید عدد باشد.',
            'فاصلهٔ پایش باید بین '.self::MIN_INTERVAL_SECONDS.' و '.self::MAX_INTERVAL_SECONDS.' ثانیه باشد.',
        );

        $threshold = self::parseIntSetting(
            $data['failures'] ?? self::$state['threshold'] ?? self::DEFAULT_FAILURES,
            self::MIN_FAILURES,
            self::MAX_FAILURES,
            'تعداد شکست پیاپی باید عدد باشد.',
            'تعداد شکست پیاپی باید بین '.self::MIN_FAILURES.' و '.self::MAX_FAILURES.' باشد.',
        );

        $targets = self::parseTargets($data['targets'] ?? null);

        if ($targets === []) {
            throw new OutageException('حداقل یک آدرس پایش معتبر (مثل 1.1.1.1:443) لازم است.');
        }

        $enabled = SystemConfigStore::truthy($data['enabled'] ?? self::$state['enabled'] ?? false);
        $terminate = SystemConfigStore::truthy($data['terminate_sessions'] ?? self::$state['terminate_sessions'] ?? true);
        $showLan = SystemConfigStore::truthy($data['show_lan_address'] ?? self::$state['show_lan_address'] ?? true);

        $targetText = implode(', ', array_map(
            static fn (array $target): string => $target[0].':'.$target[1],
            $targets,
        ));

        $writes = [
            [self::ENABLED_KEY, $enabled ? '1' : '0'],
            [self::INTERVAL_KEY, (string) $interval],
            [self::FAILURES_KEY, (string) $threshold],
            [self::TARGETS_KEY, $targetText],
            [self::LOGOUT_KEY, $terminate ? '1' : '0'],
            [self::SHOW_LAN_KEY, $showLan ? '1' : '0'],
            [self::TITLE_KEY, $title],
            [self::MESSAGE_KEY, $message],
        ];

        $failed = [];

        foreach ($writes as [$key, $value]) {
            if (! SystemConfigStore::upsert($key, $value, $actor, self::DESCRIPTIONS[$key] ?? '')) {
                $failed[] = $key;
            }
        }

        $saved = self::reload();
        $saved['saved'] = $failed === [];

        if ($failed !== []) {
            Log::error('outage settings not fully saved: '.implode(', ', $failed));
        }

        return $saved;
    }

    /**
     * Probe once and apply the result immediately.
     *
     * @return array<string, mixed>
     */
    public static function checkNow(): array
    {
        $targets = self::$state['targets'] ?? self::parseTargets(null);

        if ($targets === []) {
            $targets = self::parseTargets(self::DEFAULT_TARGETS);
        }

        try {
            [$online, $detail] = self::probe($targets);
        } catch (Throwable $exception) {
            $online = false;
            $detail = 'probe error: '.$exception::class;
        }

        self::applyProbeResult($online, $detail);

        return self::status();
    }

    /**
     * Declare the outage by hand (or clear it).
     *
     * @return array<string, mixed>
     */
    public static function setManual(bool $active, string $actor = ''): array
    {
        SystemConfigStore::writeFlag(self::MANUAL_KEY, $active, $actor, self::DESCRIPTIONS[self::MANUAL_KEY] ?? '');

        self::$state['manual'] = $active;

        if ($active) {
            self::$state['failures'] = 0;
            self::$state['successes'] = 0;
        }

        self::recomputeActive();

        return self::status();
    }

    /**
     * `"host:port,host:port"` → `[[host, port]]` (junk entries dropped).
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function parseTargets(mixed $raw): array
    {
        $raw ??= '';
        $text = is_string($raw) ? $raw : (is_scalar($raw) ? (string) $raw : '');

        $targets = [];

        foreach (explode(',', $text) as $chunk) {
            $item = trim($chunk);

            if ($item === '') {
                continue;
            }

            $host = '';
            $portText = '';

            if (str_contains($item, ':')) {
                $parts = explode(':', $item);
                $host = trim($parts[0] ?? '');
                $portText = trim($parts[1] ?? '');
            }

            if ($host === '' || preg_match('/\s/u', $host) === 1) {
                continue;
            }

            if ($portText === '' || ! ctype_digit($portText)) {
                continue;
            }

            $port = (int) $portText;

            if ($port < 1 || $port > 65535) {
                continue;
            }

            $targets[] = [$host, $port];
        }

        return $targets;
    }

    /**
     * Try every target; the first reachable one means "online".
     *
     * @param  array<int, array{0: string, 1: int}>  $targets
     * @return array{0: bool, 1: string}
     */
    public static function probe(array $targets, float $timeout = self::PROBE_TIMEOUT_SECONDS): array
    {
        $failures = [];

        foreach ($targets as [$host, $port]) {
            $errno = 0;
            $errstr = '';

            $connection = @fsockopen($host, (int) $port, $errno, $errstr, $timeout);

            if (is_resource($connection)) {
                fclose($connection);

                return [true, $host.':'.$port];
            }

            $failures[] = $host.':'.$port.' ('.$errstr.')';
        }

        return [false, implode(', ', array_slice($failures, 0, 4))];
    }

    private static function reload(): array
    {
        self::loadSettings();
        self::recomputeActive();

        return self::status();
    }

    private static function loadSettings(): void
    {
        self::$state['enabled'] = SystemConfigStore::readFlag(self::ENABLED_KEY, true);
        self::$state['manual'] = SystemConfigStore::readFlag(self::MANUAL_KEY, false);
        self::$state['terminate_sessions'] = SystemConfigStore::readFlag(self::LOGOUT_KEY, true);
        self::$state['show_lan_address'] = SystemConfigStore::readFlag(self::SHOW_LAN_KEY, true);
        self::$state['title'] = mb_substr(
            SystemConfigStore::value(self::TITLE_KEY) ?? '',
            0,
            self::MAX_TITLE_CHARS,
        ) ?: self::DEFAULT_TITLE;
        self::$state['message'] = mb_substr(
            SystemConfigStore::value(self::MESSAGE_KEY) ?? '',
            0,
            self::MAX_MESSAGE_CHARS,
        ) ?: self::DEFAULT_MESSAGE;
        self::$state['interval'] = SystemConfigStore::readInt(
            self::INTERVAL_KEY,
            self::DEFAULT_INTERVAL_SECONDS,
            self::MIN_INTERVAL_SECONDS,
            self::MAX_INTERVAL_SECONDS,
        );
        self::$state['threshold'] = SystemConfigStore::readInt(
            self::FAILURES_KEY,
            self::DEFAULT_FAILURES,
            self::MIN_FAILURES,
            self::MAX_FAILURES,
        );

        $storedTargets = SystemConfigStore::value(self::TARGETS_KEY);
        $targets = self::parseTargets($storedTargets !== null && trim($storedTargets) !== '' ? $storedTargets : null);

        if ($targets === []) {
            $targets = self::parseTargets(self::DEFAULT_TARGETS);
        }

        self::$state['targets'] = $targets;
        self::$state['targets_text'] = implode(', ', array_map(
            static fn (array $target): string => $target[0].':'.$target[1],
            $targets,
        ));
        self::$state['failures'] = 0;
        self::$state['successes'] = 0;
        self::$state['detail'] = '';
        self::$state['checked_at'] = null;
        self::$state['since'] = null;
        self::$state['reason'] = '';
        self::$state['active'] = false;
        self::$state['terminated_sessions'] = 0;
    }

    private static function recomputeActive(): void
    {
        $should = (self::$state['enabled'] ?? false)
            && ((self::$state['manual'] ?? false) || (self::$state['failures'] ?? 0) >= (self::$state['threshold'] ?? 3));

        if ($should === (self::$state['active'] ?? false)) {
            return;
        }

        if ($should) {
            self::$state['active'] = true;
            self::$state['since'] = date('c');
            self::$state['reason'] = (self::$state['manual'] ?? false)
                ? 'manual'
                : ((self::$state['failures'] ?? 0).' failed probes');
            self::$state['terminated_sessions'] = 0;
        } else {
            self::$state['active'] = false;
            self::$state['reason'] = '';
            self::$state['since'] = null;
            self::$state['terminated_sessions'] = 0;
        }
    }

    private static function applyProbeResult(bool $online, string $detail): void
    {
        self::$state['checked_at'] = date('c');
        self::$state['detail'] = $detail;

        if ($online) {
            self::$state['successes'] = (self::$state['successes'] ?? 0) + 1;

            if (! (self::$state['active'] ?? false) || (self::$state['successes'] ?? 0) >= self::RECOVERY_SUCCESSES) {
                self::$state['failures'] = 0;
            }
        } else {
            self::$state['successes'] = 0;
            self::$state['failures'] = (self::$state['failures'] ?? 0) + 1;
        }

        self::recomputeActive();
    }

    /**
     * @return array<int, string>
     */
    private static function masterAdminAccounts(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) env('MASTER_ADMIN_USERNAMES', 'ali')),
        )));
    }

    private static function cleanText(mixed $value, int $limit, string $fallback): string
    {
        $text = is_string($value) ? preg_replace('/\s+/u', ' ', $value) : '';
        $text = trim($text ?? '');

        return mb_substr($text !== '' ? $text : $fallback, 0, $limit);
    }

    private static function parseIntSetting(mixed $value, int $min, int $max, string $notNumberMessage, string $rangeMessage): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1) {
            $parsed = (int) trim($value);
        } elseif (is_float($value)) {
            $parsed = (int) $value;
        } else {
            throw new OutageException($notNumberMessage);
        }

        if ($parsed < $min || $parsed > $max) {
            throw new OutageException($rangeMessage);
        }

        return $parsed;
    }
}

class OutageException extends RuntimeException {}
