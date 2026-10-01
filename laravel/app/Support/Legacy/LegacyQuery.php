<?php

namespace App\Support\Legacy;

use Illuminate\Http\Request;

/**
 * Declared query parameters, validated the way FastAPI validated them.
 *
 * This exists because the two frameworks disagree about a rejected parameter in a way
 * that is invisible to a unit test and very visible in a browser:
 *
 * * **FastAPI** answers `422 Unprocessable Entity` with
 *   `{"detail":[{"type":…,"loc":["query",…],"msg":…,"input":…,"ctx":…}]}`.  The
 *   validation runs before the handler, so it applies even to a request that has not
 *   authenticated yet.
 * * **Laravel's** `$request->validate()` throws `ValidationException`, which the
 *   exception handler renders as a **`302` redirect back** unless the request sends
 *   `Accept: application/json`.  The legacy front-end calls these endpoints with
 *   `fetch()` defaults and reads the status code, so it would have seen a redirect to
 *   itself instead of the rejection the server has always produced.
 *
 * The fix is not to bend Laravel's validator into FastAPI's body — the parameter
 * constraints are *declared in the Python signature*, so they are ported as a
 * declaration:
 *
 * ```php
 * $params = LegacyQuery::validate($request, [
 *     'page'        => LegacyQuery::int(default: 1, ge: 1),
 *     'per_page'    => LegacyQuery::int(default: 50, ge: 1, le: 200),
 *     'active_only' => LegacyQuery::bool(),
 *     'username'    => LegacyQuery::nullableString(),
 * ]);
 * ```
 *
 * Every `type`/`msg`/`ctx` string is byte-for-byte what the running server answers;
 * each was captured from `127.0.0.1:5000` while this was written.  All parameters are
 * checked before throwing, so a request with two bad values reports both — as FastAPI
 * does — in declaration order.
 */
final class LegacyQuery
{
    /** Pydantic's `bool` literals.  `t`/`y`/`f`/`n` are real: the live server accepts them. */
    private const TRUE_VALUES = ['1', 'true', 'on', 't', 'yes', 'y'];

    private const FALSE_VALUES = ['0', 'false', 'off', 'f', 'no', 'n'];

    /** @var array<int, array<string, mixed>> */
    private array $errors = [];

    /**
     * `[key, value]` pairs, in the order they appeared — `parse_qsl`'s view of the
     * request, not PHP's.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $pairs;

    private function __construct(private readonly Request $request)
    {
        $this->pairs = self::parseQuery((string) $request->server->get('QUERY_STRING', ''));
    }

    /**
     * `urllib.parse.parse_qsl(query_string, keep_blank_values=True)`.
     *
     * The raw `QUERY_STRING` is read rather than `$request->query`, because PHP's parser
     * and Python's disagree about one thing that changes an answer: **bracketed keys**.
     * `?status[]=x` is a parameter named `status[]` to Python and a parameter named
     * `status` holding an array to PHP, so the running server falls back to the default
     * and answers the ordinary waiting list while the port raised `string_type` on an
     * array it should never have seen:
     *
     * ```text
     * ?status[]=x   parse_qsl -> [('status[]', 'x')]   PHP -> status => ['x']
     * ```
     *
     * Everything else the two agree on, and is reproduced deliberately: splitting on `&`
     * only (Python 3.11 no longer treats `;` as a separator, so `a=1;b=2` is one value),
     * `+` decoding to a space, `keep_blank_values` turning a bare `?flag` into `''`, a
     * percent-sequence that is not valid hex passing through untouched (`%ZZ`), and the
     * **last** occurrence of a repeated key winning — which is what `dict(parse_qsl(..))`
     * does.
     *
     * `QUERY_STRING` is the untouched header in production and is set from the untouched
     * URI by `Request::create()` in tests.  `Request::getQueryString()` is deliberately
     * *not* used: it re-encodes and sorts, which would turn `status[]` back into a
     * different string before it could be read.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function parseQuery(string $queryString): array
    {
        $pairs = [];

        if ($queryString === '') {
            return $pairs;
        }

        foreach (explode('&', $queryString) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            $separator = strpos($chunk, '=');

            if ($separator === false) {
                $pairs[] = [urldecode($chunk), ''];

                continue;
            }

            $pairs[] = [
                urldecode(substr($chunk, 0, $separator)),
                urldecode(substr($chunk, $separator + 1)),
            ];
        }

        return $pairs;
    }

    /**
     * The last value for an **exact** key, or `null` when the key is not there at all.
     *
     * The distinction is the one `Query(...)` turns on: absent and present-but-empty are
     * different requests.
     */
    private function lookup(string $name): ?string
    {
        $found = null;

        foreach ($this->pairs as [$key, $value]) {
            if ($key === $name) {
                $found = $value;
            }
        }

        return $found;
    }

    // ── Declaration ──────────────────────────────────────────────────────────

    /** `page: int = Query(1, ge=1)` */
    public static function int(int $default = 0, ?int $ge = null, ?int $le = null): LegacyParam
    {
        return new LegacyParam(LegacyParam::INT, default: $default, ge: $ge, le: $le);
    }

    /** `q: str = Query("")`, optionally `Query("", max_length=100)`. */
    public static function string(string $default = '', ?int $maxLength = null, ?int $minLength = null): LegacyParam
    {
        return new LegacyParam(
            LegacyParam::STRING,
            default: $default,
            minLength: $minLength,
            maxLength: $maxLength,
        );
    }

    /**
     * `username: Optional[str] = None`.
     *
     * Absent is `null`; present-but-empty is `''`.  The two are different values here,
     * which is what lets a handled endpoint answer its own "not provided" body.
     */
    public static function nullableString(?int $maxLength = null, ?int $minLength = null): LegacyParam
    {
        return new LegacyParam(
            LegacyParam::STRING,
            nullable: true,
            default: null,
            minLength: $minLength,
            maxLength: $maxLength,
        );
    }

    /** `username: str = Query(...)` — required to be *present*, not to be non-empty. */
    public static function requiredString(?int $maxLength = null, ?int $minLength = null): LegacyParam
    {
        return new LegacyParam(
            LegacyParam::STRING,
            required: true,
            default: '',
            minLength: $minLength,
            maxLength: $maxLength,
        );
    }

    /** `refresh: bool = Query(False)` */
    public static function bool(bool $default = false): LegacyParam
    {
        return new LegacyParam(LegacyParam::BOOL, default: $default);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    /**
     * Resolve and validate a declaration.
     *
     * @param  array<string, LegacyParam>  $spec  In declaration order — the order
     *                                            FastAPI reports errors in.
     * @return array<string, mixed>
     *
     * @throws LegacyValidationException when any parameter is rejected.
     */
    public static function validate(Request $request, array $spec): array
    {
        $validator = new self($request);

        return $validator->run($spec);
    }

    private function run(array $spec): array
    {
        $values = [];

        foreach ($spec as $name => $param) {
            // **Presence**, not value, decides `missing`.
            //
            // `?username=` and no `username` at all are different requests: `Query(...)`
            // requires the parameter to be there, and the handler has its own body for
            // the empty string.  `lookup()` answers `''` for the first and `null` for the
            // second, which is exactly the split — and it reads the raw query string, so
            // `?username[]=x` counts as a parameter *named* `username[]`, not as a
            // malformed `username`.
            $raw = $this->lookup($name);

            if ($raw === null) {
                // Absent: a required parameter is `missing`, anything else takes its
                // declared default — `null` for a nullable string, `''` for a plain one.
                if ($param->required) {
                    $this->errors[] = $this->error('missing', $name, 'Field required', null);

                    continue;
                }

                $values[$name] = $param->default;

                continue;
            }

            $before = count($this->errors);
            $value = match ($param->type) {
                LegacyParam::INT => $this->integer($name, $raw, $param),
                LegacyParam::BOOL => $this->boolean($name, $raw),
                default => $this->text($name, $raw, $param),
            };

            // The resolver returns `null` both for "rejected" and for a legitimately
            // null value, so acceptance is decided by whether it recorded an error.
            if (count($this->errors) === $before) {
                $values[$name] = $value;
            }
        }

        if ($this->errors !== []) {
            throw new LegacyValidationException($this->errors);
        }

        return $values;
    }

    /**
     * `int` — pydantic's lax integer parse: an optional sign and digits, nothing else.
     *
     * `1.5` and `abc` are both `int_parsing`; so is the empty string.  The bounds are
     * compared on the **string** with `bccomp` so an out-of-range literal reports
     * `less_than_equal` rather than overflowing to a PHP `int` first.
     */
    private function integer(string $name, mixed $raw, LegacyParam $param): ?int
    {
        if (! is_string($raw) || preg_match('/^[+-]?\d+$/', $raw) !== 1) {
            $this->errors[] = $this->error(
                'int_parsing',
                $name,
                'Input should be a valid integer, unable to parse string as an integer',
                $raw,
            );

            return null;
        }

        $normalised = ltrim($raw, '+');

        if ($param->ge !== null && bccomp($normalised, (string) $param->ge) < 0) {
            $this->errors[] = $this->error(
                'greater_than_equal',
                $name,
                "Input should be greater than or equal to {$param->ge}",
                $raw,
                ['ge' => $param->ge],
            );

            return null;
        }

        if ($param->le !== null && bccomp($normalised, (string) $param->le) > 0) {
            $this->errors[] = $this->error(
                'less_than_equal',
                $name,
                "Input should be less than or equal to {$param->le}",
                $raw,
                ['le' => $param->le],
            );

            return null;
        }

        return (int) $normalised;
    }

    /** `str` — a repeated parameter (`?q[]=a`) is not a string. */
    private function text(string $name, mixed $raw, LegacyParam $param): ?string
    {
        if (! is_string($raw)) {
            $this->errors[] = $this->error('string_type', $name, 'Input should be a valid string', $raw);

            return null;
        }

        // `min_length` is checked before `max_length` because pydantic reports the first
        // constraint that fails and the two are mutually exclusive in practice.
        if ($param->minLength !== null && mb_strlen($raw) < $param->minLength) {
            $this->errors[] = $this->error(
                'string_too_short',
                $name,
                "String should have at least {$param->minLength} characters",
                $raw,
                ['min_length' => $param->minLength],
            );

            return null;
        }

        // `max_length` counts characters, not bytes — the same thing Python's `len()`
        // counted, and these parameters carry Persian text.
        if ($param->maxLength !== null && mb_strlen($raw) > $param->maxLength) {
            $this->errors[] = $this->error(
                'string_too_long',
                $name,
                "String should have at most {$param->maxLength} characters",
                $raw,
                ['max_length' => $param->maxLength],
            );

            return null;
        }

        return $raw;
    }

    /**
     * `bool` — pydantic accepts six spellings in each direction, case-insensitively.
     *
     * Deliberately *not* `Request::boolean()`: that is `FILTER_VALIDATE_BOOLEAN`, which
     * silently answers `false` for `?active_only=2` where FastAPI rejects it, and
     * rejects `t`/`y` where FastAPI accepts them.  An empty `?active_only=` is the
     * commonest way to hit this: the live server answers 422, not "false".
     */
    private function boolean(string $name, mixed $raw): ?bool
    {
        if (is_string($raw)) {
            $needle = strtolower($raw);

            if (in_array($needle, self::TRUE_VALUES, true)) {
                return true;
            }

            if (in_array($needle, self::FALSE_VALUES, true)) {
                return false;
            }
        }

        $this->errors[] = $this->error(
            'bool_parsing',
            $name,
            'Input should be a valid boolean, unable to interpret input',
            $raw,
        );

        return null;
    }

    /** @param array<string, mixed> $ctx */
    private function error(string $type, string $name, string $message, mixed $input, array $ctx = []): array
    {
        $error = [
            'type' => $type,
            'loc' => ['query', $name],
            'msg' => $message,
            'input' => $input,
        ];

        // `ctx` appears only for the constraints that carry a bound.
        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }
}
