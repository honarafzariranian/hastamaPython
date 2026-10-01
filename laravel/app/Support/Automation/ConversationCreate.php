<?php

namespace App\Support\Automation;

use App\Support\Legacy\LegacyBody;
use App\Support\Legacy\LegacyParam;
use App\Support\Legacy\LegacyValidationException;
use Illuminate\Http\Request;

/**
 * `class ConversationCreate(BaseModel)` — the pydantic model `POST /api/automation`
 * validates its body against, error list and all.
 *
 * The declaration is three fields in one order, and the running server reports a
 * rejection in exactly that order — `subject`, then `participants`, then `body`:
 *
 * ```json
 * {"detail":[{"type":"string_type","loc":["body","subject"],…},
 *            {"type":"string_type","loc":["body","participants",0],…},
 *            {"type":"string_type","loc":["body","body"],…}]}
 * ```
 *
 * Two of the three are ordinary strings and are validated by `LegacyBody`, which already
 * reproduces `missing`/`string_type`/`string_too_short`/`string_too_long` byte for byte —
 * including the singular `1 character` of `min_length=1`, and including `input` echoing
 * the **whole body** for a missing field (`{"subject":"ab"}`) rather than the field's
 * value.  What `LegacyBody` cannot express is the third field:
 *
 * ```python
 * participants: list[str] = Field(default_factory=list, max_length=100)
 * ```
 *
 * It is a list, so a bad value is `list_type` rather than `string_type`, a bad *element*
 * is `string_type` reported against `["body","participants",0]` — a loc with three
 * segments, which no field-level error in this codebase has — and the bound is a
 * `too_long` about items rather than characters.  It is validated here, between the two
 * `LegacyBody` calls, so that one `LegacyValidationException` carries the whole list in
 * declaration order rather than three exceptions each carrying a fragment.
 *
 * **An absent field and an explicit `null` are different requests.**  `default_factory`
 * answers `[]` for the first; pydantic answers `list_type` with `"input": null` for the
 * second, which is why `participants` is not declared `nullable` and why the check is
 * `property_exists` rather than a null-coalescing read.
 *
 * **The length bound is checked before the elements are.**  `participants` of 101
 * integers answers one `too_long` and no `string_type` — verified against the running
 * server — so the count short-circuits rather than being reported alongside.
 *
 * ```php
 * $payload = ConversationCreate::fromRequest($request);
 * $service->create($actor, $payload->subject, $payload->participants, $payload->body);
 * ```
 */
final class ConversationCreate
{
    /** `Field(..., max_length=100)` on the list. */
    public const MAX_PARTICIPANTS = 100;

    /**
     * @param  string  $subject  `Field(min_length=2, max_length=180)`, untrimmed.
     * @param  array<int, string>  $participants  The list as it arrived, untrimmed.
     * @param  string  $body  `Field(min_length=1, max_length=4000)`, untrimmed.
     */
    private function __construct(
        public readonly string $subject,
        public readonly array $participants,
        public readonly string $body,
    ) {}

    /**
     * Validate the raw request body against the model.
     *
     * @throws LegacyValidationException 422 with FastAPI's `detail` list.
     */
    public static function fromRequest(Request $request): self
    {
        $raw = (string) $request->getContent();

        // The model-level gate: FastAPI rejects a missing, unparseable or non-object body
        // before it looks at any field, against `loc: ["body"]` alone.  With an empty
        // spec this validates the *shape* and nothing else, so no field error can be
        // raised twice.
        LegacyBody::validateJson($raw, []);

        // Known to be a JSON object by the gate above, so this decode cannot fail.
        /** @var object $decoded */
        $decoded = json_decode(trim($raw));
        $fields = (array) $decoded;

        $errors = [];
        $subject = '';
        $participants = [];
        $body = '';

        try {
            $subject = LegacyBody::validate(
                $fields,
                ['subject' => self::subjectParam()],
                modelInput: $decoded,
            )['subject'];
        } catch (LegacyValidationException $exception) {
            $errors = array_merge($errors, $exception->detail());
        }

        $participants = self::participants($decoded, $errors);

        try {
            $body = LegacyBody::validate(
                $fields,
                ['body' => self::bodyParam()],
                modelInput: $decoded,
            )['body'];
        } catch (LegacyValidationException $exception) {
            $errors = array_merge($errors, $exception->detail());
        }

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        return new self($subject, $participants, $body);
    }

    /** `subject: str = Field(min_length=2, max_length=180)` */
    private static function subjectParam(): LegacyParam
    {
        return new LegacyParam(
            LegacyParam::STRING,
            required: true,
            minLength: 2,
            maxLength: 180,
        );
    }

    /** `body: str = Field(min_length=1, max_length=4000)` */
    private static function bodyParam(): LegacyParam
    {
        return new LegacyParam(
            LegacyParam::STRING,
            required: true,
            minLength: 1,
            maxLength: 4000,
        );
    }

    /**
     * `participants: list[str] = Field(default_factory=list, max_length=100)`.
     *
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place, so the
     *                                                    field keeps its position between
     *                                                    `subject` and `body`.
     * @return array<int, string>  The accepted elements; empty whenever an error was
     *                             recorded, because the caller throws on any error.
     */
    private static function participants(object $decoded, array &$errors): array
    {
        if (! property_exists($decoded, 'participants')) {
            return [];
        }

        /** @var mixed $value */
        $value = $decoded->participants;

        // A JSON array decodes to a PHP array; everything else — object, string, number,
        // `null` — is `list_type`.  Read off the decoded value rather than off a cast
        // array, because `(array)` turns `{"0":"a"}` into a list where pydantic saw a
        // dictionary.
        if (! is_array($value)) {
            $errors[] = [
                'type' => 'list_type',
                'loc' => ['body', 'participants'],
                'msg' => 'Input should be a valid list',
                'input' => $value,
            ];

            return [];
        }

        $count = count($value);

        // Checked first: 101 integers answer one `too_long` and no element errors.
        if ($count > self::MAX_PARTICIPANTS) {
            $errors[] = [
                'type' => 'too_long',
                'loc' => ['body', 'participants'],
                'msg' => 'List should have at most '.self::MAX_PARTICIPANTS
                    .' items after validation, not '.$count,
                'input' => $value,
                'ctx' => [
                    'field_type' => 'List',
                    'max_length' => self::MAX_PARTICIPANTS,
                    'actual_length' => $count,
                ],
            ];

            return [];
        }

        $names = [];

        foreach ($value as $index => $item) {
            if (! is_string($item)) {
                $errors[] = [
                    'type' => 'string_type',
                    'loc' => ['body', 'participants', $index],
                    'msg' => 'Input should be a valid string',
                    'input' => $item,
                ];

                continue;
            }

            $names[] = $item;
        }

        return $names;
    }
}
