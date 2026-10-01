<?php

namespace App\Support\Notifications;

use App\Support\Legacy\LegacyValidationException;
use App\Support\Legacy\LegacyWhitespace;

/**
 * `NotificationInput` — the pydantic request model of `app/api/routes/notifications.py`,
 * field for field and error for error.
 *
 * The model is validated by FastAPI **before** the handler runs, so a body that fails it
 * is a 422 *before* `_actor` gets a chance to answer 401/403 — the order the controllers
 * reproduce by validating the body first.  Every `type`/`msg`/`ctx` string below was
 * captured from the running server (`127.0.0.1:5000`) or from the installed
 * FastAPI 0.115.11 + pydantic 2.10.6 pair, including the two surprises:
 *
 * * **`ctx.error` is `{}`, not the message.**  FastAPI renders a handler's 422 through
 *   `jsonable_encoder(exc.errors())`, and the encoder turns the `ValueError` carried in
 *   `ctx` into an empty object.  The direct `ValidationError.errors()` answer is
 *   `{"error": "این فیلد الزامی است."}`; the wire answer is `{"error": {}}`.
 * * **a `Literal` field reports `literal_error` for *any* non-member input**, including
 *   `null`, `5`, `['general']` and `''` — there is no `string_type` pre-check.
 *
 * Two pydantic behaviours are load-bearing for the handlers that follow:
 *
 * * the `min_length`/`max_length` constraints run on the **raw** value and the
 *   `strip_required` field-validator runs **after** them, so `"  "` (two spaces) passes
 *   `min_length=2` and is then rejected by the validator, while `"a"` is rejected by
 *   `min_length` and never reaches the validator — one error per field, core first;
 * * `action_url`'s `internal_link_only` strips **after** its own `max_length=500`, and a
 *   whitespace-only value is a `value_error` (it strips to `""`, which does not start
 *   with `/`), while `""` and `null` both become `null`.
 *
 * `targets` reports `too_long` **instead of** the per-item errors when the length fails
 * (`['a', 1, 'b', 'c']` with `max_length=3` answers one `too_long`, not the `string_type`
 * for the `1`), and the message counts the items "after validation".
 */
final class NotificationInput
{
    /** `Literal["general", "announcement", "system", "warning", "information", "success", "reminder"]` */
    public const TYPES = ['general', 'announcement', 'system', 'warning', 'information', 'success', 'reminder'];

    /** `Literal["normal", "important", "high", "critical"]` */
    public const PRIORITIES = ['normal', 'important', 'high', 'critical'];

    /** `Literal["draft", "scheduled", "published"]` */
    public const STATUSES = ['draft', 'scheduled', 'published'];

    /** `Literal["all", "selected", "role", "department"]` */
    public const TARGET_TYPES = ['all', 'selected', 'role', 'department'];

    private const TITLE_MAX = 180;

    private const CONTENT_MAX = 4000;

    private const ACTION_LABEL_MAX = 80;

    private const ACTION_URL_MAX = 500;

    private const TARGETS_MAX = 5000;

    /** `Field(min_length=2)` on both text fields. */
    private const TEXT_MIN = 2;

    /** @var array<int, array<string, mixed>> */
    private array $errors = [];

    /**
     * The decoded body, for the `input` a `missing` field echoes.
     *
     * Kept as the decoded object so an empty body stays `{}` rather than degrading to
     * `[]` — the same reason `LegacyBody` decodes to objects.
     */
    private mixed $modelInput;

    /** @param array<string, mixed> $body */
    private function __construct(private readonly array $body)
    {
        $this->modelInput = (object) $body;
    }

    /**
     * Validate the raw request body against the model.
     *
     * The three model-level failures are reported against `loc: ["body"]` alone, exactly
     * as `LegacyBody::validateJson` reports them: an empty body and the literal `null`
     * are both `missing`, an unparseable body is `json_invalid`, and a body that is not
     * an object is `model_attributes_type`.
     *
     * @return array<string, mixed> The model's fields, defaults applied.
     *
     * @throws LegacyValidationException
     */
    public static function validate(string $rawBody): array
    {
        $body = trim($rawBody);

        if ($body === '') {
            throw new LegacyValidationException([self::bodyError('missing', 'Field required', null)]);
        }

        $decoded = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LegacyValidationException([self::bodyError(
                'json_invalid',
                'JSON decode error',
                new \stdClass,
                ['error' => self::pythonJsonError()],
                [0],
            )]);
        }

        // The literal `null` is "no body" to FastAPI — the same `missing` as an empty
        // request, not a type error against the model.
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

        return (new self((array) $decoded))->run();
    }

    /**
     * The two PHP parser errors whose Python wording is unambiguous.
     *
     * The rest pass PHP's message through — `type`, `loc`, `msg` and `input` all match
     * either way, and `ctx.error` is documentation for a human reading a log.
     */
    private static function pythonJsonError(): string
    {
        return match (json_last_error()) {
            JSON_ERROR_SYNTAX => 'Expecting value',
            JSON_ERROR_DEPTH => 'Maximum recursion depth exceeded',
            default => json_last_error_msg(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function run(): array
    {
        // Field definition order — the order pydantic reports errors in.
        $values = [];
        $values['title'] = $this->requiredText('title', self::TITLE_MAX, stripRequired: true);
        $values['content'] = $this->requiredText('content', self::CONTENT_MAX, stripRequired: true);
        $values['type'] = $this->literal('type', self::TYPES, 'general');
        $values['priority'] = $this->literal('priority', self::PRIORITIES, 'normal');
        $values['status'] = $this->literal('status', self::STATUSES, 'draft');
        $values['target_type'] = $this->literal('target_type', self::TARGET_TYPES, 'all');
        $values['targets'] = $this->targets();
        $values['action_label'] = $this->optionalText('action_label', self::ACTION_LABEL_MAX);
        $values['action_url'] = $this->actionUrl();
        $values['scheduled_at'] = $this->optionalText('scheduled_at', null);

        if ($this->errors !== []) {
            throw new LegacyValidationException($this->errors);
        }

        return $values;
    }

    /**
     * `title`/`content` — a required string, then the `strip_required` validator.
     *
     * The length constraints run on the raw value; the strip runs after them and is the
     * only thing that can reject a value the constraints accepted.
     */
    private function requiredText(string $name, int $max, bool $stripRequired): string
    {
        if (! array_key_exists($name, $this->body)) {
            $this->errors[] = $this->error('missing', $name, 'Field required', $this->modelInput);

            return '';
        }

        $raw = $this->body[$name];

        if (! is_string($raw)) {
            $this->errors[] = $this->error('string_type', $name, 'Input should be a valid string', $raw);

            return '';
        }

        if (mb_strlen($raw) < self::TEXT_MIN) {
            $this->errors[] = $this->error(
                'string_too_short',
                $name,
                'String should have at least '.self::TEXT_MIN.' characters',
                $raw,
                ['min_length' => self::TEXT_MIN],
            );

            return '';
        }

        if (mb_strlen($raw) > $max) {
            $this->errors[] = $this->error(
                'string_too_long',
                $name,
                "String should have at most {$max} characters",
                $raw,
                ['max_length' => $max],
            );

            return '';
        }

        if ($stripRequired) {
            $stripped = LegacyWhitespace::strip($raw);

            if (mb_strlen($stripped) < self::TEXT_MIN) {
                $this->errors[] = $this->error(
                    'value_error',
                    $name,
                    'Value error, این فیلد الزامی است.',
                    $raw,
                    ['error' => new \stdClass],
                );

                return '';
            }

            return $stripped;
        }

        return $raw;
    }

    /**
     * A `Literal[...]` field — any present value that is not a member is a `literal_error`.
     *
     * There is no `string_type` pre-check: pydantic's literal validator reports
     * `literal_error` for `null`, numbers, arrays and the empty string alike.
     */
    private function literal(string $name, array $allowed, string $default): string
    {
        if (! array_key_exists($name, $this->body)) {
            return $default;
        }

        $raw = $this->body[$name];

        if (is_string($raw) && in_array($raw, $allowed, true)) {
            return $raw;
        }

        $expected = $this->literalMessage($allowed);

        $this->errors[] = $this->error('literal_error', $name, "Input should be {$expected}", $raw, [
            'expected' => $expected,
        ]);

        return $default;
    }

    /**
     * pydantic's literal list wording: `'a', 'b' or 'c'` — `or` before the last item.
     */
    private function literalMessage(array $allowed): string
    {
        $quoted = array_map(static fn (string $value): string => "'{$value}'", $allowed);
        $last = (string) array_pop($quoted);

        return implode(', ', $quoted).' or '.$last;
    }

    /**
     * `targets` — `list[str]`, default `[]`, at most 5000 items.
     *
     * The length is checked **before** the items: a list that is too long answers one
     * `too_long` and the per-item `string_type` errors are suppressed.
     *
     * @return array<int, string>
     */
    private function targets(): array
    {
        $name = 'targets';

        if (! array_key_exists($name, $this->body)) {
            return [];
        }

        $raw = $this->body[$name];

        // `null`, a scalar and a JSON object are all "not a list".  An empty JSON object
        // decodes to the same PHP array as an empty JSON array, so the two are
        // indistinguishable here — no caller sends `{}`.
        if (! is_array($raw) || ! array_is_list($raw)) {
            $this->errors[] = $this->error('list_type', $name, 'Input should be a valid list', $raw);

            return [];
        }

        if (count($raw) > self::TARGETS_MAX) {
            $this->errors[] = $this->error(
                'too_long',
                $name,
                'List should have at most '.self::TARGETS_MAX.' items after validation, not '.count($raw),
                $raw,
                ['field_type' => 'List', 'max_length' => self::TARGETS_MAX, 'actual_length' => count($raw)],
            );

            return [];
        }

        $values = [];

        foreach ($raw as $index => $item) {
            if (! is_string($item)) {
                $this->errors[] = $this->error(
                    'string_type',
                    $name,
                    'Input should be a valid string',
                    $item,
                    [],
                    [$index],
                );

                continue;
            }

            $values[] = $item;
        }

        return $values;
    }

    /**
     * `action_label`/`scheduled_at` — `Optional[str]`, no transform.
     */
    private function optionalText(string $name, ?int $max): ?string
    {
        if (! array_key_exists($name, $this->body)) {
            return null;
        }

        $raw = $this->body[$name];

        // An explicit `null` and an absent field are the same thing to pydantic.
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw)) {
            $this->errors[] = $this->error('string_type', $name, 'Input should be a valid string', $raw);

            return null;
        }

        if ($max !== null && mb_strlen($raw) > $max) {
            $this->errors[] = $this->error(
                'string_too_long',
                $name,
                "String should have at most {$max} characters",
                $raw,
                ['max_length' => $max],
            );

            return null;
        }

        return $raw;
    }

    /**
     * `action_url` — `Optional[str]`, `max_length=500`, then `internal_link_only`.
     *
     * The link check runs **after** the length constraint and strips before it, so a
     * 501-character `/…` is a `string_too_long` and never reaches the check, while a
     * whitespace-only value strips to `""`, fails the `/` prefix and is a `value_error`.
     */
    private function actionUrl(): ?string
    {
        $name = 'action_url';

        if (! array_key_exists($name, $this->body)) {
            return null;
        }

        $raw = $this->body[$name];

        if ($raw === null) {
            return null;
        }

        if (! is_string($raw)) {
            $this->errors[] = $this->error('string_type', $name, 'Input should be a valid string', $raw);

            return null;
        }

        if (mb_strlen($raw) > self::ACTION_URL_MAX) {
            $this->errors[] = $this->error(
                'string_too_long',
                $name,
                'String should have at most '.self::ACTION_URL_MAX.' characters',
                $raw,
                ['max_length' => self::ACTION_URL_MAX],
            );

            return null;
        }

        if ($raw === '') {
            return null;
        }

        $stripped = LegacyWhitespace::strip($raw);

        if (! str_starts_with($stripped, '/') || str_starts_with($stripped, '//')) {
            $this->errors[] = $this->error(
                'value_error',
                $name,
                'Value error, فقط پیوند داخلی با / مجاز است.',
                $raw,
                ['error' => new \stdClass],
            );

            return null;
        }

        return $stripped;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<int, mixed>  $locSuffix
     */
    private function error(string $type, string $name, string $message, mixed $input, array $ctx = [], array $locSuffix = []): array
    {
        $error = [
            'type' => $type,
            'loc' => array_merge(['body', $name], $locSuffix),
            'msg' => $message,
            'input' => $input,
        ];

        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<int, mixed>  $locSuffix
     */
    private static function bodyError(string $type, string $message, mixed $input, array $ctx = [], array $locSuffix = []): array
    {
        $error = ['type' => $type, 'loc' => array_merge(['body'], $locSuffix), 'msg' => $message, 'input' => $input];

        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }
}
