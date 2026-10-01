<?php

namespace App\Support\Tickets;

use App\Support\Legacy\LegacyBody;
use App\Support\Legacy\LegacyValidationException;
use App\Support\Legacy\LegacyWhitespace;

/**
 * The three pydantic models `app/api/routes/ticketing.py` validates its bodies
 * against — `TicketCreate`, `MessageCreate` and `TicketUpdate` — error list and
 * all.
 *
 * `LegacyBody` already reproduces the model-level gate (a missing, unparseable or
 * non-object body against `loc: ["body"]` alone) and the four string failures
 * (`missing`, `string_type`, `string_too_short`, `string_too_long`, with the
 * singular `1 character` and `input` echoing the whole body for a missing field).
 * What it cannot express is the two things these models add, both verified
 * against the running server (FastAPI 0.141.1 / pydantic 2.13.4):
 *
 * * **The `strip_text` field validator.**  Pydantic runs a field's constraints
 *   *before* an after-mode `field_validator`, so the order is: `min_length` and
 *   `max_length` on the raw string, then `.replace("\x00", "").strip()`, then the
 *   emptiness check.  A two-space `recipient_username` passes `min_length=1` and
 *   is then refused by the validator — a `value_error` whose `msg` carries the
 *   Persian message and whose `ctx.error` serializes as `{}` (FastAPI's
 *   `jsonable_encoder` has no representation for a `ValueError`):
 *
 *   ```json
 *   {"type":"value_error","loc":["body","recipient_username"],
 *    "msg":"Value error, این فیلد الزامی است.","input":"  ","ctx":{"error":{}}}
 *   ```
 *
 * * **`category_id: int | None`.**  Pydantic's lax integer parse accepts more
 *   than `ctype_digit()`: a numeric string (`"5"`), an integral float (`2.0`),
 *   a bool (`true` → 1), underscores between digits (`"1_0"` → 10) and a
 *   trailing `.0` (`"1.0"` → 1).  A fractional float is `int_from_float`, a
 *   non-numeric string is `int_parsing`, a list or object is `int_type`, and a
 *   value under the `ge=1` bound is `greater_than_equal` echoing the value **as
 *   received** — the string `"0"`, not the integer `0`.
 *
 * **An absent field and an explicit `null` are different requests**, and the two
 * optional string kinds disagree about `null`: `priority: str = Field(default=…)`
 * answers `string_type` for an explicit `null`, while `status: str | None = None`
 * accepts it as `null`.  The `nullable` flag below is that distinction.
 *
 * Unknown keys are ignored, which is pydantic's default.
 */
final class TicketPayload
{
    /** `TicketCreate`'s three validators all raise the same message. */
    private const FIELD_REQUIRED = 'این فیلد الزامی است.';

    /** `MessageCreate.strip_body`. */
    private const MESSAGE_REQUIRED = 'متن پیام الزامی است.';

    /** `category_id: int | None = Field(default=None, ge=1)`. */
    private const CATEGORY_MIN = 1;

    /**
     * `class TicketCreate(BaseModel)` — `POST /api/tickets`.
     *
     * @return array{recipient_username: string, subject: string, body: string, priority: string, category_id: int|null}
     *
     * @throws LegacyValidationException
     */
    public static function create(string $raw): array
    {
        // The model-level gate: a missing, unparseable or non-object body is
        // refused against `loc: ["body"]` before any field is looked at.
        LegacyBody::validateJson($raw, []);

        /** @var object $decoded */
        $decoded = json_decode(trim($raw));
        $fields = (array) $decoded;
        $errors = [];

        $recipient = self::textField($fields, 'recipient_username', required: true, min: 1, max: 255, stripMessage: self::FIELD_REQUIRED, modelInput: $decoded, errors: $errors);
        $subject = self::textField($fields, 'subject', required: true, min: 2, max: 180, stripMessage: self::FIELD_REQUIRED, modelInput: $decoded, errors: $errors);
        $body = self::textField($fields, 'body', required: true, min: 2, max: 4000, stripMessage: self::FIELD_REQUIRED, modelInput: $decoded, errors: $errors);

        // `priority: str = Field(default="normal", max_length=16)` — not
        // nullable, so an explicit `null` is a `string_type` failure.
        $priority = self::textField($fields, 'priority', required: false, min: null, max: 16, stripMessage: null, modelInput: $decoded, errors: $errors);
        $priority ??= 'normal';

        $categoryId = self::categoryField($fields, $errors);

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        return [
            'recipient_username' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'priority' => $priority,
            'category_id' => $categoryId,
        ];
    }

    /**
     * `class MessageCreate(BaseModel)` — `POST /api/tickets/{ticket_id}/messages`.
     *
     * @return array{body: string, visibility: string}
     *
     * @throws LegacyValidationException
     */
    public static function message(string $raw): array
    {
        LegacyBody::validateJson($raw, []);

        /** @var object $decoded */
        $decoded = json_decode(trim($raw));
        $fields = (array) $decoded;
        $errors = [];

        $body = self::textField($fields, 'body', required: true, min: 1, max: 4000, stripMessage: self::MESSAGE_REQUIRED, modelInput: $decoded, errors: $errors);

        // `visibility: str = Field(default="public", max_length=16)`.
        $visibility = self::textField($fields, 'visibility', required: false, min: null, max: 16, stripMessage: null, modelInput: $decoded, errors: $errors);
        $visibility ??= 'public';

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        return [
            'body' => $body,
            'visibility' => $visibility,
        ];
    }

    /**
     * `class TicketUpdate(BaseModel)` — `PATCH /api/tickets/{ticket_id}`.
     *
     * Every field is `X | None = None`, so an absent field and an explicit `null`
     * are the same accepted `null`, and `{}` is a valid body.
     *
     * @return array{status: string|null, priority: string|null, category_id: int|null, assigned_to: string|null}
     *
     * @throws LegacyValidationException
     */
    public static function update(string $raw): array
    {
        LegacyBody::validateJson($raw, []);

        /** @var object $decoded */
        $decoded = json_decode(trim($raw));
        $fields = (array) $decoded;
        $errors = [];

        $status = self::textField($fields, 'status', required: false, nullable: true, min: null, max: 32, stripMessage: null, modelInput: $decoded, errors: $errors);
        $priority = self::textField($fields, 'priority', required: false, nullable: true, min: null, max: 16, stripMessage: null, modelInput: $decoded, errors: $errors);
        $categoryId = self::categoryField($fields, $errors);
        $assignedTo = self::textField($fields, 'assigned_to', required: false, nullable: true, min: null, max: 255, stripMessage: null, modelInput: $decoded, errors: $errors);

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        return [
            'status' => $status,
            'priority' => $priority,
            'category_id' => $categoryId,
            'assigned_to' => $assignedTo,
        ];
    }

    /**
     * One declared string field, in pydantic's constraint order.
     *
     * `min_length` and `max_length` run on the raw string and are reported before
     * the `strip_text` validator, which is pydantic's after-mode order and is
     * observable: `"  "` passes `min_length=1` and is then refused by the
     * validator, where enforcing the minimum after stripping would have produced
     * `string_too_short` instead.
     *
     * @param  array<string, mixed>  $fields  The decoded body.
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place.
     * @param  string|null  $stripMessage  The validator's message when the
     *                                     stripped value is empty, or `null`
     *                                     when the field has no validator.
     * @return string|null The accepted value, or `null` when the field is absent
     *                     (and not required) or was refused.
     */
    private static function textField(
        array $fields,
        string $name,
        bool $required,
        bool $nullable = false,
        ?int $min = null,
        ?int $max = null,
        ?string $stripMessage = null,
        ?object $modelInput = null,
        array &$errors = [],
    ): ?string {
        if (! array_key_exists($name, $fields)) {
            if ($required) {
                // `input` is the model's input — the whole body — not the field's
                // value.  Verified against the running server.
                $errors[] = [
                    'type' => 'missing',
                    'loc' => ['body', $name],
                    'msg' => 'Field required',
                    'input' => $modelInput,
                ];
            }

            return null;
        }

        $value = $fields[$name];

        // `str | None` accepts an explicit `null`; a plain `str` (priority,
        // visibility) answers `string_type` for one.
        if ($value === null && $nullable) {
            return null;
        }

        if (! is_string($value)) {
            $errors[] = [
                'type' => 'string_type',
                'loc' => ['body', $name],
                'msg' => 'Input should be a valid string',
                'input' => $value,
            ];

            return null;
        }

        $length = mb_strlen($value);

        if ($min !== null && $length < $min) {
            $errors[] = [
                'type' => 'string_too_short',
                'loc' => ['body', $name],
                'msg' => 'String should have at least '.$min.' '.self::plural($min),
                'input' => $value,
                'ctx' => ['min_length' => $min],
            ];

            return null;
        }

        if ($max !== null && $length > $max) {
            $errors[] = [
                'type' => 'string_too_long',
                'loc' => ['body', $name],
                'msg' => 'String should have at most '.$max.' '.self::plural($max),
                'input' => $value,
                'ctx' => ['max_length' => $max],
            ];

            return null;
        }

        if ($stripMessage !== null) {
            $stripped = LegacyWhitespace::strip(str_replace("\x00", '', $value));

            if ($stripped === '') {
                $errors[] = [
                    'type' => 'value_error',
                    'loc' => ['body', $name],
                    'msg' => 'Value error, '.$stripMessage,
                    'input' => $value,
                    // FastAPI's `jsonable_encoder` has no representation for the
                    // `ValueError` itself, so `ctx.error` reaches the wire as an
                    // empty object — captured from the running server.
                    'ctx' => ['error' => new \stdClass],
                ];

                return null;
            }

            return $stripped;
        }

        return $value;
    }

    /**
     * `category_id: int | None = Field(default=None, ge=1)` — pydantic's lax
     * integer parse.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place.
     */
    private static function categoryField(array $fields, array &$errors): ?int
    {
        if (! array_key_exists('category_id', $fields)) {
            return null;
        }

        $original = $fields['category_id'];

        // An explicit `null` is the same as absent for `int | None`.
        if ($original === null) {
            return null;
        }

        if (is_bool($original)) {
            // Pydantic coerces a bool to an integer in lax mode: true → 1.
            $value = $original ? 1 : 0;
        } elseif (is_int($original)) {
            $value = $original;
        } elseif (is_float($original)) {
            if (is_nan($original) || is_infinite($original) || floor($original) != $original) {
                $errors[] = [
                    'type' => 'int_from_float',
                    'loc' => ['body', 'category_id'],
                    'msg' => 'Input should be a valid integer, got a number with a fractional part',
                    'input' => $original,
                ];

                return null;
            }

            $value = $original > PHP_INT_MAX ? PHP_INT_MAX : ($original < PHP_INT_MIN ? PHP_INT_MIN : (int) $original);
        } elseif (is_string($original)) {
            $value = self::parseIntString($original, $errors);

            if ($value === null) {
                return null;
            }
        } else {
            $errors[] = [
                'type' => 'int_type',
                'loc' => ['body', 'category_id'],
                'msg' => 'Input should be a valid integer',
                'input' => $original,
            ];

            return null;
        }

        if ($value < self::CATEGORY_MIN) {
            $errors[] = [
                'type' => 'greater_than_equal',
                'loc' => ['body', 'category_id'],
                'msg' => 'Input should be greater than or equal to '.self::CATEGORY_MIN,
                // The value **as received**: the string `"0"`, not the integer.
                'input' => $original,
                'ctx' => ['ge' => self::CATEGORY_MIN],
            ];

            return null;
        }

        return $value;
    }

    /**
     * A JSON string as pydantic's lax integer parser reads it.
     *
     * Whitespace is stripped first (Python's `int()` rule), then the grammar is
     * `[+-]? digits (_? digits)* (\. 0+)?` — so `" 5"`, `"1_0"`, `"+5"`,
     * `"05"`, `"5 "` and `"5.0"` are integers, while `"0x10"`, `""`, `"  "`,
     * `"1e3"`, `"1."`, `".5"` and `"5_"` are not.  Every acceptance and refusal
     * above was captured from the running server.
     *
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place.
     */
    private static function parseIntString(string $value, array &$errors): ?int
    {
        $trimmed = LegacyWhitespace::strip($value);

        if (preg_match('/^[+-]?[0-9](?:_?[0-9])*(?:\.(?:0+))?$/', $trimmed) !== 1) {
            $errors[] = [
                'type' => 'int_parsing',
                'loc' => ['body', 'category_id'],
                'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                'input' => $value,
            ];

            return null;
        }

        $negative = $trimmed[0] === '-';
        $digits = ltrim($trimmed, '+-');

        $dot = strpos($digits, '.');

        if ($dot !== false) {
            $digits = substr($digits, 0, $dot);
        }

        $digits = ltrim(str_replace('_', '', $digits), '0');
        $digits = $digits === '' ? '0' : $digits;

        // Python integers are unbounded and PHP's are not.  Saturate, so the
        // value lands outside every row and the request is answered 404 — the
        // same refusal `LegacyPath` makes for a path segment.
        $limit = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return $negative ? PHP_INT_MIN : PHP_INT_MAX;
        }

        return $negative ? -(int) $digits : (int) $digits;
    }

    /** Pydantic pluralises its length messages: `1 character`, `2 characters`. */
    private static function plural(int $bound): string
    {
        return $bound === 1 ? 'character' : 'characters';
    }
}
