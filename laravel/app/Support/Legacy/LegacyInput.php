<?php

namespace App\Support\Legacy;

/**
 * Input validation and password policy — a port of `app/core/password_utils.py`
 * (the `validate_*` and `validate_password_policy` functions).
 *
 * Two properties of the original are load-bearing and are preserved deliberately:
 *
 * 1. **The limits are the contract.**  `MAX_USERNAME_LENGTH` (50) and
 *    `MAX_PASSWORD_LENGTH` (128) are the same numbers the login endpoint enforces,
 *    and the password policy's five rules are the same five, in the same order,
 *    with the same Persian wording.  A "better" policy would reject passwords the
 *    live installation already accepts.
 * 2. **A failure returns a message, not an exception.**  Every caller in the
 *    original treats validation as data (`result["valid"]`, `result["error"]`),
 *    because the answer is shown to the user verbatim.  Returning the same shape
 *    keeps the translation mechanical and the wording identical.
 *
 * The policy is deliberately *not* applied to the existing accounts — 15 of the 16
 * hold an unhashed password that would fail several of these rules.  It applies to
 * new passwords only, which is what the recovery flow sets.
 */
final class LegacyInput
{
    public const MAX_USERNAME_LENGTH = 50;

    public const MAX_PASSWORD_LENGTH = 128;

    public const MAX_REQUEST_ID_LENGTH = 32;

    public const MAX_RECOVERY_CODE_LENGTH = 8;

    public const MIN_USERNAME_LENGTH = 2;

    /**
     * Usernames may contain Persian and English letters, digits, underscore and
     * space — plus the Persian, Arabic-Extended and Arabic-Presentation character
     * blocks, because the live usernames do.
     *
     * Transcribed from the Python pattern
     * `^[\w\s\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF-]+$`
     * with the `u` flag, which is what makes the block ranges apply to characters
     * rather than bytes.
     */
    private const USERNAME_PATTERN = '/^[\w\s\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\-]+$/u';

    /**
     * Validate a username submission.
     *
     * @return array{valid: bool, error: string|null}
     */
    public static function validateUsername(?string $username): array
    {
        if ($username === null || $username === '') {
            return ['valid' => false, 'error' => 'نام کاربری الزامی است.'];
        }

        $username = PersianText::strip($username);

        if (mb_strlen($username) > self::MAX_USERNAME_LENGTH) {
            return [
                'valid' => false,
                'error' => 'نام کاربری نباید بیش از '.self::MAX_USERNAME_LENGTH.' کاراکتر باشد.',
            ];
        }

        if (mb_strlen($username) < self::MIN_USERNAME_LENGTH) {
            return ['valid' => false, 'error' => 'نام کاربری باید حداقل ۲ کاراکتر باشد.'];
        }

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return ['valid' => false, 'error' => 'نام کاربری شامل کاراکترهای غیرمجاز است.'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate a recovery request id.
     *
     * @return array{valid: bool, error: string|null}
     */
    public static function validateRequestId(?string $requestId): array
    {
        if ($requestId === null || $requestId === '') {
            return ['valid' => false, 'error' => 'شناسه درخواست الزامی است.'];
        }

        $requestId = PersianText::strip($requestId);

        if (mb_strlen($requestId) > self::MAX_REQUEST_ID_LENGTH) {
            return ['valid' => false, 'error' => 'شناسه درخواست نامعتبر است.'];
        }

        if (! LegacyIds::isValid($requestId)) {
            return ['valid' => false, 'error' => 'فرمت شناسه درخواست نامعتبر است.'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate a recovery code's shape — before any database lookup.
     *
     * @return array{valid: bool, error: string|null}
     */
    public static function validateRecoveryCode(?string $code): array
    {
        if ($code === null || $code === '') {
            return ['valid' => false, 'error' => 'کد بازیابی الزامی است.'];
        }

        $code = PersianText::strip($code);

        if (mb_strlen($code) !== self::MAX_RECOVERY_CODE_LENGTH) {
            return [
                'valid' => false,
                'error' => 'کد بازیابی باید '.self::MAX_RECOVERY_CODE_LENGTH.' کاراکتر باشد.',
            ];
        }

        if (preg_match('/^[A-Z0-9]+$/', $code) !== 1) {
            return ['valid' => false, 'error' => 'کد بازیابی فقط شامل حروف بزرگ انگلیسی و اعداد باشد.'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * The password policy.
     *
     * @return array{valid: bool, errors: array<int, string>, score: int}
     */
    public static function passwordPolicy(string $password): array
    {
        $errors = [];
        $score = 0;

        if ($password === '') {
            return ['valid' => false, 'errors' => ['رمز عبور الزامی است.'], 'score' => 0];
        }

        $rules = [
            [static fn (): bool => mb_strlen($password) >= 8, 'حداقل ۸ کاراکتر'],
            [static fn (): bool => preg_match('/[A-Z]/', $password) === 1, 'حداقل یک حرف بزرگ انگلیسی'],
            [static fn (): bool => preg_match('/[a-z]/', $password) === 1, 'حداقل یک حرف کوچک انگلیسی'],
            [static fn (): bool => preg_match('/[0-9]/', $password) === 1, 'حداقل یک عدد'],
            [static fn (): bool => preg_match('/[^A-Za-z0-9]/', $password) === 1, 'حداقل یک کاراکتر خاص (!@#$%^&*)'],
        ];

        foreach ($rules as [$passes, $message]) {
            if ($passes()) {
                $score++;
            } else {
                $errors[] = $message;
            }
        }

        if (mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $errors[] = 'حداکثر ۱۲۸ کاراکتر';
            $score = max(0, $score - 1);
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'score' => $score];
    }

    /**
     * The operator-facing label for a policy score.
     *
     * The two-way tie at score 2 ("ضعیف") is in the original and is kept: it is
     * what the existing interface has always shown.
     */
    public static function passwordStrengthLabel(int $score): string
    {
        return match ($score) {
            0 => 'خیلی ضعیف',
            1, 2 => 'ضعیف',
            3 => 'متوسط',
            4 => 'خوب',
            default => 'قوی',
        };
    }
}
