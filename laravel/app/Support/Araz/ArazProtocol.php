<?php

namespace App\Support\Araz;

/**
 * Builds and parses Araz T7 protocol packets — the port of `ArazProtocol` in
 * `app/services/araz_connector.py`, itself reverse-engineered from T7Broker.exe.
 *
 * Packet layout (identical to the Python):
 *
 *     [ARAZREQPROTO0002][request_id:2 LE][device_number:2 LE][payload]
 *
 * where `payload` is `verb` + GS + `field=value` (GS-separated) + RS.  The
 * separators are the ASCII control characters FS/GS/RS/US.
 *
 * The one deliberate difference from the Python is the request id width: the
 * Python packs it as `struct.pack("<H", …)` (16-bit) and masks the counter to
 * `0xFFFF`; this port does the same, so the wire bytes are identical.
 */
final class ArazProtocol
{
    public const HEADER_SIZE = 24;

    public const FILE_SEPARATOR = 0x1C;
    public const GROUP_SEPARATOR = 0x1D;
    public const RECORD_SEPARATOR = 0x1E;
    public const UNIT_SEPARATOR = 0x1F;

    private const REQUEST_HEADER = 'ARAZREQPROTO0002';
    private const RESPONSE_HEADER = 'ARAZRESPROTO0002';

    private const FS = "\x1C";
    private const GS = "\x1D";
    private const RS = "\x1E";
    private const US = "\x1F";

    private int $requestId = 0;

    public function __construct(private readonly int $deviceNumber = 1) {}

    /**
     * The next request id, wrapped at 16 bits exactly as the Python's
     * `_next_request_id` does.
     */
    public function nextRequestId(): int
    {
        $this->requestId = ($this->requestId + 1) & 0xFFFF;

        return $this->requestId;
    }

    /**
     * Build a complete request packet for *verb* with optional `key=value`
     * fields.
     *
     * @param  array<string, string>  $fields
     */
    public function buildRequest(string $verb, array $fields = [], ?int $requestId = null): string
    {
        $requestId ??= $this->nextRequestId();

        $parts = [$verb];

        foreach ($fields as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        $payload = implode(self::GS, $parts).self::RS;

        return self::REQUEST_HEADER
            .pack('v', $requestId)
            .pack('v', $this->deviceNumber)
            .$payload;
    }

    /**
     * Parse a response packet.
     *
     * @return array{request_id: int, device_number: int, verb: string, fields: array<string, string>, raw_payload: string}
     */
    public function parseResponse(string $data): array
    {
        if (strlen($data) < self::HEADER_SIZE) {
            throw new \ValueError('Response too short: '.strlen($data).' bytes');
        }

        $requestId = unpack('v', substr($data, 16, 2))[1];
        $deviceNumber = unpack('v', substr($data, 18, 2))[1];

        $payload = substr($data, 20);

        $mainPayload = str_contains($payload, self::RS)
            ? explode(self::RS, $payload)[0]
            : $payload;

        $parts = explode(self::GS, $mainPayload);
        $verb = $parts[0] ?? '';

        $fields = [];

        foreach (array_slice($parts, 1) as $part) {
            if (str_contains($part, '=')) {
                $key = strstr($part, '=', true);
                $value = substr($part, strpos($part, '=') + 1);
                $fields[trim($key)] = trim($value);
            }
        }

        return [
            'request_id' => $requestId,
            'device_number' => $deviceNumber,
            'verb' => $verb,
            'fields' => $fields,
            'raw_payload' => $payload,
        ];
    }

    /**
     * Parse one record line: `YYMMDD\tCardNo\tHHMM\tInOutType\tFlag`.
     * Returns null when the line is not a record, exactly as the Python.
     */
    public static function parseRecordLine(string $line): ?EnterExit
    {
        $parts = explode("\t", trim($line));

        if (count($parts) < 4) {
            return null;
        }

        // Python's `int(parts[3].strip())` inside the same try/except: a
        // non-numeric InOutType or Flag fails the *whole* line, not just the
        // field, so the record is dropped rather than defaulted.
        $inOut = self::pyInt($parts[3]);
        $flag = count($parts) > 4 ? self::pyInt($parts[4]) : 1;

        if ($inOut === null || $flag === null) {
            return null;
        }

        return new EnterExit(trim($parts[1]), trim($parts[0]), trim($parts[2]), $inOut, $flag);
    }

    /**
     * Python's `int(str)` for a trimmed string, or null when it would raise
     * `ValueError` — an optional sign and digits, nothing else.
     */
    private static function pyInt(string $value): ?int
    {
        $value = trim($value);

        return preg_match('/^[+-]?\d+$/', $value) === 1 ? (int) $value : null;
    }
}
