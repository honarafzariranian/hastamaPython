<?php

namespace App\Support\Araz;

use Throwable;

/**
 * Direct TCP/IP connector for the Araz T7 attendance device — the port of
 * `ArazDevice` in `app/services/araz_connector.py`.
 *
 * The transport is one request packet followed by one response packet per
 * command, exactly as the Python's `_send_request` does: write the whole
 * packet, then read a single chunk (up to 64 KiB) with the configured
 * timeout.  The device is a LAN appliance that is frequently unreachable
 * from the server, so every failure mode the Python distinguishes is kept:
 *
 * * a refused or timed-out connection raises `ConnectionError`, which the
 *   `/test` endpoint turns into `connected: false` rather than a 500;
 * * a device that accepts the connection but answers nothing raises
 *   `ConnectionError("Device closed connection")` or a timeout;
 * * a response that is too short raises `ValueError`.
 *
 * No record is ever fabricated: if the device cannot be reached, the
 * endpoint's own error path answers, and the connector holds no cache.
 */
final class ArazDevice
{
    /** The verbs the protocol defines, as the Python's `Verb` class. */
    public const GET_CURRENT_TIME = 'get_current_time';

    public const SET_CURRENT_TIME = 'set_current_time';

    public const GET_RECORDS = 'get_records';

    public const TEST_CONNECTION = 'test_connection';

    /** The Python reads at most this much per response. */
    private const READ_CHUNK = 65536;

    /** The Python's `end_of_data` marker, which terminates a record stream. */
    private const END_OF_DATA = 'end_of_data';

    private $socket = null;

    private bool $connected = false;

    private readonly ArazProtocol $protocol;

    public function __construct(
        public readonly string $ip = '192.168.3.200',
        public readonly int $port = 1001,
        public readonly int $deviceNumber = 1,
        public readonly float $timeout = 10.0,
    ) {
        $this->protocol = new ArazProtocol($deviceNumber);
    }

    /**
     * Open the TCP connection.
     *
     * @throws ConnectionError when the device cannot be reached, with the
     *                         Python's message.
     */
    public function connect(): void
    {
        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($this->ip, $this->port, $errno, $errstr, $this->timeout);

        if ($socket === false) {
            throw new ConnectionError(
                "Cannot connect to Araz T7 at {$this->ip}:{$this->port}: {$errstr} ({$errno})"
            );
        }

        stream_set_timeout($socket, (int) $this->timeout);

        $this->socket = $socket;
        $this->connected = true;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->connected = false;
        $this->socket = null;
    }

    /**
     * Whether the device answered `test_connection`.
     *
     * The Python catches every failure here and answers `false` rather than
     * propagating, so a device that accepts the connection but misbehaves on
     * this verb is reported as `connected: false` rather than throwing.
     */
    public function testConnection(): bool
    {
        try {
            $this->sendRequest(self::TEST_CONNECTION);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The device's current clock, or null when it did not answer with a
     * parsable `yyyy MM dd HH mm ss` string.
     */
    public function getCurrentTime(): ?DeviceTime
    {
        $response = $this->sendRequest(self::GET_CURRENT_TIME);

        $time = trim($response['fields']['time'] ?? '');

        if ($time === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $time);

        if (count($parts) < 6) {
            return null;
        }

        $numbers = array_map(static fn (string $part): int => (int) $part, array_slice($parts, 0, 6));

        if (count(array_filter($numbers, static fn (int $n): bool => $n < 0)) > 0) {
            return null;
        }

        return new DeviceTime($numbers[0], $numbers[1], $numbers[2], $numbers[3], $numbers[4], $numbers[5]);
    }

    /**
     * Fetch attendance records, optionally bounded by `yyyy MM dd HH mm ss`
     * timestamps.
     *
     * @return array<int, EnterExit>
     */
    public function getRecords(?string $fromTime = null, ?string $toTime = null): array
    {
        $fields = [];

        if ($fromTime !== null && $fromTime !== '') {
            $fields['from_time'] = $fromTime;
        }

        if ($toTime !== null && $toTime !== '') {
            $fields['to_time'] = $toTime;
        }

        $response = $this->sendRequest(self::GET_RECORDS, $fields);

        $records = [];

        foreach (explode(ArazProtocol::RS, $response['raw_payload']) as $line) {
            $line = trim($line);

            if ($line === '' || $line === self::END_OF_DATA) {
                continue;
            }

            $record = ArazProtocol::parseRecordLine($line);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Set the device clock to the server's now.
     *
     * The Python returns whether the response carried a `succeeded` field.
     */
    public function setCurrentTime(): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));

        $time = $now->format('Y m d H i s');

        $response = $this->sendRequest(self::SET_CURRENT_TIME, ['time' => $time]);

        return array_key_exists('succeeded', $response['fields']);
    }

    /**
     * Send one request and parse one response.
     *
     * @param  array<string, string>  $fields
     * @return array{request_id: int, device_number: int, verb: string, fields: array<string, string>, raw_payload: string}
     *
     * @throws ConnectionError when not connected, when the device closes the
     *                         connection, or when it does not answer in time.
     */
    private function sendRequest(string $verb, array $fields = []): array
    {
        if (! $this->connected || ! is_resource($this->socket)) {
            throw new ConnectionError('Not connected to device');
        }

        $packet = $this->protocol->buildRequest($verb, $fields);

        fwrite($this->socket, $packet);

        $data = @fread($this->socket, self::READ_CHUNK);

        if ($data === false || $data === '') {
            throw new ConnectionError('Device closed connection');
        }

        try {
            return $this->protocol->parseResponse($data);
        } catch (Throwable $exception) {
            throw new ConnectionError(
                "Cannot connect to Araz T7 at {$this->ip}:{$this->port}: {$exception->getMessage()}"
            );
        }
    }
}
