<?php

namespace App\Services\Auth;

use App\Models\UserSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The revocable server-side session registry (`dbo.user_sessions`).
 *
 * Laravel's session cookie is signed and cannot be revoked, which is precisely why
 * the existing application keeps a registry table: a password reset, an account
 * disable, a role change or an administrator's "terminate session" action must
 * actually end the sessions already issued to a browser.  The migration keeps that
 * table as the authority for "who is signed in" instead of relying on the cookie
 * alone, so the control centre keeps working and a session can be cut across both
 * stacks during the side-by-side period.
 *
 * Ported from `app/core/sessions.py`, including two decisions worth not
 * re-litigating:
 *
 * * **Validation is cached for a few seconds** (`hastama.session.check_ttl`).  The
 *   middleware runs on every request and the Python implementation measured the
 *   same trade-off; revocation invalidates the cache entry, so a terminated
 *   session dies immediately rather than after the TTL.
 * * **Infrastructure failures fail *open*** — an unreachable registry logs a
 *   warning and treats the session as valid, because every other route needs the
 *   database anyway and failing closed would turn a transient outage into a global
 *   logout.  This is a recorded residual risk, not an oversight.
 *
 * One deliberate difference: the Python `_ensure_table()` created the table on
 * first use.  The migration **must not** create objects in `userDB`, and the table
 * exists, so a missing table is reported loudly instead.
 */
final class SessionRegistry
{
    /** Session key holding the registry token. */
    public const SESSION_TOKEN_KEY = 'sid';

    /** Session key holding the token's issue time. */
    public const SESSION_ISSUED_KEY = 'sid_iat';

    /** Sessions are re-read from the table at most this often. */
    private int $cacheSeconds = 5;

    public function __construct()
    {
        $this->cacheSeconds = (int) config('hastama.session.check_ttl', 5);
    }

    /**
     * A cryptographically strong opaque session identifier.
     *
     * `secrets.token_urlsafe(32)` produces 32 random bytes as URL-safe base64 with
     * the padding stripped; this is the same 43-character string.
     */
    public function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Record a freshly issued session id.
     *
     * Returns false when the registry could not be written — the caller decides
     * whether that is fatal, exactly as `register_session()` did.
     */
    public function register(string $token, string $username, string $ipAddress = '', string $userAgent = ''): bool
    {
        try {
            UserSession::query()->insert([
                'session_key' => $token,
                'username' => $username,
                'ip_address' => $ipAddress !== '' ? mb_substr($ipAddress, 0, 45) : null,
                'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 500) : null,
            ]);

            $this->forget($token);

            return true;
        } catch (Throwable $exception) {
            Log::warning('session registry insert failed', [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Whether `$token` is an active session belonging to `$username`.
     *
     * Also enforces the **server-side idle timeout** and advances `last_activity`
     * (at most once per `activity_touch_seconds`), both as in the Python version.
     */
    public function validate(string $token, string $username): bool
    {
        if ($token === '') {
            return false;
        }

        $cacheKey = $this->cacheKey($token);

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $session = UserSession::query()->where('session_key', $token)->first();

            if ($session === null) {
                return $this->remember($cacheKey, false);
            }

            $valid = (bool) $session->is_active
                && mb_strtolower(trim((string) $session->username)) === mb_strtolower(trim($username));

            if ($valid && $session->last_activity !== null) {
                $idleLimit = (int) config('hastama.session.idle_seconds', 1800);

                if ($idleLimit > 0 && $session->last_activity->diffInSeconds(now(), true) > $idleLimit) {
                    UserSession::query()->where('session_key', $token)->update([
                        'is_active' => false,
                        'logout_at' => now(),
                    ]);

                    Log::info('session expired while idle', ['session_key' => $this->mask($token)]);

                    return $this->remember($cacheKey, false);
                }
            }

            if ($valid) {
                $this->touch($token);
            }

            return $this->remember($cacheKey, $valid);
        } catch (Throwable $exception) {
            $this->reportUnavailable('session validation', $exception);

            // Documented residual risk: fail open on infrastructure errors.
            return true;
        }
    }

    /**
     * Advance `last_activity`, but only when the recorded activity is stale enough
     * to be worth a write.
     */
    public function touch(string $token, int $minimumAgeSeconds = 60): void
    {
        try {
            UserSession::query()
                ->where('session_key', $token)
                ->where('is_active', true)
                ->whereRaw('DATEDIFF(SECOND, last_activity, SYSUTCDATETIME()) >= ?', [$minimumAgeSeconds])
                ->update(['last_activity' => now()]);
        } catch (Throwable $exception) {
            Log::warning('session activity touch failed', ['exception' => $exception::class]);
        }
    }

    /** Terminate one session id. */
    public function revoke(string $token, string $byUsername = 'system'): bool
    {
        try {
            $changed = UserSession::query()
                ->where('session_key', $token)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'logout_at' => now(),
                    'terminated_by' => $byUsername,
                ]);

            $this->forget($token);

            return $changed > 0;
        } catch (Throwable $exception) {
            Log::warning('session revoke failed', ['exception' => $exception::class]);

            return false;
        }
    }

    /**
     * Terminate every active session of one user.
     *
     * Called on password reset, password change, account disable and role change.
     * Uses `LTRIM(RTRIM(username))` — the same predicate as the Python helper, with
     * no yeh/kaf folding, so it matches exactly the rows it matched before.
     */
    public function revokeUserSessions(string $username, string $byUsername = 'system'): int
    {
        try {
            $changed = UserSession::query()
                ->whereRaw('LTRIM(RTRIM(username)) = ?', [trim($username)])
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'logout_at' => now(),
                    'terminated_by' => $byUsername,
                ]);

            $this->flush();

            return $changed;
        } catch (Throwable $exception) {
            Log::warning('bulk session revoke failed', ['exception' => $exception::class]);

            return 0;
        }
    }

    /**
     * Terminate every active session except the master administrators.
     *
     * Used by internet-outage mode: the users are cut off, but an administrator who
     * has to put things right keeps their own access.
     *
     * @param  array<int, string>  $keepUsernames
     */
    public function revokeAllSessions(string $byUsername = 'system', array $keepUsernames = []): int
    {
        $keep = array_values(array_unique(array_filter(array_map(
            static fn ($name): string => mb_strtolower(trim((string) $name)),
            $keepUsernames,
        ))));

        try {
            $query = UserSession::query()->where('is_active', true);

            if ($keep !== []) {
                $placeholders = implode(',', array_fill(0, count($keep), '?'));
                $query->whereRaw("LOWER(LTRIM(RTRIM(username))) NOT IN ({$placeholders})", $keep);
            }

            $changed = $query->update([
                'is_active' => false,
                'logout_at' => now(),
                'terminated_by' => $byUsername,
            ]);

            $this->flush();

            return $changed;
        } catch (Throwable $exception) {
            Log::warning('bulk session revoke (all) failed', ['exception' => $exception::class]);

            return 0;
        }
    }

    /** Delete one session record outright (drops it from the history too). */
    public function delete(string $token): bool
    {
        try {
            $changed = UserSession::query()->where('session_key', $token)->delete();
            $this->forget($token);

            return $changed > 0;
        } catch (Throwable $exception) {
            Log::warning('session record deletion failed', ['exception' => $exception::class]);

            return false;
        }
    }

    /** Delete the whole registry — also removes the login history. */
    public function deleteAll(): int
    {
        try {
            $changed = UserSession::query()->delete();
            $this->flush();

            return $changed;
        } catch (Throwable $exception) {
            Log::warning('session registry purge failed', ['exception' => $exception::class]);

            return 0;
        }
    }

    private function remember(string $cacheKey, bool $valid): bool
    {
        Cache::put($cacheKey, $valid, $this->cacheSeconds);

        return $valid;
    }

    private function forget(string $token): void
    {
        Cache::forget($this->cacheKey($token));
    }

    private function flush(): void
    {
        // Revoking in bulk has no token list to invalidate one by one; the registry
        // is small (hundreds of rows) and every entry revalidates within seconds.
        Cache::flush();
    }

    private function cacheKey(string $token): string
    {
        return 'hastama.session.valid.'.hash('sha256', $token);
    }

    private function mask(string $token): string
    {
        return mb_substr($token, 0, 6).'…';
    }

    private function reportUnavailable(string $operation, Throwable $exception): void
    {
        Log::warning("{$operation} unavailable", [
            'exception' => $exception::class,
            'reason' => $exception->getMessage(),
        ]);
    }
}
