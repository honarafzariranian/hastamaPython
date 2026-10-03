<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OS printer discovery for the label studio.
 *
 * Ported from `app/services/printer.py`.  The spooler of the machine that runs the
 * server is the only reliable source of truth for printer queues: WebUSB /
 * WebSerial are unavailable on the LAN build (plain HTTP, no secure context)
 * and a network printer queue is never exposed to the browser anyway.
 *
 * Windows enumerates through PowerShell (`Get-CimInstance Win32_Printer`),
 * POSIX through `lpstat`.  Both are real process spawns — the result is cached
 * for a few seconds so the label studio does not fan out one probe per page load.
 */
final class PrinterService
{
    /** Sub-strings that mark a queue as a label / receipt printer. */
    private const LABEL_TOKENS = [
        'epson', 'tm-t', 'tm_t', 'tmt', 'thermal', 'receipt', 'label', 'zebra',
        'godex', 'xprinter', 'bixolon', 'citizen', 'argox', 'tsc ', 'pos-',
    ];

    /** Queues that are software endpoints, never a physical label printer. */
    private const VIRTUAL_TOKENS = [
        'print to pdf', 'microsoft xps', 'xps document', 'onenote', 'fax',
        'cutepdf', 'pdfwriter', 'snagit', 'dorsandesk', 'virtual', 'adobe pdf',
    ];

    /** How long a spooler snapshot stays valid. */
    private const CACHE_TTL_SECONDS = 20.0;

    private const POWERSHELL_TIMEOUT = 8;

    /** Win32_Printer.PrinterStatus → normalised state. */
    private const STATUS_MAP = [
        2 => 'unknown',
        3 => 'ready',
        4 => 'busy',
        5 => 'busy',
        6 => 'paused',
        7 => 'offline',
    ];

    /** @var array{at: float, rows: array<int, array<string, mixed>>|null} */
    private static array $cache = ['at' => 0.0, 'rows' => null];

    /**
     * The spooler's printer queues (cached for a few seconds).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rawPrinters(bool $force = false): array
    {
        $fresh = (microtime(true) - self::$cache['at']) < self::CACHE_TTL_SECONDS;

        if (self::$cache['rows'] !== null && $fresh && ! $force) {
            return self::$cache['rows'];
        }

        $rows = [];

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                try {
                    $rows = self::windowsSpoolerPrinters();
                } catch (Throwable $exception) {
                    Log::debug('spooler enumeration failed; falling back to registry', [
                        'exception' => $exception::class,
                    ]);
                }

                if ($rows === []) {
                    $rows = self::windowsRegistryPrinters();
                }

                if ($rows === []) {
                    $default = self::windowsRegistryDefault();

                    if ($default !== '') {
                        $rows = [[
                            'name' => $default,
                            'status' => 'unknown',
                            'port' => '',
                            'driver' => '',
                            'is_default' => true,
                        ]];
                    }
                }
            } else {
                $rows = self::posixPrinters();
            }
        } catch (Throwable $exception) {
            Log::warning('printer enumeration failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $rows = [];
        }

        self::$cache['rows'] = $rows;
        self::$cache['at'] = microtime(true);

        return $rows;
    }

    /**
     * The payload consumed by `GET /master-admin/api/printers`.
     *
     * @return array<string, mixed>
     */
    public static function describePrinters(bool $force = false): array
    {
        $printers = array_map(static fn (array $row): array => self::decorate($row), self::rawPrinters($force));

        $default = null;
        $label = null;

        foreach ($printers as $printer) {
            if ($default === null && $printer['is_default']) {
                $default = $printer;
            }
        }

        $label = self::pickLabelPrinter($printers);

        return [
            'platform' => PHP_OS_FAMILY,
            'host' => gethostname() ?: '',
            'printers' => $printers,
            'default_printer' => $default['name'] ?? '',
            'label_printer' => $label['name'] ?? '',
            'label_printer_source' => $label !== null
                ? ($label['is_default'] ? 'default' : 'candidate')
                : '',
            'enumerated' => $printers !== [],
        ];
    }

    /**
     * Best guess for the queue a queue-number label should be sent to.
     *
     * Preference order: the OS default queue when it looks like a label printer,
     * then any label-looking queue that is ready, then any label-looking queue.
     *
     * @param  array<int, array<string, mixed>>|null  $printers
     */
    public static function pickLabelPrinter(?array $printers = null): ?array
    {
        $rows = $printers ?? array_map(static fn (array $row): array => self::decorate($row), self::rawPrinters());

        $candidates = array_values(array_filter(
            $rows,
            static fn (array $printer): bool => $printer['is_label'],
        ));

        if ($candidates === []) {
            return null;
        }

        $ready = array_values(array_filter(
            $candidates,
            static fn (array $printer): bool => in_array($printer['status'], ['ready', 'busy'], true),
        ));

        $pool = $ready !== [] ? $ready : $candidates;

        foreach ($pool as $pref) {
            if ($pref['is_default']) {
                return $pref;
            }
        }

        return $pool[0];
    }

    /**
     * Add the computed `is_virtual` / `is_label` flags to a raw row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function decorate(array $row): array
    {
        $name = $row['name'];
        $virtual = self::matches($name, self::VIRTUAL_TOKENS);

        return [
            'name' => $name,
            'status' => $row['status'] ?? 'unknown',
            'port' => $row['port'] ?? '',
            'driver' => $row['driver'] ?? '',
            'is_default' => (bool) ($row['is_default'] ?? false),
            'is_virtual' => $virtual,
            'is_label' => ! $virtual && self::matches($name, self::LABEL_TOKENS),
        ];
    }

    private static function matches(string $name, array $tokens): bool
    {
        $low = mb_strtolower($name);

        foreach ($tokens as $token) {
            if (str_contains($low, $token)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function windowsSpoolerPrinters(): array
    {
        $script = 'Get-CimInstance Win32_Printer | '
            .'Select-Object Name,Default,PrinterStatus,WorkOffline,PortName,DriverName | '
            .'ConvertTo-Json -Compress';

        $stdout = self::run(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script]);
        $stdout = trim($stdout);

        if ($stdout === '') {
            return [];
        }

        $payload = json_decode($stdout, true);

        if (! is_array($payload)) {
            return [];
        }

        if (isset($payload['Name'])) {
            $payload = [$payload];
        }

        $rows = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = self::normalise((string) ($item['Name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $offline = (bool) ($item['WorkOffline'] ?? false);
            $statusKey = (int) ($item['PrinterStatus'] ?? 0);
            $state = self::STATUS_MAP[$statusKey] ?? 'unknown';

            if ($offline) {
                $state = 'offline';
            }

            $rows[] = [
                'name' => $name,
                'status' => $state,
                'port' => self::normalise((string) ($item['PortName'] ?? '')),
                'driver' => self::normalise((string) ($item['DriverName'] ?? '')),
                'is_default' => (bool) ($item['Default'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function windowsRegistryPrinters(): array
    {
        $rows = [];

        $output = self::run([
            'powershell', '-NoProfile', '-NonInteractive', '-Command',
            'Get-ChildItem "HKLM:\SYSTEM\CurrentControlSet\Control\Print\Printers" | '
            .'ForEach-Object { $_.PSChildName }',
        ]);

        foreach (preg_split('/\r\n|\r|\n/', $output) as $line) {
            $name = self::normalise($line);

            if ($name !== '') {
                $rows[] = [
                    'name' => $name,
                    'status' => 'unknown',
                    'port' => '',
                    'driver' => '',
                    'is_default' => false,
                ];
            }
        }

        $default = self::windowsRegistryDefault();

        foreach ($rows as &$row) {
            $row['is_default'] = $default !== '' && mb_strtolower($row['name']) === mb_strtolower($default);
        }

        return $rows;
    }

    private static function windowsRegistryDefault(): string
    {
        $output = self::run([
            'powershell', '-NoProfile', '-NonInteractive', '-Command',
            '(Get-ItemProperty "HKCU:\Software\Microsoft\Windows NT\CurrentVersion\Windows" -ErrorAction SilentlyContinue).Device',
        ]);

        $device = trim($output);
        $parts = explode(',', $device);

        return self::normalise($parts[0] ?? '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function posixPrinters(): array
    {
        $default = '';

        $lpstat = self::binaryPath('lpstat');

        if ($lpstat !== null) {
            $out = self::run([$lpstat, '-d']);
            $out = trim($out);

            if (preg_match('/:\s*(.+)$/u', $out, $matches) === 1) {
                $default = self::normalise($matches[1]);
            }
        }

        $rows = [];

        if ($lpstat !== null) {
            $out = self::run([$lpstat, '-p']);

            foreach (preg_split('/\r\n|\r|\n/', $out) as $line) {
                if (preg_match('/printer\s+(\S+)\s+is\s+(\S+)/u', trim($line), $matches) !== 1) {
                    continue;
                }

                $name = self::normalise($matches[1]);
                $state = mb_strtolower($matches[2]);

                $rows[] = [
                    'name' => $name,
                    'status' => in_array($state, ['disabled', 'stopped'], true) ? 'offline' : 'ready',
                    'port' => '',
                    'driver' => '',
                    'is_default' => $default !== '' && $name === $default,
                ];
            }
        }

        if ($rows === [] && $default !== '') {
            $rows[] = [
                'name' => $default,
                'status' => 'unknown',
                'port' => '',
                'driver' => '',
                'is_default' => true,
            ];
        }

        return $rows;
    }

    private static function binaryPath(string $name): ?string
    {
        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';

        $output = self::run([$which, $name]);
        $output = trim($output);

        return $output === '' ? null : $output;
    }

    /**
     * @param  array<int, string>  $command
     */
    private static function run(array $command): string
    {
        $escaped = array_map('escapeshellarg', $command);
        $line = implode(' ', $escaped).' 2>&1';

        $output = shell_exec($line);

        if ($output === null) {
            return '';
        }

        return $output;
    }
}
