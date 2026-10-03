<?php

namespace App\Support\Connectivity;

use App\Support\SystemConfigStore;
use RuntimeException;
use Throwable;

/**
 * Iran-only access — only Iranian IP addresses may use the public path.
 *
 * Ported from `app/services/iran_access.py`.  The verdict is made offline from the
 * range file shipped with the application (`app/data/iran_ip_ranges.txt`),
 * parsed once into sorted `(start, end)` integers and searched with a binary
 * search.  Nothing is asked from a geo-IP web service.
 *
 * The settings live in `system_config` and are applied without a restart.
 */
final class IranAccessService
{
    public const ENABLED_KEY = 'iran_only_enabled';

    public const TITLE_KEY = 'iran_only_title';

    public const MESSAGE_KEY = 'iran_only_message';

    public const HELP_KEY = 'iran_only_help';

    public const LOG_KEY = 'iran_only_log_blocked';

    private const DESCRIPTIONS = [
        self::ENABLED_KEY => 'پذیرش ورود فقط با آی‌پی ایران (مسدودسازی VPN)',
        self::TITLE_KEY => 'عنوان پیام مسدودی آی‌پی غیرایرانی',
        self::MESSAGE_KEY => 'متن پیام مسدودی آی‌پی غیرایرانی',
        self::HELP_KEY => 'راهنمای رفع مسدودی (قطع VPN) روی صفحهٔ هشدار',
        self::LOG_KEY => 'ثبت تلاش‌های مسدودشده در گزارش رویداد',
    ];

    public const DEFAULT_TITLE = 'دسترسی از این آی‌پی مجاز نیست';

    public const DEFAULT_MESSAGE = 'سامانه فقط ورود با آی‌پی ایران را می‌پذیرد. به نظر می‌رسد در حال حاضر از طریق VPN یا پروکسی (یا از خارج از کشور) متصل شده‌اید. لطفاً ابتدا VPN یا فیلترشکن خود را قطع کنید، سپس این صفحه را دوباره بارگذاری کنید.';

    public const DEFAULT_HELP = "روی ویندوز: آیکون VPN در نوار کنار ساعت را باز کنید و Disconnect را بزنید.\n"
        ."روی گوشی اندروید: تنظیمات ← شبکه و اینترنت ← VPN ← اتصال را قطع کنید.\n"
        ."روی iPhone: تنظیمات ← General ← VPN & Device Management ← اتصال را قطع کنید.\n"
        ."اگر از افزونهٔ مرورگر (فیلترشکن) استفاده می‌کنید، آن را غیرفعال یا حذف کنید.\n"
        .'پس از قطع VPN، این صفحه را دوباره بارگذاری کنید تا وارد شوید.';

    public const MAX_TITLE_CHARS = 120;

    public const MAX_MESSAGE_CHARS = 800;

    public const MAX_HELP_CHARS = 1200;

    public const MAX_ANSWERED_IP_CHARS = 45;

    public const BLOCK_LOG_INTERVAL_SECONDS = 300;

    public const MAX_TRACKED_ADDRESSES = 500;

    public const REGISTRY_SOURCES = [
        'https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-extended-latest',
        'https://ftp.apnic.net/stats/apnic/delegated-apnic-extended-latest',
    ];

    public const COUNTRY = 'IR';

    public const KEEP_STATUSES = ['allocated', 'assigned'];

    public const DOWNLOAD_TIMEOUT_SECONDS = 90;

    public const MAX_DOWNLOAD_BYTES = 64 * 1024 * 1024;

    public const MAX_LIST_ENTRIES = 20000;

    /** @var array<string, mixed> */
    private static array $state = [];

    /** @var array{v4_starts: array<int, int>, v4_ends: array<int, int>, v6_starts: array<int, int>, v6_ends: array<int, int>, v4_count: int, v6_count: int, extra_v4_count: int, extra_v6_count: int, loaded: bool, error: string} */
    private static array $image = [
        'v4_starts' => [],
        'v4_ends' => [],
        'v6_starts' => [],
        'v6_ends' => [],
        'v4_count' => 0,
        'v6_count' => 0,
        'extra_v4_count' => 0,
        'extra_v6_count' => 0,
        'loaded' => false,
        'error' => '',
    ];

    /**
     * Everything the settings card and the API need to show.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        self::loadList();

        $image = self::$image;
        $metadata = self::listMetadata();

        return [
            'enabled' => self::$state['enabled'] ?? true,
            'enforcing' => self::enforcing(),
            'list_loaded' => $image['loaded'],
            'list_error' => $image['error'],
            'list_path' => $metadata['path'],
            'list_exists' => $metadata['exists'],
            'list_bytes' => $metadata['bytes'],
            'list_generated' => $metadata['generated'],
            'list_source' => $metadata['source'],
            'list_updated_at' => $metadata['mtime'],
            'ranges_ipv4' => $image['v4_count'],
            'ranges_ipv6' => $image['v6_count'],
            'extra_file' => 'iran_ip_ranges_extra.txt',
            'extra_exists' => self::extraPathExists(),
            'extra_ranges' => $image['extra_v4_count'] + $image['extra_v6_count'],
            'loaded_at' => self::$state['loaded_at'] ?? null,
            'last_refresh' => self::$state['last_refresh'] ?? null,
            'last_refresh_error' => self::$state['last_refresh_error'] ?? '',
            'registry_sources' => self::REGISTRY_SOURCES,
            'blocked_count' => self::$state['blocked_count'] ?? 0,
            'allowed_count' => self::$state['allowed_count'] ?? 0,
            'last_blocked_ip' => self::$state['last_blocked_ip'] ?? '',
            'last_blocked_at' => self::$state['last_blocked_at'] ?? null,
            'last_blocked_path' => self::$state['last_blocked_path'] ?? '',
            'title' => self::$state['title'] ?? self::DEFAULT_TITLE,
            'message' => self::$state['message'] ?? self::DEFAULT_MESSAGE,
            'help_text' => self::$state['help_text'] ?? self::DEFAULT_HELP,
            'log_blocked' => self::$state['log_blocked'] ?? true,
            'armed_by_default' => true,
            'block_log_interval_seconds' => self::BLOCK_LOG_INTERVAL_SECONDS,
        ];
    }

    /**
     * Validate, persist and apply the settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applySettings(array $data, string $actor = ''): array
    {
        if (! is_array($data)) {
            throw new IranAccessException('تنظیمات نامعتبر است.');
        }

        $title = self::cleanText($data['title'] ?? null, self::MAX_TITLE_CHARS, self::DEFAULT_TITLE);
        $message = self::cleanText($data['message'] ?? null, self::MAX_MESSAGE_CHARS, self::DEFAULT_MESSAGE);
        $helpText = self::cleanText($data['help_text'] ?? null, self::MAX_HELP_CHARS, self::DEFAULT_HELP);

        if (trim($message) === '') {
            throw new IranAccessException('متن پیام نمی‌تواند خالی باشد.');
        }

        $enabled = SystemConfigStore::truthy($data['enabled'] ?? self::$state['enabled'] ?? true);
        $logBlocked = SystemConfigStore::truthy($data['log_blocked'] ?? self::$state['log_blocked'] ?? true);

        $writes = [
            [self::ENABLED_KEY, $enabled ? '1' : '0'],
            [self::TITLE_KEY, $title],
            [self::MESSAGE_KEY, $message],
            [self::HELP_KEY, $helpText],
            [self::LOG_KEY, $logBlocked ? '1' : '0'],
        ];

        $failed = [];

        foreach ($writes as [$key, $value]) {
            if (! SystemConfigStore::upsert($key, $value, $actor, self::DESCRIPTIONS[$key] ?? '')) {
                $failed[] = $key;
            }
        }

        self::loadSettings();
        self::loadList();

        $saved = self::status();
        $saved['saved'] = $failed === [];

        if ($failed !== []) {
            Log::error('iran-only settings not fully saved: '.implode(', ', $failed));
        }

        return $saved;
    }

    /**
     * The card's tester: how would *ip* be treated right now?
     *
     * @return array<string, mixed>
     */
    public static function checkIp(mixed $ip): array
    {
        self::loadList();

        $verdict = self::classify($ip);
        $verdict['enforcing'] = self::enforcing();
        $verdict['blocked'] = ($verdict['kind'] === 'foreign') && self::enforcing();
        $verdict['list_loaded'] = self::$image['loaded'];

        return $verdict;
    }

    /**
     * Clear the blocked/allowed counters shown in the card.
     *
     * @return array<string, mixed>
     */
    public static function resetCounters(): array
    {
        self::$state['blocked_count'] = 0;
        self::$state['allowed_count'] = 0;
        self::$state['last_blocked_ip'] = '';
        self::$state['last_blocked_at'] = null;
        self::$state['last_blocked_path'] = '';
        self::$state['recent'] = [];

        return self::status();
    }

    /**
     * Whether the filter is actually able to reject anything right now.
     */
    public static function enforcing(): bool
    {
        if (! (self::$state['enabled'] ?? true)) {
            return false;
        }

        self::loadList();

        return self::$image['loaded'];
    }

    /**
     * Decide which side of the filter *ip* belongs to.
     *
     * @return array<string, mixed>
     */
    public static function classify(mixed $ip): array
    {
        $address = self::normalizeIp($ip);

        if ($address === null) {
            return [
                'ip' => mb_substr((string) ($ip ?? ''), 0, self::MAX_ANSWERED_IP_CHARS),
                'kind' => 'unknown',
                'allowed' => true,
                'label' => 'نامشخص (اجازه داده شد)',
                'range' => '',
            ];
        }

        $text = (string) $address;

        if (! self::isGlobal($address)) {
            return [
                'ip' => $text,
                'kind' => 'internal',
                'allowed' => true,
                'label' => 'شبکهٔ داخلی یا محلی',
                'range' => '',
            ];
        }

        self::loadList();

        if (! self::$image['loaded']) {
            return [
                'ip' => $text,
                'kind' => 'unknown',
                'allowed' => true,
                'label' => 'فهرست آی‌پی ایران در دسترس نیست',
                'range' => '',
            ];
        }

        $version = self::ipVersion($address);
        $starts = $version === 4 ? self::$image['v4_starts'] : self::$image['v6_starts'];
        $ends = $version === 4 ? self::$image['v4_ends'] : self::$image['v6_ends'];
        $intValue = self::ipToInt($address);

        if (self::inBounds($starts, $ends, $intValue)) {
            return [
                'ip' => $text,
                'kind' => 'iran',
                'allowed' => true,
                'label' => 'ایران',
                'range' => self::containingNetwork($address),
            ];
        }

        return [
            'ip' => $text,
            'kind' => 'foreign',
            'allowed' => false,
            'label' => 'خارج از ایران (VPN یا خارج از کشور)',
            'range' => '',
        ];
    }

    /**
     * Rebuild the Iranian range list from the RIPE and APNIC registries.
     *
     * The only network call in the whole feature, and it is never automatic:
     * the filter keeps working offline from whatever list is on disk.
     *
     * @return array<string, mixed>
     */
    public static function refresh(): array
    {
        $combined = ['v4' => [], 'v6' => []];
        $failures = [];

        foreach (self::REGISTRY_SOURCES as $url) {
            try {
                $body = self::download($url);
                $parsed = self::registryLines($body);
            } catch (IranAccessException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $failures[] = $exception::class.' @ '.parse_url($url, PHP_URL_HOST);

                continue;
            }

            $combined['v4'] = array_merge($combined['v4'], $parsed['v4']);
            $combined['v6'] = array_merge($combined['v6'], $parsed['v6']);
        }

        if ($combined['v4'] === []) {
            $detail = $failures !== [] ? implode(', ', $failures) : 'بدون پاسخ';

            throw new IranAccessException('دریافت فهرست از سرورهای مرجع ناموفق بود ('.$detail.').');
        }

        $generated = date('c');
        $body = self::renderList($combined, $generated);

        try {
            $path = self::dataPath();
            $directory = dirname($path);

            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            $temporary = $path.'.tmp';
            file_put_contents($temporary, $body);
            rename($temporary, $path);
        } catch (Throwable $exception) {
            throw new IranAccessException('نوشتن فایل فهرست ناموفق بود ('.$exception::class.').');
        }

        self::loadListForce();
        self::$state['last_refresh'] = $generated;
        self::$state['last_refresh_error'] = implode(', ', $failures);

        $result = self::status();
        $result['downloaded_ranges'] = count($combined['v4']) + count($combined['v6']);
        $result['sources_failed'] = $failures;

        return $result;
    }

    private static function loadSettings(): void
    {
        self::$state['enabled'] = SystemConfigStore::readFlag(self::ENABLED_KEY, true);
        self::$state['log_blocked'] = SystemConfigStore::readFlag(self::LOG_KEY, true);
        self::$state['title'] = mb_substr(
            SystemConfigStore::value(self::TITLE_KEY) ?? '',
            0,
            self::MAX_TITLE_CHARS,
        ) ?: self::DEFAULT_TITLE;
        self::$state['message'] = mb_substr(
            SystemConfigStore::value(self::MESSAGE_KEY) ?? '',
            0,
            self::MAX_MESSAGE_CHARS,
        ) ?: self::DEFAULT_MESSAGE;
        self::$state['help_text'] = mb_substr(
            SystemConfigStore::value(self::HELP_KEY) ?? '',
            0,
            self::MAX_HELP_CHARS,
        ) ?: self::DEFAULT_HELP;
    }

    private static function loadList(): void
    {
        $path = self::dataPath();

        if (! file_exists($path)) {
            if (self::$image['loaded']) {
                self::$image = [
                    'v4_starts' => [],
                    'v4_ends' => [],
                    'v6_starts' => [],
                    'v6_ends' => [],
                    'v4_count' => 0,
                    'v6_count' => 0,
                    'extra_v4_count' => 0,
                    'extra_v6_count' => 0,
                    'loaded' => false,
                    'error' => 'فایل فهرست آی‌پی ایران یافت نشد.',
                ];
            }

            return;
        }

        try {
            $text = file_get_contents($path);
        } catch (Throwable $exception) {
            self::$image = [
                'v4_starts' => [],
                'v4_ends' => [],
                'v6_starts' => [],
                'v6_ends' => [],
                'v4_count' => 0,
                'v6_count' => 0,
                'extra_v4_count' => 0,
                'extra_v6_count' => 0,
                'loaded' => false,
                'error' => 'فهرست آی‌پی ایران خوانده نشد ('.$exception::class.').',
            ];

            return;
        }

        if ($text === false) {
            self::$image = [
                'v4_starts' => [],
                'v4_ends' => [],
                'v6_starts' => [],
                'v6_ends' => [],
                'v4_count' => 0,
                'v6_count' => 0,
                'extra_v4_count' => 0,
                'extra_v6_count' => 0,
                'loaded' => false,
                'error' => 'فهرست آی‌پی ایران خوانده نشد.',
            ];

            return;
        }

        $parsed = self::parseListText($text);
        $extra = self::loadExtraBounds();

        [$extraV4Starts, $extraV4Ends] = self::mergeBounds($extra['v4']);
        [$extraV6Starts, $extraV6Ends] = self::mergeBounds($extra['v6']);

        [$v4Starts, $v4Ends] = self::mergeBounds(array_merge($parsed['v4'], $extra['v4']));
        [$v6Starts, $v6Ends] = self::mergeBounds(array_merge($parsed['v6'], $extra['v6']));

        self::$image = [
            'v4_starts' => $v4Starts,
            'v4_ends' => $v4Ends,
            'v6_starts' => $v6Starts,
            'v6_ends' => $v6Ends,
            'v4_count' => count($v4Starts),
            'v6_count' => count($v6Starts),
            'extra_v4_count' => count($extraV4Starts),
            'extra_v6_count' => count($extraV6Starts),
            'loaded' => (count($v4Starts) + count($v6Starts)) > 0,
            'error' => (count($v4Starts) + count($v6Starts)) > 0 ? '' : 'فهرست آی‌پی ایران خالی است.',
        ];

        self::$state['loaded_at'] = date('c');
    }

    private static function loadListForce(): void
    {
        self::loadList();
    }

    /**
     * Parse a range-list file body into bounds.
     *
     * @return array{v4: array<int, array{0: int, 1: int}>, v6: array<int, array{0: int, 1: int}>}
     */
    public static function parseListText(string $text): array
    {
        $v4 = [];
        $v6 = [];
        $seen = 0;

        foreach (preg_split('/\r\n|\r|\n/', $text) as $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            $line = trim(explode('#', $line)[0]);

            if ($line === '') {
                continue;
            }

            $seen++;

            if ($seen > self::MAX_LIST_ENTRIES * 4) {
                break;
            }

            try {
                $network = self::parseNetwork($line);
            } catch (Throwable) {
                continue;
            }

            if ($network === null) {
                continue;
            }

            if ($network['version'] === 4) {
                $v4[] = $network['bounds'];
            } else {
                $v6[] = $network['bounds'];
            }
        }

        return ['v4' => $v4, 'v6' => $v6];
    }

    /**
     * @return array{version: int, bounds: array{0: int, 1: int}}|null
     */
    private static function parseNetwork(string $line): ?array
    {
        if (! str_contains($line, '/')) {
            return null;
        }

        [$address, $prefix] = explode('/', $line, 2);

        if (! ctype_digit(trim($prefix))) {
            return null;
        }

        $prefixLength = (int) trim($prefix);

        $intValue = self::ipToIntFromString(trim($address));

        if ($intValue === null) {
            return null;
        }

        $version = str_contains($address, ':') ? 6 : 4;
        $maxBits = $version === 4 ? 32 : 128;

        if ($prefixLength < 0 || $prefixLength > $maxBits) {
            return null;
        }

        $mask = $prefixLength === 0 ? 0 : (~0 << ($maxBits - $prefixLength));
        $start = $intValue & $mask;
        $end = $start | (~$mask & ($maxBits === 32 ? 0xFFFFFFFF : PHP_INT_MAX));

        return ['version' => $version, 'bounds' => [$start, $end]];
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $bounds
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private static function mergeBounds(array $bounds): array
    {
        usort($bounds, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $starts = [];
        $ends = [];

        foreach ($bounds as [$start, $end]) {
            if ($ends !== [] && $start <= $ends[count($ends) - 1] + 1) {
                $ends[count($ends) - 1] = max($ends[count($ends) - 1], $end);
            } else {
                $starts[] = $start;
                $ends[] = $end;
            }
        }

        return [$starts, $ends];
    }

    /**
     * @param  array<int, int>  $starts
     * @param  array<int, int>  $ends
     */
    private static function inBounds(array $starts, array $ends, int $value): bool
    {
        $index = self::bisectRight($starts, $value) - 1;

        return $index >= 0 && $value <= $ends[$index];
    }

    /**
     * @param  array<int, int>  $starts
     */
    private static function bisectRight(array $starts, int $value): int
    {
        $low = 0;
        $high = count($starts);

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if ($starts[$mid] <= $value) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    private static function containingNetwork(string $address): string
    {
        $version = self::ipVersion($address);
        $starts = $version === 4 ? self::$image['v4_starts'] : self::$image['v6_starts'];
        $ends = $version === 4 ? self::$image['v4_ends'] : self::$image['v6_ends'];
        $intValue = self::ipToInt($address);

        $index = self::bisectRight($starts, $intValue) - 1;

        if ($index < 0 || $intValue > $ends[$index]) {
            return '';
        }

        try {
            $first = self::intToIp($starts[$index], $version);
            $last = self::intToIp($ends[$index], $version);

            return implode(', ', self::summarizeRange($first, $last));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array<int, string>
     */
    private static function summarizeRange(string $first, string $last): array
    {
        $firstInt = self::ipToIntFromString($first);
        $lastInt = self::ipToIntFromString($last);

        if ($firstInt === null || $lastInt === null) {
            return [];
        }

        $version = str_contains($first, ':') ? 6 : 4;
        $lines = [];

        while ($firstInt <= $lastInt) {
            $maxSize = $version === 4 ? 32 : 128;
            $size = 1;

            while ($size < $maxSize) {
                $mask = (~0 << ($maxBits - $size)) & ($maxBits === 32 ? 0xFFFFFFFF : PHP_INT_MAX);
                $next = $firstInt + (1 << ($maxBits - $size));

                if (($firstInt & $mask) !== $firstInt || $next - 1 > $lastInt) {
                    break;
                }

                $size++;
            }

            $lines[] = self::intToIp($firstInt, $version).'/'.($maxBits - $size + 1);
            $firstInt += 1 << ($maxBits - $size + 1);
        }

        return $lines;
    }

    /**
     * Extract one country's allocations from a delegated-statistics file.
     *
     * @return array{v4: array<int, array{0: int, 1: int}>, v6: array<int, array{0: int, 1: int}>}
     */
    public static function registryLines(string $text): array
    {
        $v4 = [];
        $v6 = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $parts = explode('|', $line);

            if (count($parts) < 7) {
                continue;
            }

            if (strtoupper(trim($parts[1] ?? '')) !== self::COUNTRY) {
                continue;
            }

            if (! in_array(mb_strtolower(trim($parts[6] ?? '')), self::KEEP_STATUSES, true)) {
                continue;
            }

            $kind = mb_strtolower(trim($parts[2] ?? ''));
            $rawStart = trim($parts[3] ?? '');
            $rawValue = trim($parts[4] ?? '');

            try {
                if ($kind === 'ipv4') {
                    $count = (int) $rawValue;
                    $first = self::ipToIntFromString($rawStart);

                    if ($first === null || $count <= 0) {
                        continue;
                    }

                    $last = $first + $count - 1;

                    if ($last > 0xFFFFFFFF) {
                        continue;
                    }

                    $v4[] = [$first, $last];
                } elseif ($kind === 'ipv6') {
                    $length = (int) $rawValue;
                    $network = self::parseNetwork($rawStart.'/'.$length);

                    if ($network !== null && $network['version'] === 6) {
                        $v6[] = $network['bounds'];
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return ['v4' => $v4, 'v6' => $v6];
    }

    /**
     * Serialise bounds into the on-disk file format.
     *
     * @param  array{v4: array<int, array{0: int, 1: int}>, v6: array<int, array{0: int, 1: int}>}  $bounds
     */
    public static function renderList(array $bounds, ?string $generated = null): string
    {
        $v4Lines = self::boundsToLines($bounds['v4'] ?? []);
        $v6Lines = self::boundsToLines($bounds['v6'] ?? []);

        $header = [
            '# Iranian IP address ranges — only these public addresses may use the system.',
            '# One CIDR per line; \'#\' starts a comment.  Generated file, do not edit by hand:',
            '#   python scripts/refresh_iran_ip_ranges.php',
            '# generated: '.($generated ?? date('c')),
            '# source: '.implode(', ', self::REGISTRY_SOURCES),
            '# records: '.count($v4Lines).' ipv4, '.count($v6Lines).' ipv6',
            '',
        ];

        return implode("\n", array_merge($header, $v4Lines, $v6Lines))."\n";
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $bounds
     * @return array<int, string>
     */
    private static function boundsToLines(array $bounds): array
    {
        $lines = [];

        foreach ($bounds as [$start, $end]) {
            $version = $end <= 0xFFFFFFFF ? 4 : 6;
            $lines = array_merge($lines, self::summarizeRange(
                self::intToIp($start, $version),
                self::intToIp($end, $version),
            ));
        }

        return $lines;
    }

    /**
     * Fetch one registry file without any proxy from the environment.
     */
    private static function download(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::DOWNLOAD_TIMEOUT_SECONDS,
                'user_agent' => 'Hastama-IR-Range-Refresh/1.0',
                'follow_location' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            throw new IranAccessException('دریافت فهرست از سرور مرجع ناموفق بود.');
        }

        if (strlen($data) >= self::MAX_DOWNLOAD_BYTES) {
            throw new IranAccessException('پاسخ سرور مرجع بیش از حد بزرگ بود.');
        }

        return $data;
    }

    /**
     * @return array{v4: array<int, array{0: int, 1: int}>, v6: array<int, array{0: int, 1: int}>}
     */
    private static function loadExtraBounds(): array
    {
        $path = self::extraPath();

        if (! file_exists($path)) {
            return ['v4' => [], 'v6' => []];
        }

        try {
            $text = file_get_contents($path);
        } catch (Throwable) {
            return ['v4' => [], 'v6' => []];
        }

        if ($text === false) {
            return ['v4' => [], 'v6' => []];
        }

        return self::parseListText($text);
    }

    private static function extraPathExists(): bool
    {
        return file_exists(self::extraPath());
    }

    private static function dataPath(): string
    {
        return base_path('../app/data/iran_ip_ranges.txt');
    }

    private static function extraPath(): string
    {
        return base_path('../app/data/iran_ip_ranges_extra.txt');
    }

    /**
     * @return array{path: string, exists: bool, bytes: int, mtime: string, generated: string, source: string}
     */
    private static function listMetadata(): array
    {
        $path = self::dataPath();
        $info = [
            'path' => $path,
            'exists' => file_exists($path),
            'bytes' => 0,
            'mtime' => '',
            'generated' => '',
            'source' => '',
        ];

        if (! $info['exists']) {
            return $info;
        }

        $stat = @stat($path);

        if ($stat !== false) {
            $info['bytes'] = (int) $stat['size'];
            $info['mtime'] = date('c', (int) $stat['mtime']);
        }

        $handle = @fopen($path, 'r');

        if ($handle !== false) {
            for ($i = 0; $i < 12; $i++) {
                $line = fgets($handle);

                if ($line === false || ! str_starts_with($line, '#')) {
                    break;
                }

                $body = trim(ltrim($line, '#'));

                if (str_starts_with(mb_strtolower($body), 'generated:')) {
                    $info['generated'] = trim(explode(':', $body, 2)[1] ?? '');
                } elseif (str_starts_with(mb_strtolower($body), 'source')) {
                    $info['source'] = trim(explode(':', $body, 2)[1] ?? '');
                }
            }

            fclose($handle);
        }

        return $info;
    }

    /**
     * Parse *value* into an address string; `null` when it is not one.
     */
    private static function normalizeIp(mixed $value): ?string
    {
        if (is_string($value)) {
            $text = trim($value);
        } elseif (is_scalar($value)) {
            $text = trim((string) $value);
        } else {
            return null;
        }

        if ($text === '' || mb_strlen($text) > self::MAX_ANSWERED_IP_CHARS + 32) {
            return null;
        }

        if (str_starts_with($text, '[')) {
            $end = strpos($text, ']');
            $text = $end !== false ? substr($text, 1, $end - 1) : ltrim($text, '[');
        } elseif (substr_count($text, ':') === 1 && str_contains($text, '.')) {
            $text = explode(':', $text, 2)[0];
        }

        $intValue = self::ipToIntFromString($text);

        if ($intValue === null) {
            return null;
        }

        $version = str_contains($text, ':') ? 6 : 4;

        return self::intToIp($intValue, $version);
    }

    private static function ipToInt(string $address): int
    {
        $value = self::ipToIntFromString($address);

        return $value ?? 0;
    }

    private static function ipVersion(string $address): int
    {
        return str_contains($address, ':') ? 6 : 4;
    }

    private static function isGlobal(string $address): bool
    {
        $intValue = self::ipToInt($address);
        $version = self::ipVersion($address);

        if ($version === 4) {
            $bytes = self::intToBytes($intValue, 4);

            if ($bytes[0] === 10) {
                return false;
            }

            if ($bytes[0] === 172 && $bytes[1] >= 16 && $bytes[1] <= 31) {
                return false;
            }

            if ($bytes[0] === 192 && $bytes[1] === 168) {
                return false;
            }

            if ($bytes[0] === 127) {
                return false;
            }

            if ($bytes[0] === 169 && $bytes[1] === 254) {
                return false;
            }

            if ($bytes[0] === 0) {
                return false;
            }

            return true;
        }

        if (str_starts_with(strtolower($address), 'fc') || str_starts_with(strtolower($address), 'fd')) {
            return false;
        }

        if (str_starts_with(strtolower($address), 'fe80')) {
            return false;
        }

        if (str_starts_with(strtolower($address), '::1')) {
            return false;
        }

        if (str_starts_with(strtolower($address), '::ffff:127.')) {
            return false;
        }

        return true;
    }

    private static function ipToIntFromString(string $address): ?int
    {
        $address = trim($address);

        if (str_contains($address, ':')) {
            return self::ipv6ToInt($address);
        }

        $parts = explode('.', $address);

        if (count($parts) !== 4) {
            return null;
        }

        $result = 0;

        foreach ($parts as $part) {
            if (! ctype_digit($part)) {
                return null;
            }

            $value = (int) $part;

            if ($value < 0 || $value > 255) {
                return null;
            }

            $result = ($result << 8) | $value;
        }

        return $result;
    }

    private static function ipv6ToInt(string $address): ?int
    {
        $address = strtolower(trim($address));

        if (str_contains($address, '::ffff:') && str_contains($address, '.')) {
            $ipv4Part = substr($address, 7);
            $ipv4Int = self::ipToIntFromString($ipv4Part);

            return $ipv4Int;
        }

        if (! str_contains($address, '::')) {
            $parts = explode(':', $address);

            if (count($parts) !== 8) {
                return null;
            }

            $result = 0;

            foreach ($parts as $part) {
                if ($part === '' || ! ctype_xdigit($part) || strlen($part) > 4) {
                    return null;
                }

                $result = ($result << 16) | hexdec($part);
            }

            return $result;
        }

        [$left, $right] = explode('::', $address, 2);
        $leftParts = $left === '' ? [] : explode(':', $left);
        $rightParts = $right === '' ? [] : explode(':', $right);

        $missing = 8 - count($leftParts) - count($rightParts);

        if ($missing < 0) {
            return null;
        }

        $allParts = array_merge($leftParts, array_fill(0, $missing, '0'), $rightParts);
        $result = 0;

        foreach ($allParts as $part) {
            if ($part === '' || ! ctype_xdigit($part) || strlen($part) > 4) {
                return null;
            }

            $result = ($result << 16) | hexdec($part);
        }

        return $result;
    }

    private static function intToIp(int $value, int $version): string
    {
        if ($version === 4) {
            return implode('.', self::intToBytes($value, 4));
        }

        $parts = [];

        for ($i = 7; $i >= 0; $i--) {
            $parts[] = dechex(($value >> ($i * 16)) & 0xFFFF);
        }

        return implode(':', $parts);
    }

    /**
     * @return array<int, int>
     */
    private static function intToBytes(int $value, int $count): array
    {
        $bytes = [];

        // MSB first, so `implode('.', …)` in `intToIp()` prints the address in
        // the usual order.  The previous loop filled the array from index
        // `$count - 1` down to `0`, which left the **least** significant byte
        // first in the array's internal order — and `implode` follows that
        // order, not the keys — so `intToIp()` answered `1.0.0.127` for
        // `127.0.0.1`.  Every consumer reads the bytes by key (`isGlobal()`
        // checks `$bytes[0]`), which is why the reversal was invisible until
        // `/iran-only/check` published the address on the wire.
        for ($i = 0; $i < $count; $i++) {
            $bytes[$i] = ($value >> (8 * ($count - 1 - $i))) & 0xFF;
        }

        return $bytes;
    }

    private static function cleanText(mixed $value, int $limit, string $fallback): string
    {
        $text = '';

        if (is_string($value)) {
            $text = str_replace(["\r\n", "\r"], "\n", $value);
            $lines = explode("\n", $text);
            $cleanedLines = array_map(
                static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? ''),
                $lines,
            );
            $text = trim(implode("\n", $cleanedLines));
        }

        return mb_substr($text !== '' ? $text : $fallback, 0, $limit);
    }
}

class IranAccessException extends RuntimeException {}
