<?php

namespace App\Services\Auth;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;

/**
 * Brute-force and abuse throttling for the public authentication endpoints.
 *
 * The Python application used an in-process `RateLimiter` class with `defaultdict`
 * timestamp lists: the limits are the contract that matters, and they are
 * reproduced exactly here.
 *
 * | Control | Limit | Window |
 * |---|---|---|
 * | failed logins from one IP | **15** | 600 s |
 * | failed logins for one account | **30** | 600 s |
 * | `forgot_password` from one IP | 5 | 600 s |
 * | `forgot_password` for one username | 3 | 3600 s |
 * | `reset_password` from one IP | 5 | 600 s |
 *
 * Two deliberate improvements, both of which strengthen the control without
 * changing its user-visible behaviour:
 *
 * * the counters live in the application cache rather than in one PHP process'
 *   memory, so several `artisan serve` workers — and a restart — share them.  The
 *   Python limiter reset itself on every process restart, which made the limit
 *   trivially bypassable.
 * * the counters are keyed through Laravel's own rate limiter, which uses atomic
 *   cache operations instead of read-modify-write on a list, so concurrent
 *   attempts cannot lose a hit.
 *
 * The asymmetry between the IP limit (15) and the per-account limit (30) is
 * intentional and is preserved: with a lower per-account limit an attacker could
 * lock a known username out with a handful of requests, which is a denial of
 * service against a real user.
 */
final class LoginThrottle
{
    public const LOGIN_FAILURE_WINDOW = 600;

    public const LOGIN_MAX_FAILURES_PER_IP = 15;

    public const LOGIN_MAX_FAILURES_PER_USER = 30;

    public const RECOVERY_MAX_PER_IP = 5;

    public const RECOVERY_IP_WINDOW = 600;

    public const RECOVERY_MAX_PER_USERNAME = 3;

    public const RECOVERY_USERNAME_WINDOW = 3600;

    public function __construct(private readonly CacheRateLimiter $limiter) {}

    // ── Login failures ───────────────────────────────────────────────────────

    /** Whether the source address has exhausted its login attempts. */
    public function ipIsThrottled(string $ip): bool
    {
        return $this->limiter->tooManyAttempts($this->ipKey($ip), self::LOGIN_MAX_FAILURES_PER_IP);
    }

    /** Count a failed login against the address and the account; returns both counts. */
    public function registerLoginFailure(string $ip, string $username): array
    {
        $ipKey = $this->ipKey($ip);
        $userKey = $this->userKey($username);

        $this->limiter->hit($ipKey, self::LOGIN_FAILURE_WINDOW);
        $this->limiter->hit($userKey, self::LOGIN_FAILURE_WINDOW);

        return [
            'failures_from_ip' => $this->limiter->attempts($ipKey),
            'failures_for_account' => $this->limiter->attempts($userKey),
        ];
    }

    /** Forget both counters after a successful login, as the original did. */
    public function clearLoginFailures(string $ip, string $username): void
    {
        $this->limiter->clear($this->ipKey($ip));
        $this->limiter->clear($this->userKey($username));
    }

    /** Failures recorded for an address, without counting the current attempt. */
    public function failureCountForIp(string $ip): int
    {
        return $this->limiter->attempts($this->ipKey($ip));
    }

    /** Seconds until an address may try again. */
    public function retryAfterForIp(string $ip): int
    {
        return $this->limiter->availableIn($this->ipKey($ip));
    }

    // ── Recovery requests ───────────────────────────────────────────────────

    /**
     * Whether a recovery request from this address is allowed.
     *
     * Counts the request when it is, matching `check_ip()`'s "check and record"
     * behaviour rather than a separate test-then-record pair.
     */
    public function allowRecoveryFromIp(string $ip): bool
    {
        $key = 'hastama.recovery.ip:'.sha1($ip);

        if ($this->limiter->tooManyAttempts($key, self::RECOVERY_MAX_PER_IP)) {
            return false;
        }

        $this->limiter->hit($key, self::RECOVERY_IP_WINDOW);

        return true;
    }

    /** Same, scoped to a username (3 attempts per hour). */
    public function allowRecoveryForUsername(string $username): bool
    {
        $key = 'hastama.recovery.user:'.sha1(mb_strtolower(trim($username)));

        if ($this->limiter->tooManyAttempts($key, self::RECOVERY_MAX_PER_USERNAME)) {
            return false;
        }

        $this->limiter->hit($key, self::RECOVERY_USERNAME_WINDOW);

        return true;
    }

    private function ipKey(string $ip): string
    {
        return 'hastama.login.ip:'.sha1($ip);
    }

    private function userKey(string $username): string
    {
        return 'hastama.login.user:'.sha1(mb_strtolower(trim($username)));
    }
}
