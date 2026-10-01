<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The single response envelope for every JSON endpoint.
 *
 * The new Vue client reads exactly two shapes:
 *
 *   success: {"success": true,  "data": {...}, "message": null}
 *   failure: {"success": false, "message": "..."}
 *
 * Keeping this in one place is deliberate: the brief requires a consistent
 * structure and forbids leaking internals, so error responses never carry a
 * stack trace, a file path or a database message.  The technical detail is
 * logged server-side instead (see the controllers that catch Throwable).
 */
final class ApiResponse
{
    /**
     * A successful response.
     *
     * @param  mixed  $data  Payload for the client; null is valid for actions with no body.
     * @param  string|null  $message  Optional operator-facing message; null when there is nothing to say.
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    /**
     * A failed response.
     *
     * $errors is only ever populated from validation failures, where the field
     * names are already part of the client contract.  Messages must stay
     * user-facing Persian and must not describe the implementation.
     *
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(
        string $message,
        int $status = 400,
        ?array $errors = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * A failed response carrying extra top-level keys.
     *
     * The existing API is not uniform, and the migration keeps its shapes exactly
     * because the running front-end branches on them.  Two examples the login and
     * CAPTCHA endpoints need:
     *
     *   {"success": false, "message": "…", "captcha_error": true,
     *    "captcha_reason": "expired", "captcha_expired": true}
     *
     *   {"success": false, "error": "دسترسی مدیریتی ندارید."}
     *
     * Note the second: some guards answer `error` instead of `message`.  That
     * inconsistency is real, is documented, and is preserved rather than tidied —
     * `success: false` stays the one invariant every client can rely on.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function errorWith(string $message, array $extra = [], int $status = 200): JsonResponse
    {
        return new JsonResponse(array_merge([
            'success' => false,
            'message' => $message,
        ], $extra), $status);
    }

    /**
     * Validation failure with the field-keyed messages the Vue forms expect.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    public static function validation(array $errors, string $message = 'اطلاعات ارسالی معتبر نیست.'): JsonResponse
    {
        return self::error($message, 422, $errors);
    }
}
