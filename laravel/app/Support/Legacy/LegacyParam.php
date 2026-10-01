<?php

namespace App\Support\Legacy;

/**
 * One declared query parameter — the declarative half of a ported `Query(...)`.
 *
 * A FastAPI signature is a *declaration*:
 *
 * ```python
 * async def list_sessions(
 *     page: int = Query(1, ge=1),
 *     per_page: int = Query(50, ge=1, le=200),
 *     active_only: bool = False,
 *     username: Optional[str] = None,
 * )
 * ```
 *
 * `LegacyQuery::validate()` takes the same declaration as an array of these, so the
 * ported controller reads like the Python it replaces and the rejection body is
 * derived from the declaration rather than from Laravel's rule strings — which is
 * what makes it possible to reproduce FastAPI's `type`/`msg`/`ctx` exactly.
 *
 * Instances are created by the named constructors on `LegacyQuery`, not directly.
 */
final class LegacyParam
{
    /** Declared types.  Only these three appear in the ported surface. */
    public const INT = 'integer';

    public const STRING = 'string';

    public const BOOL = 'boolean';

    /**
     * @param  string  $type  One of the type constants.
     * @param  bool  $required  `Query(...)` in Python — present but possibly empty.
     * @param  bool  $nullable  `Optional[str] = None` — absent means `null`, not `''`.
     * @param  mixed  $default  Value when the parameter is absent.     * @param  int|null $ge        Inclusive lower bound (`ge=`).
     * @param  int|null  $le  Inclusive upper bound (`le=`).
     * @param  int|null  $minLength  Minimum **characters** (`min_length=`, and
     *                               `Query(min_length=…)`).
     * @param  int|null  $maxLength  Maximum **characters** (`max_length=`).
     */
    public function __construct(
        public readonly string $type,
        public readonly bool $required = false,
        public readonly bool $nullable = false,
        public readonly mixed $default = null,
        public readonly ?int $ge = null,
        public readonly ?int $le = null,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
    ) {}
}
