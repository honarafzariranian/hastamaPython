<?php

namespace App\Support\Legacy;

/**
 * The two pydantic models `app/main.py` validates the overtime status bodies
 * against — `OvertimeUpdateRequest` (`POST /update_overtime_status`) and
 * `OvertimeStatusUpdate` (`POST /update_overtime_Indivisual_status`) — error
 * list and all.
 *
 * Both models have the same shape, an `int` followed by a `str`, so the two
 * static methods below differ only in the integer field's name.  FastAPI
 * validates the model **before** the handler runs, so a refusal here is a 422
 * that precedes the handler's own `_require_admin` — the reason these two
 * routes carry only `legacy.session:optional` and validate in the handler.
 *
 * `LegacyBody` reproduces the model-level gate (a missing, unparseable or
 * non-object body against `loc: ["body"]` alone).  What it cannot express is
 * the `int` field, whose failures were captured from the running server
 * (FastAPI 0.141.1 / pydantic 2.13.4):
 *
 * | input | result |
 * |---|---|
 * | absent | `missing`, `input` echoing the whole body |
 * | `null` | `int_type` — "Input should be a valid integer" |
 * | `true` / `false` | `1` / `0` (pydantic coerces a bool in lax mode) |
 * | `2` | `2` |
 * | `2.0` | `2` (an integral float is accepted) |
 * | `1.5` | `int_from_float` |
 * | `"5"` | `5` (a numeric string is accepted) |
 * | `"abc"` | `int_parsing` |
 * | `[1]` / `{"a":1}` | `int_type` |
 *
 * Unknown keys are ignored, which is pydantic's default.  The two fields are
 * validated in declaration order and **both** failures are reported together.
 */
final class LegacyStatusUpdatePayload
{
    /**
     * `class OvertimeUpdateRequest(BaseModel)` — `POST /update_overtime_status`.
     *
     * @return array{requestId: int, status: string}
     *
     * @throws LegacyValidationException
     */
    public static function overtimeRequest(string $raw): array
    {
        LegacyBody::validateJson($raw, []);

        /** @var object $decoded */
        $decoded = json_decode(trim($raw));
        $fields = (array) $decoded;
        $errors = [];

        $requestId = self::intField($fields, 'requestId', $decoded, $errors);
        $status = self::stringField($fields, 'status', $decoded, $errors);

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        return ['requestId' => $requestId, 'status' => $status];
    }

    /**
     * `class OvertimeStatusUpdate(BaseModel)` — `POST /update_overtime_Indivisual_status`.
     *
     * @return array{id: int, status: string}
     *
     * @throws LegacyValidationException
     */
    public static function overtimeIndividual(string $raw): array
    {
        LegacyBody::validateJson($raw, []);

        /** @var object $decoded */
        $decoded = json_decode(trim($raw));
        $fields = (array) $decoded;
        $errors = [];

        $id = self::intField($fields, 'id', $decoded, $errors);
        $status = self::stringField($fields, 'status', $decoded, $errors);

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        return ['id' => $id, 'status' => $status];
    }

    /**
     * One declared `int` field, in pydantic's lax parsing order.
     *
     * @param  array<string, mixed>  $fields  The decoded body.
     * @param  object  $modelInput  The body as received, for `input` on a
     *                              `missing` failure.
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place.
     */
    private static function intField(array $fields, string $name, object $modelInput, array &$errors): ?int
    {
        if (! array_key_exists($name, $fields)) {
            $errors[] = [
                'type' => 'missing',
                'loc' => ['body', $name],
                'msg' => 'Field required',
                'input' => $modelInput,
            ];

            return null;
        }

        $original = $fields[$name];

        if (is_bool($original)) {
            return $original ? 1 : 0;
        }

        if (is_int($original)) {
            return $original;
        }

        if (is_float($original)) {
            if (is_nan($original) || is_infinite($original) || floor($original) != $original) {
                $errors[] = [
                    'type' => 'int_from_float',
                    'loc' => ['body', $name],
                    'msg' => 'Input should be a valid integer, got a number with a fractional part',
                    'input' => $original,
                ];

                return null;
            }

            return $original > PHP_INT_MAX ? PHP_INT_MAX : ($original < PHP_INT_MIN ? PHP_INT_MIN : (int) $original);
        }

        if (is_string($original)) {
            $value = self::parseIntString($original, $name, $errors);

            if ($value === null) {
                return null;
            }

            return $value;
        }

        // `null`, a list, an object — anything that is not a number or a
        // numeric string.
        $errors[] = [
            'type' => 'int_type',
            'loc' => ['body', $name],
            'msg' => 'Input should be a valid integer',
            'input' => $original,
        ];

        return null;
    }

    /**
     * One declared `str` field.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place.
     */
    private static function stringField(array $fields, string $name, object $modelInput, array &$errors): ?string
    {
        if (! array_key_exists($name, $fields)) {
            $errors[] = [
                'type' => 'missing',
                'loc' => ['body', $name],
                'msg' => 'Field required',
                'input' => $modelInput,
            ];

            return null;
        }

        $value = $fields[$name];

        // A plain `str` is not nullable: an explicit `null` is a `string_type`
        // failure, exactly like a number.
        if (! is_string($value)) {
            $errors[] = [
                'type' => 'string_type',
                'loc' => ['body', $name],
                'msg' => 'Input should be a valid string',
                'input' => $value,
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
     * `"1e3"`, `"1."`, `".5"` and `"5_"` are not.
     *
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place.
     */
    private static function parseIntString(string $value, string $name, array &$errors): ?int
    {
        $trimmed = LegacyWhitespace::strip($value);

        if (preg_match('/^[+-]?[0-9](?:_?[0-9])*(?:\.(?:0+))?$/', $trimmed) !== 1) {
            $errors[] = [
                'type' => 'int_parsing',
                'loc' => ['body', $name],
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
}
