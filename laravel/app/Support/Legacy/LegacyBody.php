<?php

namespace App\Support\Legacy;

/**
 * A pydantic request model — FastAPI's **body** validation, with its own `loc` prefix.
 *
 * `LegacyQuery` covers `Query(...)` parameters, whose errors are reported as
 * `"loc": ["query", name]`.  A field on a pydantic model is reported as
 * `"loc": ["body", name]`, and the failure types are the *string* ones rather than the
 * numeric ones:
 *
 * ```json
 * {"detail":[{"type":"string_too_long","loc":["body","reception_number"],
 *             "msg":"String should have at most 50 characters",
 *             "input":"…","ctx":{"max_length":50}}]}
 * ```
 *
 * This is not pedantry.  The model is validated **before the handler body runs**, so a
 * reception number longer than 50 characters is rejected by pydantic and never reaches
 * `_validate_reception_number` — the handler's own `شماره پذیرش بیش از حد طولانی است.` is
 * only reachable for a value that is at most 50 characters *before* trimming.  A port that
 * kept only the handler's check would answer the wrong message, and a client reading
 * `detail[0].type` would see `string_too_long` on one server and a bare string on the
 * other.
 *
 * **Constraints run before the transform.**  That is pydantic's `mode="after"` order and it
 * is observable: `CallInput.clean_number` strips its value, but a two-space string passes
 * `min_length=1` (it is two characters long) and only becomes `""` afterwards — so the
 * handler's `لطفاً شماره پذیرش را وارد کنید.` fires, where enforcing the minimum after
 * trimming would have produced pydantic's `string_too_short` instead.
 *
 * The transform is passed as a list of field names rather than as a callable, because the
 * only transform in this module is `.strip()` and a closure in a declaration reads as
 * configuration without being one.
 */
final class LegacyBody
{
    /** @var array<int, array<string, mixed>> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $body  The model's fields, for lookup.
     * @param  mixed  $modelInput  What a *field-level* failure echoes back as `input` — the
     *                             model's own input, which is the whole body and not the
     *                             field's value.  Kept as the decoded object so an empty one
     *                             stays `{}` rather than degrading to `[]`.
     */
    private function __construct(
        private readonly array $body,
        private readonly mixed $modelInput,
    ) {}

    /**
     * Validate a **required pydantic model** from the raw request body.
     *
     * A model parameter is not a field: FastAPI checks that the body exists and is an
     * object *before* it looks at any field, and it reports those three failures against
     * `loc: ["body"]` alone.  All three were captured from the running server:
     *
     * ```json
     * // no body at all
     * {"type":"missing","loc":["body"],"msg":"Field required","input":null}
     * // a JSON array where an object was declared
     * {"type":"model_attributes_type","loc":["body"],
     *  "msg":"Input should be a valid dictionary or object to extract fields from","input":[]}
     * // unparseable JSON
     * {"type":"json_invalid","loc":["body",0],"msg":"JSON decode error",
     *  "input":{},"ctx":{"error":"Expecting value"}}
     * ```
     *
     * Validating the fields directly would answer `loc: ["body","reception_number"]` for all
     * three — a different `type` *and* a different `loc`, on the request shape an attacker is
     * most likely to send.
     *
     * **A known, deliberate difference:** `ctx.error` is pydantic's message from Python's
     * `json.JSONDecodeError.msg` ("Expecting value", "Expecting property name enclosed in
     * double quotes", …).  PHP's parser reports the same conditions as "Syntax error", and
     * mapping the two exhaustively would be inventing a table for an error string no caller
     * can branch on — `type`, `loc`, `msg` and `input` all match, and `ctx.error` is
     * documentation for a human reading a log.  The two PHP errors with an obvious Python
     * wording are mapped; the rest pass PHP's message through.
     *
     * @param  array<string, LegacyParam>  $spec
     * @param  array<int, string>  $trim
     * @return array<string, mixed>
     *
     * @throws LegacyValidationException
     */
    public static function validateJson(string $rawBody, array $spec, array $trim = []): array
    {
        $body = trim($rawBody);

        if ($body === '') {
            throw new LegacyValidationException([self::bodyError('missing', 'Field required', null)]);
        }

        // Decoded to **objects**, not to associative arrays.  An empty JSON object would
        // otherwise decode to an empty PHP array, and `json_encode([])` is `[]`: a body of
        // `{}` would be answered as a JSON array (`model_attributes_type` on `loc: ["body"]`)
        // instead of a model that is missing its fields, and a field holding `{}` would echo
        // `[]` back.  Both were live divergences.  The cast to an array happens only where
        // the field lookup needs one.
        $decoded = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LegacyValidationException([self::bodyError(
                'json_invalid',
                'JSON decode error',
                new \stdClass,
                ['error' => self::pythonJsonError(json_last_error())],
                [0],
            )]);
        }

        // A body of the literal `null` is "no body" to FastAPI — it answers the same
        // `missing` as an empty request, where a type error would be the other reading and
        // is what the port did until both servers were compared.
        if ($decoded === null) {
            throw new LegacyValidationException([self::bodyError('missing', 'Field required', null)]);
        }

        if (! is_object($decoded)) {
            throw new LegacyValidationException([self::bodyError(
                'model_attributes_type',
                'Input should be a valid dictionary or object to extract fields from',
                $decoded,
            )]);
        }

        return self::validate((array) $decoded, $spec, $trim, $decoded);
    }

    /**
     * A model-level error: `loc` is just the body.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<int, mixed>  $locSuffix  `[0]` for `json_invalid`, which points at the
     *                                        offset in the document.
     */
    private static function bodyError(
        string $type,
        string $message,
        mixed $input,
        array $ctx = [],
        array $locSuffix = [],
    ): array {
        $error = ['type' => $type, 'loc' => array_merge(['body'], $locSuffix), 'msg' => $message];

        // `json_invalid` puts `input` between `msg` and `ctx`; the others carry no `ctx`.
        $error['input'] = $input;

        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }

    /** The two PHP parser errors whose Python wording is unambiguous. */
    private static function pythonJsonError(int $error): string
    {
        return match ($error) {
            JSON_ERROR_SYNTAX => 'Expecting value',
            JSON_ERROR_DEPTH => 'Maximum recursion depth exceeded',
            default => json_last_error_msg(),
        };
    }

    /**
     * pydantic pluralises its length messages: `1 character`, `2 characters`.
     *
     * Verified against the running server — `min_length=1` really does answer "String should
     * have at least 1 character", singular, and a naive `characters` would be wrong for the
     * one bound the kiosk actually hits.
     */
    private static function plural(int $bound): string
    {
        return $bound === 1 ? 'character' : 'characters';
    }

    /**
     * Validate a decoded JSON object against a declaration.
     *
     * @param  array<string, mixed>  $body  The decoded body — already known to be an object.
     * @param  array<string, LegacyParam>  $spec  In declaration order, which is the order
     *                                            FastAPI reports errors in.
     * @param  array<int, string>  $trim  Fields whose value is stripped **after** the
     *                                    length constraints, as `clean_number` did.
     * @param  mixed  $modelInput  The body as it was received, for the `input` a missing
     *                             field echoes.  Defaults to the field map itself, which is
     *                             what a caller passing a literal array means.
     * @return array<string, mixed>
     *
     * @throws LegacyValidationException
     */
    public static function validate(array $body, array $spec, array $trim = [], mixed $modelInput = null): array
    {
        $validator = new self($body, $modelInput ?? $body);

        return $validator->run($spec, $trim);
    }

    private function run(array $spec, array $trim): array
    {
        $values = [];

        foreach ($spec as $name => $param) {
            if (! array_key_exists($name, $this->body)) {
                if ($param->required) {
                    // `input` is the model's input, not `null` and not the field's value —
                    // verified against the running server: a body of `{"department":"x"}`
                    // answers `input: {"department":"x"}`.  This is the one error whose
                    // input is the body rather than the field.
                    $this->errors[] = $this->error('missing', $name, 'Field required', $this->modelInput);

                    continue;
                }

                $values[$name] = $param->default;

                continue;
            }

            $raw = $this->body[$name];

            // `Optional[str] = None` and an explicit `null` are the same thing to
            // pydantic, and the handlers treat them identically.
            if ($raw === null && $param->nullable) {
                $values[$name] = null;

                continue;
            }

            // A declared `str` gets no coercion: a number or an explicit `null` where a
            // string was declared is a `string_type` failure, which is what pydantic v2
            // reports for both.
            if (! is_string($raw)) {
                $this->errors[] = $this->error('string_type', $name, 'Input should be a valid string', $raw);

                continue;
            }

            if (! $this->withinLength($name, $raw, $param)) {
                continue;
            }

            $values[$name] = in_array($name, $trim, true) ? trim($raw) : $raw;
        }

        if ($this->errors !== []) {
            throw new LegacyValidationException($this->errors);
        }

        return $values;
    }

    /**
     * Both length bounds, recording the failure and answering whether the value passed.
     *
     * `min_length` is reported before `max_length`; they cannot both fail, so the order is
     * only about which one a nonsensical declaration would surface.
     */
    private function withinLength(string $name, string $raw, LegacyParam $param): bool
    {
        // Characters, not bytes — the text is Persian.
        $length = mb_strlen($raw);

        if ($param->minLength !== null && $length < $param->minLength) {
            $this->errors[] = $this->error(
                'string_too_short',
                $name,
                'String should have at least '.$param->minLength.' '.self::plural($param->minLength),
                $raw,
                ['min_length' => $param->minLength],
            );

            return false;
        }

        if ($param->maxLength !== null && $length > $param->maxLength) {
            $this->errors[] = $this->error(
                'string_too_long',
                $name,
                'String should have at most '.$param->maxLength.' '.self::plural($param->maxLength),
                $raw,
                ['max_length' => $param->maxLength],
            );

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $ctx */
    private function error(string $type, string $name, string $message, mixed $input, array $ctx = []): array
    {
        $error = [
            'type' => $type,
            'loc' => ['body', $name],
            'msg' => $message,
            'input' => $input,
        ];

        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }
}
