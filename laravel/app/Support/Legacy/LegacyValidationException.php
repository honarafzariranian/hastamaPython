<?php

namespace App\Support\Legacy;

use RuntimeException;

/**
 * A request-parameter rejection, carrying FastAPI's **exact** 422 body.
 *
 * FastAPI validates a handler's declared `Query(...)` parameters before the handler
 * runs and answers `422 Unprocessable Entity` with a machine-readable list:
 *
 * ```json
 * {"detail":[{"type":"less_than_equal","loc":["query","limit"],
 *             "msg":"Input should be less than or equal to 200",
 *             "input":"201","ctx":{"le":200}}]}
 * ```
 *
 * `LegacyQuery` declares those same constraints and raises this, so the replacement
 * answers the same status **and the same body** — which matters because Laravel's own
 * validation failure renders as a `302` redirect for any request that does not send
 * `Accept: application/json`, and the existing front-end does not.
 *
 * Rendered by a callback in `bootstrap/app.php`, and excluded from the report list
 * there: it is a rejection, not a fault, and must not be logged as an unhandled error.
 */
final class LegacyValidationException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $detail  FastAPI's `detail` list, in
     *                                                    parameter-declaration order.
     */
    public function __construct(private readonly array $detail)
    {
        parent::__construct('The request parameters failed validation.');
    }

    /** @return array<int, array<string, mixed>> */
    public function detail(): array
    {
        return $this->detail;
    }

    /** The response body FastAPI would have produced. */
    public function body(): array
    {
        return ['detail' => $this->detail];
    }
}
