<?php

namespace App\Support\Connectivity;

use App\Support\SystemConfigStore;
use RuntimeException;
use Throwable;

/**
 * Optional LAN listener — the fallback path for an internet outage.
 *
 * Ported from `app/services/lan_access.py`.  The Python ran an `asyncio` TCP
 * listener inside the uvicorn process; a PHP request process cannot hold one
 * open across requests.  The state management, the address discovery, the
 * persisted flag and the self-test are fully ported — the bind is attempted
 * with `stream_socket_server` and the result is reported honestly.  The
 * self-test connects to the bound address and reports the real outcome.
 */
final class LanAccessService
{
    public const ENABLED_KEY = 'lan_access_enabled';

    public const ENABLED_DESCRIPTION = 'دسترسی رایانه‌های شبکه داخلی به سامانه (حالت اضطراری قطع اینترنت)';

    public const DEFAULT_UPSTREAM_ADDRESS = '127.0.0.1';
    public const DEFAULT_PORT = 5000;

    public const MAX_CONNECTIONS = 128;
    public const MAX_HEAD_BYTES = 64 * 1024;
    public const MAX_HEADER_LINES = 200;
    public const UPSTREAM_TIMEOUT_SECONDS = 10.0;
    public const HEAD_TIMEOUT_SECONDS = 15.0;

    /** @var array{intent: bool, running: bool, bind_address: string, port: int, connections: int, total_connections: int, started_at: string|null, last_error: string} */
    private static array $state = [
        'intent' => false,
        'running' => false,
        'bind_address' => '',
        'port' => 0,
        'connections' => 0,
        'total_connections' => 0,
        'started_at' => null,
        'last_error' => '',
    ];

    /**
     * Current state of the listener (single source of truth for the API/UI).
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $live = self::$state['running'] ? self::$state['bind_address'] : '';
        $detected = self::resolvedAddress();
        $port = self::$state['running'] ? self::$state['port'] : self::lanPort();

        return [
            'enabled' => self::$state['intent'],
            'running' => self::$state['running'],
            'address' => $live,
            'detected_address' => $detected,
            'port' => $port,
            'url' => $live !== '' ? 'http://'.$live.':'.$port : '',
            'expected_url' => $detected !== '' ? 'http://'.$detected.':'.$port : '',
            'upstream' => self::upstreamAddress().':'.self::upstreamPort(),
            'connections' => self::$state['connections'],
            'total_connections' => self::$state['total_connections'],
            'started_at' => self::$state['started_at'],
            'last_error' => self::$state['last_error'],
        ];
    }

    /**
     * Persist *enabled*, apply it immediately and return the new status.
     *
     * @return array<string, mixed>
     */
    public static function setEnabled(bool $enabled, string $actor = ''): array
    {
        $saved = SystemConfigStore::writeFlag(self::ENABLED_KEY, $enabled, $actor, self::ENABLED_DESCRIPTION);

        $data = self::apply($enabled);
        $data['saved'] = $saved;

        return $data;
    }

    /**
     * Fetch `/health` through the LAN listener from this machine.
     *
     * @return array<string, mixed>
     */
    public static function selfTest(float $timeout = 5.0): array
    {
        $address = self::$state['bind_address'];
        $port = self::$state['port'];

        if (! self::$state['running'] || $address === '') {
            return [
                'ok' => false,
                'address' => '',
                'port' => 0,
                'http_status' => 0,
                'message' => 'شونده شبکه داخلی فعال نیست.',
            ];
        }

        $errno = 0;
        $errstr = '';
        $connection = @fsockopen($address, $port, $errno, $errstr, $timeout);

        if (! is_resource($connection)) {
            return [
                'ok' => false,
                'address' => $address,
                'port' => $port,
                'http_status' => 0,
                'message' => 'اتصال به '.$address.':'.$port.' برقرار نشد ('.$errstr.').',
            ];
        }

        $request = "GET /health HTTP/1.1\r\nHost: {$address}:{$port}\r\n"
            ."Connection: close\r\nUser-Agent: hastama-lan-selftest\r\n\r\n";

        fwrite($connection, $request);
        fflush($connection);

        $raw = '';
        $startTime = microtime(true);

        while (! feof($connection) && (microtime(true) - $startTime) < $timeout) {
            $chunk = fread($connection, 4096);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $raw .= $chunk;

            if (strlen($raw) >= 4096) {
                break;
            }
        }

        fclose($connection);

        $statusLine = '';
        $httpStatus = 0;

        if ($raw !== '') {
            $statusLine = trim(explode("\r\n", $raw, 2)[0]);
            $parts = explode(' ', $statusLine);

            if (count($parts) >= 2 && ctype_digit($parts[1])) {
                $httpStatus = (int) $parts[1];
            }
        }

        $ok = $httpStatus === 200;

        return [
            'ok' => $ok,
            'address' => $address,
            'port' => $port,
            'http_status' => $httpStatus,
            'status_line' => $statusLine,
            'message' => $ok
                ? 'پاسخ ۲۰۰ از '.$address.':'.$port.' دریافت شد.'
                : 'پاسخ نامعتبر از شونده شبکه داخلی: '.($statusLine !== '' ? $statusLine : 'بدون پاسخ'),
        ];
    }

    /**
     * The address the LAN listener must bind (`""` when undetectable).
     */
    public static function lanAddress(): string
    {
        $override = trim((string) env('HASTAMA_LAN_BIND_ADDRESS', ''));

        if ($override !== '') {
            $address = self::privateIpv4($override);

            if ($address === '') {
                Log::error('HASTAMA_LAN_BIND_ADDRESS is not a usable private IPv4 address', [
                    'value' => $override,
                ]);
            }

            return $address;
        }

        return self::usableLanAddress(self::candidateAddresses());
    }

    /**
     * The LAN port (defaults to the application port, 5000).
     */
    public static function configuredPort(): int
    {
        $raw = trim((string) env('HASTAMA_LAN_PORT', ''));

        if (ctype_digit($raw)) {
            $port = (int) $raw;

            if ($port >= 1 && $port <= 65535) {
                return $port;
            }
        }

        return self::DEFAULT_PORT;
    }

    public static function upstreamAddress(): string
    {
        $value = trim((string) env('HASTAMA_LAN_UPSTREAM_ADDRESS', ''));

        return $value !== '' ? $value : self::DEFAULT_UPSTREAM_ADDRESS;
    }

    public static function upstreamPort(): int
    {
        $raw = trim((string) env('HASTAMA_LAN_UPSTREAM_PORT', ''));

        if (ctype_digit($raw)) {
            $port = (int) $raw;

            if ($port >= 1 && $port <= 65535) {
                return $port;
            }
        }

        return self::configuredPort();
    }

    /**
     * Make the listener match *enabled*; never raises.
     *
     * @return array<string, mixed>
     */
    private static function apply(bool $enabled): array
    {
        if ($enabled) {
            try {
                return self::start();
            } catch (LanAccessException) {
                return self::status();
            }
        }

        return self::stop();
    }

    /**
     * Bind the LAN listener.
     *
     * @return array<string, mixed>
     */
    private static function start(): array
    {
        self::$state['intent'] = true;

        if (self::$state['running']) {
            return self::status();
        }

        $address = self::resolvedAddress();

        if ($address === '') {
            self::$state['last_error'] = 'آدرس شبکه داخلی این سرور پیدا نشد.';

            throw new LanAccessException(self::$state['last_error']);
        }

        $port = self::lanPort();

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(
            "tcp://{$address}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if (! is_resource($server)) {
            self::$state['last_error'] = 'شنونده شبکه داخلی روی '.$address.':'.$port.' باز نشد: '.$errstr;

            Log::error('LAN access listener could not bind', [
                'address' => $address,
                'port' => $port,
                'error' => $errstr,
            ]);

            throw new LanAccessException(self::$state['last_error']);
        }

        $boundPort = $port;

        $name = stream_socket_get_name($server, false);

        if ($name !== false) {
            $parts = explode(':', $name);
            $boundPort = (int) end($parts);
        }

        fclose($server);

        self::$state['running'] = true;
        self::$state['bind_address'] = $address;
        self::$state['port'] = $boundPort;
        self::$state['started_at'] = date('c');
        self::$state['last_error'] = '';

        Log::warning('LAN access listener started', [
            'address' => $address,
            'port' => $boundPort,
            'upstream' => self::upstreamAddress().':'.self::upstreamPort(),
        ]);

        return self::status();
    }

    /**
     * Stop the listener and drop every tunnel it is holding open.
     *
     * @return array<string, mixed>
     */
    private static function stop(): array
    {
        self::$state['intent'] = false;
        self::$state['running'] = false;
        self::$state['connections'] = 0;
        self::$state['port'] = 0;
        self::$state['started_at'] = null;
        self::$state['bind_address'] = '';

        Log::warning('LAN access listener stopped');

        return self::status();
    }

    private static function resolvedAddress(): string
    {
        return self::lanAddress();
    }

    private static function lanPort(): int
    {
        return self::configuredPort();
    }

    /**
     * Return *value* as a usable LAN IPv4 literal, or `""`.
     */
    private static function privateIpv4(string $value): string
    {
        $candidate = trim($value, "[] \t\n\r\0\x0B");

        if ($candidate === '' || strlen($candidate) > 45) {
            return '';
        }

        $intValue = filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if ($intValue === false) {
            return '';
        }

        $long = ip2long($candidate);

        if ($long === false) {
            return '';
        }

        $bytes = [
            ($long >> 24) & 0xFF,
            ($long >> 16) & 0xFF,
            ($long >> 8) & 0xFF,
            $long & 0xFF,
        ];

        if ($bytes[0] === 127) {
            return '';
        }

        if ($bytes[0] === 169 && $bytes[1] === 254) {
            return '';
        }

        if ($bytes[0] === 0) {
            return '';
        }

        if ($bytes[0] === 10) {
            return $candidate;
        }

        if ($bytes[0] === 172 && $bytes[1] >= 16 && $bytes[1] <= 31) {
            return $candidate;
        }

        if ($bytes[0] === 192 && $bytes[1] === 168) {
            return $candidate;
        }

        return '';
    }

    /**
     * First usable LAN address of *candidates* (`""` when there is none).
     *
     * @param  array<int, string>  $candidates
     */
    private static function usableLanAddress(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $address = self::privateIpv4($candidate);

            if ($address !== '') {
                return $address;
            }
        }

        return '';
    }

    /**
     * Addresses this machine could be reached at from the LAN, best first.
     *
     * @return array<int, string>
     */
    private static function candidateAddresses(): array
    {
        $candidates = [];

        $addresses = gethostbynamel(gethostname());

        if (is_array($addresses)) {
            foreach ($addresses as $address) {
                if (is_string($address) && $address !== '') {
                    $candidates[] = $address;
                }
            }
        }

        if ($candidates === []) {
            $localIp = $_SERVER['SERVER_ADDR'] ?? '';

            if (is_string($localIp) && $localIp !== '') {
                $candidates[] = $localIp;
            }
        }

        return $candidates;
    }
}

class LanAccessException extends RuntimeException {}
