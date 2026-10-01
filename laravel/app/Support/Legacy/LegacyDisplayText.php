<?php

namespace App\Support\Legacy;

/**
 * `app/core/validation.py` — the check that stops stored markup reaching the displays.
 *
 * A department name typed into the kiosk is broadcast to every TV on the LAN, echoed
 * back by `/api/queue/list`, rendered in the admin console and printed on a label.  It
 * was the one piece of text in the call system that an unauthenticated caller could put
 * anywhere, so the Python rejected markup on input as a second line of defence behind
 * escaping at the render layer.  Both halves are live and both are ported.
 *
 * The messages are the contract: they are shown to the operator verbatim, and the
 * call-system module converts a `ValueError` into `HTTPException(422, str(exc))` — so
 * each message is the `detail` of a 422, not an internal exception string.  That is why
 * the throw lives here rather than at each call site.
 */
final class LegacyDisplayText
{
    /**
     * Iranian and Latin letters, digits, spaces and a conservative punctuation set.
     *
     * Written with `\x{…}` so the character blocks apply to characters and not bytes —
     * the same thing the Python's `re` module did with `str`.  U+200C (ZWNJ) and
     * U+200F (RLM) are allowed because Persian text contains them; `\u200c` is what
     * makes «نمونه‌گیری» a single word.
     */
    private const ALLOWED = '/^[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}'
        .'A-Za-z0-9 .,_\/()\x{200c}\x{200f}]+$/u';

    /** `[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]` — tabs and newlines survive, the rest do not. */
    private const CONTROL_CHARS = '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/';

    /**
     * Validate a human-readable field, or refuse the request.
     *
     * @throws LegacyHttpException 422 with the operator-facing Persian message.
     */
    public static function clean(
        mixed $value,
        int $maxLength,
        string $field = 'value',
        bool $required = false,
        bool $allowPunctuation = true,
    ): string {
        $text = self::stripControl((string) ($value ?? ''));
        $text = trim($text);

        if ($text === '') {
            if ($required) {
                throw LegacyHttpException::detail(422, "{$field} الزامی است.");
            }

            return '';
        }

        // `len()` counted characters, not bytes, and this text is Persian.
        if (mb_strlen($text) > $maxLength) {
            throw LegacyHttpException::detail(422, "{$field} نباید بیش از {$maxLength} کاراکتر باشد.");
        }

        // `"&" in text and "&amp;" not in text` — an already-escaped entity is let
        // through, which is what the Python's precedence expressed.
        if (str_contains($text, '<') || str_contains($text, '>')
            || (str_contains($text, '&') && ! str_contains($text, '&amp;'))) {
            throw LegacyHttpException::detail(422, "{$field} شامل کاراکترهای غیرمجاز است.");
        }

        if ($allowPunctuation && preg_match(self::ALLOWED, $text) !== 1) {
            throw LegacyHttpException::detail(422, "{$field} شامل کاراکترهای غیرمجاز است.");
        }

        return $text;
    }

    /**
     * `clean_display_text(value, max_length=100, field="بخش") or "نمونه‌گیری"`.
     *
     * An empty department is not an error: the call system has always had a default,
     * and a call with no department would render an empty line on the TV.
     */
    public static function department(mixed $value): string
    {
        $cleaned = self::clean($value, maxLength: 100, field: 'بخش');

        return $cleaned === '' ? 'نمونه‌گیری' : $cleaned;
    }

    /** `CONTROL_CHARS.sub("", value)` — log/HTML injection and NUL truncation. */
    public static function stripControl(string $value): string
    {
        return preg_replace(self::CONTROL_CHARS, '', $value) ?? '';
    }
}
