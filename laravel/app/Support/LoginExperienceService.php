<?php

namespace App\Support;

use App\Support\SystemConfigStore;
use RuntimeException;
use Throwable;

/**
 * Login experience — the loader that greets a user and the CAPTCHA lifetime.
 *
 * Ported from `app/services/login_experience.py`.  The values live in
 * `system_config` and are applied without a restart.
 */
final class LoginExperienceService
{
    public const ENABLED_KEY = 'login_loader_enabled';
    public const SECONDS_KEY = 'login_loader_seconds';
    public const TITLE_KEY = 'login_loader_title';
    public const MESSAGE_KEY = 'login_loader_message';
    public const NOTICE_KEY = 'login_captcha_notice';
    public const TTL_KEY = 'login_captcha_ttl_seconds';

    private const DESCRIPTIONS = [
        self::ENABLED_KEY => 'نمایش لودر تمام‌صفحه هنگام ورود به سامانه',
        self::SECONDS_KEY => 'مدت نمایش لودر ورود (ثانیه)',
        self::TITLE_KEY => 'عنوان لودر ورود',
        self::MESSAGE_KEY => 'متن زیر عنوان لودر ورود',
        self::NOTICE_KEY => 'هشدار زودهنگام انقضای کد امنیتی در صفحهٔ ورود',
        self::TTL_KEY => 'مدت اعتبار کد امنیتی (ثانیه)',
    ];

    public const DEFAULT_TITLE = 'در حال آماده‌سازی میزکار شما…';
    public const DEFAULT_MESSAGE = 'لطفاً چند لحظه صبر کنید؛ در حال ورود به سامانه هستما.';

    public const DEFAULT_SECONDS = 3;
    public const MIN_SECONDS = 1;
    public const MAX_SECONDS = 15;

    public const DEFAULT_CAPTCHA_TTL_SECONDS = 180;
    public const MIN_CAPTCHA_TTL_SECONDS = 30;
    public const MAX_CAPTCHA_TTL_SECONDS = 1800;

    public const MAX_TITLE_CHARS = 80;
    public const MAX_MESSAGE_CHARS = 200;

    public const CAPTCHA_WARNING_LEAD_SECONDS = 60;

    /** @var array{loader_enabled: bool, loader_seconds: int, loader_title: string, loader_message: string, captcha_notice: bool, captcha_ttl: int} */
    private static array $state = [
        'loader_enabled' => true,
        'loader_seconds' => self::DEFAULT_SECONDS,
        'loader_title' => self::DEFAULT_TITLE,
        'loader_message' => self::DEFAULT_MESSAGE,
        'captcha_notice' => true,
        'captcha_ttl' => self::DEFAULT_CAPTCHA_TTL_SECONDS,
    ];

    public static function status(): array
    {
        return [
            'loader_enabled' => self::$state['loader_enabled'],
            'loader_seconds' => self::loaderSeconds(),
            'loader_title' => self::loaderTitle(),
            'loader_message' => self::loaderMessage(),
            'captcha_notice' => self::$state['captcha_notice'],
            'captcha_ttl_seconds' => self::captchaTtlSeconds(),
            'captcha_warning_lead_seconds' => self::CAPTCHA_WARNING_LEAD_SECONDS,
            'min_seconds' => self::MIN_SECONDS,
            'max_seconds' => self::MAX_SECONDS,
            'min_captcha_ttl_seconds' => self::MIN_CAPTCHA_TTL_SECONDS,
            'max_captcha_ttl_seconds' => self::MAX_CAPTCHA_TTL_SECONDS,
            'public_config_keys' => self::publicConfigKeys(),
        ];
    }

    /**
     * Validate, persist and apply the settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applySettings(array $data, string $actor = ''): array
    {
        if (! is_array($data)) {
            throw new LoginExperienceException('تنظیمات نامعتبر است.');
        }

        $title = self::cleanText($data['loader_title'] ?? null, self::MAX_TITLE_CHARS, self::DEFAULT_TITLE);
        $message = self::cleanText($data['loader_message'] ?? null, self::MAX_MESSAGE_CHARS, self::DEFAULT_MESSAGE);

        $seconds = self::parseIntSetting(
            $data['loader_seconds'] ?? self::$state['loader_seconds'],
            self::MIN_SECONDS,
            self::MAX_SECONDS,
            'مدت نمایش لودر باید عدد باشد.',
            'مدت نمایش لودر باید بین '.self::MIN_SECONDS.' و '.self::MAX_SECONDS.' ثانیه باشد.',
        );

        $ttl = self::parseIntSetting(
            $data['captcha_ttl_seconds'] ?? self::$state['captcha_ttl'],
            self::MIN_CAPTCHA_TTL_SECONDS,
            self::MAX_CAPTCHA_TTL_SECONDS,
            'مدت اعتبار کد امنیتی باید عدد باشد.',
            'مدت اعتبار کد امنیتی باید بین '.self::MIN_CAPTCHA_TTL_SECONDS.' و '.self::MAX_CAPTCHA_TTL_SECONDS.' ثانیه باشد.',
        );

        $loaderEnabled = SystemConfigStore::truthy($data['loader_enabled'] ?? self::$state['loader_enabled']);
        $notice = SystemConfigStore::truthy($data['captcha_notice'] ?? self::$state['captcha_notice']);

        $writes = [
            [self::ENABLED_KEY, $loaderEnabled ? '1' : '0'],
            [self::SECONDS_KEY, (string) $seconds],
            [self::TITLE_KEY, $title],
            [self::MESSAGE_KEY, $message],
            [self::NOTICE_KEY, $notice ? '1' : '0'],
            [self::TTL_KEY, (string) $ttl],
        ];

        $failed = [];

        foreach ($writes as [$key, $value]) {
            if (! SystemConfigStore::upsert($key, $value, $actor, self::DESCRIPTIONS[$key] ?? '')) {
                $failed[] = $key;
            }
        }

        self::loadSettings();

        $saved = self::status();
        $saved['saved'] = $failed === [];

        if ($failed !== []) {
            Log::error('login-experience settings not fully saved: '.implode(', ', $failed));
        }

        return $saved;
    }

    private static function loadSettings(): void
    {
        self::$state['loader_enabled'] = SystemConfigStore::readFlag(self::ENABLED_KEY, true);
        self::$state['captcha_notice'] = SystemConfigStore::readFlag(self::NOTICE_KEY, true);
        self::$state['loader_seconds'] = SystemConfigStore::readInt(
            self::SECONDS_KEY,
            self::DEFAULT_SECONDS,
            self::MIN_SECONDS,
            self::MAX_SECONDS,
        );
        self::$state['captcha_ttl'] = SystemConfigStore::readInt(
            self::TTL_KEY,
            self::DEFAULT_CAPTCHA_TTL_SECONDS,
            self::MIN_CAPTCHA_TTL_SECONDS,
            self::MAX_CAPTCHA_TTL_SECONDS,
        );
        self::$state['loader_title'] = mb_substr(
            SystemConfigStore::value(self::TITLE_KEY, self::DEFAULT_TITLE) ?? self::DEFAULT_TITLE,
            0,
            self::MAX_TITLE_CHARS,
        );
        self::$state['loader_message'] = mb_substr(
            SystemConfigStore::value(self::MESSAGE_KEY, self::DEFAULT_MESSAGE) ?? self::DEFAULT_MESSAGE,
            0,
            self::MAX_MESSAGE_CHARS,
        );
    }

    private static function loaderSeconds(): int
    {
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, self::$state['loader_seconds']));
    }

    private static function loaderTitle(): string
    {
        return self::$state['loader_title'] !== '' ? self::$state['loader_title'] : self::DEFAULT_TITLE;
    }

    private static function loaderMessage(): string
    {
        return self::$state['loader_message'] !== '' ? self::$state['loader_message'] : self::DEFAULT_MESSAGE;
    }

    private static function captchaTtlSeconds(): int
    {
        return max(self::MIN_CAPTCHA_TTL_SECONDS, min(self::MAX_CAPTCHA_TTL_SECONDS, self::$state['captcha_ttl']));
    }

    /**
     * @return array<int, string>
     */
    private static function publicConfigKeys(): array
    {
        return [
            self::ENABLED_KEY,
            self::SECONDS_KEY,
            self::TITLE_KEY,
            self::MESSAGE_KEY,
            self::NOTICE_KEY,
            self::TTL_KEY,
        ];
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
            throw new LoginExperienceException($notNumberMessage);
        }

        if ($parsed < $min || $parsed > $max) {
            throw new LoginExperienceException($rangeMessage);
        }

        return $parsed;
    }
}

class LoginExperienceException extends RuntimeException {}
