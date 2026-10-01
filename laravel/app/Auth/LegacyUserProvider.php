<?php

namespace App\Auth;

use App\Models\User;
use App\Services\Auth\LegacyCredentialWriter;
use App\Support\Legacy\LegacyPassword;
use App\Support\Legacy\PersianText;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * User provider for `dbo.user_table`.
 *
 * Laravel's stock `EloquentUserProvider` is unusable here for one reason, and it is
 * the migration's headline risk: **15 of the 16 live accounts store a plaintext
 * password** in `password nchar(10)`, while `password_hash` is populated for only
 * one.  `validateCredentials()` calls `Hash::check()` against the password column,
 * so every real account would fail to log in.
 *
 * This provider delegates verification to {@see LegacyPassword}, which reproduces
 * the four formats the Python application accepted, and it adds the two lookups
 * the legacy schema demands:
 *
 * * usernames are matched as `LOWER(REPLACE(REPLACE(LTRIM(RTRIM(username)), …)))`,
 *   so padding, case and the Arabic/Persian yeh and kaf spellings all resolve to
 *   the same account;
 * * the account's `is_active` state is part of credential validation, using the
 *   existing rule (everything is allowed except `disabled`, `inactive`, `locked`,
 *   `0`, `false`).  A disabled account therefore fails as a *credential* failure,
 *   which is what keeps the login response generic and non-enumerating.
 *
 * "Remember me" does not exist in this application and `user_table` has no
 * `remember_token` column, so the remember-token methods are inert rather than
 * faked.
 */
class LegacyUserProvider implements UserProvider
{
    public function __construct(
        private readonly User $model,
        private readonly LegacyCredentialWriter $credentials = new LegacyCredentialWriter,
    ) {}

    /**
     * A user by primary key.
     *
     * The session guard calls this on every request to rebuild the authenticated
     * user; it returns null when the account was deleted between requests, which
     * makes the guard treat the session as unauthenticated.
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->model->newQuery()->find($identifier);
    }

    /** No remember tokens exist, so no user can be retrieved by one. */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return null;
    }

    /** No-op: there is nowhere to store a remember token. */
    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        // Intentionally inert — see the class docblock.
    }

    /**
     * A user by credentials — the padded, letter-folded username lookup.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $username = $credentials['username'] ?? null;

        if (! is_string($username) || PersianText::strip($username) === '') {
            return null;
        }

        return $this->model->newQuery()->whereUsername($username)->first();
    }

    /**
     * Whether the supplied password authenticates this account.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $password = $credentials['password'] ?? null;

        if (! is_string($password) || $password === '') {
            return false;
        }

        return $user->isLoginAllowed() && $user->verifyPassword($password);
    }

    /**
     * The contract's "replace this credential now that it has been proven" hook.
     *
     * Laravel calls this from `SessionGuard::attempt()` immediately after
     * {@see validateCredentials()} answers *true*, and its stock implementation is
     * about bcrypt cost factors: `Hash::needsRehash()` on the stored value.  This
     * provider has a much bigger reason to rewrite a row — the credential may be
     * **plaintext** — so the two cases are folded into one method:
     *
     * * `$force` (the framework asking for an unconditional rewrite) always writes;
     * * a row that still holds a legacy-format credential is upgraded, which is the
     *   confirmed migration decision: verify the legacy value, then replace it with
     *   bcrypt on the first successful authentication;
     * * a bcrypt hash made with a cost factor we no longer accept is rehashed.
     *
     * Every branch runs **after** a proven authentication, so no row is ever
     * rewritten on the strength of an unverified password.  Without this method the
     * class does not satisfy `Illuminate\Contracts\Auth\UserProvider` at all in
     * Laravel 13, and the guard cannot even be resolved — a fatal error rather than
     * a degraded login.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        if (! $user instanceof User) {
            return;
        }

        $password = $credentials['password'] ?? null;

        if (! is_string($password) || $password === '') {
            return;
        }

        if (! $force && ! $this->needsUpgrade($user) && ! $this->hashNeedsRehash($user)) {
            return;
        }

        $this->upgradeCredential($user, $password);
    }

    /** Whether a stored bcrypt hash was made with a cost we no longer accept. */
    private function hashNeedsRehash(User $user): bool
    {
        $stored = $user->storedHash();

        return $stored !== null
            && LegacyPassword::looksLikeBcrypt($stored)
            && LegacyPassword::needsRehash($stored);
    }

    /**
     * Whether this row's credential should be replaced after a successful login.
     *
     * The confirmed migration decision: verify the legacy value, then upgrade it to
     * bcrypt on the first successful authentication.  No row is ever rewritten
     * without a password that has just been proven correct.
     */
    public function needsUpgrade(Authenticatable $user): bool
    {
        return $user instanceof User && $user->needsPasswordUpgrade();
    }

    /**
     * Replace the legacy credential with a bcrypt hash.
     *
     * Zeros in on the row by primary key so a username that moved between the lookup
     * and the write cannot send the hash to the wrong account, and delegates the
     * statement itself to {@see LegacyCredentialWriter} so the recovery flow writes
     * exactly the same three columns in exactly the same way.  The plaintext value
     * stops existing the moment the user has proven they knew it.
     */
    public function upgradeCredential(User $user, string $plainPassword): bool
    {
        return $this->credentials->forUserId($user->getKey(), $plainPassword);
    }
}
