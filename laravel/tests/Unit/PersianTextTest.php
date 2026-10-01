<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyRequestStatus;
use App\Support\Legacy\LegacyValue;
use App\Support\Legacy\PersianText;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The two text quirks of the legacy database, pinned by tests.
 *
 * These are small functions with disproportionate consequences: if `trim()`
 * regresses, 16 `role` values come back as `'admin     '` and every role check
 * fails; if `normalizeLetters()` regresses, users who typed the Arabic yeh cannot
 * log in.  Both are pure functions, so they can be pinned without a database.
 */
class PersianTextTest extends TestCase
{
    public function test_trim_removes_trailing_padding_only(): void
    {
        // Exactly what the live `user_table.role` column returns.
        $this->assertSame('admin', PersianText::trim('admin     '));
        $this->assertSame('user', PersianText::trim('user      '));

        // Leading whitespace is preserved on purpose.
        $this->assertSame('  admin', PersianText::trim('  admin  '));

        // Null and empty are normalised to an empty string, never to null.
        $this->assertSame('', PersianText::trim(null));
        $this->assertSame('', PersianText::trim(''));
        $this->assertSame('', PersianText::trim('     '));
    }

    public function test_normalize_letters_folds_arabic_yeh_and_kaf(): void
    {
        // «آی تی» as entered with the Arabic yeh (U+064A), which 7 live usernames use.
        $arabic = "\u{0622}\u{064A} \u{062A}\u{064A}";
        // The same name with the Persian yeh (U+06CC).
        $persian = "\u{0622}\u{06CC} \u{062A}\u{06CC}";

        $this->assertNotSame($arabic, $persian, 'the fixtures must differ before folding');
        $this->assertSame($persian, PersianText::normalizeLetters($arabic));
        $this->assertSame($persian, PersianText::normalizeLetters($persian));

        // Kaf: Arabic U+0643 → Persian U+06A9.
        $this->assertSame("\u{06A9}\u{0627}\u{0631}", PersianText::normalizeLetters("\u{0643}\u{0627}\u{0631}"));
    }

    public function test_fold_username_combines_folding_trimming_and_case(): void
    {
        $this->assertSame('paknafs', PersianText::foldUsername('  PAKNAFS   '));
        $this->assertSame(mb_strtolower("آی\u{06CC}", 'UTF-8'), PersianText::foldUsername("آی\u{064A}  "));
    }

    public function test_normalized_column_expression_matches_the_legacy_sql(): void
    {
        $expression = PersianText::normalizedColumnExpression('username');

        // The exact expression the Python application compares against.
        $this->assertSame(
            "REPLACE(REPLACE(LTRIM(RTRIM(username)), N'\u{064A}', N'\u{06CC}'), N'\u{0643}', N'\u{06A9}')",
            $expression,
        );

        // A qualified identifier is allowed.
        $this->assertStringContainsString('u.username', PersianText::normalizedColumnExpression('u.username'));
    }

    public function test_normalized_column_expression_refuses_anything_but_an_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // The one place a SQL fragment is built must never accept caller data.
        PersianText::normalizedColumnExpression('username) OR 1=1 --');
    }

    public function test_persian_and_latin_digits_round_trip(): void
    {
        $this->assertSame('۱۲۳۴۵۶۷۸۹۰', PersianText::toPersianDigits('1234567890'));
        $this->assertSame('1234567890', PersianText::toLatinDigits('۱۲۳۴۵۶۷۸۹۰'));
        $this->assertSame('2026', PersianText::toLatinDigits('۲۰۲۶'));
    }

    public function test_truthy_matches_the_legacy_flag_set(): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on', 'enabled', ' Enabled '] as $value) {
            $this->assertTrue(LegacyValue::truthy($value), "{$value} must read as on");
        }

        foreach (['0', 'false', '', null, 'no', 'off', 'disabled', 'maybe'] as $value) {
            $this->assertFalse(LegacyValue::truthy($value));
        }

        $this->assertTrue(LegacyValue::truthy(true));
        $this->assertFalse(LegacyValue::truthy(false));
    }

    public function test_integer_falls_back_to_the_default_and_clamps(): void
    {
        $this->assertSame(480, LegacyValue::integer('480', 60));
        $this->assertSame(60, LegacyValue::integer(null, 60));
        $this->assertSame(60, LegacyValue::integer('not a number', 60));
        $this->assertSame(900, LegacyValue::integer('999999', 60, 0, 900));
        $this->assertSame(0, LegacyValue::integer('-5', 60, 0, 900));
    }

    public function test_json_returns_the_default_instead_of_throwing(): void
    {
        $this->assertSame(['width_mm' => 76], LegacyValue::json('{"width_mm":76}', null));
        $this->assertNull(LegacyValue::json('{not json', null));
        $this->assertSame(['fallback' => true], LegacyValue::json('', ['fallback' => true]));
        $this->assertNull(LegacyValue::json(null, null));
    }

    public function test_request_statuses_are_the_exact_persian_strings_the_data_uses(): void
    {
        // The trigger and every report aggregate on this literal.
        $this->assertSame('تایید شده', LegacyRequestStatus::APPROVED);
        $this->assertSame('انتظار تایید', LegacyRequestStatus::PENDING);
        $this->assertSame('رد شده', LegacyRequestStatus::REJECTED);
        $this->assertSame('انصراف', LegacyRequestStatus::CANCELLED);

        $this->assertTrue(LegacyRequestStatus::isTerminal(' تایید شده  '));
        $this->assertFalse(LegacyRequestStatus::isTerminal('انتظار تایید'));
    }
}
