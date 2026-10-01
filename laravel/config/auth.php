<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | One guard, one provider.  The application has exactly one kind of account
    | (`dbo.user_table`) and one browser session model, so a second guard would
    | be an unused abstraction rather than a feature.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | The `session` guard with the `users` provider backs every authenticated
    | request.  The revocable half of the session lives in `dbo.user_sessions`
    | and is enforced by the `legacy.session` middleware, since Laravel's signed
    | cookie cannot be revoked on its own.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | The provider is **not** `eloquent`, and that is the single most important
    | decision in this file.  `EloquentUserProvider::validateCredentials()`
    | calls `Hash::check()` against {@see User::getAuthPassword()}, but 15 of the
    | 16 live accounts store their password as **plaintext** in the legacy
    | `password nchar(10)` column, so every real account would be rejected.
    |
    | The `legacy` driver (registered in `AppServiceProvider`) delegates to
    | `App\Auth\LegacyUserProvider`, which reproduces the Python
    | verification order (bcrypt in `password_hash`, bcrypt or SHA-512 in
    | `password`, then constant-time plaintext) and folds the account-active rule
    | into credential validation.  The `model` key names the row class; the
    | driver decides how it is queried, so replacing the provider never requires
    | touching this file again.
    |
    | The `passwords` broker stays pointed at the `users` provider for
    | `Password::sendResetLink()`, which is **not** used: this application's
    | recovery flow is its own (see `App\Services\Audit\RecoveryCodeService`),
    | because the code is delivered through MailPoet and verified against an
    | HMAC digest rather than a `password_reset_tokens` row.
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'legacy',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | Declared for completeness only — no route uses it.  The live recovery flow
    | stores an HMAC digest in `dbo.password_reset_requests` and never creates a
    | `password_reset_tokens` row, so the table this section names does not exist
    | in `userDB`, and `Password::broker()` must not be called.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | The legacy application had no password-confirmation window; it re-checks
    | the session flags instead.  Left at Laravel's default because nothing reads
    | it, and a lower value would silently change behaviour if something did.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
