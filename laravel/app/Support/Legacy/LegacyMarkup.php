<?php

namespace App\Support\Legacy;

use ValueError;

/**
 * Markup rejection for employee-supplied free text — a port of `reject_markup()`
 * and `strip_control()` in `app/core/validation.py`.
 *
 * The Python validation module exists because several write paths accepted
 * unauthenticated or administrator-supplied text (names, departments, queue
 * labels) without length or content checks, and that text is later rendered by
 * the admin UI.  Escaping at the render layer is the primary control; rejecting
 * markup on input is the defence in depth.  The two functions the write handlers
 * actually call are ported here:
 *
 *     strip_control(value)          — remove ASCII control characters
 *     reject_markup(value, …)       — reject `<`, `>` and NUL, enforce a length
 *
 * `reject_markup` deliberately allows the full range of punctuation (quotes,
 * colons, Persian punctuation) so it can be applied to employee-written
 * descriptions without changing accepted input — unlike `clean_display_text`,
 * which restricts the whole character set.  The distinction matters: the leave,
 * overtime and overtime-time fields carry Persian prose with `،` and `:` that
 * `clean_display_text` would reject.
 *
 * The length and content failures raise `ValueError` with the same Persian
 * messages the Python raised, because the handlers catch `ValueError` and answer
 * `{"success": false, "message": str(exc)}` — the message is the operator-facing
 * text, not an internal detail.
 */
final class LegacyMarkup
{
    /**
     * ASCII control characters (log/report injection, NUL truncation).
     *
     * `[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]` — note that `\t`, `\n` and `\r`
     * (U+0009–U+000D) are **not** removed, exactly as in the Python.
     */
    private const CONTROL_CHARS = '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/';

    /**
     * Characters that turn stored text into markup.
     *
     * Rejecting them on the way in is defence in depth: the render layer
     * escapes (`dom-escape.js`), but a field that can never contain markup
     * cannot become a stored-XSS primitive if some future screen forgets to
     * escape.
     */
    private const MARKUP_CHARS = ['<', '>', "\x00"];

    /**
     * Remove ASCII control characters (log/report injection, NUL truncation).
     */
    public static function stripControl(?string $value): string
    {
        return (string) preg_replace(self::CONTROL_CHARS, '', $value ?? '');
    }

    /**
     * Reject angle brackets / NUL in free text while keeping normal punctuation.
     *
     * @param  int  $maxLength  The field's maximum length, in characters.
     * @param  string  $field  The Persian field name used in the messages.
     * @param  bool  $required  Whether an empty value is itself a failure.
     *
     * @throws ValueError with the operator-facing Persian message.
     */
    public static function rejectMarkup(mixed $value, int $maxLength, string $field, bool $required = false): string
    {
        $text = trim(self::stripControl(is_string($value) ? $value : strval($value)));

        if ($text === '') {
            if ($required) {
                throw new ValueError("{$field} الزامی است.");
            }

            return '';
        }

        if (mb_strlen($text) > $maxLength) {
            throw new ValueError("{$field} نباید بیش از {$maxLength} کاراکتر باشد.");
        }

        foreach (self::MARKUP_CHARS as $char) {
            if (str_contains($text, $char)) {
                throw new ValueError("{$field} شامل کاراکترهای غیرمجاز است.");
            }
        }

        return $text;
    }
}
