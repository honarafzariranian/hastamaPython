<?php

namespace App\Http\Controllers\Araz;

use App\Http\Controllers\Controller;
use App\Support\Araz\DeviceConfig;
use App\Support\Legacy\LegacyValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET|POST /api/araz/config` — the device connection configuration, ported
 * from `get_device_config` / `update_device_config` in
 * `app/api/routes/araz_api.py`.
 *
 * The configuration is process memory, exactly as the Python's module-level
 * `_device_config` dict: it is not persisted, so a restart restores the
 * connector defaults.  `POST` accepts a partial update — only the fields
 * present in the body change — and answers the whole config back.
 *
 * The body is validated the way pydantic validated `DeviceConfigUpdate`:
 * every field is optional, but a field that is present must be of the
 * declared type, and a refusal is FastAPI's 422 body rather than Laravel's
 * redirect.
 */
final class ArazConfigController extends Controller
{
    /** `GET /api/araz/config` */
    public function show(): JsonResponse
    {
        return response()->json(DeviceConfig::all());
    }

    /** `POST /api/araz/config` */
    public function update(Request $request): JsonResponse
    {
        $decoded = json_decode($request->getContent());

        if (! is_object($decoded)) {
            throw new LegacyValidationException([self::bodyError(
                'model_attributes_type',
                'Input should be a valid dictionary or object to extract fields from',
                $decoded,
            )]);
        }

        $body = (array) $decoded;
        $changes = [];

        if (array_key_exists('ip', $body)) {
            $changes['ip'] = $this->stringField($body['ip'], 'ip');
        }

        if (array_key_exists('port', $body)) {
            $changes['port'] = $this->intField($body['port'], 'port');
        }

        if (array_key_exists('device_number', $body)) {
            $changes['device_number'] = $this->intField($body['device_number'], 'device_number');
        }

        if (array_key_exists('timeout', $body)) {
            $changes['timeout'] = $this->floatField($body['timeout'], 'timeout');
        }

        $config = DeviceConfig::update($changes);

        return response()->json(['status' => 'ok', 'config' => $config]);
    }

    /**
     * `ip: Optional[str]` — a present value must be a string.
     *
     * @return string
     */
    private function stringField(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new LegacyValidationException([
                $this->error('string_type', $field, 'Input should be a valid string', $value),
            ]);
        }

        return $value;
    }

    /**
     * `port: Optional[int]` / `device_number: Optional[int]`.
     *
     * Pydantic's lax integer parse: an int, or a string of digits, or an
     * integral float.  A string that is not digits is `int_parsing`; any
     * other non-integer is `int_type`.
     *
     * @return int
     */
    private function intField(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[+-]?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_float($value) && $value === floor($value) && ! is_infinite($value)) {
            return (int) $value;
        }

        $type = is_string($value) ? 'int_parsing' : 'int_type';
        $message = is_string($value)
            ? 'Input should be a valid integer, unable to parse string as an integer'
            : 'Input should be a valid integer';

        throw new LegacyValidationException([
            $this->error($type, $field, $message, $value),
        ]);
    }

    /**
     * `timeout: Optional[float]` — an int, a float, or a numeric string.
     *
     * @return float
     */
    private function floatField(mixed $value, string $field): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        $type = is_string($value) ? 'float_parsing' : 'float_type';
        $message = is_string($value)
            ? 'Input should be a valid number, unable to parse string as a number'
            : 'Input should be a valid number';

        throw new LegacyValidationException([
            $this->error($type, $field, $message, $value),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private static function bodyError(string $type, string $message, mixed $input, array $ctx = []): array
    {
        $error = ['type' => $type, 'loc' => ['body'], 'msg' => $message, 'input' => $input];

        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function error(string $type, string $field, string $message, mixed $input, array $ctx = []): array
    {
        $error = ['type' => $type, 'loc' => ['body', $field], 'msg' => $message, 'input' => $input];

        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }
}
