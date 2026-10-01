<?php

namespace App\Support\Notifications;

use App\Support\Legacy\LegacyParam;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacyValidationException;
use Illuminate\Http\Request;

/**
 * The query parameters of the two list handlers, validated in signature order.
 *
 * `admin_list` and `user_list` each declare one `Literal[...]` parameter — `sort` and
 * `state` respectively — and both are **last** in the Python signature.  `LegacyQuery`
 * models `Query(...)` parameters but has no literal mode, so the literal is validated
 * here and its `literal_error` is appended after the `LegacyQuery` errors, which is
 * exactly FastAPI's declaration order: a request with several bad values reports all
 * of them, the `Query(...)` ones first.
 *
 * The value is read from the **raw query string**, not from `$request->query`, for the
 * same reason `LegacyQuery` does — `?sort[]=x` is a parameter *named* `sort[]` to
 * Python (so `sort` is absent and takes its default) and an array named `sort` to PHP.
 */
final class NotificationQuery
{
    /**
     * @param  array<string, LegacyParam>  $spec  The `Query(...)` parameters, in signature order.
     * @return array<string, mixed>
     *
     * @throws LegacyValidationException
     */
    public static function validate(Request $request, array $spec, string $literalName, array $literalAllowed, string $literalDefault): array
    {
        $literalError = self::literalError($request, $literalName, $literalAllowed);

        try {
            $params = LegacyQuery::validate($request, $spec);
        } catch (LegacyValidationException $exception) {
            $detail = $exception->detail();

            if ($literalError !== null) {
                $detail[] = $literalError;
            }

            throw new LegacyValidationException($detail);
        }

        if ($literalError !== null) {
            throw new LegacyValidationException([$literalError]);
        }

        $params[$literalName] = self::raw($request, $literalName) ?? $literalDefault;

        return $params;
    }

    /**
     * The `literal_error` detail for a `Literal[...]` parameter, or null when it is valid.
     *
     * Any present value that is not a member — including `null`, a number, an array
     * and the empty string — is a `literal_error`; there is no `string_type` pre-check.
     *
     * @param  array<int, string>  $allowed
     * @return array<string, mixed>|null
     */
    public static function literalError(Request $request, string $name, array $allowed): ?array
    {
        $raw = self::raw($request, $name);

        if ($raw === null) {
            return null;
        }

        if (is_string($raw) && in_array($raw, $allowed, true)) {
            return null;
        }

        $expected = self::expected($allowed);

        return [
            'type' => 'literal_error',
            'loc' => ['query', $name],
            'msg' => "Input should be {$expected}",
            'input' => $raw,
            'ctx' => ['expected' => $expected],
        ];
    }

    /**
     * The last value for an **exact** key in the raw query string, or null when absent.
     *
     * `parse_qsl`'s view of the request: split on `&` only, `+` is not decoded (it is
     * a literal plus in a query string), a bare `?flag` is the empty string, and the
     * last occurrence of a repeated key wins.
     */
    private static function raw(Request $request, string $name): ?string
    {
        $query = (string) $request->server->get('QUERY_STRING', '');

        if ($query === '') {
            return null;
        }

        $found = null;

        foreach (explode('&', $query) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            $separator = strpos($chunk, '=');

            if ($separator === false) {
                if (urldecode($chunk) === $name) {
                    $found = '';
                }

                continue;
            }

            if (urldecode(substr($chunk, 0, $separator)) === $name) {
                $found = urldecode(substr($chunk, $separator + 1));
            }
        }

        return $found;
    }

    /**
     * pydantic's literal list wording: `'a', 'b' or 'c'` — `or` before the last item.
     *
     * @param  array<int, string>  $allowed
     */
    private static function expected(array $allowed): string
    {
        $quoted = array_map(static fn (string $value): string => "'{$value}'", $allowed);
        $last = (string) array_pop($quoted);

        return implode(', ', $quoted).' or '.$last;
    }
}
