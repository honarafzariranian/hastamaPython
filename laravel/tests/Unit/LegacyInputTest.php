<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyIds;
use App\Support\Legacy\LegacyInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ported input validators, pinned to the values the Python module produced.
 *
 * These are the cheapest tests in the suite and the ones that matter most, because
 * the strings below are shown to users verbatim: a "tidied" message here is a visible
 * change to the interface, and a loosened pattern is a security change nobody would
 * notice from the outside.
 */
final class LegacyInputTest extends TestCase
{
    // ── Usernames ────────────────────────────────────────────────────────────

    /**
     * Real names from the live installation, in both alphabet spellings.
     *
     * The Arabic yeh `ي` (U+064A) and the Persian `ی` (U+06CC) are different
     * characters and the installation contains usernames typed with each; the
     * validator has to accept both, because the database does.
     *
     * @return array<string, array{0: string}>
     */
    public static function acceptedUsernames(): array
    {
        return [
            'ascii' => ['ali'],
            'with a digit' => ['user1'],
            'with an underscore' => ['user_name'],
            'with internal space' => ['علی رضایی'],
            'persian' => ['مدیریت'],
            'arabic yeh' => ['علي'],
            'persian yeh' => ['علی'],
            'two characters (the minimum)' => ['ab'],
            'hyphen is explicitly allowed by the pattern' => ['user-name'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedUsernames')]
    public function it_accepts_the_usernames_the_live_installation_contains(string $username): void
    {
        $result = LegacyInput::validateUsername($username);

        $this->assertTrue($result['valid'], "expected '{$username}' to be accepted");
        $this->assertNull($result['error']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedUsernames(): array
    {
        return [
            'empty' => ['', 'نام کاربری الزامی است.'],
            'one character' => ['a', 'نام کاربری باید حداقل ۲ کاراکتر باشد.'],
            'markup' => ['<script>', 'نام کاربری شامل کاراکترهای غیرمجاز است.'],
            'an at sign' => ['user@host', 'نام کاربری شامل کاراکترهای غیرمجاز است.'],
            'a slash' => ['user/name', 'نام کاربری شامل کاراکترهای غیرمجاز است.'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedUsernames')]
    public function it_rejects_invalid_usernames_with_the_existing_message(string $username, string $expected): void
    {
        $result = LegacyInput::validateUsername($username);

        $this->assertFalse($result['valid']);
        $this->assertSame($expected, $result['error']);
    }

    #[Test]
    public function the_username_length_limit_is_the_legacy_fifty_characters(): void
    {
        $atLimit = str_repeat('a', LegacyInput::MAX_USERNAME_LENGTH);

        $this->assertTrue(LegacyInput::validateUsername($atLimit)['valid']);
        $this->assertSame(
            'نام کاربری نباید بیش از 50 کاراکتر باشد.',
            LegacyInput::validateUsername($atLimit.'a')['error'],
        );
    }

    // ── Request ids ──────────────────────────────────────────────────────────

    #[Test]
    public function it_accepts_the_canonical_request_id_shape(): void
    {
        $result = LegacyInput::validateRequestId('HST-20260930-0A1B2C3D');

        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function it_accepts_lowercase_hex_because_the_python_pattern_did(): void
    {
        $this->assertTrue(LegacyInput::validateRequestId('HST-20260930-abcdef01')['valid']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedRequestIds(): array
    {
        return [
            'empty' => ['', 'شناسه درخواست الزامی است.'],
            'missing prefix' => ['20260930-0A1B2C3D', 'فرمت شناسه درخواست نامعتبر است.'],
            'non-hex tail' => ['HST-20260930-ZZZZZZZZ', 'فرمت شناسه درخواست نامعتبر است.'],
            'short date' => ['HST-2026093-0A1B2C3D', 'فرمت شناسه درخواست نامعتبر است.'],
            'nine hex digits' => ['HST-20260930-0A1B2C3DE', 'فرمت شناسه درخواست نامعتبر است.'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedRequestIds')]
    public function it_rejects_malformed_request_ids(string $requestId, string $expected): void
    {
        $this->assertSame($expected, LegacyInput::validateRequestId($requestId)['error']);
    }

    #[Test]
    public function the_request_id_length_check_runs_before_the_pattern(): void
    {
        // Over `MAX_REQUEST_ID_LENGTH` the answer is the length message, not the
        // format one; the Python module ordered the two the same way.
        $this->assertSame(
            'شناسه درخواست نامعتبر است.',
            LegacyInput::validateRequestId('HST-20260930-'.str_repeat('A', 21))['error'],
        );
    }

    // ── Recovery codes ───────────────────────────────────────────────────────

    #[Test]
    public function it_accepts_an_eight_character_uppercase_code(): void
    {
        $this->assertTrue(LegacyInput::validateRecoveryCode('AB12CD34')['valid']);
    }

    #[Test]
    public function a_lowercase_code_is_rejected_before_any_lookup(): void
    {
        $result = LegacyInput::validateRecoveryCode('ab12cd34');

        $this->assertFalse($result['valid']);
        $this->assertSame('کد بازیابی فقط شامل حروف بزرگ انگلیسی و اعداد باشد.', $result['error']);
    }

    #[Test]
    public function the_code_length_is_exactly_eight(): void
    {
        $this->assertSame(
            'کد بازیابی باید 8 کاراکتر باشد.',
            LegacyInput::validateRecoveryCode('AB12CD3')['error'],
        );
        $this->assertSame(
            'کد بازیابی باید 8 کاراکتر باشد.',
            LegacyInput::validateRecoveryCode('AB12CD345')['error'],
        );
    }

    // ── Password policy ──────────────────────────────────────────────────────

    #[Test]
    public function a_password_meeting_every_rule_scores_five(): void
    {
        $result = LegacyInput::passwordPolicy('Str0ng!Pass');

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(5, $result['score']);
    }

    #[Test]
    public function an_empty_password_reports_the_single_required_message(): void
    {
        $result = LegacyInput::passwordPolicy('');

        $this->assertFalse($result['valid']);
        $this->assertSame(['رمز عبور الزامی است.'], $result['errors']);
        $this->assertSame(0, $result['score']);
    }

    #[Test]
    public function every_missing_rule_is_listed_in_the_legacy_order(): void
    {
        // Seven lowercase letters: fails length, uppercase, digit and symbol, and
        // passes only "a lowercase letter".
        $result = LegacyInput::passwordPolicy('abcdefg');

        $this->assertSame([
            'حداقل ۸ کاراکتر',
            'حداقل یک حرف بزرگ انگلیسی',
            'حداقل یک عدد',
            'حداقل یک کاراکتر خاص (!@#$%^&*)',
        ], $result['errors']);
        $this->assertSame(1, $result['score']);
    }

    #[Test]
    public function the_legacy_policy_counts_persian_characters_as_characters(): void
    {
        // Eight Persian characters satisfies the length rule and none of the others.
        $result = LegacyInput::passwordPolicy('مدیریتاین');

        $this->assertNotContains('حداقل ۸ کاراکتر', $result['errors']);
    }

    #[Test]
    public function an_over_long_password_is_capped_and_loses_a_score_point(): void
    {
        $result = LegacyInput::passwordPolicy('Str0ng!Pass'.str_repeat('a', 120));

        $this->assertFalse($result['valid']);
        $this->assertContains('حداکثر ۱۲۸ کاراکتر', $result['errors']);
        $this->assertSame(4, $result['score']);
    }

    #[Test]
    public function strength_labels_match_the_existing_scale(): void
    {
        $this->assertSame('خیلی ضعیف', LegacyInput::passwordStrengthLabel(0));
        $this->assertSame('ضعیف', LegacyInput::passwordStrengthLabel(1));
        $this->assertSame('ضعیف', LegacyInput::passwordStrengthLabel(2));
        $this->assertSame('متوسط', LegacyInput::passwordStrengthLabel(3));
        $this->assertSame('خوب', LegacyInput::passwordStrengthLabel(4));
        $this->assertSame('قوی', LegacyInput::passwordStrengthLabel(5));
    }

    // ── Identifier generation ────────────────────────────────────────────────

    #[Test]
    public function every_generated_identifier_validates_against_its_own_rule(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $id = LegacyIds::requestId();

            $this->assertSame(LegacyIds::LENGTH, strlen($id));
            $this->assertMatchesRegularExpression(LegacyIds::PATTERN, $id);
            $this->assertTrue(LegacyIds::isValid($id));
            $this->assertTrue(LegacyInput::validateRequestId($id)['valid']);
        }
    }

    #[Test]
    public function the_identifier_carries_the_utc_date(): void
    {
        $this->assertStringStartsWith('HST-'.now()->utc()->format('Ymd').'-', LegacyIds::eventId());
    }

    #[Test]
    public function generated_identifiers_do_not_collide(): void
    {
        $ids = [];

        for ($i = 0; $i < 500; $i++) {
            $ids[LegacyIds::actionId()] = true;
        }

        $this->assertCount(500, $ids);
    }

    #[Test]
    public function a_formatted_but_non_hex_identifier_is_refused(): void
    {
        // The regression this guards: an identifier built from an alphanumeric
        // generator looks plausible and can never be looked up again.
        $this->assertFalse(LegacyIds::isValid('HST-20260930-ZZZZZZZZ'));
        $this->assertFalse(LegacyIds::isValid('HST-20260930-0A1B2C3'));
    }
}
