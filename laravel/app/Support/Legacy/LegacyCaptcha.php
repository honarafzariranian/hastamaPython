<?php

namespace App\Support\Legacy;

use App\Services\Settings\SystemSettings;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Store;

/**
 * The login CAPTCHA — a port of `app/services/captcha.py`.
 *
 * Everything that matters is carried over exactly; only the drawing library
 * differs (PHP's bundled GD instead of Pillow), which is why the settings that
 * were read from the image code are reproduced below:
 *
 * * **6 characters**, drawn from the upper-case alphabet with `O`, `I`, `0` and
 *   `1` removed — an ambiguous glyph in a CAPTCHA produces support calls, not
 *   security;
 * * the **code never reaches the client**: it lives in the session and the
 *   response carries only the PNG, which is the whole point of the control;
 * * **lifetime from `system_config`** (`login_captcha_ttl_seconds`, default 180 s,
 *   clamped to 30–1800 by the login-experience service);
 * * **three attempts**, after which the code is destroyed and a new one is
 *   required;
 * * comparison is **case-insensitive** and the code is cleared on success, so a
 *   captured response cannot be replayed;
 * * the "why did it fail" distinction the login endpoint relies on:
 *   `expired` vs `missing` vs `mismatch`, determined *before* validation clears
 *   the session.
 *
 * The image is 180×56 with the brand palette, per-character rotation (−15°…15°),
 * noise dots, straight interference lines and sine-wave lines, as in the original.
 */
final class LegacyCaptcha
{
    public const SESSION_KEY = 'captcha_code';

    public const SESSION_TS_KEY = 'captcha_ts';

    public const SESSION_ATTEMPTS_KEY = 'captcha_attempts';

    /** Characters offered, with ambiguous glyphs removed. */
    public const LENGTH = 6;

    public const MAX_ATTEMPTS = 3;

    public const WIDTH = 180;

    public const HEIGHT = 56;

    /** Fallback lifetime; the live value comes from `system_config`. */
    public const DEFAULT_TTL_SECONDS = 180;

    public const MIN_TTL_SECONDS = 30;

    public const MAX_TTL_SECONDS = 1800;

    /**
     * The character pool: `A–Z` minus `O` and `I`, and no digits, matching
     * `ascii_uppercase.replace("O","").replace("I","").replace("0","").replace("1","")`.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** Reasons a validation failed, mirroring the Python `captcha_reason` values. */
    public const REASON_MISSING = 'missing';

    public const REASON_EXPIRED = 'expired';

    public const REASON_MISMATCH = 'mismatch';

    public function __construct(private readonly SystemSettings $settings) {}

    /** Lifetime of a code in seconds, clamped as `login_experience` clamps it. */
    public function ttlSeconds(): int
    {
        return $this->settings->number(
            'login_captcha_ttl_seconds',
            self::DEFAULT_TTL_SECONDS,
            self::MIN_TTL_SECONDS,
            self::MAX_TTL_SECONDS,
        );
    }

    /** Generate a 6-character code. */
    public function code(): string
    {
        $limit = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $limit)];
        }

        return $code;
    }

    /**
     * Render a code as PNG bytes.
     *
     * The font list mirrors the Python one (Consolas, Courier New, Arial, Georgia,
     * all of which ship with Windows) and falls back to GD's built-in bitmap font
     * if none of them can be read, so a missing font degrades instead of erroring.
     */
    public function render(string $code): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        $backgroundColor = $this->pickBackground();
        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $this->color($image, $backgroundColor));

        // Noise dots.
        for ($i = 0, $count = random_int(80, 120); $i < $count; $i++) {
            $x = random_int(0, self::WIDTH - 1);
            $y = random_int(0, self::HEIGHT - 1);
            $radius = random_int(1, 2);
            imagefilledellipse(
                $image,
                $x,
                $y,
                $radius * 2,
                $radius * 2,
                $this->color($image, $this->pick($this->dotColors())),
            );
        }

        // Straight interference lines.
        for ($i = 0, $count = random_int(3, 5); $i < $count; $i++) {
            imageline(
                $image,
                random_int(0, self::WIDTH),
                random_int(0, self::HEIGHT),
                random_int(0, self::WIDTH),
                random_int(0, self::HEIGHT),
                $this->color($image, $this->pick($this->lineColors())),
            );
        }

        // Characters, each on its own rotated layer, as Pillow's `rotate(expand=True)`.
        $fontSize = random_int(28, 34);
        $font = $this->font($fontSize);
        $charWidth = intdiv(self::WIDTH, self::LENGTH + 1);
        $startX = intdiv($charWidth, 2);

        foreach (str_split($code) as $index => $character) {
            $x = $startX + $index * $charWidth + random_int(-3, 3);
            $y = random_int(4, max(4, self::HEIGHT - $fontSize - 4));
            $color = $this->color($image, $this->pick($this->textColors()));

            $layer = imagecreatetruecolor($fontSize + 10, $fontSize + 10);
            imagesavealpha($layer, true);
            imagealphablending($layer, false);
            imagefilledrectangle(
                $layer,
                0,
                0,
                $fontSize + 10,
                $fontSize + 10,
                imagecolorallocatealpha($layer, 0, 0, 0, 127),
            );
            imagealphablending($layer, true);

            if ($font === null) {
                imagechar($layer, 5, 2, 2, $character, $color);
            } else {
                imagettftext($layer, $fontSize, 0, 2, $fontSize + 2, $color, $font, $character);
            }

            $rotated = imagerotate($layer, random_int(-15, 15), imagecolorallocatealpha($layer, 0, 0, 0, 127));

            if ($rotated !== false) {
                imagealphablending($image, true);
                imagecopy($image, $rotated, $x, $y, 0, 0, imagesx($rotated), imagesy($rotated));
                imagedestroy($rotated);
            }

            imagedestroy($layer);
        }

        // Sine-wave interference.
        for ($i = 0, $count = random_int(2, 3); $i < $count; $i++) {
            $amplitude = random_int(3, 8);
            $frequency = random_int(2, 6) / 100;
            $phase = random_int(0, 628) / 100;
            $previous = null;

            for ($x = 0; $x < self::WIDTH; $x += 2) {
                $y = (int) round(self::HEIGHT / 2 + $amplitude * sin($frequency * $x + $phase));

                if ($previous !== null) {
                    imageline($image, $previous[0], $previous[1], $x, $y, $this->color($image, $this->pick($this->lineColors())));
                }

                $previous = [$x, $y];
            }
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** Store a freshly generated code in the session, with its timestamp. */
    public function store(Store|Session $session, string $code): void
    {
        $session->put(self::SESSION_KEY, $code);
        $session->put(self::SESSION_TS_KEY, time());
        $session->put(self::SESSION_ATTEMPTS_KEY, 0);
    }

    /** Seconds of life left on the stored code (0 when there is none). */
    public function remainingSeconds(Session $session): int
    {
        $storedAt = (int) $session->get(self::SESSION_TS_KEY, 0);

        if ($storedAt === 0) {
            return 0;
        }

        return max(0, $this->ttlSeconds() - (time() - $storedAt));
    }

    /**
     * Whether the stored code is past its lifetime — **without clearing it**.
     *
     * The login endpoint asks this *before* calling {@see validate()}, because
     * validation destroys the evidence and every rejection would then look like a
     * mismatch.
     */
    public function expired(Session $session): bool
    {
        if (! $session->get(self::SESSION_KEY)) {
            return false;
        }

        return $this->remainingSeconds($session) <= 0;
    }

    /** Whether a code is currently stored (used for the `has_captcha` flag). */
    public function hasCode(Session $session): bool
    {
        return (bool) $session->get(self::SESSION_KEY);
    }

    /**
     * Validate a submitted code.
     *
     * @return array{valid: bool, message: string}
     */
    public function validate(Session $session, string $submitted): array
    {
        $stored = $session->get(self::SESSION_KEY);
        $attempts = (int) $session->get(self::SESSION_ATTEMPTS_KEY, 0);

        if (! $stored) {
            return [
                'valid' => false,
                'message' => 'کد امنیتی یافت نشد. لطفاً صفحه را مجدداً بارگذاری کنید.',
            ];
        }

        if ($this->remainingSeconds($session) <= 0) {
            $this->forget($session);

            return [
                'valid' => false,
                'message' => 'کد امنیتی منقضی شده است. لطفاً کد جدیدی دریافت کنید.',
            ];
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->forget($session);

            return [
                'valid' => false,
                'message' => 'تعداد تلاش‌ها بیش از حد مجاز است. کد امنیتی جدیدی دریافت کنید.',
            ];
        }

        if (mb_strtoupper(trim($submitted)) !== mb_strtoupper((string) $stored)) {
            $remaining = self::MAX_ATTEMPTS - ($attempts + 1);

            if ($remaining > 0) {
                $session->put(self::SESSION_ATTEMPTS_KEY, $attempts + 1);

                return [
                    'valid' => false,
                    'message' => "کد امنیتی اشتباه است. {$remaining} تلاش باقی مانده.",
                ];
            }

            $this->forget($session);

            return [
                'valid' => false,
                'message' => 'کد امنیتی اشتباه است. کد جدیدی دریافت کنید.',
            ];
        }

        $this->forget($session);

        return ['valid' => true, 'message' => ''];
    }

    /** Destroy the stored code, its timestamp and its attempt counter. */
    public function forget(Session $session): void
    {
        $session->forget([self::SESSION_KEY, self::SESSION_TS_KEY, self::SESSION_ATTEMPTS_KEY]);
    }

    /**
     * The reason a submission failed, learned before validation clears the session.
     *
     * Mirrors the Python inline expression:
     * `expired if expired else ("missing" if not present else "mismatch")`.
     */
    public function failureReason(Session $session, bool $wasPresent, bool $wasExpired): string
    {
        if ($wasExpired) {
            return self::REASON_EXPIRED;
        }

        return $wasPresent ? self::REASON_MISMATCH : self::REASON_MISSING;
    }

    /** @return array<int, array{0: int, 1: int, 2: int}> */
    private function backgroundColors(): array
    {
        return [[240, 248, 255], [245, 250, 255], [238, 244, 250], [242, 247, 252]];
    }

    /** @return array<int, array{0: int, 1: int, 2: int}> */
    private function textColors(): array
    {
        return [[30, 64, 75], [15, 23, 42], [30, 58, 138], [55, 48, 107], [17, 94, 89]];
    }

    /** @return array<int, array{0: int, 1: int, 2: int}> */
    private function lineColors(): array
    {
        return [[180, 200, 220], [170, 190, 210], [160, 185, 205], [148, 163, 184]];
    }

    /** @return array<int, array{0: int, 1: int, 2: int}> */
    private function dotColors(): array
    {
        return [[180, 200, 220], [160, 180, 200], [148, 163, 184], [120, 140, 160]];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function pickBackground(): array
    {
        return $this->pick($this->backgroundColors());
    }

    /** @param array<int, array{0: int, 1: int, 2: int}> $colors */
    private function pick(array $colors): array
    {
        return $colors[array_rand($colors)];
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private function color(\GdImage $image, array $rgb): int
    {
        return (int) imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * A TrueType font path, or null when none can be read.
     *
     * The same candidate list as the Python implementation, tried in the same
     * order; a bitmap glyph is used as the fallback so the CAPTCHA is always
     * legible rather than broken.
     */
    private function font(int $size): ?string
    {
        foreach (['consola.ttf', 'cour.ttf', 'arial.ttf', 'georgia.ttf'] as $candidate) {
            $path = 'C:/Windows/Fonts/'.$candidate;

            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}
