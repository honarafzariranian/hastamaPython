<?php

use App\Services\Settings\SystemSettings;
use App\Support\Legacy\LegacyCaptcha;

/*
|--------------------------------------------------------------------------
| Hastama deployment configuration
|--------------------------------------------------------------------------
|
| Only secrets and deployment facts belong in the environment.  Everything the
| operator can change at runtime lives in the `system_config` table and is read
| through `App\Services\Settings\SystemSettings` — which is why this file is short.
|
| The values here are the ones the Python application took from environment
| variables, kept under their original names so the existing scripts and the
| documented deployment procedure keep working:
|
|   MASTER_ADMIN_USERNAMES      who is allowed into the control centre
|   HASTAMA_IDLE_TIMEOUT_SECONDS server-side session idle limit
|   HASTAMA_SESSION_CHECK_TTL    how long a session check is cached
|   SESSION_MAX_AGE_SECONDS      lifetime of the readable CSRF cookie
|
*/

return [

    /*
    | Accounts that may open the master-admin control centre.
    |
    | Transcribed from `MASTER_ADMIN_USERNAMES` (default `ali`), which
    | `app/api/routes/auth.py` and `app/core/sessions.py` both read.  Compared
    | case-insensitively against the trimmed username, as the original did.
    */
    'master_admin_usernames' => array_values(array_filter(array_map(
        static fn (string $name): string => trim($name),
        explode(',', (string) env('MASTER_ADMIN_USERNAMES', 'ali')),
    ))),

    /*
    | The wall clock the legacy application used for **date boundaries**.
    |
    | Distinct from `app.timezone`, which stays `UTC` because the database stores
    | UTC (`SYSUTCDATETIME()` defaults) and reinterpreting stored datetimes would
    | be a data bug.  But the Python server computed "today" from the machine's
    | local clock — `date.today()`, `JalaliDate.today()`, `jdatetime.date.today()`
    | — and this deployment runs in Iran, so the two differ between 20:30 and
    | 24:00 UTC.  Anything that answers "today" or "this Jalali month" must use
    | this clock, or it silently answers for the wrong day for three and a half
    | hours every night.  See `App\Support\Legacy\LegacyDate`.
    */
    'display_timezone' => (string) env('HASTAMA_DISPLAY_TIMEZONE', 'Asia/Tehran'),

    'session' => [
        /*
         | How long a registry check may be cached, in seconds.
         |
         | The middleware validates the session on every request; the Python
         | application cached the answer for a few seconds for the same reason.
         | Revocation clears the entry, so a terminated session still dies at once.
         */
        'check_ttl' => (int) env('HASTAMA_SESSION_CHECK_TTL', 5),

        /*
         | Server-side idle limit, in seconds.
         |
         | This is **not** `system_config.idle_timeout_seconds` (300).  That setting
         | drives the client-side timer in the existing JavaScript, which redirects
         | the browser to `/login`; the registry's own idle check has always used
         | `HASTAMA_IDLE_TIMEOUT_SECONDS` (default 1800).  Keeping both distinct
         | preserves the existing behaviour instead of inventing a stricter one.
         */
        'idle_seconds' => (int) env('HASTAMA_IDLE_TIMEOUT_SECONDS', 1800),

        /*
         | Lifetime of the readable `csrf_token` cookie, in seconds.
         |
         | The legacy front-end reads this cookie and echoes it in the
         | `X-CSRF-Token` header, so it has to be published alongside Laravel's own
         | session token while both applications share a browser.
         */
        'csrf_cookie_max_age' => (int) env('SESSION_MAX_AGE_SECONDS', 28800),
    ],

    'captcha' => [
        /* Length and alphabet are fixed by the legacy generator. */
        'length' => LegacyCaptcha::LENGTH,
        'ttl_setting' => 'login_captcha_ttl_seconds',
        'default_ttl' => LegacyCaptcha::DEFAULT_TTL_SECONDS,
        'min_ttl' => LegacyCaptcha::MIN_TTL_SECONDS,
        'max_ttl' => LegacyCaptcha::MAX_TTL_SECONDS,

        /* The login page warns this many seconds before the code expires. */
        'warning_lead_seconds' => 60,
    ],

    /*
    | CSRF exemptions.
    |
    | Transcribed _verbatim_ from `CSRF_EXEMPT_PREFIXES` in `app/main.py`, and they
    | are matched as **prefixes**, not as whole URIs: an exempt area gains a child
    | endpoint far more often than it changes its root, and a prefix that stopped
    | matching a nested path would be a silent security regression.
    |
    | An exemption is only half of the control.  The legacy middleware still
    | rejected a cross-site write to an exempt path with an `Origin` check, and
    | `App\Http\Middleware\VerifyLegacyCsrf` does the same — see its docblock for
    | why the check lives there rather than being deferred to the feature phase
    | that adds the kiosk endpoints.
    |
    | This list is deliberately exhaustive rather than grown endpoint by endpoint:
    | the middleware must already agree with the running application on the day the
    | first kiosk request arrives, because the failure mode of getting it wrong in
    | the other direction (a kiosk that cannot issue a ticket) is loud, while the
    | failure mode of an accidentally *unexempted* endpoint is a 403 nobody notices
    | until the LAN integration is reported broken.
    */
    'csrf' => [
        'exempt_prefixes' => [
            '/api/calls',
            '/api/call-display',
            '/api/display-queue',
            '/api/waiting-queue',
            '/api/queue/take',
            '/api/queue/print',
            '/api/label/print-document',
            '/api/queue/ticket',
            '/api/slides',
            '/api/ws/',
            '/login_user',
            '/forgot_password',
            '/reset_password',
            '/verify_recovery_code',
            '/registration/submit',
            '/registration/status/',
            '/registration/check-username',
            '/registration/check-national-id',
            '/registration/departments',
            '/registration/work-schedules',
            '/registration/active-users',
            '/captcha/',
            '/api/araz/',
            '/public/',
        ],
    ],

    'settings' => [
        /* Keys the public `/api/system-config` endpoint may publish. */
        'public_keys' => SystemSettings::PUBLIC_KEYS,
    ],

    /*
    | Where private attachments are written.
    |
    | `TICKETING_PRIVATE_DIR` under its original name, defaulting to the relative
    | path `app/private_uploads/tickets` the Python resolved against the legacy
    | working directory — which is this repository's root, not `laravel/`.  Both
    | `app/services/ticketing.py` (`_PRIVATE_ROOT`) and `app/services/automation.py`
    | read the same variable, so one key covers the ticket and the conversation
    | attachments: a deployment that moved the directory would otherwise move half
    | of them.
    |
    | It lives here rather than behind `env()` in the store class because `env()`
    | answers `null` once `php artisan config:cache` has been run, and a cached
    | configuration must not silently relocate every upload.
    */
    'ticketing_private_dir' => (string) env('TICKETING_PRIVATE_DIR', base_path('../app/private_uploads/tickets')),

    'recovery' => [
        /*
         | Key protecting the password-recovery codes.
         |
         | `HASTAMA_HMAC_SECRET` under its original name.  The recovery flow
         | **fails closed** when it is empty: the code is stored as an HMAC digest,
         | so without a key the digest would carry no integrity and recovery is
         | refused rather than allowed with a weaker check.  In debug mode only, an
         | ephemeral per-process key is used so a local checkout still works.
         |
         | Note this is a machine credential, not an account password: it belongs in
         | `.env` and must never be committed.
         */
        'hmac_secret' => (string) env('HASTAMA_HMAC_SECRET', ''),

        /* Validity of an issued code, in minutes (`password_reset_code_ttl_minutes`). */
        'code_ttl_minutes' => 60,
    ],

];
