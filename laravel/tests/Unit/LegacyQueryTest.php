<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyParam;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacyValidationException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The ported `Query(...)` declarations.
 *
 * Every accepted spelling, every `type` string and every `msg` in this file was
 * captured from the **running** FastAPI server on `127.0.0.1:5000` (`curl` against
 * `/master-admin/api/sessions` and friends) while the read surface was written — none
 * of it is recalled from the Pydantic documentation, because the point of the class is
 * that the two servers agree on the wire, not that they agree with the docs.
 *
 * The cases worth having tests for are the ones where the obvious implementation
 * differs from the live one:
 *
 * * `?active_only=` (empty) is a **422**, and Laravel's `Request::boolean()` answers
 *   `false` for it;
 * * `?active_only=t` and `=y` are accepted, and `FILTER_VALIDATE_BOOLEAN` rejects them;
 * * `?per_page=201` reports `less_than_equal` with `ctx.le`, while `?per_page=abc`
 *   reports `int_parsing` — two different `type`s for two different mistakes;
 * * `?username=` is present-but-empty and must **not** be reported as `missing`.
 */
final class LegacyQueryTest extends TestCase
{
    /** `GET /master-admin/api/sessions`, near enough. */
    private function sessions(): array
    {
        return [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'active_only' => LegacyQuery::bool(),
            'username' => LegacyQuery::nullableString(),
        ];
    }

    /** @param array<string, LegacyParam> $spec */
    private function validate(string $queryString, array $spec): array
    {
        // A real query bag, built from a real query string.  `bootstrap/app.php`
        // removes `ConvertEmptyStringsToNull`, so `?x=` keeps its empty string — which
        // is what FastAPI saw.
        return LegacyQuery::validate(Request::create('/probe?'.$queryString, 'GET'), $spec);
    }

    /** @param array<string, LegacyParam> $spec */
    private function rejection(string $queryString, array $spec): array
    {
        try {
            $this->validate($queryString, $spec);
        } catch (LegacyValidationException $exception) {
            return $exception->detail();
        }

        $this->fail("the parameters in `{$queryString}` were expected to be rejected");
    }

    // ── Defaults and presence ────────────────────────────────────────────────

    public function test_an_empty_query_string_takes_every_declared_default(): void
    {
        $params = $this->validate('', $this->sessions());

        $this->assertSame(1, $params['page']);
        $this->assertSame(50, $params['per_page']);
        $this->assertFalse($params['active_only']);
        $this->assertNull($params['username'], 'Optional[str] = None defaults to null, not ""');
    }

    /**
     * Both the test harness and a real SAPI must hand `''` through.
     *
     * `ConvertEmptyStringsToNull` would have made this `null`, which is why the report
     * endpoint answered `Field required` for a parameter the caller *had* supplied.
     */
    public function test_a_present_but_empty_parameter_is_an_empty_string_not_null(): void
    {
        $this->assertSame('', $this->validate('username=', $this->sessions())['username']);
    }

    /** A parameter with no `=` at all is still present, and still empty. */
    public function test_a_parameter_without_an_equals_sign_is_present(): void
    {
        $this->assertSame('', $this->validate('username', $this->sessions())['username']);
    }

    public function test_whitespace_is_not_trimmed_because_fastapi_did_not_trim_it(): void
    {
        $this->assertSame(' a ', $this->validate('username=%20a%20', $this->sessions())['username']);
    }

    // ── Integers ─────────────────────────────────────────────────────────────

    /** @return array<string, array{string, int}> */
    public static function acceptedIntegers(): array
    {
        return [
            'plain' => ['7', 7],
            'leading zeroes' => ['007', 7],
            // `%2B` because a literal `+` in a query string means a space; a bare `+7`
            // would arrive as ` 7` and be a parse failure.
            'explicit plus' => ['%2B7', 7],
            'the lower bound itself' => ['1', 1],
            'the upper bound itself' => ['200', 200],
        ];
    }

    #[DataProvider('acceptedIntegers')]
    public function test_accepted_integer_spellings(string $raw, int $expected): void
    {
        $this->assertSame(
            $expected,
            $this->validate('per_page='.$raw, $this->sessions())['per_page'],
            "per_page={$raw} must parse to {$expected}",
        );
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedIntegers(): array
    {
        return [
            'words' => ['abc', 'abc'],
            'a decimal' => ['1.5', '1.5'],
            'an empty value' => ['', ''],
            'a space' => ['%20', ' '],
        ];
    }

    #[DataProvider('rejectedIntegers')]
    public function test_a_value_that_is_not_an_integer_reports_int_parsing(string $raw, string $input): void
    {
        $this->assertSame([[
            'type' => 'int_parsing',
            'loc' => ['query', 'per_page'],
            'msg' => 'Input should be a valid integer, unable to parse string as an integer',
            'input' => $input,
        ]], $this->rejection('per_page='.$raw, $this->sessions()), 'int_parsing carries no ctx — that is part of the shape');
    }

    /**
     * The two bounds report *different* types, and `input` stays the raw **string**.
     *
     * That last detail is easy to get wrong and impossible to notice: FastAPI echoes
     * `"201"`, not `201`, because the value never became an integer.
     */
    public function test_the_bounds_report_their_own_types_with_the_raw_input(): void
    {
        $this->assertSame([[
            'type' => 'greater_than_equal',
            'loc' => ['query', 'per_page'],
            'msg' => 'Input should be greater than or equal to 1',
            'input' => '0',
            'ctx' => ['ge' => 1],
        ]], $this->rejection('per_page=0', $this->sessions()));

        $this->assertSame([[
            'type' => 'less_than_equal',
            'loc' => ['query', 'per_page'],
            'msg' => 'Input should be less than or equal to 200',
            'input' => '201',
            'ctx' => ['le' => 200],
        ]], $this->rejection('per_page=201', $this->sessions()));
    }

    /**
     * An out-of-range literal larger than `PHP_INT_MAX` still reports the *bound*.
     *
     * Casting to `int` first would overflow to `PHP_INT_MAX` — still out of range, so
     * this particular value happens to survive the mistake.  The comparison is done on
     * the string with `bccomp` so the `input` FastAPI echoes is not lost to rounding.
     */
    public function test_a_bound_is_compared_without_overflowing_php_int(): void
    {
        $this->assertSame('less_than_equal', $this->rejection(
            'per_page=99999999999999999999',
            $this->sessions(),
        )[0]['type']);
    }

    // ── Booleans ─────────────────────────────────────────────────────────────

    /** @return array<string, array{string, bool}> */
    public static function booleanSpellings(): array
    {
        $cases = [];

        foreach (['true', '1', 'on', 't', 'yes', 'y'] as $value) {
            $cases[$value] = [$value, true];
            // `?active_only=TRUE` was accepted by the live server: the parse folds case.
            $cases[strtoupper($value)] = [strtoupper($value), true];
        }

        foreach (['false', '0', 'off', 'f', 'no', 'n'] as $value) {
            $cases[$value] = [$value, false];
        }

        return $cases;
    }

    #[DataProvider('booleanSpellings')]
    public function test_accepted_boolean_spellings(string $raw, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->validate('active_only='.$raw, $this->sessions())['active_only'],
            "active_only={$raw} must be understood as ".var_export($expected, true),
        );
    }

    /**
     * The spellings the live server rejected, including the empty one.
     *
     * `?active_only=` is the important case: it is what a form produces by accident,
     * and `Request::boolean()` would have answered `false` and returned every session
     * instead of refusing the request.
     */
    public function test_rejected_boolean_spellings_report_bool_parsing(): void
    {
        foreach (['', 'maybe', '2'] as $raw) {
            $this->assertSame([[
                'type' => 'bool_parsing',
                'loc' => ['query', 'active_only'],
                'msg' => 'Input should be a valid boolean, unable to interpret input',
                'input' => $raw,
            ]], $this->rejection('active_only='.$raw, $this->sessions()), "active_only={$raw}");
        }
    }

    // ── Required parameters ──────────────────────────────────────────────────

    public function test_a_missing_required_parameter_reports_missing_with_a_null_input(): void
    {
        $this->assertSame([[
            'type' => 'missing',
            'loc' => ['query', 'username'],
            'msg' => 'Field required',
            'input' => null,
        ]], $this->rejection('', ['username' => LegacyQuery::requiredString()]));
    }

    /** `Query(...)` requires presence, not content. */
    public function test_a_required_parameter_may_be_empty(): void
    {
        $this->assertSame(
            '',
            $this->validate('username=', ['username' => LegacyQuery::requiredString()])['username'],
        );
    }

    // ── Strings ──────────────────────────────────────────────────────────────

    /**
     * `?q[]=a` is a parameter **named** `q[]`, not a repeated `q`.
     *
     * Verified against the running server rather than assumed:
     * `GET /get_user_info_report?username[]=a` answers `422` with `missing`, because
     * `urllib.parse.parse_qsl` never splits a bracketed key — the declared `username` is
     * simply not there, and the handler's own default applies.  PHP's query parser does
     * the opposite (`status[]` becomes `status => ['x']`), which is what made the obvious
     * port answer `string_type` on an array the running server never sees.
     */
    public function test_a_bracketed_key_is_a_different_parameter_name(): void
    {
        $this->assertSame('', $this->validate('q[]=a', ['q' => LegacyQuery::string()])['q']);

        $this->assertSame([[
            'type' => 'missing',
            'loc' => ['query', 'username'],
            'msg' => 'Field required',
            'input' => null,
        ]], $this->rejection('username[]=a', ['username' => LegacyQuery::requiredString()]));
    }

    /** A genuinely repeated key: the **last** occurrence wins, as `dict(parse_qsl(..))` does. */
    public function test_a_repeated_key_takes_the_last_value(): void
    {
        $this->assertSame('b', $this->validate('q=a&q=b', ['q' => LegacyQuery::string()])['q']);
    }

    /**
     * `max_length` counts characters, not bytes.
     *
     * `تست` is three characters and six UTF-8 bytes: a `strlen` bound would reject it,
     * and Python's `len()` — which is what FastAPI used — would not.
     */
    public function test_max_length_counts_characters(): void
    {
        $spec = ['search' => LegacyQuery::string(default: '', maxLength: 3)];
        $persian = 'تست';

        $this->assertSame(6, strlen($persian), 'the byte length differs from the character length');
        $this->assertSame(3, mb_strlen($persian));

        $this->assertSame($persian, $this->validate('search='.urlencode($persian), $spec)['search']);
    }

    public function test_a_too_long_string_reports_string_too_long(): void
    {
        $this->assertSame([[
            'type' => 'string_too_long',
            'loc' => ['query', 'search'],
            'msg' => 'String should have at most 3 characters',
            'input' => 'abcd',
            'ctx' => ['max_length' => 3],
        ]], $this->rejection('search=abcd', ['search' => LegacyQuery::string(default: '', maxLength: 3)]));
    }

    // ── Reporting every failure ──────────────────────────────────────────────

    /**
     * FastAPI reports **all** bad parameters, in declaration order — not just the first.
     *
     * The order is the declaration order, so `page` (declared first) is reported before
     * `active_only`, regardless of which appears first in the query string.
     */
    public function test_every_rejected_parameter_is_reported_in_declaration_order(): void
    {
        $detail = $this->rejection('active_only=maybe&page=abc', $this->sessions());

        $this->assertSame(
            [['query', 'page'], ['query', 'active_only']],
            array_map(static fn (array $error): array => $error['loc'], $detail),
        );
        $this->assertSame('int_parsing', $detail[0]['type']);
        $this->assertSame('bool_parsing', $detail[1]['type']);
    }
}
